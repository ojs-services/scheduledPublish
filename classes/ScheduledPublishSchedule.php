<?php

/**
 * @file classes/ScheduledPublishSchedule.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishSchedule
 * @brief Reads and writes the scheduling data of an issue.
 *
 * The data lives in the issue's own settings (issue_settings; the properties are added to the
 * issue schema), so OJS saves it with the issue form and removes it with the issue. The row
 * that holds the state doubles as the lock: a task claims an issue by moving the state from
 * "scheduled" to "processing" in one UPDATE, so two overlapping runs can never publish the
 * same issue.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\issue\Issue;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;

class ScheduledPublishSchedule
{
    public const FIELD_AT = 'scheduledPublishAt';
    public const FIELD_BY = 'scheduledPublishBy';
    public const FIELD_STATE = 'scheduledPublishState';
    public const FIELD_CLAIMED_AT = 'scheduledPublishClaimedAt';
    public const FIELD_NOTE = 'scheduledPublishNote';

    public const STATE_SCHEDULED = 'scheduled';
    public const STATE_PROCESSING = 'processing';
    public const STATE_EXPIRED = 'expired';

    /** A claim older than this is considered abandoned (the run died) and is released. */
    public const CLAIM_STALE_SECONDS = 1800;

    /** @return string[] Setting names this plugin stores on an issue. */
    public static function fieldNames(): array
    {
        return [self::FIELD_AT, self::FIELD_BY, self::FIELD_STATE, self::FIELD_CLAIMED_AT, self::FIELD_NOTE];
    }

    /** @return array<string, array> Issue schema properties (Schema::get::issue). */
    public static function schemaProperties(): array
    {
        return [
            self::FIELD_AT => ['type' => 'string', 'validation' => ['nullable', 'date_format:Y-m-d H:i:s']],
            self::FIELD_BY => ['type' => 'integer', 'validation' => ['nullable']],
            self::FIELD_STATE => ['type' => 'string', 'validation' => ['nullable']],
            self::FIELD_CLAIMED_AT => ['type' => 'string', 'validation' => ['nullable']],
            self::FIELD_NOTE => ['type' => 'string', 'validation' => ['nullable']],
        ];
    }

    /**
     * Turn the form input into a stored value.
     * @param $date string Y-m-d (the browser's date input)
     * @param $time string H:i or H:i:s (the browser's time input); empty means 00:00
     * @return string|null Y-m-d H:i:00, or null when the input is not a valid date/time
     */
    public static function parseInput($date, $time): ?string
    {
        $date = trim((string) $date);
        $time = trim((string) $time);
        if ($time === '') {
            $time = '00:00';
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])) {
            return null;
        }
        if (!preg_match('/^(\d{2}):(\d{2})(?::\d{2})?$/', $time, $t) || (int) $t[1] > 23 || (int) $t[2] > 59) {
            return null;
        }
        return sprintf('%04d-%02d-%02d %02d:%02d:00', $d[1], $d[2], $d[3], $t[1], $t[2]);
    }

    public function getState(Issue $issue): ?string
    {
        $state = $issue->getData(self::FIELD_STATE);
        return $state ? (string) $state : null;
    }

    public function getAt(Issue $issue): ?string
    {
        $at = $issue->getData(self::FIELD_AT);
        return $at ? (string) $at : null;
    }

    public function getBy(Issue $issue): ?int
    {
        $by = $issue->getData(self::FIELD_BY);
        return $by ? (int) $by : null;
    }

    public function getNote(Issue $issue): ?string
    {
        $note = $issue->getData(self::FIELD_NOTE);
        return $note ? (string) $note : null;
    }

    /** Schedule the issue (in memory; the issue form's own save persists it). */
    public function set(Issue $issue, string $at, ?int $userId): void
    {
        $issue->setData(self::FIELD_AT, $at);
        $issue->setData(self::FIELD_BY, $userId ?: null);
        $issue->setData(self::FIELD_STATE, self::STATE_SCHEDULED);
        $issue->setData(self::FIELD_CLAIMED_AT, null);
        $issue->setData(self::FIELD_NOTE, null);
    }

    /** Remove the scheduling data from the object only, so a following save does not write it. */
    public function detach(Issue $issue): void
    {
        foreach (self::fieldNames() as $name) {
            $issue->unsetData($name);
        }
    }

    /** Remove the scheduling data from the object and from the database. */
    public function clear(Issue $issue): void
    {
        $this->detach($issue);
        $this->deleteRows((int) $issue->getId());
    }

    public function deleteRows(int $issueId): void
    {
        DB::table('issue_settings')
            ->where('issue_id', $issueId)
            ->whereIn('setting_name', self::fieldNames())
            ->delete();
    }

    /**
     * Issues of a journal whose scheduled time has arrived and that are still unpublished.
     * @return array of ['issue_id' => int, 'at' => string], oldest first
     */
    public function findDue(int $contextId, string $now): array
    {
        $rows = DB::table('issues AS i')
            ->join('issue_settings AS st', function ($join) {
                $join->on('st.issue_id', '=', 'i.issue_id')
                    ->where('st.setting_name', '=', self::FIELD_STATE)
                    ->where('st.setting_value', '=', self::STATE_SCHEDULED);
            })
            ->join('issue_settings AS a', function ($join) {
                $join->on('a.issue_id', '=', 'i.issue_id')
                    ->where('a.setting_name', '=', self::FIELD_AT);
            })
            ->where('i.journal_id', $contextId)
            ->where('i.published', 0)
            ->where('a.setting_value', '<=', $now)
            ->orderBy('a.setting_value')
            ->orderBy('i.issue_id')
            ->select('i.issue_id', 'a.setting_value AS at')
            ->get();
        $due = [];
        foreach ($rows as $row) {
            $due[] = ['issue_id' => (int) $row->issue_id, 'at' => (string) $row->at];
        }
        return $due;
    }

    /** Take the issue for publishing. Succeeds for exactly one caller. */
    public function claim(int $issueId): bool
    {
        $changed = DB::table('issue_settings')
            ->where('issue_id', $issueId)
            ->where('setting_name', self::FIELD_STATE)
            ->where('setting_value', self::STATE_SCHEDULED)
            ->update(['setting_value' => self::STATE_PROCESSING]);
        if ($changed !== 1) {
            return false;
        }
        $this->writeSetting($issueId, self::FIELD_CLAIMED_AT, Core::getCurrentDate());
        return true;
    }

    /** Mark the issue as not published because the tolerance window has passed. */
    public function expire(int $issueId, string $note): void
    {
        DB::table('issue_settings')
            ->where('issue_id', $issueId)
            ->where('setting_name', self::FIELD_STATE)
            ->whereIn('setting_value', [self::STATE_SCHEDULED, self::STATE_PROCESSING])
            ->update(['setting_value' => self::STATE_EXPIRED]);
        $this->writeSetting($issueId, self::FIELD_NOTE, $note);
    }

    /** Keep the claim (the stale check retries later) but record what went wrong. */
    public function noteFailure(int $issueId, string $note): void
    {
        $this->writeSetting($issueId, self::FIELD_NOTE, $note);
    }

    /**
     * Release claims older than CLAIM_STALE_SECONDS so an interrupted run is retried.
     * @return int Number of released issues
     */
    public function releaseStale(int $contextId): int
    {
        $limit = Core::getCurrentDate(time() - self::CLAIM_STALE_SECONDS);
        $rows = DB::table('issues AS i')
            ->join('issue_settings AS st', function ($join) {
                $join->on('st.issue_id', '=', 'i.issue_id')
                    ->where('st.setting_name', '=', self::FIELD_STATE)
                    ->where('st.setting_value', '=', self::STATE_PROCESSING);
            })
            ->leftJoin('issue_settings AS c', function ($join) {
                $join->on('c.issue_id', '=', 'i.issue_id')
                    ->where('c.setting_name', '=', self::FIELD_CLAIMED_AT);
            })
            ->where('i.journal_id', $contextId)
            ->where(function ($query) use ($limit) {
                $query->whereNull('c.setting_value')->orWhere('c.setting_value', '<', $limit);
            })
            ->select('i.issue_id')
            ->get();
        $released = 0;
        foreach ($rows as $row) {
            $released += DB::table('issue_settings')
                ->where('issue_id', (int) $row->issue_id)
                ->where('setting_name', self::FIELD_STATE)
                ->where('setting_value', self::STATE_PROCESSING)
                ->update(['setting_value' => self::STATE_SCHEDULED]);
        }
        return $released;
    }

    /** Write one setting row directly (used by the tasks, outside the issue form). */
    public function writeSetting(int $issueId, string $name, ?string $value): void
    {
        $keys = ['issue_id' => $issueId, 'locale' => '', 'setting_name' => $name];
        if ($value === null) {
            DB::table('issue_settings')->where($keys)->delete();
            return;
        }
        DB::table('issue_settings')->updateOrInsert($keys, ['setting_value' => $value]);
    }
}

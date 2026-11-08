<?php

/**
 * @file classes/ScheduledPublishSchedule.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishSchedule
 * @brief Reads and writes the scheduling data of an issue.
 *
 * The data lives in the issue's own settings (issue_settings), so OJS saves it with the
 * issue form and removes it with the issue. The row that holds the state doubles as the
 * lock: a task claims an issue by moving the state from "scheduled" to "processing" in one
 * UPDATE, so two overlapping runs can never publish the same issue.
 */

use Illuminate\Database\Capsule\Manager as Capsule;

class ScheduledPublishSchedule
{
    const FIELD_AT = 'scheduledPublishAt';
    const FIELD_BY = 'scheduledPublishBy';
    const FIELD_STATE = 'scheduledPublishState';
    const FIELD_CLAIMED_AT = 'scheduledPublishClaimedAt';
    const FIELD_NOTE = 'scheduledPublishNote';

    const STATE_SCHEDULED = 'scheduled';
    const STATE_PROCESSING = 'processing';
    const STATE_EXPIRED = 'expired';

    /** A claim older than this is considered abandoned (the run died) and is released. */
    const CLAIM_STALE_SECONDS = 1800;

    /** @return string[] Setting names this plugin stores on an issue. */
    public static function fieldNames()
    {
        return [self::FIELD_AT, self::FIELD_BY, self::FIELD_STATE, self::FIELD_CLAIMED_AT, self::FIELD_NOTE];
    }

    /**
     * Turn the form input into a stored value.
     * @param $date string Y-m-d (the browser's date input)
     * @param $time string H:i or H:i:s (the browser's time input); empty means 00:00
     * @return string|null Y-m-d H:i:00, or null when the input is not a valid date/time
     */
    public static function parseInput($date, $time)
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

    /** @return string|null One of the STATE_* values, or null when the issue is not scheduled. */
    public function getState($issue)
    {
        $state = $issue->getData(self::FIELD_STATE);
        return $state ? (string) $state : null;
    }

    /** @return string|null Y-m-d H:i:s */
    public function getAt($issue)
    {
        $at = $issue->getData(self::FIELD_AT);
        return $at ? (string) $at : null;
    }

    /** @return int|null */
    public function getBy($issue)
    {
        $by = $issue->getData(self::FIELD_BY);
        return $by ? (int) $by : null;
    }

    /** @return string|null */
    public function getNote($issue)
    {
        $note = $issue->getData(self::FIELD_NOTE);
        return $note ? (string) $note : null;
    }

    /**
     * Schedule the issue (in memory; the issue form's own save persists it).
     * @param $at string Y-m-d H:i:s
     * @param $userId int|null
     */
    public function set($issue, $at, $userId)
    {
        $issue->setData(self::FIELD_AT, $at);
        $issue->setData(self::FIELD_BY, $userId ? (int) $userId : null);
        $issue->setData(self::FIELD_STATE, self::STATE_SCHEDULED);
        $issue->setData(self::FIELD_CLAIMED_AT, null);
        $issue->setData(self::FIELD_NOTE, null);
    }

    /** Remove the scheduling data from the object only, so a following save does not write it. */
    public function detach($issue)
    {
        foreach (self::fieldNames() as $name) {
            $issue->unsetData($name);
        }
    }

    /** Remove the scheduling data from the object and from the database. */
    public function clear($issue)
    {
        $this->detach($issue);
        $this->deleteRows((int) $issue->getId());
    }

    /** @param $issueId int */
    public function deleteRows($issueId)
    {
        Capsule::table('issue_settings')
            ->where('issue_id', (int) $issueId)
            ->whereIn('setting_name', self::fieldNames())
            ->delete();
    }

    /**
     * Issues of a journal whose scheduled time has arrived and that are still unpublished.
     * @param $contextId int
     * @param $now string Y-m-d H:i:s
     * @return array of ['issue_id' => int, 'at' => string], oldest first
     */
    public function findDue($contextId, $now)
    {
        $rows = Capsule::table('issues AS i')
            ->join('issue_settings AS st', function ($join) {
                $join->on('st.issue_id', '=', 'i.issue_id')
                    ->where('st.setting_name', '=', self::FIELD_STATE)
                    ->where('st.setting_value', '=', self::STATE_SCHEDULED);
            })
            ->join('issue_settings AS a', function ($join) {
                $join->on('a.issue_id', '=', 'i.issue_id')
                    ->where('a.setting_name', '=', self::FIELD_AT);
            })
            ->where('i.journal_id', (int) $contextId)
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

    /**
     * Take the issue for publishing. Succeeds for exactly one caller.
     * @return bool
     */
    public function claim($issueId)
    {
        $changed = Capsule::table('issue_settings')
            ->where('issue_id', (int) $issueId)
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
    public function expire($issueId, $note)
    {
        Capsule::table('issue_settings')
            ->where('issue_id', (int) $issueId)
            ->where('setting_name', self::FIELD_STATE)
            ->whereIn('setting_value', [self::STATE_SCHEDULED, self::STATE_PROCESSING])
            ->update(['setting_value' => self::STATE_EXPIRED]);
        $this->writeSetting($issueId, self::FIELD_NOTE, $note);
    }

    /** Keep the claim (the stale check retries later) but record what went wrong. */
    public function noteFailure($issueId, $note)
    {
        $this->writeSetting($issueId, self::FIELD_NOTE, $note);
    }

    /**
     * Release claims older than CLAIM_STALE_SECONDS so an interrupted run is retried.
     * @return int Number of released issues
     */
    public function releaseStale($contextId)
    {
        $limit = Core::getCurrentDate(time() - self::CLAIM_STALE_SECONDS);
        $rows = Capsule::table('issues AS i')
            ->join('issue_settings AS st', function ($join) {
                $join->on('st.issue_id', '=', 'i.issue_id')
                    ->where('st.setting_name', '=', self::FIELD_STATE)
                    ->where('st.setting_value', '=', self::STATE_PROCESSING);
            })
            ->leftJoin('issue_settings AS c', function ($join) {
                $join->on('c.issue_id', '=', 'i.issue_id')
                    ->where('c.setting_name', '=', self::FIELD_CLAIMED_AT);
            })
            ->where('i.journal_id', (int) $contextId)
            ->where(function ($query) use ($limit) {
                $query->whereNull('c.setting_value')->orWhere('c.setting_value', '<', $limit);
            })
            ->select('i.issue_id')
            ->get();
        $released = 0;
        foreach ($rows as $row) {
            $released += Capsule::table('issue_settings')
                ->where('issue_id', (int) $row->issue_id)
                ->where('setting_name', self::FIELD_STATE)
                ->where('setting_value', self::STATE_PROCESSING)
                ->update(['setting_value' => self::STATE_SCHEDULED]);
        }
        return $released;
    }

    /** Write one setting row directly (used by the task, outside the issue form). */
    public function writeSetting($issueId, $name, $value)
    {
        $table = Capsule::table('issue_settings');
        $keys = ['issue_id' => (int) $issueId, 'locale' => '', 'setting_name' => $name];
        if ($value === null) {
            $table->where($keys)->delete();
            return;
        }
        $exists = Capsule::table('issue_settings')->where($keys)->exists();
        if ($exists) {
            Capsule::table('issue_settings')->where($keys)->update(['setting_value' => (string) $value, 'setting_type' => 'string']);
        } else {
            $table->insert($keys + ['setting_value' => (string) $value, 'setting_type' => 'string']);
        }
    }
}

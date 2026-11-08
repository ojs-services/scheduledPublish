<?php

/**
 * @file classes/ScheduledPublishIssueTask.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishIssueTask
 * @brief Scheduled task: publishes issues whose scheduled date and time have arrived.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use PKP\core\Core;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class ScheduledPublishIssueTask extends ScheduledTask
{
    /** @copydoc ScheduledTask::getName() */
    public function getName(): string
    {
        return __('plugins.generic.scheduledPublish.task.issues');
    }

    /** @copydoc ScheduledTask::executeActions() */
    protected function executeActions(): bool
    {
        $plugin = ScheduledPublishPlugin::getInstance();
        if (!$plugin || !$plugin->getEnabled()) {
            $this->addExecutionLogEntry('Scheduled Publish plugin is not enabled; nothing to do.', ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            return true;
        }

        $plugin->markTaskRun();
        $schedule = new ScheduledPublishSchedule();
        $publisher = new ScheduledPublishIssuePublisher($plugin, $schedule, function (string $message, string $type) {
            $this->addExecutionLogEntry($message, $type);
        });
        $toleranceHours = $plugin->getToleranceHours();
        $toleranceSeconds = $toleranceHours * 3600;
        $now = Core::getCurrentDate();

        foreach (Application::getContextDAO()->getAll(true)->toArray() as $journal) {
            $contextId = (int) $journal->getId();
            $released = $schedule->releaseStale($contextId);
            if ($released) {
                $this->addExecutionLogEntry(sprintf('Journal %d: %d abandoned claim(s) released for retry.', $contextId, $released), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
            }

            foreach ($schedule->findDue($contextId, $now) as $due) {
                $issueId = $due['issue_id'];
                $late = time() - strtotime($due['at']);

                if ($late > $toleranceSeconds) {
                    $note = sprintf('expired: scheduled %s, first run %s, tolerance %d h', $due['at'], $now, $toleranceHours);
                    $schedule->expire($issueId, $note);
                    $this->addExecutionLogEntry(sprintf('Journal %d, issue %d NOT published: %s.', $contextId, $issueId, $note), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                    continue;
                }

                if (!$schedule->claim($issueId)) {
                    $this->addExecutionLogEntry(sprintf('Journal %d, issue %d: already being published by another run.', $contextId, $issueId), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
                    continue;
                }

                try {
                    if ($late > 120) {
                        $this->addExecutionLogEntry(sprintf('Journal %d, issue %d: running %d minutes after the scheduled time.', $contextId, $issueId, (int) round($late / 60)), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
                    }
                    $publisher->publish($issueId, $due['at']);
                } catch (Throwable $e) {
                    error_log('[scheduledPublish] issue ' . $issueId . ': ' . $e->getMessage());
                    $issue = Repo::issue()->get($issueId);
                    if ($issue && $issue->getPublished()) {
                        // The issue is out; only a step after the publish itself failed.
                        $schedule->clear($issue);
                        $this->addExecutionLogEntry(sprintf('Journal %d, issue %d: published, but a later step failed: %s', $contextId, $issueId, $e->getMessage()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
                        continue;
                    }
                    $schedule->noteFailure($issueId, 'error: ' . $e->getMessage());
                    $this->addExecutionLogEntry(sprintf('Journal %d, issue %d: publishing failed, will be retried after %d minutes. %s', $contextId, $issueId, ScheduledPublishSchedule::CLAIM_STALE_SECONDS / 60, $e->getMessage()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
                }
            }
        }

        return true;
    }
}

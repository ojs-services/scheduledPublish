<?php

/**
 * @file classes/ScheduledPublishSubmissionsTask.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishSubmissionsTask
 * @brief Scheduled task: publishes articles whose publication date has arrived.
 *
 * Same job as the core's PublishSubmissions task, which OJS ships but never registers, with
 * two differences: an article is published only when its issue is published (or it has no
 * issue), and the request carries the article's journal while it is published.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use APP\submission\Submission;
use PKP\observers\events\MetadataChanged;
use PKP\scheduledTask\ScheduledTask;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class ScheduledPublishSubmissionsTask extends ScheduledTask
{
    /** @copydoc ScheduledTask::getName() */
    public function getName(): string
    {
        return __('plugins.generic.scheduledPublish.task.submissions');
    }

    /** @copydoc ScheduledTask::executeActions() */
    protected function executeActions(): bool
    {
        $plugin = ScheduledPublishPlugin::getInstance();
        if (!$plugin || !$plugin->getEnabled()) {
            $this->addExecutionLogEntry('Scheduled Publish plugin is not enabled; nothing to do.', ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            return true;
        }

        $now = time();
        foreach (Application::getContextDAO()->getAll(true)->toArray() as $journal) {
            $contextId = (int) $journal->getId();

            // Find the due articles first; the journal context (and its plugins) is set up
            // only when there is something to publish, so idle runs stay cheap.
            $due = [];
            $submissions = Repo::submission()->getCollector()
                ->filterByContextIds([$contextId])
                ->filterByStatus([Submission::STATUS_SCHEDULED])
                ->getMany();
            foreach ($submissions as $submission) { /** @var Submission $submission */
                $publication = $submission->getCurrentPublication();
                if (!$publication || $publication->getData('status') !== Submission::STATUS_SCHEDULED) {
                    continue;
                }
                $datePublished = $publication->getData('datePublished');
                if (!$datePublished || strtotime($datePublished) > $now) {
                    continue;
                }
                $issueId = (int) $publication->getData('issueId');
                if ($issueId) {
                    $issue = Repo::issue()->get($issueId);
                    if (!$issue || !$issue->getPublished()) {
                        continue; // the issue is not out yet: the article waits for it
                    }
                }
                $due[] = [$submission, $publication];
            }
            if (!$due) {
                continue;
            }

            $saved = ScheduledPublishContext::enter($journal);
            $published = 0;
            try {
                foreach ($due as [$submission, $publication]) {
                    try {
                        Repo::publication()->publish($publication);
                        if ($plugin->isOjs35()) {
                            event(new MetadataChanged($submission));
                        }
                        $published++;
                    } catch (Throwable $e) {
                        $this->addExecutionLogEntry(sprintf('Journal %d, submission %d: publishing failed. %s', $contextId, $submission->getId(), $e->getMessage()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
                        error_log('[scheduledPublish] submission ' . $submission->getId() . ': ' . $e->getMessage());
                    }
                }
            } finally {
                ScheduledPublishContext::leave($saved);
            }
            if ($published) {
                $this->addExecutionLogEntry(sprintf('Journal %d: %d article(s) published.', $contextId, $published), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            }
        }

        return true;
    }
}

<?php

/**
 * @file classes/ScheduledPublishSubmissionsTask.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishSubmissionsTask
 * @brief Scheduled task: publishes articles whose publication date has arrived.
 *
 * Same job as the core's PublishSubmissions task, which OJS ships but never registers,
 * with two differences: an article is published only when its issue is published (or it
 * has no issue), and the request carries the article's journal while it is published.
 */

import('lib.pkp.classes.scheduledTask.ScheduledTask');
import('plugins.generic.scheduledPublish.classes.ScheduledPublishContext');

class ScheduledPublishSubmissionsTask extends ScheduledTask
{
    /** @copydoc ScheduledTask::getName() */
    public function getName()
    {
        return __('plugins.generic.scheduledPublish.task.submissions');
    }

    /** @copydoc ScheduledTask::executeActions() */
    protected function executeActions()
    {
        $plugin = ScheduledPublishPlugin::getInstance();
        if (!$plugin || !$plugin->getEnabled()) {
            $this->addExecutionLogEntry('Scheduled Publish plugin is not enabled; nothing to do.', SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            return true;
        }

        import('classes.submission.Submission');
        $contextDao = Application::getContextDAO();
        $now = time();

        $contextIds = Services::get('context')->getIds(['isEnabled' => true]);
        foreach ($contextIds as $contextId) {
            $journal = $contextDao->getById($contextId);
            if (!$journal) {
                continue;
            }
            // Find the due articles first; the journal context (and its plugins) is set up
            // only when there is something to publish, so idle runs stay cheap.
            $due = [];
            $submissions = Services::get('submission')->getMany([
                'contextId' => $contextId,
                'status' => STATUS_SCHEDULED,
            ]);
            foreach ($submissions as $submission) { /** @var Submission $submission */
                $publication = $submission->getCurrentPublication();
                if (!$publication || $publication->getData('status') !== STATUS_SCHEDULED) {
                    continue;
                }
                $datePublished = $publication->getData('datePublished');
                if (!$datePublished || strtotime($datePublished) > $now) {
                    continue;
                }
                $issueId = (int) $publication->getData('issueId');
                if ($issueId) {
                    $issue = Services::get('issue')->get($issueId);
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
                foreach ($due as list($submission, $publication)) {
                    try {
                        Services::get('publication')->publish($publication);
                        $published++;
                    } catch (Throwable $e) {
                        $this->addExecutionLogEntry(sprintf('Journal %d, submission %d: publishing failed. %s', $contextId, $submission->getId(), $e->getMessage()), SCHEDULED_TASK_MESSAGE_TYPE_ERROR);
                        error_log('[scheduledPublish] submission ' . $submission->getId() . ': ' . $e->getMessage());
                    }
                }
            } finally {
                ScheduledPublishContext::leave($saved);
            }
            if ($published) {
                $this->addExecutionLogEntry(sprintf('Journal %d: %d article(s) published.', $contextId, $published), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            }
        }

        return true;
    }
}

<?php

/**
 * @file classes/ScheduledPublishIssuePublisher.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishIssuePublisher
 * @brief Publishes a scheduled issue exactly as the "Publish Issue" action does.
 *
 * Mirrors IssueGridHandler::publishIssue() of OJS 3.3 step by step: public identifiers,
 * issue flags, delayed open access, the IssueGridHandler::publishIssue hook, current issue,
 * the scheduled articles, and the reader notification under OJS's own rule. Differences:
 * the publication date is the scheduled time, not the moment the task ran; the request
 * carries the issue's journal; and the scheduling data is removed before the notification.
 */

import('plugins.generic.scheduledPublish.classes.ScheduledPublishSchedule');
import('plugins.generic.scheduledPublish.classes.ScheduledPublishContext');

class ScheduledPublishIssuePublisher
{
    /** @var ScheduledPublishPlugin */
    private $_plugin;

    /** @var ScheduledPublishSchedule */
    private $_schedule;

    /** @var callable function (string $message, string $type) */
    private $_log;

    public function __construct($plugin, $schedule, callable $log)
    {
        $this->_plugin = $plugin;
        $this->_schedule = $schedule;
        $this->_log = $log;
    }

    /**
     * @param $issueId int
     * @param $scheduledAt string Y-m-d H:i:s, becomes the issue's publication date
     * @return array Summary for the task log
     */
    public function publish($issueId, $scheduledAt)
    {
        $issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
        $issue = $issueDao->getById((int) $issueId, null, false);
        if (!$issue) {
            throw new Exception('Issue ' . (int) $issueId . ' no longer exists.');
        }
        if ($issue->getPublished()) {
            // Published by hand in the meantime: nothing to do but drop the schedule.
            $this->_schedule->clear($issue);
            return ['skipped' => 'alreadyPublished'];
        }

        $contextDao = Application::getContextDAO();
        $journal = $contextDao->getById($issue->getJournalId());
        if (!$journal) {
            throw new Exception('Journal of issue ' . (int) $issueId . ' not found.');
        }
        $contextId = (int) $journal->getId();
        $request = Application::get()->getRequest();

        $saved = ScheduledPublishContext::enter($journal);
        $this->_plugin->setPublishing(true);
        $vars = & $request->getUserVars();
        $hadVar = array_key_exists('sendIssueNotification', $vars);
        $oldVar = $hadVar ? $vars['sendIssueNotification'] : null;
        try {
            AppLocale::requireComponents(LOCALE_COMPONENT_APP_EDITOR, LOCALE_COMPONENT_PKP_SUBMISSION, LOCALE_COMPONENT_APP_COMMON);
            import('classes.submission.Submission');

            // 1. Public identifiers, as the publish dialog assigns them with its default ticks.
            $this->_assignPubIds($issue, $contextId);

            // 2. Issue flags. The publication date is the scheduled time (decision 9).
            $issue->setCurrent(1);
            $issue->setPublished(1);
            $issue->setDatePublished($scheduledAt);

            // 3. Delayed open access, as in the core.
            if ($journal->getData('publishingMode') == PUBLISHING_MODE_SUBSCRIPTION && ($delayDuration = $journal->getData('delayedOpenAccessDuration'))) {
                $delayYears = (int) floor($delayDuration / 12);
                $delayMonths = (int) fmod($delayDuration, 12);
                $curYear = date('Y');
                $curMonth = date('n');
                $curDay = date('j');
                $delayOpenAccessYear = $curYear + $delayYears + (int) floor(($curMonth + $delayMonths) / 12);
                $delayOpenAccessMonth = (int) fmod($curMonth + $delayMonths, 12);
                $issue->setAccessStatus(ISSUE_ACCESS_SUBSCRIPTION);
                $issue->setOpenAccessDate(date('Y-m-d H:i:s', mktime(0, 0, 0, $delayOpenAccessMonth, $curDay, $delayOpenAccessYear)));
            }

            // 4. The dialog's notification tick, in its default state, so plugins that read
            //    or remove it (Issue Contributor Notify) see what they see on a manual publish.
            $vars['sendIssueNotification'] = 1;

            // 5. The same hook the grid handler calls.
            $this->_schedule->detach($issue); // the core's save below must not rewrite our rows
            HookRegistry::call('IssueGridHandler::publishIssue', [&$issue]);

            // 6. Current issue and save.
            $issueDao->updateCurrent($contextId, $issue);

            // 7. Scheduled articles of this issue (decisions 2 and 3).
            $articles = $this->_publishArticles($issue, $contextId);

            // 8. The schedule is done.
            $this->_schedule->clear($issue);
            $this->_log(sprintf(
                'Issue %d published (scheduled %s, run %s); articles published: %d, waiting for their own date: %d; %s.',
                $issue->getId(), $scheduledAt, Core::getCurrentDate(), $articles['published'], $articles['waiting'], $articles['timing']
            ), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

            // 9. Reader notification under OJS's own rule (decision 4), last. The issue is
            //    out by now: a problem here is logged, never thrown.
            $notified = 0;
            if (!empty($vars['sendIssueNotification']) && $journal->getData('publishingMode') != PUBLISHING_MODE_NONE) {
                $notified = $this->_notifyUsers($request, $contextId, $issue);
                $this->_log(sprintf('Issue %d: notification created for %d users.', $issue->getId(), $notified), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            } else {
                $this->_log(sprintf('Issue %d: reader notification not sent (removed by a plugin or publishing mode off).', $issue->getId()), SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            }

            return ['published' => true, 'articles' => $articles, 'notified' => $notified];
        } finally {
            if ($hadVar) {
                $vars['sendIssueNotification'] = $oldVar;
            } else {
                unset($vars['sendIssueNotification']);
            }
            $this->_plugin->setPublishing(false);
            ScheduledPublishContext::leave($saved);
        }
    }

    /**
     * Assign public identifiers the way the publish dialog does when its checkboxes are
     * left in their default (ticked) state: only enabled plugins, only for object types
     * they are enabled for, only when the identifier can be assigned and none is stored.
     */
    private function _assignPubIds($issue, $contextId)
    {
        $pubIdPlugins = PluginRegistry::getPlugins('pubIds');
        if (empty($pubIdPlugins)) {
            $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $contextId);
        }
        if (empty($pubIdPlugins)) {
            return;
        }
        import('controllers.grid.pubIds.form.AssignPublicIdentifiersForm');
        $form = new AssignPublicIdentifiersForm('controllers/grid/pubIds/form/assignPublicIdentifiersForm.tpl', $issue, true, '');
        $any = false;
        foreach ($pubIdPlugins as $pubIdPlugin) {
            if (!$pubIdPlugin->getEnabled($contextId)) {
                continue;
            }
            $objectType = $pubIdPlugin->getPubObjectType($issue);
            if (!$pubIdPlugin->isObjectTypeEnabled($objectType, $contextId)) {
                continue;
            }
            if ($issue->getStoredPubId($pubIdPlugin->getPubIdType())) {
                continue;
            }
            if (!$pubIdPlugin->canBeAssigned($issue) || !$pubIdPlugin->getPubId($issue)) {
                continue;
            }
            $form->setData($pubIdPlugin->getAssignFormFieldName(), 1);
            $any = true;
        }
        if ($any) {
            $form->execute();
        }
    }

    /**
     * Publish the issue's scheduled articles. An article with no date gets the issue's
     * date; an article whose date is still in the future stays scheduled and is published
     * by the article task when its date arrives.
     * @return array ['published' => int, 'waiting' => int]
     */
    private function _publishArticles($issue, $contextId)
    {
        $published = $waiting = 0;
        $started = microtime(true);
        $issueDate = $issue->getDatePublished();
        $submissions = Services::get('submission')->getMany([
            'contextId' => $contextId,
            'issueIds' => $issue->getId(),
            'status' => STATUS_SCHEDULED,
        ]);
        foreach ($submissions as $submission) { /** @var Submission $submission */
            foreach ($submission->getData('publications') as $publication) { /** @var Publication $publication */
                if ($publication->getData('status') !== STATUS_SCHEDULED || (int) $publication->getData('issueId') !== (int) $issue->getId()) {
                    continue;
                }
                if (!$publication->getData('datePublished')) {
                    $publication->setData('datePublished', $issueDate);
                }
                try {
                    $result = Services::get('publication')->publish($publication);
                } catch (Throwable $e) {
                    // The issue is out; the article stays scheduled and the article task
                    // publishes it on its next run.
                    $this->_log(sprintf('Issue %d, submission %d: publishing failed, left for the article task: %s', $issue->getId(), $submission->getId(), $e->getMessage()), SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                    $waiting++;
                    continue;
                }
                if ($result->getData('status') === STATUS_PUBLISHED) {
                    $published++;
                } else {
                    $waiting++;
                }
            }
        }
        return [
            'published' => $published,
            'waiting' => $waiting,
            'timing' => sprintf('articles took %.1f s', microtime(true) - $started),
        ];
    }

    /**
     * The core's reader notification: every enabled user of the journal, as in
     * IssueGridHandler::publishIssue(). OJS 3.3 sends the emails synchronously.
     * @return int Users notified
     */
    private function _notifyUsers($request, $contextId, $issue)
    {
        import('classes.notification.NotificationManager');
        $notificationManager = new NotificationManager();
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO'); /* @var $userGroupDao UserGroupDAO */
        $allUsers = $userGroupDao->getUsersByContextId($contextId);
        $count = 0;
        $failed = 0;
        while ($user = $allUsers->next()) {
            if ($user->getDisabled()) {
                continue;
            }
            try {
                $notificationManager->createNotification(
                    $request, $user->getId(), NOTIFICATION_TYPE_PUBLISHED_ISSUE,
                    $contextId, ASSOC_TYPE_ISSUE, $issue->getId()
                );
                $count++;
            } catch (Throwable $e) {
                if ($failed++ === 0) {
                    $this->_log(sprintf('Issue %d: notification failed for user %d: %s', $issue->getId(), $user->getId(), $e->getMessage()), SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                }
            }
        }
        if ($failed) {
            $this->_log(sprintf('Issue %d: notification failed for %d user(s).', $issue->getId(), $failed), SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
        }
        return $count;
    }

    private function _log($message, $type)
    {
        call_user_func($this->_log, $message, $type);
    }
}

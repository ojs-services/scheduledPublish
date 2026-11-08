<?php

/**
 * @file classes/ScheduledPublishIssuePublisher.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishIssuePublisher
 * @brief Publishes a scheduled issue exactly as the "Publish Issue" action does.
 *
 * Mirrors IssueGridHandler::publishIssue() of OJS 3.4 / 3.5 step by step: public identifiers
 * and the issue DOI, issue flags, delayed open access, the IssueGridHandler::publishIssue hook,
 * current issue, DOI update, the scheduled articles, and the reader notification under OJS's
 * own rule (queued jobs). Differences: the publication date is the scheduled time, not the
 * moment the task ran; the request carries the issue's journal; the notification's sender is
 * the user who scheduled the issue; the scheduling data is removed before the notification.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\controllers\grid\pubIds\form\AssignPublicIdentifiersForm;
use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\jobs\notifications\IssuePublishedNotifyUsers;
use APP\journal\Journal;
use APP\notification\Notification;
use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use APP\submission\Submission;
use Exception;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use PKP\core\Core;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\mail\Mailer;
use PKP\notification\NotificationSubscriptionSettingsDAO;
use PKP\observers\events\MetadataChanged;
use PKP\plugins\Hook;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\ScheduledTaskHelper;
use Throwable;

class ScheduledPublishIssuePublisher
{
    private ScheduledPublishPlugin $plugin;
    private ScheduledPublishSchedule $schedule;
    /** @var callable function (string $message, string $type) */
    private $log;

    public function __construct(ScheduledPublishPlugin $plugin, ScheduledPublishSchedule $schedule, callable $log)
    {
        $this->plugin = $plugin;
        $this->schedule = $schedule;
        $this->log = $log;
    }

    /**
     * @param $scheduledAt string Y-m-d H:i:s, becomes the issue's publication date
     * @return array Summary for the task log
     */
    public function publish(int $issueId, string $scheduledAt): array
    {
        $issue = Repo::issue()->get($issueId);
        if (!$issue) {
            throw new Exception('Issue ' . $issueId . ' no longer exists.');
        }
        if ($issue->getPublished()) {
            // Published by hand in the meantime: nothing to do but drop the schedule.
            $this->schedule->clear($issue);
            return ['skipped' => 'alreadyPublished'];
        }
        $scheduledBy = $this->schedule->getBy($issue);

        $journal = Application::getContextDAO()->getById($issue->getJournalId());
        if (!$journal) {
            throw new Exception('Journal of issue ' . $issueId . ' not found.');
        }
        $contextId = (int) $journal->getId();

        $saved = ScheduledPublishContext::enter($journal);
        $this->plugin->setPublishing(true);
        $request = Application::get()->getRequest();
        $vars = & $request->getUserVars();
        $hadVar = array_key_exists('sendIssueNotification', $vars);
        $oldVar = $hadVar ? $vars['sendIssueNotification'] : null;
        try {
            // 1. Public identifiers, as the publish dialog assigns them with its default ticks,
            //    and the issue DOI as the core creates it on publish.
            $this->assignPubIds($issue, $contextId);
            Repo::issue()->createDoi($issue);

            // 2. Issue flags. The publication date is the scheduled time (decision 9).
            $issue->setPublished(1);
            $issue->setDatePublished($scheduledAt);

            // 3. Delayed open access, as in the core.
            if ($journal->getData('publishingMode') == Journal::PUBLISHING_MODE_SUBSCRIPTION && ($delayDuration = $journal->getData('delayedOpenAccessDuration'))) {
                $delayYears = (int) floor($delayDuration / 12);
                $delayMonths = (int) fmod($delayDuration, 12);
                $curYear = date('Y');
                $curMonth = date('n');
                $curDay = date('j');
                $delayOpenAccessYear = $curYear + $delayYears + (int) floor(($curMonth + $delayMonths) / 12);
                $delayOpenAccessMonth = (int) fmod($curMonth + $delayMonths, 12);
                $issue->setAccessStatus(Issue::ISSUE_ACCESS_SUBSCRIPTION);
                $issue->setOpenAccessDate(date('Y-m-d H:i:s', mktime(0, 0, 0, $delayOpenAccessMonth, $curDay, $delayOpenAccessYear)));
            }

            // 4. The dialog's notification tick in its default state, so plugins that read or
            //    remove it (Issue Contributor Notify) see what they see on a manual publish; then
            //    the same hook the grid handler calls. Our scheduling data must not be written
            //    back by the core's save below.
            $vars['sendIssueNotification'] = 1;
            $this->schedule->detach($issue);
            Hook::call('IssueGridHandler::publishIssue', [&$issue]);
            $sendNotification = !empty($vars['sendIssueNotification']);

            // 5. Current issue and save. On a site upgraded from OJS 3.3 a stale
            //    journal_settings row "currentIssueId" can survive and override the column
            //    when the journal is loaded; keep it in step so the new issue really shows.
            Repo::issue()->updateCurrent($contextId, $issue);
            DB::table('journal_settings')->where('journal_id', $contextId)->where('setting_name', 'currentIssueId')->update(['setting_value' => (string) $issue->getId()]);

            // 6. DOI update, as in the core.
            Repo::doi()->issueUpdated($issue);

            // 7. Scheduled articles of this issue (decisions 2 and 3).
            $articles = $this->publishArticles($issue, $contextId);

            // 8. The schedule is done.
            $this->schedule->clear($issue);
            $this->logLine(sprintf(
                'Issue %d published (scheduled %s, run %s); articles published: %d, waiting for their own date: %d; %s.',
                $issue->getId(), $scheduledAt, Core::getCurrentDate(), $articles['published'], $articles['waiting'], $articles['timing']
            ), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);

            // 9. Reader notification under OJS's own rule (decision 4): the dialog's default
            //    is on unless a plugin removed it in the hook. Queued jobs, as in the core; the
            //    sender is the user who scheduled.
            $notified = 0;
            if ($sendNotification && $journal->getData('publishingMode') != Journal::PUBLISHING_MODE_NONE) {
                try {
                    $notified = $this->notifyUsers($contextId, $issue, $scheduledBy);
                    $this->logLine(sprintf('Issue %d: notification jobs queued for %d users.', $issue->getId(), $notified), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
                } catch (Throwable $e) {
                    $this->logLine(sprintf('Issue %d: notification failed: %s', $issue->getId(), $e->getMessage()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                }
            } else {
                $this->logLine(sprintf('Issue %d: reader notification not sent (removed by a plugin or publishing mode off).', $issue->getId()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_NOTICE);
            }

            return ['published' => true, 'articles' => $articles, 'notified' => $notified];
        } finally {
            if ($hadVar) {
                $vars['sendIssueNotification'] = $oldVar;
            } else {
                unset($vars['sendIssueNotification']);
            }
            $this->plugin->setPublishing(false);
            ScheduledPublishContext::leave($saved);
        }
    }

    /**
     * Assign public identifiers (URN and other pub-id plugins; the DOI is handled by the core)
     * the way the publish dialog does with its checkboxes in their default, ticked state.
     */
    private function assignPubIds(Issue $issue, int $contextId): void
    {
        // Always the issue's own journal: the request may belong to another journal.
        $pubIdPlugins = PluginRegistry::loadCategory('pubIds', true, $contextId);
        if (empty($pubIdPlugins)) {
            return;
        }
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
     * Publish the issue's scheduled articles. An article with no date gets the issue's date;
     * an article whose date is still in the future stays scheduled (the plugin's own
     * Publication::publish::before hook) and is published by the article task on its date.
     * @return array ['published' => int, 'waiting' => int, 'timing' => string]
     */
    private function publishArticles(Issue $issue, int $contextId): array
    {
        $published = $waiting = 0;
        $started = microtime(true);
        $issueDate = $issue->getDatePublished();
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([$contextId])
            ->filterByIssueIds([$issue->getId()])
            ->filterByStatus([Submission::STATUS_SCHEDULED, Submission::STATUS_PUBLISHED])
            ->getMany();
        foreach ($submissions as $submission) { /** @var Submission $submission */
            foreach ($submission->getData('publications') as $publication) {
                if ($publication->getData('status') !== Submission::STATUS_SCHEDULED || (int) $publication->getData('issueId') !== (int) $issue->getId()) {
                    continue;
                }
                if (!$publication->getData('datePublished')) {
                    $publication->setData('datePublished', $issueDate);
                }
                try {
                    Repo::publication()->publish($publication);
                    if ($this->plugin->isOjs35()) {
                        event(new MetadataChanged($submission));
                    }
                } catch (Throwable $e) {
                    // The issue is out; the article stays scheduled and the article task
                    // publishes it on its next run.
                    $this->logLine(sprintf('Issue %d, submission %d: publishing failed, left for the article task: %s', $issue->getId(), $submission->getId(), $e->getMessage()), ScheduledTaskHelper::SCHEDULED_TASK_MESSAGE_TYPE_WARNING);
                    $waiting++;
                    continue;
                }
                $saved = Repo::publication()->get($publication->getId());
                if ($saved && $saved->getData('status') === Submission::STATUS_PUBLISHED) {
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
     * The core's reader notification: subscribed users get an in-app notification and, if
     * they also subscribed to the email, an email; both as queued jobs.
     * @return int Users addressed
     */
    private function notifyUsers(int $contextId, Issue $issue, ?int $senderId): int
    {
        /** @var NotificationSubscriptionSettingsDAO $notificationSubscriptionSettingsDao */
        $notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');

        $userIdsToNotify = $notificationSubscriptionSettingsDao->getSubscribedUserIds(
            [NotificationSubscriptionSettingsDAO::BLOCKED_NOTIFICATION_KEY],
            [Notification::NOTIFICATION_TYPE_PUBLISHED_ISSUE],
            [$contextId]
        );
        $userIdsToMail = $notificationSubscriptionSettingsDao->getSubscribedUserIds(
            [
                NotificationSubscriptionSettingsDAO::BLOCKED_NOTIFICATION_KEY,
                NotificationSubscriptionSettingsDAO::BLOCKED_EMAIL_NOTIFICATION_KEY
            ],
            [Notification::NOTIFICATION_TYPE_PUBLISHED_ISSUE],
            [$contextId]
        );
        $userIdsToNotifyAndMail = $userIdsToNotify->intersect($userIdsToMail);
        $userIdsToNotify = $userIdsToNotify->diff($userIdsToMail);

        $sender = $senderId ? Repo::user()->get($senderId) : null;
        $locale = Locale::getLocale();
        $jobs = [];
        foreach ($userIdsToNotify->chunk(Notification::NOTIFICATION_CHUNK_SIZE_LIMIT) as $notifyUserIds) {
            $jobs[] = new IssuePublishedNotifyUsers($notifyUserIds, $contextId, $issue, $locale);
        }
        foreach ($userIdsToNotifyAndMail->chunk(Mailer::BULK_EMAIL_SIZE_LIMIT) as $mailUserIds) {
            $jobs[] = new IssuePublishedNotifyUsers($mailUserIds, $contextId, $issue, $locale, $sender);
        }
        if ($jobs) {
            Bus::batch($jobs)->dispatch();
        }
        return $userIdsToNotify->count() + $userIdsToNotifyAndMail->count();
    }

    private function logLine(string $message, string $type): void
    {
        call_user_func($this->log, $message, $type);
    }
}

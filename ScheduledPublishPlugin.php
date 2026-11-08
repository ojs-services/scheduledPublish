<?php

/**
 * @file plugins/generic/scheduledPublish/ScheduledPublishPlugin.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishPlugin
 * @brief Publishes issues and articles automatically when their scheduled date and time arrive.
 *
 * Site-wide plugin for OJS 3.4 and 3.5. It adds a date and time to the issue form of an
 * unpublished issue, a "Scheduled" column to the Future Issues grid, keeps an article with a
 * future date scheduled until that date, and runs two scheduled tasks through OJS's own task
 * runner (acron on 3.4, the built-in scheduler on 3.5): one publishes due issues exactly as
 * the "Publish Issue" action does, the other publishes articles whose date has arrived, in
 * published issues only.
 */

namespace APP\plugins\generic\scheduledPublish;

// OJS 3.4 has no HasTaskScheduler interface; the shim declares it there so that this one
// class serves both lines. The class below is bound after this line has run.
require_once __DIR__ . '/classes/HasTaskSchedulerShim.php';

use APP\core\Application;
use APP\facades\Repo;
use APP\issue\Issue;
use APP\notification\NotificationManager;
use APP\plugins\generic\scheduledPublish\classes\ScheduledPublishGridFeature;
use APP\plugins\generic\scheduledPublish\classes\ScheduledPublishIssueTask;
use APP\plugins\generic\scheduledPublish\classes\ScheduledPublishSchedule;
use APP\plugins\generic\scheduledPublish\classes\ScheduledPublishSettingsForm;
use APP\plugins\generic\scheduledPublish\classes\ScheduledPublishSubmissionsTask;
use APP\submission\Submission;
use Carbon\Carbon;
use PKP\config\Config;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\facades\Locale;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\plugins\interfaces\HasTaskScheduler;
use PKP\plugins\PluginRegistry;
use PKP\scheduledTask\PKPScheduler;

class ScheduledPublishPlugin extends GenericPlugin implements HasTaskScheduler
{
    /** Must match version.xml; used to refresh the 3.4 task runner's cached task list once per version. */
    public const VERSION = '2.0.0.0';

    /** Tolerance window: hours after the scheduled time within which a late run still publishes. */
    public const TOLERANCE_DEFAULT = 24;
    public const TOLERANCE_MIN = 1;
    public const TOLERANCE_MAX = 168;

    /** Issue Contributor Notify (OJS 3.3 plugin; kept for a future 3.4+ release). */
    public const ICN_PRODUCT = 'issueContributorNotify';
    public const ICN_PLUGIN_NAME = 'issuecontributornotifyplugin';
    public const ICN_MIN_VERSION = '1.5.3.0';

    private static ?ScheduledPublishPlugin $instance = null;

    /** Hooks are registered once per request, however often OJS instantiates the plugin. */
    private static bool $hooksRegistered = false;

    /** True while the publisher runs, so the publishIssue hook leaves the schedule to it. */
    private bool $publishing = false;

    private ?ScheduledPublishSchedule $schedule = null;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        self::$instance = $this;
        $this->addLocaleData();

        if (self::$hooksRegistered) {
            return true;
        }
        self::$hooksRegistered = true;

        // OJS 3.4: the acron task runner asks every plugin for its tasks; answer even when
        // disabled so the task list is complete the moment the plugin is enabled. OJS 3.5
        // has no acron and rejects its hook name, so the hook is added on 3.4 only.
        if (!$this->isOjs35()) {
            Hook::add('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
        }

        if (!Config::getVar('general', 'installed') || Application::isUnderMaintenance() || !$this->getEnabled()) {
            return true;
        }

        $this->refreshTaskRunnerOnce();

        // Scheduling data travels with the issue (issue schema, issue_settings).
        Hook::add('Schema::get::issue', [$this, 'callbackIssueSchema']);

        // Issue form: fields, reading, validation, saving.
        Hook::add('Templates::Editor::Issues::IssueData::AdditionalMetadata', [$this, 'callbackIssueFormFields']);
        Hook::add('issueform::readuservars', [$this, 'callbackIssueFormReadUserVars']);
        Hook::add('issueform::validate', [$this, 'callbackIssueFormValidate']);
        Hook::add('issueform::execute', [$this, 'callbackIssueFormExecute']);

        // A manual publish ends the schedule.
        Hook::add('IssueGridHandler::publishIssue', [$this, 'callbackIssuePublished']);

        // "Scheduled" column in the Future Issues grid.
        Hook::add('futureissuegridhandler::initfeatures', [$this, 'callbackGridFeatures']);

        // An article with a future date stays scheduled until that date (decision 2). The
        // core of 3.4/3.5 would publish it at once.
        Hook::add('Publication::publish::before', [$this, 'callbackPublicationPublishBefore']);

        return true;
    }

    /** @return ScheduledPublishPlugin|null The instance OJS registered in this request. */
    public static function getInstance(): ?ScheduledPublishPlugin
    {
        if (self::$instance) {
            return self::$instance;
        }
        $plugin = PluginRegistry::getPlugin('generic', 'scheduledpublishplugin');
        if (!$plugin) {
            PluginRegistry::loadCategory('generic');
            $plugin = PluginRegistry::getPlugin('generic', 'scheduledpublishplugin');
        }
        return $plugin ?: self::$instance;
    }

    /** @copydoc Plugin::isSitePlugin() */
    public function isSitePlugin()
    {
        return true;
    }

    /** @copydoc Plugin::getName() */
    public function getName()
    {
        return 'scheduledpublishplugin';
    }

    /** @copydoc Plugin::getDisplayName() */
    public function getDisplayName()
    {
        return __('plugins.generic.scheduledPublish.displayName');
    }

    /** @copydoc Plugin::getDescription() */
    public function getDescription()
    {
        return __('plugins.generic.scheduledPublish.description');
    }

    /** @copydoc Plugin::getActions() */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);
        if (!$this->getEnabled()) {
            return $actions;
        }
        $router = $request->getRouter();
        array_unshift($actions, new LinkAction(
            'settings',
            new AjaxModal(
                $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        ));
        return $actions;
    }

    /** @copydoc Plugin::manage() */
    public function manage($args, $request)
    {
        if ($request->getUserVar('verb') === 'settings') {
            $form = new ScheduledPublishSettingsForm($this);
            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager = new NotificationManager();
                    $notificationManager->createTrivialNotification($request->getUser()->getId());
                    return new JSONMessage(true);
                }
            } else {
                $form->initData();
            }
            return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }

    /**
     * OJS 3.5: register the tasks with the built-in scheduler.
     * @copydoc HasTaskScheduler::registerSchedules()
     */
    public function registerSchedules(PKPScheduler $scheduler): void
    {
        if (!$this->getEnabled()) {
            return;
        }
        $scheduler
            ->addSchedule(new ScheduledPublishIssueTask())
            ->everyMinute()
            ->name(ScheduledPublishIssueTask::class)
            ->withoutOverlapping();
        $scheduler
            ->addSchedule(new ScheduledPublishSubmissionsTask())
            ->everyMinute()
            ->name(ScheduledPublishSubmissionsTask::class)
            ->withoutOverlapping();
    }

    //
    // Settings and helpers
    //

    /** @return bool True on OJS 3.5 (built-in scheduler), false on 3.4 (acron). */
    public function isOjs35(): bool
    {
        return class_exists('\PKP\scheduledTask\PKPScheduler');
    }

    public function getToleranceHours(): int
    {
        $hours = (int) $this->getSetting(Application::CONTEXT_ID_NONE, 'toleranceHours');
        if ($hours < self::TOLERANCE_MIN || $hours > self::TOLERANCE_MAX) {
            return self::TOLERANCE_DEFAULT;
        }
        return $hours;
    }

    public function getSchedule(): ScheduledPublishSchedule
    {
        if (!$this->schedule) {
            $this->schedule = new ScheduledPublishSchedule();
        }
        return $this->schedule;
    }

    public function setPublishing(bool $publishing): void
    {
        $this->publishing = $publishing;
    }

    /** @return string The time zone OJS uses for dates (config.inc.php time_zone, else PHP's). */
    public function getTimeZoneLabel(): string
    {
        return date_default_timezone_get();
    }

    /**
     * Format a stored date/time for display, with the journal's own formats.
     * @param $dateString string Y-m-d H:i:s
     */
    public function formatDateTime(string $dateString, $context = null): string
    {
        $timestamp = strtotime($dateString);
        if (!$timestamp) {
            return $dateString;
        }
        $format = $context ? $context->getLocalizedDateTimeFormatShort() : 'Y-m-d H:i';
        return (new Carbon($dateString))->locale(Locale::getLocale())->translatedFormat($format);
    }

    /**
     * How Issue Contributor Notify will behave on a scheduled publish in this journal.
     * @return string 'none' (not installed or not enabled here), 'auto', 'manual'
     */
    public function getIcnStatus(int $contextId): string
    {
        /** @var \PKP\site\VersionDAO $versionDao */
        $versionDao = DAORegistry::getDAO('VersionDAO');
        $version = $versionDao->getCurrentVersion('plugins.generic', self::ICN_PRODUCT);
        if (!$version) {
            return 'none';
        }
        /** @var \PKP\plugins\PluginSettingsDAO $pluginSettingsDao */
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
        if (!$pluginSettingsDao->getSetting($contextId, self::ICN_PLUGIN_NAME, 'enabled')) {
            return 'none';
        }
        return version_compare($version->getVersionString(), self::ICN_MIN_VERSION, '>=') ? 'auto' : 'manual';
    }

    /** Short status text for the grid column. */
    public function getIssueStatusLabel(Issue $issue): string
    {
        $schedule = $this->getSchedule();
        $state = $schedule->getState($issue);
        if (!$state || $issue->getPublished()) {
            return '';
        }
        $request = Application::get()->getRequest();
        $at = $this->formatDateTime((string) $schedule->getAt($issue), $request->getContext());
        switch ($state) {
            case ScheduledPublishSchedule::STATE_SCHEDULED:
                return __('plugins.generic.scheduledPublish.grid.scheduled', ['at' => $at]);
            case ScheduledPublishSchedule::STATE_PROCESSING:
                return __('plugins.generic.scheduledPublish.grid.processing', ['at' => $at]);
            case ScheduledPublishSchedule::STATE_EXPIRED:
                return __('plugins.generic.scheduledPublish.grid.expired', ['at' => $at]);
        }
        return '';
    }

    /**
     * Articles of the issue that are scheduled with a date later than now; they will not
     * come out with the issue but on their own date.
     */
    public function countFutureDatedArticles(Issue $issue): int
    {
        $count = 0;
        $now = time();
        $submissions = Repo::submission()->getCollector()
            ->filterByContextIds([(int) $issue->getJournalId()])
            ->filterByIssueIds([(int) $issue->getId()])
            ->filterByStatus([Submission::STATUS_SCHEDULED])
            ->getMany();
        foreach ($submissions as $submission) {
            foreach ($submission->getData('publications') as $publication) {
                if ($publication->getData('status') !== Submission::STATUS_SCHEDULED || (int) $publication->getData('issueId') !== (int) $issue->getId()) {
                    continue;
                }
                $date = $publication->getData('datePublished');
                if ($date && strtotime($date) > $now) {
                    $count++;
                }
            }
        }
        return $count;
    }

    //
    // Hook callbacks
    //

    /**
     * OJS 3.4: add this plugin's task file to the acron task runner's list.
     * @see AcronPlugin::_parseCrontab()
     */
    public function callbackParseCronTab($hookName, $args)
    {
        if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
            $taskFilesPath = & $args[0];
            $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        }
        return Hook::CONTINUE;
    }

    /** Add the scheduling fields to the issue schema, so the issue DAO saves and loads them. */
    public function callbackIssueSchema($hookName, $args)
    {
        $schema = & $args[0];
        foreach (ScheduledPublishSchedule::schemaProperties() as $name => $definition) {
            if (!isset($schema->properties->{$name})) {
                $schema->properties->{$name} = (object) $definition;
            }
        }
        return Hook::CONTINUE;
    }

    /** Append the scheduling fields to the issue form (template hook). */
    public function callbackIssueFormFields($hookName, $args)
    {
        $smarty = $args[1];
        $output = & $args[2];

        $issue = $smarty->getTemplateVars('issue');
        if (!($issue instanceof Issue) || $issue->getPublished()) {
            return Hook::CONTINUE;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || (int) $context->getId() !== (int) $issue->getJournalId()) {
            return Hook::CONTINUE;
        }

        $vars = $this->getIssueFormVars($issue, $context);
        // After a failed validation the form is redrawn with what the user typed.
        foreach (['scheduledPublishDate', 'scheduledPublishTime'] as $field) {
            $typed = $smarty->getTemplateVars($field);
            if (is_string($typed) && $typed !== '') {
                $vars[$field] = $typed;
            }
        }
        $smarty->assign($vars);
        $output .= $smarty->fetch($this->getTemplateResource('issueFormFields.tpl'));
        return Hook::CONTINUE;
    }

    /** Template variables for issueFormFields.tpl. */
    public function getIssueFormVars(Issue $issue, $context): array
    {
        $schedule = $this->getSchedule();
        $state = $schedule->getState($issue);
        $at = $schedule->getAt($issue);
        $timestamp = $at ? strtotime($at) : false;

        $byName = '';
        if ($userId = $schedule->getBy($issue)) {
            $user = Repo::user()->get($userId, true);
            $byName = $user ? $user->getFullName() : '';
        }

        $currentIssueLabel = '';
        $current = Repo::issue()->getCurrent((int) $context->getId());
        if ($current && (int) $current->getId() !== (int) $issue->getId()) {
            $currentIssueLabel = $current->getIssueIdentification();
        }

        return [
            'scheduledPublishState' => $state ?: '',
            'scheduledPublishAtFormatted' => $at ? $this->formatDateTime($at, $context) : '',
            'scheduledPublishDate' => $timestamp && $state !== ScheduledPublishSchedule::STATE_EXPIRED ? date('Y-m-d', $timestamp) : '',
            'scheduledPublishTime' => $timestamp && $state !== ScheduledPublishSchedule::STATE_EXPIRED ? date('H:i', $timestamp) : '',
            'scheduledPublishByName' => $byName,
            'scheduledPublishTimeZone' => $this->getTimeZoneLabel(),
            'scheduledPublishToleranceHours' => $this->getToleranceHours(),
            'scheduledPublishFutureArticles' => $this->countFutureDatedArticles($issue),
            'scheduledPublishCurrentIssue' => $currentIssueLabel,
            'scheduledPublishIcn' => $this->getIcnStatus((int) $context->getId()),
        ];
    }

    /** Read the scheduling fields with the issue form. */
    public function callbackIssueFormReadUserVars($hookName, $args)
    {
        $vars = & $args[1];
        $vars[] = 'scheduledPublishDate';
        $vars[] = 'scheduledPublishTime';
        return Hook::CONTINUE;
    }

    /** Validate the scheduling fields: a valid date and time, in the future. */
    public function callbackIssueFormValidate($hookName, $args)
    {
        $form = $args[0];
        $issue = $form->issue ?? null;
        if (!($issue instanceof Issue) || $issue->getPublished()) {
            return Hook::CONTINUE;
        }
        $date = trim((string) $form->getData('scheduledPublishDate'));
        $time = trim((string) $form->getData('scheduledPublishTime'));
        if ($date === '' && $time === '') {
            return Hook::CONTINUE;
        }
        $at = ScheduledPublishSchedule::parseInput($date, $time);
        if ($at === null) {
            $form->addError('scheduledPublishDate', __('plugins.generic.scheduledPublish.form.invalid'));
            $form->addErrorField('scheduledPublishDate');
            return Hook::CONTINUE;
        }
        // Keep an existing schedule as it is even if its time has passed meanwhile; only a
        // new or changed time has to be in the future.
        $existing = $this->getSchedule()->getAt($issue);
        if ($existing !== $at && strtotime($at) <= time()) {
            $form->addError('scheduledPublishDate', __('plugins.generic.scheduledPublish.form.past'));
            $form->addErrorField('scheduledPublishDate');
        }
        return Hook::CONTINUE;
    }

    /**
     * Save the scheduling fields with the issue. The hook fires from Form::execute(), just
     * before IssueForm saves the issue with Repo::issue()->edit().
     */
    public function callbackIssueFormExecute($hookName, $args)
    {
        $form = $args[0];
        $issue = $form->issue ?? null;
        if (!($issue instanceof Issue) || !$issue->getId() || $issue->getPublished()) {
            return Hook::CONTINUE;
        }
        $schedule = $this->getSchedule();
        $date = trim((string) $form->getData('scheduledPublishDate'));
        $time = trim((string) $form->getData('scheduledPublishTime'));

        if ($date === '' && $time === '') {
            if ($schedule->getState($issue)) {
                $schedule->clear($issue);
            }
            return Hook::CONTINUE;
        }
        $at = ScheduledPublishSchedule::parseInput($date, $time);
        if ($at === null) {
            return Hook::CONTINUE; // refused by validate()
        }
        if ($schedule->getAt($issue) === $at && $schedule->getState($issue) === ScheduledPublishSchedule::STATE_SCHEDULED) {
            return Hook::CONTINUE; // unchanged
        }
        $user = Application::get()->getRequest()->getUser();
        $schedule->set($issue, $at, $user ? (int) $user->getId() : null);
        return Hook::CONTINUE;
    }

    /** A manual publish (or the publisher itself) ends the schedule. */
    public function callbackIssuePublished($hookName, $args)
    {
        if ($this->publishing) {
            return Hook::CONTINUE; // the publisher clears the schedule itself, after the issue is saved
        }
        $issue = $args[0];
        if ($issue instanceof Issue && $this->getSchedule()->getState($issue)) {
            $this->getSchedule()->clear($issue);
        }
        return Hook::CONTINUE;
    }

    /** Add the "Scheduled" column feature to the Future Issues grid. */
    public function callbackGridFeatures($hookName, $args)
    {
        $features = & $args[3];
        $features[] = new ScheduledPublishGridFeature($this);
        return Hook::CONTINUE;
    }

    /**
     * An article whose publication date is still in the future is not published but kept
     * scheduled; the article task publishes it when the date arrives. Same behaviour as
     * OJS 3.3 and as this plugin on that line.
     */
    public function callbackPublicationPublishBefore($hookName, $args)
    {
        $newPublication = $args[0];
        if ($newPublication->getData('status') !== Submission::STATUS_PUBLISHED) {
            return Hook::CONTINUE;
        }
        $datePublished = $newPublication->getData('datePublished');
        if ($datePublished && strtotime($datePublished) > time()) {
            $newPublication->setData('status', Submission::STATUS_SCHEDULED);
        }
        return Hook::CONTINUE;
    }

    //
    // Private
    //

    /**
     * OJS 3.4: the acron task runner caches the task list; when this plugin is enabled from
     * the plugin grid the runner refreshes it, but not when it is enabled any other way, and
     * not when a new version brings new tasks. Clear the cache once per plugin version.
     * Harmless on 3.5 (no acron settings).
     */
    private function refreshTaskRunnerOnce(): void
    {
        if ($this->getSetting(Application::CONTEXT_ID_NONE, 'cronTabFlushedFor') === self::VERSION) {
            return;
        }
        $this->updateSetting(Application::CONTEXT_ID_NONE, 'cronTabFlushedFor', self::VERSION, 'string');
        /** @var \PKP\plugins\PluginSettingsDAO $pluginSettingsDao */
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO');
        $pluginSettingsDao->deleteSetting(Application::CONTEXT_ID_NONE, 'acronplugin', 'crontab');
    }
}

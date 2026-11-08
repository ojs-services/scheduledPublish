<?php

/**
 * @file plugins/generic/scheduledPublish/ScheduledPublishPlugin.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishPlugin
 * @brief Publishes issues and articles automatically when their scheduled date and time arrive.
 *
 * Site-wide plugin for OJS 3.3. It adds a date and time to the issue form of an unpublished
 * issue, a "Scheduled" column to the Future Issues grid, and two scheduled tasks that run
 * through OJS's own task runner (the acron plugin): one publishes due issues exactly as the
 * "Publish Issue" action does, the other publishes articles whose date has arrived, in
 * published issues only.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class ScheduledPublishPlugin extends GenericPlugin
{
    /** Must match version.xml; used to refresh the task runner's cached task list once per version. */
    const VERSION = '1.2.0.1';

    /** Tolerance window: hours after the scheduled time within which a late run still publishes. */
    const TOLERANCE_DEFAULT = 24;
    const TOLERANCE_MIN = 1;
    const TOLERANCE_MAX = 168;

    /** Issue Contributor Notify: first version that applies its journal defaults without its dialog. */
    const ICN_PRODUCT = 'issueContributorNotify';
    const ICN_PLUGIN_NAME = 'issuecontributornotifyplugin';
    const ICN_MIN_VERSION = '1.5.4.0';

    /** @var ScheduledPublishPlugin|null */
    private static $_instance = null;

    /** @var bool Hooks are registered once per request, however often OJS instantiates the plugin. */
    private static $_hooksRegistered = false;

    /** @var bool True while the publisher runs, so the publishIssue hook leaves the schedule to it (shared by all instances). */
    private static $_publishing = false;

    /** The task runner is considered stalled when no task ran for this long. */
    const RUNNER_STALE_SECONDS = 3600;

    /** @var ScheduledPublishSchedule|null */
    private $_schedule = null;

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!$success) {
            return false;
        }
        self::$_instance = $this;
        $this->addLocaleData();

        if (self::$_hooksRegistered) {
            return true;
        }
        self::$_hooksRegistered = true;

        // The task runner asks every plugin for its tasks; answer even when disabled so
        // the task list is complete the moment the plugin is enabled.
        HookRegistry::register('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);

        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE') || !$this->getEnabled()) {
            return true;
        }

        $this->_refreshTaskRunnerOnce();

        // Scheduling data travels with the issue (issue_settings).
        HookRegistry::register('issuedao::getAdditionalFieldNames', [$this, 'callbackIssueFieldNames']);

        // Issue form: fields, reading, validation, saving.
        HookRegistry::register('Templates::Editor::Issues::IssueData::AdditionalMetadata', [$this, 'callbackIssueFormFields']);
        HookRegistry::register('issueform::readuservars', [$this, 'callbackIssueFormReadUserVars']);
        HookRegistry::register('issueform::validate', [$this, 'callbackIssueFormValidate']);
        HookRegistry::register('issueform::execute', [$this, 'callbackIssueFormExecute']);

        // A manual publish ends the schedule.
        HookRegistry::register('IssueGridHandler::publishIssue', [$this, 'callbackIssuePublished']);

        // "Scheduled" column in the Future Issues grid.
        HookRegistry::register('futureissuegridhandler::initfeatures', [$this, 'callbackGridFeatures']);

        return true;
    }

    /** @return ScheduledPublishPlugin|null The instance OJS registered in this request. */
    public static function getInstance()
    {
        if (self::$_instance) {
            return self::$_instance;
        }
        $plugin = PluginRegistry::getPlugin('generic', 'scheduledpublishplugin');
        if (!$plugin) {
            PluginRegistry::loadCategory('generic');
            $plugin = PluginRegistry::getPlugin('generic', 'scheduledpublishplugin');
        }
        return $plugin ?: self::$_instance;
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
        import('lib.pkp.classes.linkAction.request.AjaxModal');
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
            $this->import('classes.ScheduledPublishSettingsForm');
            $form = new ScheduledPublishSettingsForm($this);
            if ($request->getUserVar('save')) {
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    import('classes.notification.NotificationManager');
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

    //
    // Settings and helpers
    //

    /** @return int Hours */
    public function getToleranceHours()
    {
        $hours = (int) $this->getSetting(CONTEXT_ID_NONE, 'toleranceHours');
        if ($hours < self::TOLERANCE_MIN || $hours > self::TOLERANCE_MAX) {
            return self::TOLERANCE_DEFAULT;
        }
        return $hours;
    }

    /** @return ScheduledPublishSchedule */
    public function getSchedule()
    {
        if (!$this->_schedule) {
            $this->import('classes.ScheduledPublishSchedule');
            $this->_schedule = new ScheduledPublishSchedule();
        }
        return $this->_schedule;
    }

    /** @param $publishing bool */
    public function setPublishing($publishing)
    {
        self::$_publishing = (bool) $publishing;
    }

    /** Called by the tasks on every run, so the forms can tell whether the runner is alive. */
    public function markTaskRun()
    {
        $this->updateSetting(CONTEXT_ID_NONE, 'lastRunAt', Core::getCurrentDate(), 'string');
    }

    /**
     * Whether the task runner looks stalled: the tasks never ran, ran more than an hour ago,
     * or the acron plugin (OJS's task runner) is disabled.
     * @return bool
     */
    public function isTaskRunnerStale()
    {
        $lastRun = (string) $this->getSetting(CONTEXT_ID_NONE, 'lastRunAt');
        if (!$lastRun || strtotime($lastRun) < time() - self::RUNNER_STALE_SECONDS) {
            return true;
        }
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $pluginSettingsDao PluginSettingsDAO */
        return !$pluginSettingsDao->getSetting(CONTEXT_ID_NONE, 'acronplugin', 'enabled');
    }

    /** @return string The time zone OJS uses for dates (config.inc.php time_zone, else PHP's). */
    public function getTimeZoneLabel()
    {
        return date_default_timezone_get();
    }

    /**
     * Format a stored date/time for display, with the journal's own formats.
     * @param $dateString string Y-m-d H:i:s
     * @param $context Context|null
     * @return string
     */
    public function formatDateTime($dateString, $context = null)
    {
        $timestamp = strtotime((string) $dateString);
        if (!$timestamp) {
            return (string) $dateString;
        }
        $format = $context ? $context->getLocalizedDateTimeFormatShort() : '%Y-%m-%d %H:%M';
        if (function_exists('PHP81_BC\\strftime')) {
            return \PHP81_BC\strftime($format, $timestamp);
        }
        return @strftime($format, $timestamp);
    }

    /**
     * How Issue Contributor Notify will behave on a scheduled publish in this journal.
     * @return string 'none' (not installed or not enabled here), 'auto' (applies its journal
     *   defaults without its dialog), 'manual' (older version: the announcement is sent by hand)
     */
    public function getIcnStatus($contextId)
    {
        $versionDao = DAORegistry::getDAO('VersionDAO'); /* @var $versionDao VersionDAO */
        $version = $versionDao->getCurrentVersion('plugins.generic', self::ICN_PRODUCT, true);
        if (!$version) {
            return 'none';
        }
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $pluginSettingsDao PluginSettingsDAO */
        if (!$pluginSettingsDao->getSetting((int) $contextId, self::ICN_PLUGIN_NAME, 'enabled')) {
            return 'none';
        }
        return version_compare($version->getVersionString(), self::ICN_MIN_VERSION, '>=') ? 'auto' : 'manual';
    }

    /**
     * Short status text for the grid column.
     * @param $issue Issue
     * @return string
     */
    public function getIssueStatusLabel($issue)
    {
        $schedule = $this->getSchedule();
        $state = $schedule->getState($issue);
        if (!$state || $issue->getPublished()) {
            return '';
        }
        $request = Application::get()->getRequest();
        $at = $this->formatDateTime($schedule->getAt($issue), $request->getContext());
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
     * @return int
     */
    public function countFutureDatedArticles($issue)
    {
        import('classes.submission.Submission');
        $count = 0;
        $now = time();
        $submissions = Services::get('submission')->getMany([
            'contextId' => $issue->getJournalId(),
            'issueIds' => $issue->getId(),
            'status' => STATUS_SCHEDULED,
        ]);
        foreach ($submissions as $submission) {
            foreach ((array) $submission->getData('publications') as $publication) {
                if ($publication->getData('status') !== STATUS_SCHEDULED || (int) $publication->getData('issueId') !== (int) $issue->getId()) {
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
     * Add this plugin's task file to the task runner's list.
     * @see PKPAcronPlugin::_parseCrontab()
     */
    public function callbackParseCronTab($hookName, $args)
    {
        if ($this->getEnabled() || !Config::getVar('general', 'installed')) {
            $taskFilesPath = & $args[0];
            $taskFilesPath[] = $this->getPluginPath() . DIRECTORY_SEPARATOR . 'scheduledTasks.xml';
        }
        return false;
    }

    /** Register the scheduling fields as issue settings, so IssueDAO saves and loads them. */
    public function callbackIssueFieldNames($hookName, $args)
    {
        $fieldNames = & $args[1];
        $this->import('classes.ScheduledPublishSchedule');
        foreach (ScheduledPublishSchedule::fieldNames() as $name) {
            $fieldNames[] = $name;
        }
        return false;
    }

    /** Append the scheduling fields to the issue form (template hook). */
    public function callbackIssueFormFields($hookName, $args)
    {
        $smarty = $args[1];
        $output = & $args[2];

        $issue = $smarty->getTemplateVars('issue');
        if (!$issue || !is_a($issue, 'Issue') || $issue->getPublished()) {
            return false;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context || (int) $context->getId() !== (int) $issue->getJournalId()) {
            return false;
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
        return false;
    }

    /**
     * Template variables for issueFormFields.tpl.
     * @return array
     */
    public function getIssueFormVars($issue, $context)
    {
        $schedule = $this->getSchedule();
        $state = $schedule->getState($issue);
        $at = $schedule->getAt($issue);
        $timestamp = $at ? strtotime($at) : false;

        $byName = '';
        if ($userId = $schedule->getBy($issue)) {
            $userDao = DAORegistry::getDAO('UserDAO'); /* @var $userDao UserDAO */
            $user = $userDao->getById($userId);
            $byName = $user ? $user->getFullName() : '';
        }

        $currentIssueLabel = '';
        $issueDao = DAORegistry::getDAO('IssueDAO'); /* @var $issueDao IssueDAO */
        $current = $issueDao->getCurrent($context->getId());
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
            'scheduledPublishIcn' => $this->getIcnStatus($context->getId()),
            'scheduledPublishRunnerWarning' => $this->isTaskRunnerStale(),
        ];
    }

    /** Read the scheduling fields with the issue form. */
    public function callbackIssueFormReadUserVars($hookName, $args)
    {
        $vars = & $args[1];
        $vars[] = 'scheduledPublishDate';
        $vars[] = 'scheduledPublishTime';
        return false;
    }

    /** Validate the scheduling fields: a valid date and time, in the future. */
    public function callbackIssueFormValidate($hookName, $args)
    {
        $form = $args[0];
        $issue = isset($form->issue) ? $form->issue : null;
        if (!$issue || $issue->getPublished()) {
            return false;
        }
        $date = trim((string) $form->getData('scheduledPublishDate'));
        $time = trim((string) $form->getData('scheduledPublishTime'));
        if ($date === '' && $time === '') {
            return false;
        }
        $this->import('classes.ScheduledPublishSchedule');
        $at = ScheduledPublishSchedule::parseInput($date, $time);
        if ($at === null) {
            $form->addError('scheduledPublishDate', __('plugins.generic.scheduledPublish.form.invalid'));
            $form->addErrorField('scheduledPublishDate');
            return false;
        }
        // Keep an existing schedule as it is even if its time has passed meanwhile; only a
        // new or changed time has to be in the future.
        $existing = $this->getSchedule()->getAt($issue);
        if ($existing !== $at && strtotime($at) <= time()) {
            $form->addError('scheduledPublishDate', __('plugins.generic.scheduledPublish.form.past'));
            $form->addErrorField('scheduledPublishDate');
        }
        return false;
    }

    /**
     * Save the scheduling fields with the issue. The hook fires twice in OJS 3.3: from the
     * generic Form::execute() without the issue, and from IssueForm::execute() with the issue,
     * just before the issue is saved; only the second call does the work.
     */
    public function callbackIssueFormExecute($hookName, $args)
    {
        $form = $args[0];
        $issue = isset($args[1]) && is_a($args[1], 'Issue') ? $args[1] : null;
        if (!$issue || !$issue->getId() || $issue->getPublished()) {
            return false;
        }
        $schedule = $this->getSchedule();
        $date = trim((string) $form->getData('scheduledPublishDate'));
        $time = trim((string) $form->getData('scheduledPublishTime'));

        if ($date === '' && $time === '') {
            if ($schedule->getState($issue)) {
                $schedule->clear($issue);
            }
            return false;
        }
        $at = ScheduledPublishSchedule::parseInput($date, $time);
        if ($at === null) {
            return false; // refused by validate()
        }
        if ($schedule->getAt($issue) === $at && $schedule->getState($issue) === ScheduledPublishSchedule::STATE_SCHEDULED) {
            return false; // unchanged
        }
        $request = Application::get()->getRequest();
        $user = $request->getUser();
        $schedule->set($issue, $at, $user ? $user->getId() : null);
        return false;
    }

    /** A manual publish (or the publisher itself) ends the schedule. */
    public function callbackIssuePublished($hookName, $args)
    {
        if (self::$_publishing) {
            return false; // the publisher clears the schedule itself, after the issue is saved
        }
        $issue = $args[0];
        if ($issue && is_a($issue, 'Issue') && $this->getSchedule()->getState($issue)) {
            $this->getSchedule()->clear($issue);
        }
        return false;
    }

    /** Add the "Scheduled" column feature to the Future Issues grid. */
    public function callbackGridFeatures($hookName, $args)
    {
        $features = & $args[3];
        $this->import('classes.ScheduledPublishGridFeature');
        $features[] = new ScheduledPublishGridFeature($this);
        return false;
    }

    //
    // Private
    //

    /**
     * The task runner caches the task list; when this plugin is enabled from the plugin
     * grid the runner refreshes it, but not when it is enabled any other way, and not when
     * a new version brings new tasks. Clear the cache once per plugin version.
     */
    private function _refreshTaskRunnerOnce()
    {
        if ($this->getSetting(CONTEXT_ID_NONE, 'cronTabFlushedFor') === self::VERSION) {
            return;
        }
        // Record first, so a site without the acron plugin does not retry on every request.
        $this->updateSetting(CONTEXT_ID_NONE, 'cronTabFlushedFor', self::VERSION, 'string');
        $pluginSettingsDao = DAORegistry::getDAO('PluginSettingsDAO'); /* @var $pluginSettingsDao PluginSettingsDAO */
        $pluginSettingsDao->deleteSetting(CONTEXT_ID_NONE, 'acronplugin', 'crontab');
    }
}

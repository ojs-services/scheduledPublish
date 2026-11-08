<?php

/**
 * @file classes/ScheduledPublishSettingsForm.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishSettingsForm
 * @brief Site-wide settings: the tolerance window.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\core\Application;
use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;

class ScheduledPublishSettingsForm extends Form
{
    private ScheduledPublishPlugin $plugin;

    public function __construct(ScheduledPublishPlugin $plugin)
    {
        $this->plugin = $plugin;
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorCustom(
            $this,
            'toleranceHours',
            'required',
            'plugins.generic.scheduledPublish.settings.toleranceHours.invalid',
            function ($value) {
                return ctype_digit((string) $value)
                    && (int) $value >= ScheduledPublishPlugin::TOLERANCE_MIN
                    && (int) $value <= ScheduledPublishPlugin::TOLERANCE_MAX;
            }
        ));
        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /** @copydoc Form::initData() */
    public function initData()
    {
        $this->setData('toleranceHours', $this->plugin->getToleranceHours());
        parent::initData();
    }

    /** @copydoc Form::readInputData() */
    public function readInputData()
    {
        $this->readUserVars(['toleranceHours']);
        $this->setData('toleranceHours', trim((string) $this->getData('toleranceHours')));
    }

    /** @copydoc Form::fetch() */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign([
            'pluginName' => $this->plugin->getName(),
            'toleranceMin' => ScheduledPublishPlugin::TOLERANCE_MIN,
            'toleranceMax' => ScheduledPublishPlugin::TOLERANCE_MAX,
            'scheduledPublishTimeZone' => $this->plugin->getTimeZoneLabel(),
            'scheduledPublishRunnerWarning' => $this->plugin->isTaskRunnerStale(),
        ]);
        return parent::fetch($request, $template, $display);
    }

    /** @copydoc Form::execute() */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(Application::CONTEXT_ID_NONE, 'toleranceHours', (int) $this->getData('toleranceHours'), 'int');
        return parent::execute(...$functionArgs);
    }
}

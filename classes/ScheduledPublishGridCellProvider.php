<?php

/**
 * @file classes/ScheduledPublishGridCellProvider.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishGridCellProvider
 * @brief Renders the "Scheduled" column of the Future Issues grid.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\issue\Issue;
use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use PKP\controllers\grid\GridCellProvider;

class ScheduledPublishGridCellProvider extends GridCellProvider
{
    private ScheduledPublishPlugin $plugin;

    public function __construct(ScheduledPublishPlugin $plugin)
    {
        parent::__construct();
        $this->plugin = $plugin;
    }

    /** @copydoc GridCellProvider::getTemplateVarsFromRowColumn() */
    public function getTemplateVarsFromRowColumn($row, $column)
    {
        $issue = $row->getData();
        if (!($issue instanceof Issue) || $column->getId() !== ScheduledPublishGridFeature::COLUMN_ID) {
            return ['label' => ''];
        }
        return ['label' => $this->plugin->getIssueStatusLabel($issue)];
    }
}

<?php

/**
 * @file classes/ScheduledPublishGridFeature.inc.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishGridFeature
 * @brief Adds a "Scheduled" column to the Future Issues grid.
 *
 * The grid handler adds its own columns after the initFeatures hook, so the column is
 * added when the grid or a row is about to be rendered; by then the core columns exist and
 * ours comes last.
 */

import('lib.pkp.classes.controllers.grid.feature.GridFeature');
import('lib.pkp.classes.controllers.grid.GridColumn');
import('lib.pkp.classes.controllers.grid.GridCellProvider');

class ScheduledPublishGridFeature extends GridFeature
{
    const COLUMN_ID = 'scheduledPublish';

    /** @var ScheduledPublishPlugin */
    private $_plugin;

    public function __construct($plugin)
    {
        parent::__construct('scheduledPublish');
        $this->_plugin = $plugin;
    }

    /** @copydoc GridFeature::fetchGrid() */
    public function fetchGrid($args)
    {
        $this->_ensureColumn($args['grid']);
    }

    /** @copydoc GridFeature::getInitializedRowInstance() */
    public function getInitializedRowInstance($args)
    {
        $this->_ensureColumn($args['grid']);
    }

    private function _ensureColumn($grid)
    {
        if (!$grid || $grid->hasColumn(self::COLUMN_ID)) {
            return;
        }
        $grid->addColumn(new GridColumn(
            self::COLUMN_ID,
            null,
            __('plugins.generic.scheduledPublish.grid.column'),
            null,
            new ScheduledPublishGridCellProvider($this->_plugin)
        ));
    }
}

class ScheduledPublishGridCellProvider extends GridCellProvider
{
    /** @var ScheduledPublishPlugin */
    private $_plugin;

    public function __construct($plugin)
    {
        parent::__construct();
        $this->_plugin = $plugin;
    }

    /** @copydoc GridCellProvider::getTemplateVarsFromRowColumn() */
    public function getTemplateVarsFromRowColumn($row, $column)
    {
        $issue = $row->getData();
        if (!is_a($issue, 'Issue') || $column->getId() !== ScheduledPublishGridFeature::COLUMN_ID) {
            return ['label' => ''];
        }
        return ['label' => $this->_plugin->getIssueStatusLabel($issue)];
    }
}

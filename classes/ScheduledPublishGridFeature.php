<?php

/**
 * @file classes/ScheduledPublishGridFeature.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @class ScheduledPublishGridFeature
 * @brief Adds a "Scheduled" column to the Future Issues grid.
 *
 * The grid handler adds its own columns after the initFeatures hook, so the column is added
 * when the grid or a row is about to be rendered; by then the core columns exist and ours
 * comes last.
 */

namespace APP\plugins\generic\scheduledPublish\classes;

use APP\plugins\generic\scheduledPublish\ScheduledPublishPlugin;
use PKP\controllers\grid\feature\GridFeature;
use PKP\controllers\grid\GridColumn;

class ScheduledPublishGridFeature extends GridFeature
{
    public const COLUMN_ID = 'scheduledPublish';

    private ScheduledPublishPlugin $plugin;

    public function __construct(ScheduledPublishPlugin $plugin)
    {
        parent::__construct('scheduledPublish');
        $this->plugin = $plugin;
    }

    /**
     * The grid assigns its columns to the template before the fetchGrid hook, but it builds
     * its URLs (getRequestArgs) first; by then the core columns exist, so the column is added
     * here and comes last.
     * @copydoc GridFeature::getRequestArgs()
     */
    public function getRequestArgs($args)
    {
        $this->ensureColumn($args['grid']);
        return null;
    }

    /** @copydoc GridFeature::fetchGrid() */
    public function fetchGrid($args)
    {
        $this->ensureColumn($args['grid']);
    }

    /** @copydoc GridFeature::getInitializedRowInstance() */
    public function getInitializedRowInstance($args)
    {
        $this->ensureColumn($args['grid']);
    }

    private function ensureColumn($grid): void
    {
        if (!$grid || $grid->hasColumn(self::COLUMN_ID)) {
            return;
        }
        $grid->addColumn(new GridColumn(
            self::COLUMN_ID,
            null,
            __('plugins.generic.scheduledPublish.grid.column'),
            null,
            new ScheduledPublishGridCellProvider($this->plugin)
        ));
    }
}

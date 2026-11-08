<?php

/**
 * @file index.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @brief Wrapper for the Scheduled Publish plugin.
 */

// This package is for OJS 3.3. OJS 3.5 and later have no import(): stay out there
// instead of failing on every request.
if (!function_exists('import')) {
    return null;
}

require_once('ScheduledPublishPlugin.inc.php');

return new ScheduledPublishPlugin();

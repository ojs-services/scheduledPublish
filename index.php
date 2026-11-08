<?php

/**
 * @file index.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 * @brief Wrapper for the Scheduled Publish plugin.
 */

// This package is for OJS 3.3. OJS 3.5 has no import(); OJS 3.4 still has it but defines
// PKP_STRICT_MODE: stay out of both instead of failing on every request.
if (!function_exists('import') || defined('PKP_STRICT_MODE')) {
    return null;
}

require_once('ScheduledPublishPlugin.inc.php');

return new ScheduledPublishPlugin();

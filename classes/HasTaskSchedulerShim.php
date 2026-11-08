<?php

/**
 * @file classes/HasTaskSchedulerShim.php
 * Copyright (c) 2026 OJS Services. Distributed under the GNU GPL v3; see LICENSE.
 *
 * OJS 3.5 lets a plugin register scheduled tasks by implementing
 * PKP\plugins\interfaces\HasTaskScheduler. OJS 3.4 has no such interface (it uses the acron
 * plugin). So that one plugin class serves both lines, this file declares the interface on
 * 3.4 only; on 3.5 the core's own interface is used untouched.
 */

namespace PKP\plugins\interfaces {
    if (!interface_exists('PKP\plugins\interfaces\HasTaskScheduler', false)) {
        interface HasTaskScheduler
        {
            public function registerSchedules(\PKP\scheduledTask\PKPScheduler $scheduler): void;
        }
    }
}

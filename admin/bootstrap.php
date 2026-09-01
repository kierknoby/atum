<?php
// SPDX-License-Identifier: GPL-3.0-or-later

define('ATUM_BOOTSTRAPPED', true);
define('ATUM_ROOT', dirname(__DIR__));
define('ATUM_ADMIN', __DIR__);
define('ATUM_MODULES', __DIR__ . '/modules');

date_default_timezone_set(@date_default_timezone_get() ?: 'UTC');

require_once __DIR__ . '/libraries/Atum/Module.class.php';
require_once __DIR__ . '/libraries/Atum/Config.class.php';
require_once __DIR__ . '/libraries/Atum/View.class.php';
require_once __DIR__ . '/libraries/Atum/State.class.php';
require_once __DIR__ . '/libraries/Atum/AuditDestination.class.php';
require_once __DIR__ . '/libraries/Atum/Audit.class.php';
require_once __DIR__ . '/libraries/Atum/Lifecycle.class.php';
require_once __DIR__ . '/libraries/Atum/Auth.class.php';
require_once __DIR__ . '/libraries/Atum/Security.class.php';
require_once __DIR__ . '/libraries/Atum/Manifest.class.php';
require_once __DIR__ . '/libraries/Atum/Modules.class.php';
require_once __DIR__ . '/libraries/Atum/Ajax.class.php';
require_once __DIR__ . '/libraries/Atum/System.class.php';
require_once __DIR__ . '/libraries/Atum.php';

Atum::create();

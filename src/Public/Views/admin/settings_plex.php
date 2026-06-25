<?php

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Auth\PageAuthorization;
include 'session.php';
include 'functions.php';

if (!PageAuthorization::checkPermissions()) {
    AdminHelpers::goHome();
}

$rBouquets = BouquetService::getAllSimple();
$_TITLE = 'Plex Settings';

require_once __DIR__ . '/../layouts/admin.php';
renderUnifiedLayoutHeader('admin');
include dirname(__DIR__, 3) . '/Modules/plex/views/settings.php';
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/Modules/plex/views/settings_scripts.php';

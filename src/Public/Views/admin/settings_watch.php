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
$_TITLE = 'Watch Settings';

require_once __DIR__ . '/../layouts/admin.php';
renderUnifiedLayoutHeader('admin');
include dirname(__DIR__, 3) . '/Modules/watch/views/settings_watch.php';
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/Modules/watch/views/settings_watch_scripts.php';

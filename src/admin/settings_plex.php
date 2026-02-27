<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rBouquets = BouquetService::getAllSimple();
$_TITLE = 'Plex Settings';

require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
renderUnifiedLayoutHeader('admin');
include dirname(__DIR__) . '/modules/plex/views/settings.php';
require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/plex/views/settings_scripts.php';

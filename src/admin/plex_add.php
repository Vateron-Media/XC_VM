<?php

if (!isset($__viewMode)):

    include 'session.php';
    include 'functions.php';

    if (!checkPermissions()) {
        goHome();
    }

    if (!isset(CoreUtilities::$rRequest['id']) || ($rFolder = getWatchFolder(CoreUtilities::$rRequest['id']))) {
    } else {
        goHome();
    }

    $rBouquets = BouquetService::getAllSimple();
    $_TITLE = 'Add Library';

    require_once __DIR__ . '/../public/Views/layouts/admin.php';
    renderUnifiedLayoutHeader('admin');

endif; // !$__viewMode
include dirname(__DIR__) . '/modules/plex/views/library_edit.php';

require_once __DIR__ . '/../public/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/plex/views/library_edit_scripts.php';

<?php

include dirname(__DIR__, 3) . '/Modules/plex/views/index.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/Modules/plex/views/library_scripts.php';

<?php

include dirname(__DIR__, 3) . '/modules/plex/views/index.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/modules/plex/views/library_scripts.php';

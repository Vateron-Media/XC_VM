<?php

include dirname(__DIR__, 3) . '/Modules/plex/views/library_edit.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/Modules/plex/views/library_edit_scripts.php';

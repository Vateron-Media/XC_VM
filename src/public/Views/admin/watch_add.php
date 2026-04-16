<?php


include dirname(__DIR__, 3) . '/modules/watch/views/watch_add.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');

include dirname(__DIR__, 3) . '/modules/watch/views/watch_add_scripts.php';

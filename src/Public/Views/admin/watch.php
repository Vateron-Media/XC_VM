<?php


include dirname(__DIR__, 3) . '/Modules/watch/views/watch.php';

require_once __DIR__ . '/../layouts/footer.php';

renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/Modules/watch/views/watch_scripts.php';

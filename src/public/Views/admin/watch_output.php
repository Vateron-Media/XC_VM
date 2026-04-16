<?php

include dirname(__DIR__, 3) . '/modules/watch/views/watch_output.php';

require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/modules/watch/views/watch_output_scripts.php';

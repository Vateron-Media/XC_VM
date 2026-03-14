<?php

/**
 * Streaming micro-router (подготовительный).
 *
 * Текущая конфигурация nginx направляет запросы напрямую на конкретные файлы
 * (live.php, vod.php и т.д.). Этот файл используется как fallback при прямом
 * обращении к /stream/ и готов к опциональному переключению nginx на единый
 * entry point.
 *
 * Overhead: один array lookup (< 0.1ms).
 */

$rHandler = isset($_GET['handler']) ? $_GET['handler'] : null;

$rMap = [
    'live'      => 'live.php',
    'vod'       => 'vod.php',
    'segment'   => 'segment.php',
    'key'       => 'key.php',
    'timeshift' => 'timeshift.php',
    'thumb'     => 'thumb.php',
    'subtitle'  => 'subtitle.php',
    'rtmp'      => 'rtmp.php',
    'auth'      => 'auth.php',
];

if ($rHandler && isset($rMap[$rHandler])) {
    require __DIR__ . '/' . $rMap[$rHandler];
} else {
    http_response_code(404);
    echo "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
}

<?php

$rPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
header('Location: ' . $rPath . '/login');

exit();

<?php

use XcVm\Core\Auth\SessionManager;

include 'functions.php';
SessionManager::clearContext('admin');
header('Location: ./login');

exit();

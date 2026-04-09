<?php

include 'functions.php';
SessionManager::clearContext('admin');
header('Location: ./login');

exit();

<?php
/**
 * Player session bootstrap.
 *
 * Manages player session lifecycle: start session, redirect if not authenticated.
 * Session keys: 'phash' (user ID), 'pverify' (md5 of username||password)
 */

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// Not logged in → redirect to login
if (!isset($_SESSION['phash'])) {
    $referrer = defined('PAGE_NAME') ? PAGE_NAME : '';
    $code = $_SERVER['XC_CODE'] ?? '';
    $loginUrl = $code ? '/' . $code . '/login' : 'login';
    header('Location: ' . $loginUrl . ($referrer ? '?referrer=' . urlencode($referrer) : ''));
    exit();
}

<?php
/**
 * Player session bootstrap.
 *
 * Manages player session lifecycle: start session, redirect if not authenticated.
 * Session keys: 'phash' (user ID), 'pverify' (md5 of username||password)
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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

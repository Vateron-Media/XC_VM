<?php

/**
 * XC_VM — Конфигурация приложения
 *
 * Версия, Git-репозитории, флаги разработки и прочие
 * application-level константы.
 *
 * Не содержит путей (см. Paths.php) и бинарников (см. Binaries.php).
 */

define('AUTO_RESTART_MARIADB', true);  // Test function
define('DEVELOPMENT', true);          // It will be deleted in the future.

// ── Версия и Git ──────────────────────────────────────────────
define('XC_VM_VERSION',   '1.2.15');
define('GIT_OWNER',       'Vateron-Media');
define('GIT_REPO_MAIN',   'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');

// ── Разное ────────────────────────────────────────────────────
define('MONITOR_CALLS', 3);
define('OPENSSL_EXTRA', 'fNiu3XD448xTDa27xoY4');

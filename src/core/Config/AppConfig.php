<?php

/**
 * XC_VM Application Configuration
 *
 * Copyright (c) 2026 Vateron-Media
 *
 * @author      Divarion_D
 * @license     GNU Affero General Public License v3.0 (AGPL-3.0)
 * @link        https://github.com/Vateron-Media/XC_VM
 *
 * This file contains application-level configuration constants,
 * including versioning, Git repositories, and feature flags.
 */

// ── Development & Experimental Flags ───────────────────────────

define('AUTO_RESTART_MARIADB', true); // Enables automatic MariaDB restart (experimental)
define('DEVELOPMENT', true);          // Development mode (planned for removal)

// ── Version & Git Configuration ────────────────────────────────

define('XC_VM_VERSION', '2.0.2');

define('GIT_OWNER',       'Vateron-Media');
define('GIT_REPO_MAIN',   'XC_VM');
define('GIT_REPO_UPDATE', 'XC_VM_Update');
define('GIT_REPO_BIN',    'XC_VM_Binaries');

// ── Miscellaneous Settings ─────────────────────────────────────

define('MONITOR_CALLS', 3);          // Number of retry attempts for monitoring tasks
define('OPENSSL_EXTRA', 'fNiu3XD448xTDa27xoY4'); // Additional OpenSSL entropy/seed (review necessity)
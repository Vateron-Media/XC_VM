<?php

/**
 * PHPStan bootstrap for XC_VM.
 *
 * The project has no Composer and no PSR-4 namespaces. We deliberately do NOT
 * include src/autoload.php here: registering the runtime class-map autoloader
 * makes PHPStan execute a full live directory rescan on every class miss
 * (the igbinary cache is unavailable on a plain CLI), which exhausts memory
 * and crashes the parallel workers.
 *
 * Instead, PHPStan discovers every class/interface/function on its own by
 * statically scanning the directories listed under `paths` and
 * `scanDirectories` in phpstan.dist.neon — no project code is executed.
 *
 * This file only defines the global constants the codebase references so that
 * analysis of constant-dependent expressions stays accurate.
 *
 * @package XC_VM
 */

$srcRoot = dirname(__DIR__, 2) . '/src';

if (!defined('MAIN_HOME')) {
    define('MAIN_HOME', $srcRoot . '/');
}

// Declare constants the app defines at runtime (inside methods) so PHPStan can
// resolve them. Auto-generated — regenerate with gen-constants-stub.php.
require __DIR__ . '/constants.stub.php';

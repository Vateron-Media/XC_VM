<?php

/**
 * PHPStan bootstrap for XC_VM.
 *
 * The project uses Composer PSR-4, but this bootstrap deliberately does NOT wire
 * a project autoloader. PHPStan discovers every class/interface/function itself
 * by statically scanning the directories listed under `paths` and
 * `scanDirectories` in build/phpstan.dist.neon — no project code is executed. (The
 * Composer-installed phpstan binary loads src/vendor/autoload.php for its own
 * dependencies.)
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

// NOTE: constants.stub.php is loaded via its own bootstrapFiles entry in
// build/phpstan.dist.neon (so PHPStan tracks it for result-cache invalidation).

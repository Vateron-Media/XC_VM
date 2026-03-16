#!/usr/bin/env php
<?php

/**
 * XC_VM Console — единая точка входа для CLI-команд и cron-задач.
 *
 * Использование:
 *   php console.php <command> [arguments]
 *   php console.php --list              # список всех команд
 *   php console.php --help              # справка
 *
 * Примеры:
 *   php console.php startup             # запуск демона startup
 *   php console.php cron:servers        # запуск cron servers
 *   php console.php watchdog            # запуск демона watchdog
 *
 * Команды регистрируются ниже явно — добавьте свою команду в секцию
 * "Register commands" после создания класса, реализующего CommandInterface.
 */

// ─── Guard: CLI only ─────────────────────────────────────────────

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit;
}

// ─── Bootstrap ───────────────────────────────────────────────────

require_once __DIR__ . '/cli/CommandInterface.php';
require_once __DIR__ . '/cli/CommandRegistry.php';
require_once __DIR__ . '/bootstrap.php';

XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, [
	'process' => 'XC_VM[Console]',
]);

// ─── Registry ────────────────────────────────────────────────────

$rRegistry = new CommandRegistry();

// ── Auto-discover: core Commands + CronJobs ──────────────────────

$rCommandDirs = [
	__DIR__ . '/cli/Commands',
	__DIR__ . '/cli/CronJobs',
];

foreach ($rCommandDirs as $rDir) {
	if (!is_dir($rDir)) {
		continue;
	}
	foreach (glob($rDir . '/*.php') as $rFile) {
		$rClassName = basename($rFile, '.php');
		require_once $rFile;
		if (class_exists($rClassName, false)) {
			$rReflection = new ReflectionClass($rClassName);
			if (!$rReflection->isAbstract() && $rReflection->implementsInterface('CommandInterface')) {
				$rRegistry->register(new $rClassName());
			}
		}
	}
}

// ── Module commands ──────────────────────────────────────────────
// Модули регистрируют свои команды через ModuleInterface::registerCommands()
// Без filesystem scanning — каждый модуль явно знает свои команды.

$rModuleLoader = new ModuleLoader();
$rModuleLoader->loadAll();
$rModuleLoader->registerAllCommands($rRegistry);

// ─── Dispatch ────────────────────────────────────────────────────

exit($rRegistry->dispatch($argv));

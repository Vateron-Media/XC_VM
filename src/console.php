#!/home/xc_vm/bin/php/bin/php
<?php
/**
 * XC_VM Console — единая точка входа для CLI-команд и cron-задач.
 *
 * Использование:
 *   /home/xc_vm/console.php <command> [arguments]
 *   /home/xc_vm/console.php --list              # список всех команд
 *   /home/xc_vm/console.php --help              # справка
 *
 * Примеры:
 *   /home/xc_vm/console.php startup             # запуск демона startup
 *   /home/xc_vm/console.php cron:servers        # запуск cron servers
 *   /home/xc_vm/console.php watchdog            # запуск демона watchdog
 *
 * Команды регистрируются ниже явно — добавьте свою команду в секцию
 * "Register commands" после создания класса, реализующего CommandInterface.
 *
 * @package XC_VM
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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

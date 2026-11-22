<?php

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CommandRegistry;
use XcVm\Core\Cluster\ReplicaBoot;

/**
 * Root-only console. Unlike console.php it never discovers or boots modules:
 * every file PHP can execute here is part of the root-owned core tree.
 */

if (php_sapi_name() !== 'cli') {
	http_response_code(403);
	exit;
}

require_once __DIR__ . '/bootstrap.php';

XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_CLI, [
	'process' => 'XC_VM[Root]',
	'replica' => ReplicaBoot::forArgv($argv),
]);

$rRegistry = new CommandRegistry();
foreach ([
	__DIR__ . '/Cli/Commands' => 'XcVm\\Cli\\Commands\\',
	__DIR__ . '/Cli/CronJobs' => 'XcVm\\Cli\\CronJobs\\',
] as $rDir => $rNamespace) {
	if (!is_dir($rDir)) {
		continue;
	}
	foreach (glob($rDir . '/*.php') as $rFile) {
		$rClass = $rNamespace . basename($rFile, '.php');
		if (!class_exists($rClass)) {
			continue;
		}
		$rReflection = new ReflectionClass($rClass);
		if (!$rReflection->isAbstract() && $rReflection->implementsInterface(CommandInterface::class)) {
			$rCommand = new $rClass();
			$rName = $rCommand->getName();
			if (!str_starts_with($rName, 'module:') && !str_starts_with($rName, 'cron:module_') && $rName !== 'ondemand') {
				$rRegistry->register($rCommand);
			}
		}
	}
}

exit($rRegistry->dispatch($argv));

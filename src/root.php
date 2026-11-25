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
$rRootCommands = [
	XcVm\Cli\Commands\BackupOpenBundleCommand::class,
	XcVm\Cli\Commands\BinariesCommand::class,
	XcVm\Cli\Commands\CertbotCommand::class,
	XcVm\Cli\Commands\ClusterDbAllowlistCommand::class,
	XcVm\Cli\Commands\ClusterLockdownCommand::class,
	XcVm\Cli\Commands\ClusterPinRootCommand::class,
	XcVm\Cli\Commands\ClusterRootCommand::class,
	XcVm\Cli\Commands\ClusterSetDbPasswordCommand::class,
	XcVm\Cli\Commands\FanoutBinaryCommand::class,
	XcVm\Cli\Commands\IpsetCommand::class,
	XcVm\Cli\Commands\RootOwnCommand::class,
	XcVm\Cli\Commands\RootPathsCommand::class,
	XcVm\Cli\Commands\ServerDiagnoseCommand::class,
	XcVm\Cli\Commands\ServerSyncOpensslExtraCommand::class,
	XcVm\Cli\Commands\ServiceCommand::class,
	XcVm\Cli\Commands\StartupCommand::class,
	XcVm\Cli\Commands\StatusCommand::class,
	XcVm\Cli\Commands\ToolsCommand::class,
	XcVm\Cli\Commands\UpdateCommand::class,
	XcVm\Cli\Commands\XcvmCoreCommand::class,
	XcVm\Cli\Commands\YtDlpCommand::class,
	XcVm\Cli\CronJobs\MaxMindCronJob::class,
	XcVm\Cli\CronJobs\RootMysqlCronJob::class,
	XcVm\Cli\CronJobs\RootSignalsCronJob::class,
];
foreach ($rRootCommands as $rClass) {
	if (!class_exists($rClass)) {
		continue;
	}
	$rReflection = new ReflectionClass($rClass);
	if (!$rReflection->isAbstract() && $rReflection->implementsInterface(CommandInterface::class)) {
		$rRegistry->register(new $rClass());
	}
}

exit($rRegistry->dispatch($argv));

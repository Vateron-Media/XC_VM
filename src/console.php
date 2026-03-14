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

// ── Daemon commands ──────────────────────────────────────────────

require_once __DIR__ . '/cli/Commands/SignalsCommand.php';
$rRegistry->register(new SignalsCommand());

require_once __DIR__ . '/cli/Commands/WatchdogCommand.php';
$rRegistry->register(new WatchdogCommand());

require_once __DIR__ . '/cli/Commands/QueueCommand.php';
$rRegistry->register(new QueueCommand());

if (file_exists(__DIR__ . '/cli/Commands/CacheHandlerCommand.php')) {
    require_once __DIR__ . '/cli/Commands/CacheHandlerCommand.php';
    $rRegistry->register(new CacheHandlerCommand());
}

require_once __DIR__ . '/cli/Commands/StartupCommand.php';
$rRegistry->register(new StartupCommand());

require_once __DIR__ . '/cli/Commands/MonitorCommand.php';
$rRegistry->register(new MonitorCommand());

require_once __DIR__ . '/cli/Commands/ScannerCommand.php';
$rRegistry->register(new ScannerCommand());

if (file_exists(__DIR__ . '/cli/Commands/BalancerCommand.php')) {
    require_once __DIR__ . '/cli/Commands/BalancerCommand.php';
    $rRegistry->register(new BalancerCommand());
}

// ── Stream commands ──────────────────────────────────────────────

require_once __DIR__ . '/cli/Commands/ArchiveCommand.php';
$rRegistry->register(new ArchiveCommand());

require_once __DIR__ . '/cli/Commands/CreatedCommand.php';
$rRegistry->register(new CreatedCommand());

require_once __DIR__ . '/cli/Commands/DelayCommand.php';
$rRegistry->register(new DelayCommand());

require_once __DIR__ . '/cli/Commands/LoopbackCommand.php';
$rRegistry->register(new LoopbackCommand());

require_once __DIR__ . '/cli/Commands/LlodCommand.php';
$rRegistry->register(new LlodCommand());

require_once __DIR__ . '/cli/Commands/ProxyCommand.php';
$rRegistry->register(new ProxyCommand());

require_once __DIR__ . '/cli/Commands/RecordCommand.php';
$rRegistry->register(new RecordCommand());

require_once __DIR__ . '/cli/Commands/OndemandCommand.php';
$rRegistry->register(new OndemandCommand());

// ── Utility commands ─────────────────────────────────────────────

require_once __DIR__ . '/cli/Commands/ThumbnailCommand.php';
$rRegistry->register(new ThumbnailCommand());

require_once __DIR__ . '/cli/Commands/PlexItemCommand.php';
$rRegistry->register(new PlexItemCommand());

require_once __DIR__ . '/cli/Commands/WatchItemCommand.php';
$rRegistry->register(new WatchItemCommand());

require_once __DIR__ . '/cli/Commands/BinariesCommand.php';
$rRegistry->register(new BinariesCommand());

require_once __DIR__ . '/cli/Commands/CertbotCommand.php';
$rRegistry->register(new CertbotCommand());

require_once __DIR__ . '/cli/Commands/ToolsCommand.php';
$rRegistry->register(new ToolsCommand());

require_once __DIR__ . '/cli/Commands/UpdateCommand.php';
$rRegistry->register(new UpdateCommand());

if (file_exists(__DIR__ . '/cli/Commands/MigrateCommand.php')) {
    require_once __DIR__ . '/cli/Commands/MigrateCommand.php';
    $rRegistry->register(new MigrateCommand());
}

require_once __DIR__ . '/cli/Commands/ServiceCommand.php';
$rRegistry->register(new ServiceCommand());

require_once __DIR__ . '/cli/Commands/StatusCommand.php';
$rRegistry->register(new StatusCommand());

// ── Cron jobs ────────────────────────────────────────────────────

require_once __DIR__ . '/cli/CronJobs/ActivityCronJob.php';
$rRegistry->register(new ActivityCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/BackupsCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/BackupsCronJob.php';
	$rRegistry->register(new BackupsCronJob());
}

require_once __DIR__ . '/cli/CronJobs/CacheCronJob.php';
$rRegistry->register(new CacheCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/CacheEngineCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/CacheEngineCronJob.php';
	$rRegistry->register(new CacheEngineCronJob());
}

require_once __DIR__ . '/cli/CronJobs/CertbotCronJob.php';
$rRegistry->register(new CertbotCronJob());

require_once __DIR__ . '/cli/CronJobs/CleanupCronJob.php';
$rRegistry->register(new CleanupCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/EpgCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/EpgCronJob.php';
	$rRegistry->register(new EpgCronJob());
}

require_once __DIR__ . '/cli/CronJobs/ErrorsCronJob.php';
$rRegistry->register(new ErrorsCronJob());

require_once __DIR__ . '/cli/CronJobs/LinesLogsCronJob.php';
$rRegistry->register(new LinesLogsCronJob());

require_once __DIR__ . '/cli/CronJobs/PlexCronJob.php';
$rRegistry->register(new PlexCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/ProvidersCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/ProvidersCronJob.php';
	$rRegistry->register(new ProvidersCronJob());
}

if (file_exists(__DIR__ . '/cli/CronJobs/RootMysqlCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/RootMysqlCronJob.php';
	$rRegistry->register(new RootMysqlCronJob());
}

require_once __DIR__ . '/cli/CronJobs/RootSignalsCronJob.php';
$rRegistry->register(new RootSignalsCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/SeriesCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/SeriesCronJob.php';
	$rRegistry->register(new SeriesCronJob());
}

require_once __DIR__ . '/cli/CronJobs/ServersCronJob.php';
$rRegistry->register(new ServersCronJob());

require_once __DIR__ . '/cli/CronJobs/StatsCronJob.php';
$rRegistry->register(new StatsCronJob());

require_once __DIR__ . '/cli/CronJobs/StreamsCronJob.php';
$rRegistry->register(new StreamsCronJob());

require_once __DIR__ . '/cli/CronJobs/StreamsLogsCronJob.php';
$rRegistry->register(new StreamsLogsCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/TmdbCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/TmdbCronJob.php';
	$rRegistry->register(new TmdbCronJob());
}

if (file_exists(__DIR__ . '/cli/CronJobs/TmdbPopularCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/TmdbPopularCronJob.php';
	$rRegistry->register(new TmdbPopularCronJob());
}

require_once __DIR__ . '/cli/CronJobs/TmpCronJob.php';
$rRegistry->register(new TmpCronJob());

if (file_exists(__DIR__ . '/cli/CronJobs/UpdateCronJob.php')) {
	require_once __DIR__ . '/cli/CronJobs/UpdateCronJob.php';
	$rRegistry->register(new UpdateCronJob());
}

require_once __DIR__ . '/cli/CronJobs/UsersCronJob.php';
$rRegistry->register(new UsersCronJob());

require_once __DIR__ . '/cli/CronJobs/VodCronJob.php';
$rRegistry->register(new VodCronJob());

require_once __DIR__ . '/cli/CronJobs/WatchCronJob.php';
$rRegistry->register(new WatchCronJob());

// ─────────────────────────────────────────────────────────────────

// ─── Dispatch ────────────────────────────────────────────────────

exit($rRegistry->dispatch($argv));

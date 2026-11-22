<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Module\ModuleLoader;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\CrontabLock;

/** Keep validated module jobs in xc_vm's crontab, never root's. */
final class ModuleCronSyncCommand implements CommandInterface {
	private const MAX_CRONTAB_BYTES = 1048576;

	public function getName(): string {
		return 'module:cron-sync';
	}

	public function getDescription(): string {
		return 'Synchronize validated module cron entries for the xc_vm user';
	}

	public function execute(array $rArgs): int {
		if (posix_geteuid() === 0) {
			fwrite(STDERR, "module:cron-sync must run as xc_vm, not root.\n");
			return 1;
		}
		$rLock = CrontabLock::acquire();
		if ($rLock === null) {
			return 1;
		}

		[$rCode, $rCurrent] = ProcessRunner::capture(['crontab', '-l'], self::MAX_CRONTAB_BYTES + 1);
		if ($rCode !== 0 && $rCode !== 1) {
			return 1;
		}
		if (strlen($rCurrent) > self::MAX_CRONTAB_BYTES) {
			return 1;
		}
		$rLines = $rCode === 0 ? preg_split('/\R/', rtrim($rCurrent)) : [];
		$rOutput = self::merge(is_array($rLines) ? $rLines : [], (new ModuleLoader())->loadAll()->collectCronEntries());
		$rText = $rOutput === [] ? '' : implode("\n", $rOutput) . "\n";
		if ($rCode === 0 && $rText === ($rCurrent === '' || str_ends_with($rCurrent, "\n") ? $rCurrent : $rCurrent . "\n")) {
			return 0;
		}
		return ProcessRunner::capture(['crontab', '-'], 65536, $rText)[0] === 0 ? 0 : 1;
	}

	/** @param list<string> $rCurrent @param list<string> $rModules @return list<string> */
	public static function merge(array $rCurrent, array $rModules): array {
		$rOutput = array_values(array_filter($rCurrent, static fn(string $line): bool => !str_contains($line, '# XC_VM_MODULE')));
		foreach ($rModules as $rLine) {
			if (!in_array($rLine, $rOutput, true)) {
				$rOutput[] = $rLine;
			}
		}
		return $rOutput;
	}
}

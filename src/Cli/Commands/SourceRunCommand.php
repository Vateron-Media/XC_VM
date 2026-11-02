<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Stream\StreamProcess;

/** Build and replace this process with an opted-in source driver on this LB. */
final class SourceRunCommand implements CommandInterface {
	public function getName(): string {
		return 'source:run';
	}

	public function getDescription(): string {
		return 'Run a load-balancer source driver on this node';
	}

	public function execute(array $rArgs): int {
		try {
			if (!isset($rArgs[0], $rArgs[1], $rArgs[2]) || !ctype_digit((string) $rArgs[0]) || !ctype_digit((string) $rArgs[1]) || !function_exists('pcntl_exec')) {
				throw new \UnexpectedValueException('Invalid source:run invocation');
			}
			$rArgv = StreamProcess::lbDriverArgv((int) $rArgs[0], (int) $rArgs[1], (string) $rArgs[2]);
			pcntl_exec($rArgv[0], array_slice($rArgv, 1));
			throw new \RuntimeException('Cannot execute source driver');
		} catch (\Throwable $rError) {
			fwrite(STDERR, 'source:run: ' . $rError->getMessage() . "\n");
			return 1;
		}
	}
}

<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CommandInterface;

/**
 * Guard for Фаза 2: console.php discovers commands by FQCN built from the scan
 * directory's PSR-4 namespace (no basename==classname, no manual require, no
 * global 'CommandInterface' string). This test mirrors that discovery without
 * booting the full CLI (which needs the ioncube XC_VM class) and asserts the
 * command surface stays stable.
 */
final class ConsoleDiscoveryTest extends TestCase {
	private const DIRS = [
		'Cli/Commands' => 'XcVm\\Cli\\Commands\\',
		'Cli/CronJobs' => 'XcVm\\Cli\\CronJobs\\',
	];

	/** @return array{0:string[],1:string[]} [resolved FQCNs, concrete commands implementing the interface] */
	private function discover(): array {
		$resolved = [];
		$commands = [];
		foreach (self::DIRS as $dir => $ns) {
			$path = MAIN_HOME . $dir;
			$this->assertDirectoryExists($path);
			foreach (glob($path . '/*.php') as $file) {
				$fqcn = $ns . basename($file, '.php');
				if (!class_exists($fqcn)) {
					continue;
				}
				$resolved[] = $fqcn;
				$ref = new ReflectionClass($fqcn);
				if (!$ref->isAbstract() && $ref->implementsInterface(CommandInterface::class)) {
					$commands[] = $fqcn;
				}
			}
		}
		return [$resolved, $commands];
	}

	public function testEveryCliFileResolvesByFqcn(): void {
		$files = 0;
		foreach (self::DIRS as $dir => $ns) {
			$files += count(glob(MAIN_HOME . $dir . '/*.php'));
		}
		[$resolved] = $this->discover();
		$this->assertSame(
			$files,
			count($resolved),
			'Every Cli/Commands + Cli/CronJobs file must resolve to a class by FQCN.'
		);
	}

	public function testDiscoversAStableSetOfCommands(): void {
		[, $commands] = $this->discover();
		// The concrete command surface should not silently shrink.
		$this->assertGreaterThanOrEqual(
			40,
			count($commands),
			'Far fewer commands than expected — discovery likely broke.'
		);
		foreach ($commands as $fqcn) {
			$this->assertTrue(
				(new ReflectionClass($fqcn))->implementsInterface(CommandInterface::class)
			);
		}
	}
}

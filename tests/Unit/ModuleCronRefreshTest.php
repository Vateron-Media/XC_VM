<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * root_signals writes root's crontab again when config/modules.php changes,
 * so a module installed or enabled from the panel gets its cron lines (the
 * anti-abuse shield's, which starts its honeypot) without a restart.
 */
final class ModuleCronRefreshTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-modcron-' . getmypid() . '/';
		@mkdir($this->rDir);
		@unlink($this->rDir . 'crontab.modules');
	}

	protected function tearDown(): void {
		@unlink($this->rDir . 'modules.php');
		@unlink($this->rDir . 'crontab.modules');
		@rmdir($this->rDir);
	}

	public function testWrittenOnceForEachChangeOfTheModules(): void {
		$rWrites = 0;
		$rWrite = static function () use (&$rWrites): bool {
			$rWrites++;
			return true;
		};
		$this->assertFalse(RootSignalsCronJob::refreshModuleCrons($rWrite, $this->rDir), 'no modules.php: nothing to do');

		file_put_contents($this->rDir . 'modules.php', "<?php return ['anti-abuse' => ['installed_version' => '1.0.0']];");
		$this->assertTrue(RootSignalsCronJob::refreshModuleCrons($rWrite, $this->rDir), 'a module installed');
		$this->assertFalse(RootSignalsCronJob::refreshModuleCrons($rWrite, $this->rDir), 'the next minute: unchanged');

		file_put_contents($this->rDir . 'modules.php', "<?php return ['anti-abuse' => ['installed_version' => '1.0.0', 'enabled' => false]];");
		$this->assertTrue(RootSignalsCronJob::refreshModuleCrons($rWrite, $this->rDir), 'a module disabled');
		$this->assertSame(2, $rWrites);
	}

	public function testACrontabNotWrittenIsTriedAgain(): void {
		file_put_contents($this->rDir . 'modules.php', "<?php return [];");
		$this->assertFalse(RootSignalsCronJob::refreshModuleCrons(static fn(): bool => false, $this->rDir));
		$this->assertTrue(RootSignalsCronJob::refreshModuleCrons(static fn(): bool => true, $this->rDir), 'the next minute');
	}
}

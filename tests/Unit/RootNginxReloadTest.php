<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootSignalsCronJob;

/**
 * nginx reloads once per root pass, whatever its actions ask. One save of a
 * server's ports queues its HTTP, HTTPS and RTMP ports, and each reloaded
 * nginx at once: two reloads in the same second, and nginx aborted a worker
 * (signal 6 in MAIN's error.log). Every action now asks, and the pass (the
 * root cron's minute, cluster:root's drain) reloads once at its end.
 */
final class RootNginxReloadTest extends TestCase {
	/** @var list<list<string>> */
	private array $rRan = [];

	protected function setUp(): void {
		RootSignalsCronJob::useRunner(function (array $rArgv): array {
			$this->rRan[] = $rArgv;
			return [0, ''];
		});
		RootSignalsCronJob::reloadAsked(); // nothing left from another test
		$this->rRan = [];
	}

	protected function tearDown(): void {
		RootSignalsCronJob::useRunner(null);
	}

	private function db(): object {
		return new class {
			public function query(string $rSql, mixed ...$rParams): bool {
				return true;
			}
		};
	}

	public function testActionsAskAndThePassReloadsOnce(): void {
		$rJob = new RootSignalsCronJob();
		ob_start();
		$rJob->executeAction(['action' => 'reload_nginx'], [], $this->db());
		$rJob->executeAction(['action' => 'reload_nginx'], [], $this->db());
		ob_end_clean();
		$this->assertSame([], $this->rRan, 'an action reloads nothing itself');

		RootSignalsCronJob::reloadAsked();
		$this->assertSame([
			['sudo', BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp', '-s', 'reload'],
			['sudo', BIN_PATH . 'nginx/sbin/nginx', '-s', 'reload'],
		], $this->rRan);

		RootSignalsCronJob::reloadAsked();
		$this->assertCount(2, $this->rRan, 'nothing asked since: no reload');
	}

	public function testNoActionReloadsNginxByItself(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertDoesNotMatchRegularExpression('/-s reload/', $rSource, 'every reload goes through reloadAsked()');
		$rRoot = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ClusterRootCommand.php');
		$this->assertMatchesRegularExpression('/self::drain\([^;]+;\s*RootSignalsCronJob::reloadAsked\(\);/', $rRoot, 'cluster:root reloads once per drain');
	}
}

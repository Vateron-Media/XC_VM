<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Cli\Commands\ProxyInstallFlow;

/**
 * The mode a node's install leaves on /home/xc_vm/bin, the directory of the
 * interpreter root's crontab runs. Only its owner writes to it: everything
 * that touches it on a load balancer or a proxy is root or xc_vm, who owns
 * the tree once the install has handed it over. Others keep read and search,
 * which nothing may rely on: an update closes the directory to them again
 * (0750), so the flows' own checks in it run as root.
 */
final class AuditInstallBinDirModeTest extends TestCase {
	public static function setUpBeforeClass(): void {
		if (!defined('TMP_PATH')) {
			define('TMP_PATH', sys_get_temp_dir() . '/xcvm-test-tmp/');
		}
		@mkdir(TMP_PATH, 0777, true);
	}

	/**
	 * The commands a flow's configureRuntime() runs on the node.
	 *
	 * @param callable(callable, callable): mixed $rFlow Given the file sender and the command runner.
	 * @return list<string>
	 */
	private function ran(callable $rFlow): array {
		$rRan = [];
		$rRunSSH = static function ($rConn, string $rCommand) use (&$rRan): array {
			$rRan[] = $rCommand;
			return ['output' => str_contains($rCommand, '/proc/cpuinfo') ? "2\n" : '', 'error' => ''];
		};
		$rSendFileSSH = static function ($rConn, string $rPath, string $rOutput, bool $rWarn = false): bool {
			// The flow's own temporary files only, never MAIN's nginx configuration.
			if (str_starts_with($rPath, TMP_PATH)) {
				@unlink($rPath);
			}
			return true;
		};
		ob_start();
		try {
			$rFlow($rSendFileSSH, $rRunSSH);
		} finally {
			ob_end_clean();
		}
		return $rRan;
	}

	/** @return array<string, list<string>> */
	private function flows(): array {
		$rServers = [1 => ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 80], 7 => ['server_ip' => '10.0.0.7', 'http_broadcast_port' => 8080]];
		return [
			'load balancer' => $this->ran(static fn(callable $rSend, callable $rRun) => LbInstallFlow::configureRuntime(null, $rSend, $rRun, $rServers, 7)),
			'proxy' => $this->ran(static fn(callable $rSend, callable $rRun) => ProxyInstallFlow::configureRuntime(null, $rSend, $rRun, $rServers, [], 0, 80, 443, 7)),
		];
	}

	public function testOnlyItsOwnerWritesToTheBinDirectory(): void {
		foreach ($this->flows() as $rNode => $rRan) {
			$rModes = [];
			foreach ($rRan as $rCommand) {
				if (preg_match('~^sudo chmod (\S+) /home/xc_vm/bin/?$~', $rCommand, $rM)) {
					$rModes[] = $rM[1];
				}
			}
			$this->assertSame(['0755'], $rModes, $rNode . ': no write for group or others');
		}
	}

	public function testNoStepOpensAPathToEveryUser(): void {
		foreach ($this->flows() as $rNode => $rRan) {
			foreach ($rRan as $rCommand) {
				$this->assertDoesNotMatchRegularExpression('~chmod\s+(-R\s+)?(0?[0-7]{2}[2367]\b|[ugoa]*[oa][ugoa]*\+[rxX]*w)~', $rCommand, $rNode);
			}
		}
	}
}

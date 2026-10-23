<?php

use PHPUnit\Framework\TestCase;

/**
 * P6 Part A: a serve-only node is made PHP-free at the END of a successful
 * install — after every PHP-dependent install step has run with PHP present
 * (installExtension's xcvm_core probe, readback's XC_VM::config_server, the
 * first `service` boot's console.php startup). console.php, bin/php and the
 * http-nginx build are then removed (nginx_rtmp stays), which flips the Go
 * agent's PHP-free gates, and the final restart boots PHP-free (the service
 * skips every PHP startup/cron via php_available). A mode-1 install is unchanged.
 *
 * @covers \XcVm\Cli\Commands\ServerInstallCommand
 */
final class ServeOnlyPhpFreeInstallTest extends TestCase {
	public function testPhpIsRemovedOnlyAtTheEndOfAServeOnlyInstall(): void {
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');

		// The PHP-free transition removes console.php + bin/php only.
		$this->assertStringContainsString("'sudo rm -rf ' . MAIN_HOME . 'console.php ' . MAIN_HOME . 'bin/php'", $rCmd, 'the serve-only install removes console.php and bin/php');
		// bin/nginx is KEPT: the Go front reads its listen ports from
		// bin/nginx/conf/ports/*, so deleting it would leave the front with no
		// ports. (nginx_rtmp is a different tree and also stays.)
		$this->assertStringNotContainsString("MAIN_HOME . 'bin/nginx'", $this->removalLine($rCmd), 'bin/nginx is NOT removed (the Go front reads its ports from bin/nginx/conf/ports)');

		// It is INSIDE the serve-only completion block and AFTER readback — so the
		// install's PHP steps (installExtension, readback) run with PHP present and
		// only then is PHP removed. Removing it earlier would abort the install.
		$rReadbackPos = strpos($rCmd, 'LbInstallFlow::readback(');
		$rRemovePos = strpos($rCmd, "MAIN_HOME . 'console.php ' . MAIN_HOME . 'bin/php");
		$this->assertNotFalse($rReadbackPos);
		$this->assertNotFalse($rRemovePos);
		$this->assertGreaterThan($rReadbackPos, $rRemovePos, 'PHP is removed AFTER readback (and the other PHP-dependent install steps), not before');

		// installArchive / installDistributionBinaries do NOT strip PHP mid-install
		// (the earlier, broken placement): their signatures carry no serve-only flag.
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('public static function installArchive($rConn, callable $rRunSSH, string $rInstallFiles, string $rHash, int $rServerID, $db): bool', $rLb, 'installArchive keeps its original signature — it does not remove PHP mid-install');
		$this->assertStringNotContainsString("MAIN_HOME . 'bin/php ' . MAIN_HOME . 'bin/nginx'", $rLb, 'the PHP/http-nginx strip is NOT in LbInstallFlow (mid-install); it is at the install end in ServerInstallCommand');
	}

	public function testServiceSkipsAllPhpWhenTheInterpreterIsGone(): void {
		$rService = (string) file_get_contents(MAIN_HOME . 'service');
		$this->assertStringContainsString('php_available() {', $rService, 'a php_available helper gates PHP on the interpreter being present');
		$this->assertStringContainsString('[ -x $SCRIPT/bin/php/bin/php ]', $rService, 'php_available checks bin/php exists');
		$this->assertStringContainsString('if php_available; then', $rService, 'the PHP startup/cron batch is gated on php_available');
		$this->assertStringContainsString('&& ! serve_only_active && php_available; then', $rService, 'the boot cluster:apply is gated on php_available too');
		// nginx_rtmp always starts; http-nginx only on a non-serve-only node.
		$this->assertStringContainsString('$SCRIPT/bin/nginx_rtmp/sbin/nginx_rtmp', $rService, 'nginx_rtmp is started regardless');
		// start/reload recognise a running serve-only node (no http-nginx) by the Go
		// front, not only "nginx", so reload is not refused and starts do not duplicate.
		$this->assertStringContainsString("pgrep -u xc_vm 'nginx|xc_fanout'", $rService, "the running-check matches the Go front (xc_fanout), not only nginx");
		$this->assertStringNotContainsString('pgrep -u xc_vm nginx |', $rService, 'the nginx-only running-check is gone');
	}

	private function removalLine(string $rCmd): string {
		$rPos = strpos($rCmd, "MAIN_HOME . 'console.php ' . MAIN_HOME . 'bin/php");
		return $rPos === false ? '' : substr($rCmd, $rPos, 120);
	}
}

<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * LbInstallFlow::provisionOpensslExtra — an LB added with server:install must
 * end up with the MAIN's OPENSSL_EXTRA, or every token MAIN mints for a
 * redirect is rejected there. The MAIN's config/openssl_extra is shipped when
 * it holds a value; otherwise both sides run on the built-in default. Whatever
 * a previous MAIN left on a reused host is removed first.
 */
final class LbInstallFlowOpensslExtraTest extends TestCase {

	private string $rLocal;

	/** @var array<int,array<int,string>> */
	private array $rCalls = [];

	public static function setUpBeforeClass(): void {
		// The value init() gives it; the module loader's fallbacks resolve the same directory.
		if (!defined('CONFIG_PATH')) {
			define('CONFIG_PATH', MAIN_HOME . 'config/');
		}
	}

	protected function setUp(): void {
		$this->rLocal = sys_get_temp_dir() . '/xcvm_lb_openssl_extra_' . uniqid();
		$this->rCalls = [];
	}

	protected function tearDown(): void {
		@unlink($this->rLocal);
	}

	private function provision(bool $rSendOk = true): bool {
		$rRunSSH = function ($rConn, string $rCommand): array {
			$this->rCalls[] = ['run', $rCommand];
			return ['output' => '', 'error' => ''];
		};
		$rSendFileSSH = function ($rConn, string $rPath, string $rOutput, bool $rWarn = false) use ($rSendOk): bool {
			$this->rCalls[] = ['send', $rPath, $rOutput];
			return $rSendOk;
		};

		return LbInstallFlow::provisionOpensslExtra(null, $rRunSSH, $rSendFileSSH, $this->rLocal);
	}

	private function clear(): array {
		return ['run', 'sudo rm -f ' . CONFIG_PATH . 'openssl_extra ' . CONFIG_PATH . 'openssl_extra.prev'];
	}

	public function testShipsTheMainsValueAndLocksItDown(): void {
		file_put_contents($this->rLocal, 'main-extra');

		$this->assertTrue($this->provision());
		$this->assertSame([
			$this->clear(),
			['send', $this->rLocal, CONFIG_PATH . 'openssl_extra'],
			['run', 'sudo chmod 600 ' . CONFIG_PATH . 'openssl_extra'],
		], $this->rCalls);
	}

	public function testWithoutAValueOnTheMainOnlyAStaleFileIsRemoved(): void {
		$this->assertTrue($this->provision(), 'no file');
		$this->assertSame([$this->clear()], $this->rCalls);

		foreach (['', " \n"] as $rEmpty) {
			$this->rCalls = [];
			file_put_contents($this->rLocal, $rEmpty);
			$this->assertTrue($this->provision());
			$this->assertSame([$this->clear()], $this->rCalls);
		}
	}

	public function testAFailedUploadFailsTheStep(): void {
		file_put_contents($this->rLocal, 'main-extra');

		$this->assertFalse($this->provision(false));
		$this->assertNotContains(['run', 'sudo chmod 600 ' . CONFIG_PATH . 'openssl_extra'], $this->rCalls);
	}

	/**
	 * Shipped as root after config.enc and before config/ is handed to xc_vm, so
	 * FPM can read it; a failed upload marks the server errored (status 4).
	 * provisionConfig() needs the xcvm_core extension (XC_VM::config_pack), so
	 * this reads its source, located by reflection.
	 */
	public function testProvisionConfigShipsTheValueBeforeHandingConfigToXcVm(): void {
		$rMethod = new ReflectionMethod(LbInstallFlow::class, 'provisionConfig');
		$rLines = file((string) $rMethod->getFileName());
		$rBody = implode('', array_slice($rLines, $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1));

		$rGuard = preg_quote("if (!self::provisionOpensslExtra(\$rConn, \$rRunSSH, \$rSendFileSSH, CONFIG_PATH . 'openssl_extra')) {", '/');
		$rFail = preg_quote("\$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', \$rServerID);", '/');
		$this->assertSame(1, preg_match('/' . $rGuard . '\s*' . $rFail . '\s*echo [^;]+;\s*return false;\s*\}/', $rBody, $rMatch, PREG_OFFSET_CAPTURE), 'a failed upload fails the install');

		$rConfigEnc = strpos($rBody, "CONFIG_PATH . 'config.enc', false)");
		$rChown = strpos($rBody, "'sudo chown -R xc_vm:xc_vm '", $rConfigEnc ?: 0);
		$this->assertNotFalse($rConfigEnc);
		$this->assertNotFalse($rChown);
		$this->assertGreaterThan($rConfigEnc, $rMatch[0][1]);
		$this->assertLessThan($rChown, $rMatch[0][1]);
	}
}

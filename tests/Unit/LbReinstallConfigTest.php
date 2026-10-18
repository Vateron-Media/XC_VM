<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

final class LbReinstallConfigTest extends TestCase {
	/** Execute the actual remote initialization code with an isolated extension double. */
	private function initialize(string $rResult, bool $rInit = true): array {
		$rDir = sys_get_temp_dir() . '/xcvm-reinstall-' . bin2hex(random_bytes(6));
		mkdir($rDir, 0700);
		$rPath = $rDir . '/config.enc';
		file_put_contents($rPath, 'old-machine-encrypted-config');
		$rParams = ['hostname' => '192.0.2.1', 'database' => 'xc_vm', 'server_id' => 73, 'is_lb' => 1];
		$rDouble = 'class XC_VM { public static function config_init($data) { '
			. 'file_put_contents(' . var_export($rDir . '/input.json', true) . ', json_encode($data)); '
			. 'file_put_contents(' . var_export($rPath, true) . ', "new-local-config"); return ' . ($rInit ? 'true' : 'false') . '; } '
			. 'public static function config_server() { return ' . $rResult . '; }}';
		$rProcess = proc_open([PHP_BINARY, '-n', '-r', $rDouble . LbInstallFlow::apiConfigScript($rParams, $rPath)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOutput = stream_get_contents($rPipes[1]);
		$rErrors = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProcess);
		$rSaved = file_get_contents($rPath);
		$rData = json_decode(file_get_contents($rDir . '/input.json'), true);
		foreach (glob($rDir . '/*') as $rFile) {
			unlink($rFile);
		}
		rmdir($rDir);
		$this->assertSame('', $rErrors);
		return [$rCode, $rOutput, $rSaved, $rData];
	}

	public function testAReinstallEncryptsLocallyAndConfirmsTheTargetSlot(): void {
		[$rCode, $rOutput, $rSaved, $rData] = $this->initialize("['server_id' => 73, 'is_lb' => 1]");
		$this->assertSame(0, $rCode);
		$this->assertSame("CONFIG_READY:73\n", $rOutput);
		$this->assertSame('new-local-config', $rSaved);
		$this->assertSame(['server_id' => 73, 'is_lb' => 1], $rData['server']);
		$this->assertSame('', $rData['db']['username']);
		$this->assertSame('', $rData['db']['password']);
		$this->assertSame('', $rData['redis']['auth']);
	}

	public function testUnreadableWrongSlotAndWrongRoleRestoreThePreviousConfig(): void {
		foreach (['false', "['server_id' => 0, 'is_lb' => 1]", "['server_id' => 74, 'is_lb' => 1]", "['server_id' => 73, 'is_lb' => 0]"] as $rResult) {
			[$rCode, $rOutput, $rSaved] = $this->initialize($rResult);
			$this->assertSame(1, $rCode);
			$this->assertSame('', $rOutput);
			$this->assertSame('old-machine-encrypted-config', $rSaved);
		}
	}

	public function testARefusedInitializationRestoresThePreviousConfig(): void {
		[$rCode, $rOutput, $rSaved] = $this->initialize("['server_id' => 73, 'is_lb' => 1]", false);
		$this->assertSame(1, $rCode);
		$this->assertSame('', $rOutput);
		$this->assertSame('old-machine-encrypted-config', $rSaved);
	}
}

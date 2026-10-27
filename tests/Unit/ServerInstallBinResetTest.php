<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerInstallCommand;

/**
 * ServerInstallCommand::binResetCommand — a reinstall over a live node used to
 * `rm -rf bin/`: the node lost its issued certificates (and came back on the
 * self-signed server.crt) and the fanout daemon's config.json (#477).
 */
final class ServerInstallBinResetTest extends TestCase {

	private string $rBin = '';

	protected function setUp(): void {
		$this->rBin = sys_get_temp_dir() . '/xcvm-bin-reset-' . bin2hex(random_bytes(4)) . '/bin/';
		foreach (['certbot/config/live/node.example', 'xc_fanout', 'php/bin', 'nginx/conf'] as $rDir) {
			mkdir($this->rBin . $rDir, 0700, true);
		}
		foreach (['certbot/config/live/node.example/fullchain.pem', 'xc_fanout/config.json', 'xc_fanout/xc_fanout', 'xc_fanout/run.sh', 'php/bin/php', 'nginx/conf/nginx.conf', 'redis-server'] as $rFile) {
			file_put_contents($this->rBin . $rFile, 'x');
		}
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg(dirname($this->rBin)));
	}

	public function testKeepsTheCertificatesAndTheDaemonsConfigOnly(): void {
		exec('sh -c ' . escapeshellarg(ServerInstallCommand::binResetCommand($this->rBin)), $rOut, $rCode);
		$this->assertSame(0, $rCode);
		$this->assertFileExists($this->rBin . 'certbot/config/live/node.example/fullchain.pem');
		$this->assertFileExists($this->rBin . 'xc_fanout/config.json');
		foreach (['xc_fanout/xc_fanout', 'xc_fanout/run.sh', 'php', 'nginx', 'redis-server'] as $rGone) {
			$this->assertFileDoesNotExist($this->rBin . $rGone, $rGone . ' is replaced by the install');
		}
	}

	public function testAFreshNodeWithoutBinIsFine(): void {
		exec('sh -c ' . escapeshellarg(ServerInstallCommand::binResetCommand(dirname($this->rBin) . '/none/')), $rOut, $rCode);
		$this->assertSame(0, $rCode);
	}
}

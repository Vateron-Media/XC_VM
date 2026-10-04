<?php

use PHPUnit\Framework\TestCase;

/**
 * A panel update, then the binaries update it queues, must not take MAIN's
 * cluster API down: the runtime bundle's PHP shipped an older xcvm_core
 * (2.0.0, no cluster API) that replaced the one `console.php xcvm_core` had
 * installed (2.3.1). The panel update also overwrote the installed bundle's
 * release record with the repository's, so every panel update reinstalled
 * the bundle.
 */
final class BinariesUpdateKeepsCoreTest extends TestCase {
	private const ROOT = MAIN_HOME;

	private string $rDir = '';

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-bin-' . bin2hex(random_bytes(4));
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Run update_binaries.sh's keep step over $rDir/target and $rDir/stage. */
	private function keep(): void {
		$rScript = (string) file_get_contents(self::ROOT . 'bin/install/update_binaries.sh');
		$this->assertSame(1, preg_match('/^# xcvm_core is installed and kept current.*?^done$/ms', $rScript, $rStep), 'the keep step');
		$rEnv = 'TARGET_BIN_DIR=' . escapeshellarg($this->rDir . '/target') . ' STAGE_DIR=' . escapeshellarg($this->rDir . '/stage');
		exec($rEnv . ' bash -euo pipefail -c ' . escapeshellarg($rStep[0]) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
	}

	private function put(string $rPath, string $rBody): void {
		@mkdir(dirname($this->rDir . '/' . $rPath), 0777, true);
		file_put_contents($this->rDir . '/' . $rPath, $rBody);
	}

	public function testTheInstalledCoreSurvivesABundleForTheSameAbi(): void {
		$rExt = 'php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so';
		$this->put('target/' . $rExt, 'installed 2.3.1');
		$this->put('stage/' . $rExt, 'bundled 2.0.0');
		$this->keep();
		$this->assertSame('installed 2.3.1', file_get_contents($this->rDir . '/stage/' . $rExt));
	}

	public function testABundleForAnotherAbiKeepsItsOwnCore(): void {
		$this->put('target/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so', 'installed for 8.1');
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20240924/xcvm_core.so', 'bundled for 8.4');
		$this->keep();
		$this->assertSame('bundled for 8.4', file_get_contents($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20240924/xcvm_core.so'));
		$this->assertFileDoesNotExist($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so');
	}

	public function testNoInstalledCoreChangesNothing(): void {
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so', 'bundled');
		@mkdir($this->rDir . '/target/php', 0777, true);
		$this->keep();
		$this->assertSame('bundled', file_get_contents($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20210902/xcvm_core.so'));
	}

	public function testAPanelUpdateLeavesTheBundlesReleaseRecord(): void {
		$rUpdate = (string) file_get_contents(self::ROOT . 'update');
		$this->assertSame(1, preg_match('/^UPDATE_EXCLUDE_DIRS = \[(.*?)^\]/ms', $rUpdate, $rList));
		$this->assertStringContainsString('"bin/bin_version.json"', $rList[1]);
	}
	/**
	 * A release without this distribution's asset (an empty one, a 404) must
	 * not take the panel down: the updater stopped the service before it
	 * downloaded, failed, started it again, and the start's `status` queued
	 * the update once more, so the panel went down every minute. Now it stops
	 * the service only once the new binaries are downloaded and staged.
	 */
	public function testAReleaseWithoutTheAssetNeverStopsTheService(): void {
		if (posix_geteuid() !== 0) {
			$this->markTestSkipped('the updater runs as root');
		}
		$rFake = $this->rDir . '/fake';
		@mkdir($rFake, 0777, true);
		// systemctl logs what it is asked; curl answers every URL with a 404 (exit 22).
		file_put_contents($rFake . '/systemctl', "#!/bin/sh\necho \"$@\" >> " . escapeshellarg($this->rDir . '/systemctl.log') . "\n");
		file_put_contents($rFake . '/curl', "#!/bin/sh\necho 'curl: (22) The requested URL returned error: 404' >&2\nexit 22\n");
		chmod($rFake . '/systemctl', 0755);
		chmod($rFake . '/curl', 0755);
		@mkdir($this->rDir . '/target', 0777, true);
		$rCmd = 'PATH=' . escapeshellarg($rFake . ':' . getenv('PATH')) . ' bash ' . escapeshellarg(self::ROOT . 'bin/install/update_binaries.sh')
			. ' Vateron-Media XC_VM_Binaries ' . escapeshellarg($this->rDir . '/target') . ' 01102026 2>&1';
		exec($rCmd, $rOut, $rCode);
		$rText = implode("\n", $rOut);
		$this->assertNotSame(0, $rCode, $rText);
		$this->assertStringContainsString('404', $rText);
		$this->assertFileDoesNotExist($this->rDir . '/systemctl.log', 'the service was neither stopped nor started: ' . $rText);
	}
	/**
	 * A panel's own updater script is never refreshed by a panel update
	 * (bin/install is kept), so `console.php binaries` checks first that the
	 * release has this distribution's bundle, as the script names it.
	 */
	public function testTheBundleIsNamedAsTheUpdaterNamesIt(): void {
		$this->assertSame('ubuntu_22.tar.gz', \XcVm\Core\Updates\ReleaseAsset::bundleFor('ubuntu', '22.04'));
		$this->assertSame('debian_12.tar.gz', \XcVm\Core\Updates\ReleaseAsset::bundleFor('debian', '12'));
		// Not supported: no bundle (Ubuntu 18, Debian 11, the RHEL family).
		$this->assertNull(\XcVm\Core\Updates\ReleaseAsset::bundleFor('rocky', '9.4'));
		$this->assertNull(\XcVm\Core\Updates\ReleaseAsset::bundleFor('ubuntu', '18.04'));
		$this->assertNull(\XcVm\Core\Updates\ReleaseAsset::bundleFor('debian', '11'));
		$this->assertNull(\XcVm\Core\Updates\ReleaseAsset::bundleFor('ubuntu', '16.04'));
		$this->assertNull(\XcVm\Core\Updates\ReleaseAsset::bundleFor('arch', ''));
		$this->assertFalse(\XcVm\Core\Updates\ReleaseAsset::exists('http://127.0.0.1:9/none.tar.gz'), 'nothing there: no update');
		$rSource = (string) file_get_contents(self::ROOT . 'Cli/Commands/BinariesCommand.php');
		$this->assertLessThan(strpos($rSource, 'update_binaries.sh'), strpos($rSource, 'ReleaseAsset::exists('), 'checked before the updater runs');
	}
}

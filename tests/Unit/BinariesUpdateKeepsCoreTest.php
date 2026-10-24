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
		$rExt = 'php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so';
		$this->put('target/' . $rExt, 'installed 2.3.1');
		$this->put('stage/' . $rExt, 'bundled 2.0.0');
		$this->keep();
		$this->assertSame('installed 2.3.1', file_get_contents($this->rDir . '/stage/' . $rExt));
	}

	public function testABundleForAnotherAbiKeepsItsOwnCore(): void {
		$this->put('target/php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so', 'installed for 8.3');
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20240924/xcvm_core.so', 'bundled for 8.4');
		$this->keep();
		$this->assertSame('bundled for 8.4', file_get_contents($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20240924/xcvm_core.so'));
		$this->assertFileDoesNotExist($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so');
	}

	public function testNoInstalledCoreChangesNothing(): void {
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so', 'bundled');
		@mkdir($this->rDir . '/target/php', 0777, true);
		$this->keep();
		$this->assertSame('bundled', file_get_contents($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so'));
	}

	/**
	 * A bundle on a new PHP minor (8.3 -> 8.4) brings no xcvm_core, and the
	 * new PHP cannot boot console.php to fetch one: the updater fetches the
	 * build for the bundle's PHP into the staged tree, before the service stops.
	 */
	public function testABundleWithoutCoreFetchesItsBuildFirst(): void {
		$rScript = (string) file_get_contents(self::ROOT . 'bin/install/update_binaries.sh');
		$this->assertSame(1, preg_match('/^# A bundle on another ABI.*?^done$/ms', $rScript, $rStep), 'the fetch step');
		$this->assertLessThan(strpos($rScript, 'systemctl stop'), strpos($rScript, $rStep[0]), 'fetched before the service stops');
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20230831/xcvm_core.so', 'kept');
		@mkdir($this->rDir . '/stage/php/lib/php/extensions/no-debug-non-zts-20240924', 0777, true);
		$this->put('inst/install_xcvm_core.sh', 'echo "$@" >> ' . escapeshellarg($this->rDir . '/asked') . "\n");
		$rEnv = 'STAGE_DIR=' . escapeshellarg($this->rDir . '/stage') . ' SCRIPT_DIR=' . escapeshellarg($this->rDir . '/inst') . ' OWNER=o REPO=r';
		exec($rEnv . ' bash -euo pipefail -c ' . escapeshellarg($rStep[0]) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$this->assertSame('o r ' . $this->rDir . '/stage ' . $this->rDir . "/stage/php/lib/php/extensions/no-debug-non-zts-20240924\n", file_get_contents($this->rDir . '/asked'), 'only the ABI without one');

		$this->put('inst/install_xcvm_core.sh', "exit 1\n");
		exec($rEnv . ' bash -euo pipefail -c ' . escapeshellarg($rStep[0]) . ' 2>&1', $rOut, $rCode);
		$this->assertNotSame(0, $rCode, 'no build that loads: the update stops here');
	}

	/**
	 * A panel update never refreshes php.ini, which names the ionCube loader of
	 * the PHP it was written for: on a bundle of a new PHP minor it loaded the
	 * old one, absent there, and ionCube-encoded modules stopped running.
	 */
	public function testPhpIniLoadsTheBundlesIoncubeLoader(): void {
		$rScript = (string) file_get_contents(self::ROOT . 'bin/install/update_binaries.sh');
		$this->assertSame(1, preg_match('/^# php\.ini is kept across.*?^fi$/ms', $rScript, $rStep), 'the loader step');
		$this->assertLessThan(strpos($rScript, 'systemctl stop'), strpos($rScript, $rStep[0]), 'before the service stops');
		$rIni = "memory_limit=1G\nzend_extension=ioncube_loader_lin_8.3.so\nzend_extension=opcache.so\n";
		$this->put('target/php/lib/php.ini', $rIni);
		$this->put('stage/php/lib/php/extensions/no-debug-non-zts-20240924/ioncube_loader_lin_8.4.so', '');
		$rEnv = 'TARGET_BIN_DIR=' . escapeshellarg($this->rDir . '/target') . ' STAGE_DIR=' . escapeshellarg($this->rDir . '/stage');
		exec($rEnv . ' bash -euo pipefail -c ' . escapeshellarg($rStep[0]) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$this->assertSame(str_replace('8.3', '8.4', $rIni), file_get_contents($this->rDir . '/stage/php/lib/php.ini'));
		$this->assertSame($rIni, file_get_contents($this->rDir . '/target/php/lib/php.ini'), 'the running one stays until the swap');

		// A bundle without a loader leaves php.ini alone.
		exec('rm -rf ' . escapeshellarg($this->rDir . '/stage'));
		@mkdir($this->rDir . '/stage/php', 0777, true);
		exec($rEnv . ' bash -euo pipefail -c ' . escapeshellarg($rStep[0]) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$this->assertFileDoesNotExist($this->rDir . '/stage/php/lib/php.ini');
	}

	/**
	 * A load balancer kept the PHP it was installed with: the LB build stripped
	 * bin/install, update_binaries.sh among it, and nothing ran `binaries`
	 * there. It ships the two updaters now (not MAIN's install schema), and
	 * cron:root_signals polls `binaries` on every node but MAIN.
	 */
	public function testALoadBalancerUpdatesItsOwnBundle(): void {
		$rMake = static fn(string $rVar): array => preg_split('/\s+/', trim((string) shell_exec('make -s -C ' . escapeshellarg(dirname(self::ROOT)) . ' print-' . $rVar)));
		$rDirs = $rMake('LB_DIRS_TO_REMOVE');
		$rFiles = $rMake('LB_FILES_TO_REMOVE');
		$this->assertContains('bin/redis', $rDirs, 'make printed the list');
		$this->assertNotContains('bin/install', $rDirs);
		$this->assertNotContains('bin/install/update_binaries.sh', $rFiles);
		$this->assertNotContains('bin/install/install_xcvm_core.sh', $rFiles);
		$this->assertContains('bin/install/database.sql', $rFiles);

		$rCron = (string) file_get_contents(self::ROOT . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertSame(1, preg_match('/if \(!NodeRole::isMain\(\) && [^\n]*\$rBinariesStamp[^\n]*\n[^\n]*\n\s*ProcessRunner::start\(\[PHP_BIN, MAIN_HOME \. \'console\.php\', \'binaries\'\]\);/', $rCron), 'polled off MAIN');
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

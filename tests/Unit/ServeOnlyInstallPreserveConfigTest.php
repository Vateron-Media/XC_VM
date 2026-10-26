<?php

use PHPUnit\Framework\TestCase;

/**
 * A reinstall re-extracts the panel archive over MAIN_HOME; its shipped defaults
 * reset the node's tuned fanout config (bin/xc_fanout/config.json) and empty its
 * certbot config (bin/certbot/config) — the latter dropping the real certificate
 * so the front comes up on a self-signed one (XC_VM #477). installArchive must
 * stash both across the extraction and restore them, so a reinstall keeps them.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyInstallPreserveConfigTest extends TestCase {
	private function installArchiveBody(): string {
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$rStart = strpos($rLb, 'function installArchive(');
		$this->assertNotFalse($rStart, 'installArchive exists');
		// To the next function declaration (the body of installArchive only).
		$rEnd = strpos($rLb, "\n\tpublic static function", $rStart + 1);
		return substr($rLb, $rStart, ($rEnd === false ? strlen($rLb) : $rEnd) - $rStart);
	}

	public function testReinstallPreservesFanoutConfigAndCertbotConfig(): void {
		$rBody = $this->installArchiveBody();

		// Both paths are stashed and restored.
		$this->assertStringContainsString("bin/xc_fanout/config.json", $rBody, 'the fanout config is handled');
		$this->assertStringContainsString("bin/certbot/config", $rBody, 'the certbot config is handled');

		$rKeep = strpos($rBody, 'mkdir -p "');            // the stash step
		$rTar = strpos($rBody, 'tar -zxvf ');             // the extraction
		$rRestore = strpos($rBody, 'cp -a "');            // first restore cp after the stash
		$this->assertNotFalse($rKeep, 'a stash step is present');
		$this->assertNotFalse($rTar, 'the tar extraction is present');

		// Stash BEFORE the tar, restore AFTER it.
		$this->assertLessThan($rTar, $rKeep, 'the config is stashed before the archive is extracted over it');
		// The restore cp is after the tar (a restore cp exists past the extraction).
		$rRestoreAfter = strpos($rBody, 'config.json"; }', $rTar);
		$this->assertNotFalse($rRestoreAfter, 'the fanout config is restored after the extraction');

		// Restore is guarded (only if the stash exists), so a FIRST install — which
		// has neither file — keeps the archive's defaults.
		$this->assertStringContainsString('[ -f "', $rBody, 'restore is guarded on the stash existing');
	}

	public function testRestoreHappensBeforeTheWorkDirIsRemoved(): void {
		$rBody = $this->installArchiveBody();
		$rTar = strpos($rBody, 'tar -zxvf ');
		$rRestore = strpos($rBody, 'bin/xc_fanout/config.json"; }');
		// The work-dir cleanup that runs AFTER the extraction (not the early
		// md5-failure cleanup), which also removes the stash under $rDir.
		$rRmDir = strpos($rBody, 'rm -rf \' . $rDir', $rTar);
		$this->assertNotFalse($rRestore, 'restore present');
		$this->assertNotFalse($rRmDir, 'the post-extraction work-dir cleanup is present');
		$this->assertLessThan($rRmDir, $rRestore, 'restore runs while the stash (under $rDir) still exists');
	}
}

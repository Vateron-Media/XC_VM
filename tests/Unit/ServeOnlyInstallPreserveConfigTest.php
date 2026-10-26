<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * A reinstall re-extracts the panel archive over MAIN_HOME after prepareInstallRoot
 * has wiped BIN_PATH; the archive's shipped defaults reset the node's tuned fanout
 * config (bin/xc_fanout/config.json), empty its certbot config (bin/certbot/config,
 * dropping the real certificate) and replace the front's bin/nginx/conf/server.{crt,key}
 * with self-signed defaults (XC_VM #477). The install must stash all of these BEFORE
 * anything is destroyed and restore them AFTER extraction.
 *
 * These exercise the real stash/restore shell scripts against a temp MAIN_HOME, so a
 * broken command (not just a reordered source line) is caught.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyInstallPreserveConfigTest extends TestCase {
	private string $base;
	private string $home;
	private string $keep;

	protected function setUp(): void {
		$this->base = sys_get_temp_dir() . '/preserve-' . bin2hex(random_bytes(6));
		$this->home = $this->base . '/home/';
		$this->keep = $this->base . '/home/.preserve';
		mkdir($this->home, 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->base));
	}

	/** Run a shell script the way the installer does (sh -c); return its exit code. */
	private function sh(string $rScript): int {
		$rProc = proc_open(['/bin/sh', '-c', $rScript], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		stream_get_contents($rPipes[1]);
		stream_get_contents($rPipes[2]);
		return proc_close($rProc);
	}

	private function write(string $rRel, string $rContents): void {
		$rPath = $this->home . 'bin/' . $rRel;
		@mkdir(dirname($rPath), 0777, true);
		file_put_contents($rPath, $rContents);
	}

	private function read(string $rRel): string {
		return (string) file_get_contents($this->home . 'bin/' . $rRel);
	}

	/** prepareInstallRoot wipes BIN_PATH, then the archive lays down its defaults. */
	private function wipeBinAndLayDefaults(): void {
		exec('rm -rf ' . escapeshellarg($this->home . 'bin'));
		$this->write('xc_fanout/config.json', 'DEFAULT');
		$this->write('certbot/config/.gitkeep', '');
		$this->write('nginx/conf/server.crt', 'SELFSIGNED');
		$this->write('nginx/conf/server.key', 'SELFSIGNEDKEY');
	}

	public function testReinstallPreservesTunedConfigAndRealCert(): void {
		$this->write('xc_fanout/config.json', 'TUNED');
		$this->write('certbot/config/live/lb/fullchain.pem', 'REALCERT');
		$this->write('nginx/conf/server.crt', 'REALCERT');
		$this->write('nginx/conf/server.key', 'REALKEY');

		$this->assertSame(0, $this->sh(LbInstallFlow::stashConfigScript($this->home, $this->keep)), 'stash before the wipe');
		$this->wipeBinAndLayDefaults();
		$this->assertSame(0, $this->sh(LbInstallFlow::restoreConfigScript($this->home, $this->keep)), 'restore after extraction');

		$this->assertSame('TUNED', $this->read('xc_fanout/config.json'), 'fanout tuning survives');
		$this->assertSame('REALCERT', $this->read('certbot/config/live/lb/fullchain.pem'), 'the certbot cert survives');
		$this->assertSame('REALCERT', $this->read('nginx/conf/server.crt'), 'the front cert survives (no self-signed revert)');
		$this->assertSame('REALKEY', $this->read('nginx/conf/server.key'), 'the front key survives');
		$this->assertDirectoryDoesNotExist($this->keep, 'the snapshot is consumed after restore');
	}

	public function testFirstInstallKeepsArchiveDefaults(): void {
		// Fresh node: no bin/ yet, nothing to preserve.
		$this->assertSame(0, $this->sh(LbInstallFlow::stashConfigScript($this->home, $this->keep)), 'stash is a no-op on a fresh node');
		$this->assertDirectoryDoesNotExist($this->keep, 'a fresh node creates no snapshot');
		$this->wipeBinAndLayDefaults();
		$this->assertSame(0, $this->sh(LbInstallFlow::restoreConfigScript($this->home, $this->keep)), 'restore is a no-op');

		$this->assertSame('DEFAULT', $this->read('xc_fanout/config.json'), 'the archive default stands');
		$this->assertSame('SELFSIGNED', $this->read('nginx/conf/server.crt'), 'the self-signed default stands');
	}

	public function testStashSurvivesAnInterruptedRetry(): void {
		// The install stashes, extracts, then is interrupted before restore. The
		// retry must NOT re-stash the archive defaults over the good snapshot.
		$this->write('xc_fanout/config.json', 'TUNED');
		$this->assertSame(0, $this->sh(LbInstallFlow::stashConfigScript($this->home, $this->keep)));
		$this->wipeBinAndLayDefaults(); // interrupted here: defaults are live, snapshot still on disk

		$this->assertSame(0, $this->sh(LbInstallFlow::stashConfigScript($this->home, $this->keep)), 'retry keeps the first snapshot');
		$this->wipeBinAndLayDefaults();
		$this->assertSame(0, $this->sh(LbInstallFlow::restoreConfigScript($this->home, $this->keep)));

		$this->assertSame('TUNED', $this->read('xc_fanout/config.json'), 'the original tuned config survives the retry');
	}

	public function testStashIsWiredBeforeTheRootIsWipedAndRestoreAfter(): void {
		// The behavioral tests above prove the scripts; this pins their placement in
		// the install sequence — the regression that made the stash a no-op was
		// stashing AFTER prepareInstallRoot had already wiped BIN_PATH (XC_VM #477).
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rStash = strpos($rSrc, 'stashNodeConfig(');
		$rPrepare = strpos($rSrc, '$this->prepareInstallRoot(');
		$rInstall = strpos($rSrc, 'LbInstallFlow::installArchive(');
		$rRestore = strpos($rSrc, 'restoreNodeConfig(');
		$this->assertNotFalse($rStash, 'the stash is wired into the install');
		$this->assertNotFalse($rRestore, 'the restore is wired into the install');
		$this->assertLessThan($rPrepare, $rStash, 'config is stashed before prepareInstallRoot wipes BIN_PATH');
		$this->assertGreaterThan($rInstall, $rRestore, 'config is restored after the archive is extracted');
	}

	public function testStashFailsLoudlyInsteadOfMaskingACopyError(): void {
		// A real stash failure (here: the keep path is blocked by a regular file, so
		// mkdir -p cannot create it) must exit non-zero — uid-independent, unlike a
		// chmod the test's own root would bypass — so the installer can stop before
		// prepareInstallRoot destroys anything, rather than `|| true` swallowing it.
		$this->write('xc_fanout/config.json', 'TUNED');
		file_put_contents($this->keep, 'not a directory'); // dirname($keep) == $home, already created in setUp

		$this->assertNotSame(0, $this->sh(LbInstallFlow::stashConfigScript($this->home, $this->keep)), 'a copy/mkdir failure aborts the stash');
	}
}

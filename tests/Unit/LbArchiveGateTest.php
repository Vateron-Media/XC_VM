<?php

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * tools/ci/verify-lb-archive.sh (make verify-lb-archive) guards the Makefile's
 * LB lists. It must fail on an entry that matches no tracked path (a strip rule
 * that became a no-op after a rename, as the old www/* entries did), on an entry
 * of the wrong kind for its list, on privileged code left in the manifest, on a
 * stripped file that lb_configs/nginx.conf still routes to, and on an LB
 * deleted-files list that would delete a shipped file on update, and on a
 * committed deleted_files.txt that names a tracked file. The last test
 * pins what `make lb_delete_files_list` puts in that list.
 *
 * Each case overrides Makefile variables through MAKEFLAGS, as `make VAR=...`
 * on the command line would, and runs the real script against the checkout.
 * A case injects all its entries into one run (each gate run takes about a
 * second) and asserts that every entry produces its own finding line.
 */
#[Group('skip-on-panel')]
final class LbArchiveGateTest extends TestCase {

	private string $rRoot;

	/** @var string[] Temporary directories removed in tearDown(). */
	private array $rTempDirs = [];

	protected function setUp(): void {
		// The gate reads the Makefile and `git ls-files`, so it only runs in a
		// git checkout of the repo (not on a flat /home/xc_vm deployment).
		$this->rRoot = dirname(__DIR__, 2);
		if (!is_file($this->rRoot . '/Makefile') || !is_file($this->rRoot . '/tools/ci/verify-lb-archive.sh') || !file_exists($this->rRoot . '/.git')) {
			$this->markTestSkipped('the LB archive gate runs only in a git checkout of the repo');
		}
	}

	protected function tearDown(): void {
		foreach ($this->rTempDirs as $rDir) {
			$this->removeDir($rDir);
		}
	}

	public function testCurrentListsPass(): void {
		[$rCode, $rOutput] = $this->runGate([]);

		$this->assertSame(0, $rCode, $rOutput);
		$this->assertStringStartsWith('OK: ', $rOutput);
		$this->assertDoesNotMatchRegularExpression('/^(STALE|WRONG-LIST|LEAK|MISSING|DELETES-SHIPPED|FAIL):/m', $rOutput);
	}

	public function testStaleEntriesFail(): void {
		$rStale = [
			'LB_DIRS'            => 'www',
			'LB_ROOT_FILES'      => 'index.php',
			'LB_DIRS_TO_REMOVE'  => 'Domain/Auth',
			'LB_FILES_TO_REMOVE' => 'www/admin/api.php',
		];
		$rOverrides = [];
		foreach ($rStale as $rList => $rEntry) {
			$rOverrides[$rList] = array_merge($this->makeList($rList), [$rEntry]);
		}
		[$rCode, $rOutput] = $this->runGate($rOverrides);

		$this->assertSame(1, $rCode, $rOutput);
		foreach ($rStale as $rList => $rEntry) {
			$this->assertStringContainsString("STALE: '" . $rEntry . "' in " . $rList . ' ', $rOutput);
		}
	}

	public function testEntriesOfTheWrongKindFail(): void {
		// rm -rf strips a file named in LB_DIRS_TO_REMOVE, while rm -f and cp skip a
		// directory named in LB_FILES_TO_REMOVE or LB_ROOT_FILES.
		$rWrong = [
			'LB_DIRS'            => ['bootstrap.php', 'is a file'],
			'LB_ROOT_FILES'      => ['Cli', 'is a directory'],
			'LB_DIRS_TO_REMOVE'  => ['Public/stream/live.php', 'is a file'],
			'LB_FILES_TO_REMOVE' => ['Domain/Epg', 'is a directory'],
		];
		$rOverrides = [];
		foreach ($rWrong as $rList => [$rEntry]) {
			$rOverrides[$rList] = array_merge($this->makeList($rList), [$rEntry]);
		}
		[$rCode, $rOutput] = $this->runGate($rOverrides);

		$this->assertSame(1, $rCode, $rOutput);
		foreach ($rWrong as $rList => [$rEntry, $rKind]) {
			$this->assertStringContainsString("WRONG-LIST: '" . $rEntry . "' in " . $rList . ' ' . $rKind, $rOutput);
		}
		// The manifest models rm -rf, so the stripped routed file is reported too.
		$this->assertMatchesRegularExpression('#^MISSING: .*\'Public/stream/live\.php\'#m', $rOutput);
	}

	public function testPrivilegedCodeLeftInTheManifestFails(): void {
		$rDirs = ['Public/Controllers/PlayerV2'];
		$rFiles = ['Public/admin/api.php', 'Public/admin/proxy_api.php', 'Public/stream/auth.php', 'Public/stream/probe.php', 'Cli/Commands/DbMigrateCommand.php'];
		[$rCode, $rOutput] = $this->runGate([
			'LB_DIRS_TO_REMOVE'  => array_values(array_diff($this->makeList('LB_DIRS_TO_REMOVE'), $rDirs)),
			'LB_FILES_TO_REMOVE' => array_values(array_diff($this->makeList('LB_FILES_TO_REMOVE'), $rFiles)),
		]);

		$this->assertSame(1, $rCode, $rOutput);
		foreach (array_merge($rDirs, $rFiles) as $rPath) {
			$this->assertStringContainsString("LEAK: '" . $rPath . "'", $rOutput);
		}
	}

	public function testStrippingARoutedFileFails(): void {
		$rRouted = [
			'SCRIPT_FILENAME'           => 'Public/index.php',
			'/stream/ gateway handler'  => 'Public/stream/live.php',
			'/admin/ gateway handler'   => 'Public/admin/vod.php',
			'XC_API internal (/api)'    => 'Public/Controllers/Api/InternalApiController.php',
			'XC_API $1 (/api/<name>)'   => 'Public/Controllers/Api/PlayerApiController.php',
			'XC_API shared base class'  => 'Public/Controllers/Api/BaseApiController.php',
			'XC_API request bootstrap'  => 'Infrastructure/Bootstrap/StreamingRequestBootstrap.php',
		];
		[$rCode, $rOutput] = $this->runGate(['LB_FILES_TO_REMOVE' => array_merge($this->makeList('LB_FILES_TO_REMOVE'), array_values($rRouted))]);

		$this->assertSame(1, $rCode, $rOutput);
		foreach ($rRouted as $rRoute => $rPath) {
			$this->assertMatchesRegularExpression('#^MISSING: .*\'' . preg_quote($rPath, '#') . '\'#m', $rOutput, $rRoute);
		}
	}

	public function testUnparseableNginxConfigFailsClosed(): void {
		$rConf = (string) file_get_contents($this->rRoot . '/lb_configs/nginx.conf');
		$rBroken = str_replace(
			['SCRIPT_FILENAME /home/xc_vm/', 'location ~ ^/admin/(', 'fastcgi_param XC_API internal;'],
			['SCRIPT_FILENAME /srv/xc_vm/', 'location ~ ^/panel/(', 'fastcgi_param XC_API bogus;'],
			$rConf,
			$rCount
		);
		$this->assertGreaterThanOrEqual(3, $rCount, 'lb_configs/nginx.conf no longer has the lines this test rewrites');
		$rConfigDir = $this->tempDir();
		file_put_contents($rConfigDir . '/nginx.conf', $rBroken);

		[$rCode, $rOutput] = $this->runGate(['CONFIG_DIR' => [$rConfigDir]]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString('MISSING: no SCRIPT_FILENAME /home/xc_vm/', $rOutput);
		$this->assertStringContainsString('MISSING: no \'location ~ ^/admin/(...)$\' gateway', $rOutput);
		$this->assertStringContainsString("sets XC_API 'bogus', which src/Public/index.php does not map", $rOutput);
	}

	public function testDeletingAShippedFileOnUpdateFails(): void {
		// A git deletion whose path is tracked again: the LB update would unlink it.
		$rMainDir = $this->mainDirWithDeletedFiles(['Public/stream/live.php', 'console.php']);

		[$rCode, $rOutput] = $this->runGate(['MAIN_DIR' => [$rMainDir]]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString("DELETES-SHIPPED: the LB update would delete 'Public/stream/live.php'", $rOutput);
		$this->assertStringContainsString("DELETES-SHIPPED: the LB update would delete 'console.php'", $rOutput);
	}

	public function testListingATrackedFileForDeletionFails(): void {
		// MigrationRunner::runFileCleanup() applies the committed list on MAIN and on
		// LBs after the new tree is unpacked, so a restored file (listed by an older
		// generate_deleted_files run) must not stay in it. ProxyCommand.php was deleted
		// and later restored; www/old.php is not tracked at all.
		$rMainDir = $this->mainDirWithDeletedFiles(['Cli/Commands/ProxyCommand.php', 'www/old.php']);

		[$rCode, $rOutput] = $this->runGate(['MAIN_DIR' => [$rMainDir]]);

		$this->assertSame(1, $rCode, $rOutput);
		$this->assertStringContainsString("deleted_files.txt lists 'Cli/Commands/ProxyCommand.php', which is tracked in src/", $rOutput);
		$this->assertStringNotContainsString("lists 'www/old.php'", $rOutput);
	}

	public function testDeletedFilesListHoldsLbGitDeletionsAndStrippedCode(): void {
		$rMainDir = $this->mainDirWithDeletedFiles(['www/old.php', 'resources/data/a.txt', 'Modules/Foo/x.php', 'bin/y']);
		$rTempDir = $this->tempDir();

		[$rCode, $rOutput] = $this->runProcess(['make', '-s', 'lb_delete_files_list', 'TEMP_DIR=' . $rTempDir, 'MAIN_DIR=' . $rMainDir], []);

		$this->assertSame(0, $rCode, $rOutput);
		$rList = file($rTempDir . '/migrations/deleted_files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertIsArray($rList);

		// Git deletions under LB_DIRS or a retired LB tree; never a module path.
		foreach (['www/old.php', 'resources/data/a.txt', 'bin/y'] as $rPath) {
			$this->assertContains($rPath, $rList);
		}
		$this->assertNotContains('Modules/Foo/x.php', $rList);

		// Every stripped code file, including whole stripped trees...
		foreach ($this->makeList('LB_FILES_TO_REMOVE') as $rPath) {
			if (preg_match('#^(Cli|Core|Domain|Infrastructure|Public|Streaming)/#', $rPath)) {
				$this->assertContains($rPath, $rList);
			}
		}
		$this->assertContains('Public/Controllers/PlayerV2/BasePlayerV2Controller.php', $rList);
		$this->assertContains('Domain/Device/MagService.php', $rList);

		// ...but no stripped runtime file, nothing under LB_KEEP_ON_UPDATE and no
		// shipped file.
		$this->assertSame([], preg_grep('#^(Domain/User|bin/install|bin/redis)/#', $rList));
		foreach (['config/rclone.conf', 'bin/nginx/conf/gzip.conf', 'Public/stream/live.php', 'Public/stream/rtmp.php', 'Public/index.php'] as $rPath) {
			$this->assertNotContains($rPath, $rList);
		}
	}

	/** @return string[] The current value of a Makefile list. */
	public function testEntriesAreReadAsTheUpdaterReadsThem(): void {
		// runFileCleanup() trims each line: a stray space, a tab, a CRLF or a last
		// line without a newline still names the file it deletes.
		$rDir = $this->tempDir();
		mkdir($rDir . '/migrations');
		$rFiles = ['Public/Controllers/Admin/ActiveCodeController.php', 'Public/Controllers/Admin/ActiveCodeDetailsController.php', 'Public/Controllers/Admin/ActiveCodesBatchController.php', 'Public/Controllers/Admin/ActiveCodesController.php'];
		file_put_contents($rDir . '/migrations/deleted_files.txt', $rFiles[0] . " \n\t" . $rFiles[1] . "\nwww/old.php\r\n" . $rFiles[2] . "\r\n" . $rFiles[3]);

		[$rCode, $rOutput] = $this->runGate(['MAIN_DIR' => [$rDir]]);

		$this->assertSame(1, $rCode, $rOutput);
		foreach ($rFiles as $rFile) {
			$this->assertStringContainsString("deleted_files.txt lists '" . $rFile . "', which is tracked in src/", $rOutput);
		}
		$this->assertStringNotContainsString("lists 'www/old.php'", $rOutput);
	}

	private function makeList(string $rName): array {
		[$rCode, $rOutput] = $this->runProcess(['make', '-s', 'print-' . $rName], []);
		$this->assertSame(0, $rCode, $rOutput);

		return preg_split('/\s+/', trim($rOutput), -1, PREG_SPLIT_NO_EMPTY);
	}

	/**
	 * A MAIN_DIR stand-in whose migrations/deleted_files.txt lists $rPaths.
	 *
	 * @param string[] $rPaths
	 */
	private function mainDirWithDeletedFiles(array $rPaths): string {
		$rDir = $this->tempDir();
		mkdir($rDir . '/migrations');
		file_put_contents($rDir . '/migrations/deleted_files.txt', "# Files to delete during update\n" . implode("\n", $rPaths) . "\n\n");

		return $rDir;
	}

	private function tempDir(): string {
		$rDir = sys_get_temp_dir() . '/lb-gate-' . bin2hex(random_bytes(6));
		mkdir($rDir, 0700);
		$this->rTempDirs[] = $rDir;

		return $rDir;
	}

	private function removeDir(string $rDir): void {
		if (!is_dir($rDir)) {
			return;
		}
		foreach (array_diff(scandir($rDir) ?: [], ['.', '..']) as $rEntry) {
			$rPath = $rDir . '/' . $rEntry;
			if (is_dir($rPath)) {
				$this->removeDir($rPath);
			} else {
				unlink($rPath);
			}
		}
		rmdir($rDir);
	}

	/**
	 * @param array<string, string[]> $rOverrides Makefile variables to replace.
	 * @return array{int, string} Exit code and combined output.
	 */
	private function runGate(array $rOverrides): array {
		$rFlags = [];
		foreach ($rOverrides as $rName => $rValues) {
			$this->assertSame([], preg_grep('#^[A-Za-z0-9_./-]+$#', $rValues, PREG_GREP_INVERT), 'a path MAKEFLAGS cannot carry');
			// MAKEFLAGS separates words with spaces; a space inside a value is escaped.
			$rFlags[] = $rName . '=' . implode('\\ ', $rValues);
		}

		return $this->runProcess(['bash', 'tools/ci/verify-lb-archive.sh'], $rFlags);
	}

	/**
	 * @param string[] $rCommand
	 * @param string[] $rFlags Variable overrides passed to every make call.
	 * @return array{int, string}
	 */
	private function runProcess(array $rCommand, array $rFlags): array {
		$rEnv = getenv();
		unset($rEnv['MAKEFLAGS'], $rEnv['MFLAGS'], $rEnv['MAKELEVEL'], $rEnv['MAKEOVERRIDES']);
		if ($rFlags !== []) {
			$rEnv['MAKEFLAGS'] = '-- ' . implode(' ', $rFlags);
		}
		$rProcess = proc_open($rCommand, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $rPipes, $this->rRoot, $rEnv);
		if (!is_resource($rProcess)) {
			$this->fail('could not start ' . implode(' ', $rCommand));
		}
		$rOutput = (string) stream_get_contents($rPipes[1]);
		fclose($rPipes[1]);

		return [proc_close($rProcess), $rOutput];
	}
}

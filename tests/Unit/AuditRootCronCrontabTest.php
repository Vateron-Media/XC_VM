<?php

use PHPUnit\Framework\TestCase;

/**
 * Root's crontab holds the panel's root jobs beside the operator's own lines.
 * `startup` and `status` write it only when its list changed, never remove
 * it first, and leave it as it is when the new list cannot be written whole
 * or crontab refuses it. Regenerating the xc_vm user's crontab replaces that
 * one crontab and removes nobody's.
 *
 * Each case runs in a child PHP with `sudo` and `crontab` stand-ins first on
 * its PATH: they keep a crontab in a file of this test and tell what they
 * were asked on stderr. Nothing reaches the host's crontabs.
 */
final class AuditRootCronCrontabTest extends TestCase {
	/** An operator's own lines, with a comment and a variable. */
	private const OPERATOR = ['MAILTO=ops@example.com', '# nightly snapshot', '15 2 * * * /usr/local/bin/snapshot.sh'];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_crontab_' . bin2hex(random_bytes(4)) . '/';
		foreach (['bin', 'tmp', 'systmp'] as $rSub) {
			mkdir($this->rDir . $rSub, 0777, true);
		}
		$rRoot = escapeshellarg($this->rDir . 'root.crontab');
		$rXcVm = escapeshellarg($this->rDir . 'xc_vm.crontab');
		// `crontab`: -l lists, -r removes, a file name installs it and `-`
		// what is on its standard input, as `cat` reads either (or is refused,
		// with REFUSE set); -u xc_vm is the xc_vm user's crontab.
		file_put_contents($this->rDir . 'bin/crontab', "#!/bin/sh\necho \"crontab \$*\" >&2\nfile=$rRoot\nif [ \"\$1\" = -u ]; then file=$rXcVm; shift 2; fi\ncase \"\$1\" in\n\t-l) [ -f \"\$file\" ] || exit 1; cat \"\$file\";;\n\t-r) rm -f \"\$file\";;\n\t*) [ -z \"\$REFUSE\" ] || exit 1; cat \"\$1\" > \"\$file\";;\nesac\n");
		file_put_contents($this->rDir . 'bin/sudo', "#!/bin/sh\n[ \"\$1\" = crontab ] && exec \"\$@\"\necho \"\$*\" >&2\n");
		chmod($this->rDir . 'bin/crontab', 0755);
		chmod($this->rDir . 'bin/sudo', 0755);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Run $rBody in a child PHP booted as the unit tests are, with TMP_PATH
	 * and the system temp directory inside this test's directory.
	 *
	 * @param array<string, string> $rEnv
	 * @param bool $rNoRoom no file may grow: every write of data fails, as on a full disk
	 * @return array{0: string, 1: list<string>} [stdout, what the stand-ins were asked]
	 */
	private function child(string $rBody, array $rEnv = [], bool $rNoRoom = false): array {
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, '<?php require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\ndefine('TMP_PATH', " . var_export($this->rDir . 'tmp/', true) . ");\n" . $rBody);
		$rCommand = [PHP_BINARY, $rScript];
		if ($rNoRoom) {
			$rCommand = array_merge(['/bin/sh', '-c', 'trap "" XFSZ; ulimit -f 0; exec "$0" "$@"'], $rCommand);
		}
		$rProc = proc_open($rCommand, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, null, $rEnv + ['PATH' => $this->rDir . 'bin:' . getenv('PATH'), 'TMPDIR' => $this->rDir . 'systmp']);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		return [$rOut, array_values(preg_grep('/^(crontab|chattr) /', explode("\n", $rErr)) ?: [])];
	}

	/** @return array{0: string, 1: list<string>} */
	private function installRootCrontab(array $rEnv = [], bool $rNoRoom = false): array {
		return $this->child('XcVm\Cli\Commands\StartupCommand::installRootCrontab();', $rEnv, $rNoRoom);
	}

	/** @return list<string> */
	private function rootCrontab(): array {
		return is_file($this->rDir . 'root.crontab') ? (array) file($this->rDir . 'root.crontab', FILE_IGNORE_NEW_LINES) : [];
	}

	private static function rootSignals(): string {
		return '* * * * * ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:root_signals # XC_VM';
	}

	/** Root's crontab of an older release: the operator's lines, and the panel's under an old path and both markers. */
	private function seed(): string {
		$rSeed = implode("\n", array_merge(self::OPERATOR, [
			'* * * * * /usr/bin/php ' . MAIN_HOME . 'crons/root_signals.php',
			'* * * * * /old/php /old/console.php cron:root_signals # XC_VM',
			'0 5 * * * /old/php /old/console.php cron:old_job # \XC_VM',
		])) . "\n";
		file_put_contents($this->rDir . 'root.crontab', $rSeed);
		return $rSeed;
	}

	public function testTheOperatorsLinesSurviveAndThePanelsAreRefreshedInOneStep(): void {
		$this->seed();
		[$rOut, $rAsked] = $this->installRootCrontab();

		$rLines = $this->rootCrontab();
		$this->assertSame(self::OPERATOR, array_slice($rLines, 0, 3), 'the operator\'s lines, in their order');
		$this->assertContains(self::rootSignals(), $rLines);
		$this->assertSame([], preg_grep('#/old/|crons/root_#', $rLines), 'the panel\'s lines of an older release are gone');
		$this->assertSame(array_values(array_unique($rLines)), $rLines, 'no line twice');
		$this->assertStringContainsString('Crontab installed', $rOut);
		$this->assertSame([], preg_grep('/^crontab -r/', $rAsked), 'root\'s crontab is replaced, never removed first');
		$this->assertSame(['crontab -l', 'crontab -'], array_values(preg_grep('/^crontab /', $rAsked)), 'read, then installed once, from the standard input');
		$this->assertSame([], array_merge(glob($this->rDir . 'tmp/*') ?: [], glob($this->rDir . 'systmp/*') ?: []), 'the list\'s temporary file is removed, and was never in tmp/');
	}

	public function testAnUnchangedCrontabIsNotWrittenAgain(): void {
		$this->seed();
		$this->installRootCrontab();
		$rInstalled = $this->rootCrontab();
		[$rOut, $rAsked] = $this->installRootCrontab();

		$this->assertSame(['crontab -l'], $rAsked, 'read, and left alone');
		$this->assertStringContainsString('Crontab already installed', $rOut);
		$this->assertSame($rInstalled, $this->rootCrontab());
	}

	public function testWithNoRoomForTheNewListRootsCrontabStaysAsItIs(): void {
		$rSeed = $this->seed();
		[$rOut, $rAsked] = $this->installRootCrontab([], true);

		$this->assertSame($rSeed, (string) @file_get_contents($this->rDir . 'root.crontab'), 'the operator\'s jobs and the panel\'s are all still there');
		$this->assertSame(['crontab -l'], $rAsked, 'nothing was removed or installed');
		$this->assertStringContainsString('Crontab not installed', $rOut);
	}

	public function testAListCrontabRefusesLeavesRootsCrontabAsItIs(): void {
		$rSeed = $this->seed();
		[$rOut, $rAsked] = $this->installRootCrontab(['REFUSE' => '1']);

		$this->assertSame($rSeed, (string) @file_get_contents($this->rDir . 'root.crontab'));
		$this->assertSame([], preg_grep('/^crontab -r/', $rAsked));
		$this->assertStringContainsString('Crontab not installed', $rOut);
		$this->assertStringNotContainsString("Crontab installed\n", $rOut);
	}

	public function testRegeneratingTheXcVmCrontabRemovesNobodysCrontab(): void {
		// The crontab of whoever boots (root's, for a root cron or `status`).
		$rSeed = $this->seed();
		[$rOut, $rAsked] = $this->child('$GLOBALS["db"] = new class {
	public function query(string $rQuery, mixed ...$rArgs): bool {
		return true;
	}
	public function get_rows(): array {
		return [["filename" => "streams", "time" => "* * * * *"], ["filename" => "tmdb", "time" => "0 * * * *"]];
	}
};
$rGenerate = new ReflectionMethod(XcVm\Core\Init\LegacyInitializer::class, "generateCron");
$rGenerate->setAccessible(true);
echo $rGenerate->invoke(null) ? "generated" : "skipped";');

		$this->assertSame('generated', $rOut);
		$this->assertSame($rSeed, (string) @file_get_contents($this->rDir . 'root.crontab'), 'the booting user\'s own crontab is not the xc_vm crontab');
		$this->assertSame([], preg_grep('/^crontab -r/', $rAsked));
		$rLine = static fn(string $rTime, string $rName): string => $rTime . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:' . $rName . ' # XC_VM';
		$this->assertSame([$rLine('* * * * *', 'streams'), $rLine('0 * * * *', 'tmdb')], (array) file($this->rDir . 'xc_vm.crontab', FILE_IGNORE_NEW_LINES), 'the xc_vm crontab is replaced with the jobs');
		$this->assertFileExists($this->rDir . 'tmp/crontab', 'done once: the marker');
	}
}

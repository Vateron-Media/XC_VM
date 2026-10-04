<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;

/**
 * ProcessRunner::run(): a program started from its argv list with no shell
 * in between, where the signals daemon's cache rebuilds, cron:certbot's
 * nginx reloads and the stream store's flush ran shell lines (Semgrep's
 * php.lang.security.exec-use). Each element is one argument, as it is; the
 * program's stdin is /dev/null, its stdout is read to the end and dropped,
 * its stderr stays this process's (or goes to /dev/null), and run() returns
 * its exit status once it ended, as shell_exec() and exec() waited for it.
 *
 * The programs are shell scripts in a throwaway directory; the checks that
 * need this process's own stdin and stderr run the helper in a child PHP.
 */
final class ProcessRunnerTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-runner-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin dir', 0777, true);
	}

	protected function tearDown(): void {
		ProcessRunner::useRunner(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A shell script under the throwaway directory, whose name holds a space; returns its path. */
	private function program(string $rName, string $rBody): string {
		$rPath = $this->rDir . 'bin dir/' . $rName;
		file_put_contents($rPath, "#!/bin/sh\n" . $rBody . "\n");
		chmod($rPath, 0755);
		return $rPath;
	}

	/**
	 * Run $rCode in a child PHP with the autoloader, its stdin $rStdin (then
	 * closed), within 30 s.
	 *
	 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
	 */
	private function child(string $rCode, string $rStdin = ''): array {
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, "<?php\nrequire " . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ";\nuse XcVm\\Core\\Process\\ProcessRunner;\n" . $rCode . "\n");
		$rProc = proc_open(['timeout', '30', PHP_BINARY, $rScript], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		fwrite($rPipes[0], $rStdin);
		fclose($rPipes[0]);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		return [proc_close($rProc), $rOut, $rErr];
	}

	/**
	 * Shell syntax in an argument is that argument's text: the program gets
	 * each element as it is (an empty one, a newline, quotes, spaces), its
	 * path may hold a space, and none of the commands in them runs.
	 */
	public function testEachElementIsOneArgumentAndNoShellReadsIt(): void {
		$rProgram = $this->program('args', 'for rArg in "$@"; do printf \'%s\0\' "$rArg"; done > "$0.args"');
		$rPwned = $this->rDir . 'pwned';
		$rArgs = [
			'1;touch ' . $rPwned,
			'$(touch ' . $rPwned . ')',
			'`touch ' . $rPwned . '`',
			'2 && touch ' . $rPwned,
			'3 | touch ' . $rPwned,
			'> ' . $rPwned,
			"'4' \"5\"",
			'',
			"6\ntouch " . $rPwned,
			'*',
		];
		$this->assertSame(0, ProcessRunner::run(array_merge([$rProgram], $rArgs)));
		$this->assertSame(implode("\0", $rArgs) . "\0", (string) file_get_contents($rProgram . '.args'));
		$this->assertFileDoesNotExist($rPwned, 'nothing in an argument ran');
		$this->assertSame(['args', 'args.args'], array_map('basename', glob($this->rDir . 'bin dir/*') ?: []), 'and nothing else was written');
	}

	/** run() returns once the program ended, with its exit status. */
	public function testItWaitsForTheProgramAndReturnsItsExitStatus(): void {
		$rProgram = $this->program('slow', 'sleep 0.3; touch "$0.done"; exit 3');
		$this->assertSame(3, ProcessRunner::run([$rProgram]));
		$this->assertFileExists($rProgram . '.done');
		$this->assertSame(0, ProcessRunner::run([$this->program('ok', 'exit 0')]));
	}

	/**
	 * Its stdout is read to the end and dropped: a program that writes far
	 * more than a pipe holds never blocks on it, and none of it reaches this
	 * process's output.
	 */
	public function testItsOutputIsReadToTheEndAndDropped(): void {
		$rProgram = $this->program('chatty', 'head -c 1048576 /dev/zero | tr "\\0" x; echo; echo end-of-output; touch "$0.done"');
		[$rCode, $rOut, $rErr] = $this->child('echo ProcessRunner::run([' . var_export($rProgram, true) . ']), "\n";');
		$this->assertSame([0, "0\n", ''], [$rCode, $rOut, $rErr], 'it ended, and its output was not this process\'s');
		$this->assertFileExists($rProgram . '.done');
	}

	/** Its stdin is /dev/null, never this process's. */
	public function testItsStdinIsDevNull(): void {
		$rProgram = $this->program('reader', 'cat > "$0.stdin"');
		[$rCode, , $rErr] = $this->child('exit(ProcessRunner::run([' . var_export($rProgram, true) . ']));', "this process's stdin\n");
		$this->assertSame([0, ''], [$rCode, $rErr]);
		$this->assertSame('', (string) file_get_contents($rProgram . '.stdin'));
	}

	/**
	 * Its stderr is this process's, where shell_exec() left it; with $rQuiet
	 * it goes to /dev/null, as the old line's `2>/dev/null` sent it.
	 */
	public function testItsStderrIsThisProcesssOrDevNull(): void {
		$rProgram = var_export($this->program('warns', 'echo "warning $1" >&2; echo "output $1"'), true);
		[$rCode, $rOut, $rErr] = $this->child(
			'fwrite(STDERR, "before\n");' . "\n"
			. 'ProcessRunner::run([' . $rProgram . ', "kept"]);' . "\n"
			. 'fwrite(STDERR, "between\n");' . "\n"
			. 'ProcessRunner::run([' . $rProgram . ', "dropped"], true);' . "\n"
			. 'fwrite(STDERR, "after\n");'
		);
		$this->assertSame(0, $rCode, $rErr);
		$this->assertSame("before\nwarning kept\nbetween\nafter\n", $rErr);
		$this->assertSame('', $rOut);
	}

	/**
	 * A program that is not there does not start: 127, as a shell answered,
	 * and no warning is reported (PHP 8.3+ raises posix_spawn's failure as
	 * one, which run() silences as `@exec` did).
	 */
	public function testAProgramThatIsNotThereIs127WithoutAWarning(): void {
		$rWarnings = [];
		set_error_handler(static function (int $rNo, string $rMessage) use (&$rWarnings): bool {
			// What error_reporting() still reports (not silenced with @), as PHPUnit's own handler checks.
			if ((error_reporting() & $rNo) !== 0) {
				$rWarnings[] = $rMessage;
			}
			return true;
		});
		try {
			$this->assertSame(127, ProcessRunner::run([$this->rDir . 'bin dir/missing', 'reload']));
		} finally {
			restore_error_handler();
		}
		$this->assertSame([], $rWarnings);
	}

	/**
	 * start(): what a line ending in `&` did. The program keeps running after
	 * the call returns — nothing here waits for it — and its argv is still one
	 * element per argument, whatever shell syntax a value holds.
	 */
	public function testStartDoesNotWaitForTheProgram(): void {
		$rProgram = $this->program('slow', 'sleep 1; touch "$0.late"');
		$rBefore = microtime(true);

		$this->assertTrue(ProcessRunner::start([$rProgram, '; touch "' . $this->rDir . 'injected"']));

		$this->assertLessThan(2.0, microtime(true) - $rBefore, 'start() waited for the program');
		$this->assertFileDoesNotExist($rProgram . '.late', 'and it has not finished yet');
		// The argument is text, not a command: the shell in between reads the
		// script only, never the values.
		usleep(1200000);
		$this->assertFileExists($rProgram . '.late', 'and it has finished before teardown');
		$this->assertFileDoesNotExist($this->rDir . 'injected');
	}

	/**
	 * What start() answers is that the launch was handed off, not that the
	 * program exists: the shell backgrounds it and exits before it can fail, as
	 * a line ending in `&` did. A caller learns of the program the way it always
	 * did — the pid file it waits for never appears.
	 */
	public function testStartAnswersTheHandOffNotTheProgram(): void {
		$this->assertTrue(ProcessRunner::start([$this->rDir . 'not-a-program']));
		// False is for the hand-off itself failing, which is /bin/sh or proc_open
		// gone — not a program that is not there.
		$this->assertFileDoesNotExist($this->rDir . 'not-a-program');
	}

	/**
	 * passThrough(): the operator's own command — its output is this process's,
	 * as passthru() left it, and its exit status comes back.
	 */
	public function testPassThroughLeavesTheOutputWhereItWas(): void {
		$rProgram = $this->program('talks', 'echo out; echo err >&2; exit 3');
		[$rCode, $rOut, $rErr] = $this->child('exit(ProcessRunner::passThrough([' . var_export($rProgram, true) . ']));');

		$this->assertSame(3, $rCode, 'the program\'s own status');
		$this->assertStringContainsString('out', $rOut);
		$this->assertStringContainsString('err', $rErr);
	}

	public function testPassThroughIs127WithoutAProgram(): void {
		$this->assertSame(127, @ProcessRunner::passThrough([$this->rDir . 'not-a-program']));
	}

	/** Tests: another runner gets the argv list and $rQuiet, and nothing is started. */
	public function testTheSeamStartsNothing(): void {
		$rProgram = $this->program('never', 'touch "$0.ran"');
		$rSeen = [];
		ProcessRunner::useRunner(static function (array $rArgv, bool $rQuiet) use (&$rSeen): int {
			$rSeen[] = [$rArgv, $rQuiet];
			return 5;
		});
		$this->assertSame(5, ProcessRunner::run([$rProgram, 'a b'], true));
		$this->assertSame(5, ProcessRunner::run([$rProgram]));
		$this->assertSame([[[$rProgram, 'a b'], true], [[$rProgram], false]], $rSeen);
		$this->assertFileDoesNotExist($rProgram . '.ran');
		// start() and passThrough() go through the same seam; the seam's non-zero
		// status is start()'s "it did not start".
		$this->assertFalse(ProcessRunner::start([$rProgram, 'x']));
		$this->assertSame(5, ProcessRunner::passThrough([$rProgram]));
		$this->assertSame([[$rProgram, 'x'], true], $rSeen[2]);
		$this->assertSame([[$rProgram], false], $rSeen[3]);

		ProcessRunner::useRunner(null);
		$this->assertSame(0, ProcessRunner::run([$rProgram]));
		$this->assertFileExists($rProgram . '.ran');
	}
}

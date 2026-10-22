<?php

namespace XcVm\Core\Process;

/**
 * Run a program from its argv list, with no shell in between: each element
 * is one argument, exactly as it is, so nothing in a value is ever read as
 * shell syntax (no quoting to get right, no `;`, `$(…)` or backticks).
 *
 * It does what shell_exec() and exec() did with a command whose output the
 * caller dropped: it waits for the program, reading its stdout to the end
 * and dropping it (so the program never blocks on a full pipe), and returns
 * its exit status. Its stdin is /dev/null; its stderr is this process's, as
 * shell_exec() left it, or /dev/null (`$rQuiet`, a line's `2>/dev/null`).
 */
final class ProcessRunner {
	/** The most of a program's output read at a time (and dropped). */
	private const CHUNK = 65536;

	/**
	 * Tests: runs the argv lists instead, and nothing is started; null
	 * restores proc_open.
	 *
	 * @var (callable(non-empty-list<string>, bool): int)|null
	 */
	private static $rRunner = null;

	/** Tests: run argv lists through $rRunner (argv, quiet => exit status); null restores proc_open. */
	public static function useRunner(?callable $rRunner): void {
		self::$rRunner = $rRunner;
	}

	/**
	 * Run $rArgv (a program's path, or a name PATH finds, then its
	 * arguments) and wait for it to end.
	 *
	 * @param non-empty-list<string> $rArgv
	 * @param bool $rQuiet its stderr to /dev/null instead of this process's
	 * @return int its exit status; 127 when it did not start, as a shell answers
	 */
	public static function run(array $rArgv, bool $rQuiet = false): int {
		if (self::$rRunner !== null) {
			return (self::$rRunner)($rArgv, $rQuiet);
		}
		// Descriptor 2 left out is inherited: this process's stderr.
		$rSpec = [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w']];
		if ($rQuiet) {
			$rSpec[2] = ['file', '/dev/null', 'w'];
		}
		// An argv list, no shell: each element one argument; callers pass constant paths and words and values they validated (integer ids, the store's own directory).
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, $rSpec, $rPipes);
		if (!is_resource($rProc)) {
			return 127;
		}
		while (!feof($rPipes[1])) {
			if (fread($rPipes[1], self::CHUNK) === false) {
				break;
			}
		}
		fclose($rPipes[1]);
		return proc_close($rProc);
	}

	/**
	 * Start $rArgv and do not wait for it: what a line ending in `&` did. The
	 * caller learns of the program another way — a pid file it writes, a later
	 * `ps` — and this process must not be held for its lifetime (a channel
	 * build or a cache pass runs for minutes).
	 *
	 * `proc_close()` waits, so a detached child needs one shell to background it
	 * and exit, leaving the child to init. The script is constant and the argv is
	 * the shell's own arguments (`$0`, then `$@`), never interpolated into it, so
	 * no value can be read as shell syntax.
	 *
	 * @param non-empty-list<string> $rArgv
	 * @return bool whether the shell that backgrounds it started
	 */
	public static function start(array $rArgv): bool {
		if (self::$rRunner !== null) {
			return (self::$rRunner)($rArgv, true) === 0;
		}
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open(
			array_merge(['/bin/sh', '-c', '"$0" "$@" >/dev/null 2>&1 &'], array_values($rArgv)),
			[0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
			$rPipes
		);
		if (!is_resource($rProc)) {
			return false;
		}
		// The shell exits as soon as it has started the child, so this waits for
		// the shell alone.
		return proc_close($rProc) === 0;
	}

	/**
	 * Run $rArgv with its output left where passthru() put it — this process's
	 * stdout and stderr — and wait for it. For a command an operator typed and
	 * reads the output of; a daemon's launch is start(), a silent call run().
	 *
	 * Descriptors 1 and 2 are left out of the spec, which inherits them.
	 *
	 * @param non-empty-list<string> $rArgv
	 * @return int its exit status; 127 when it did not start, as a shell answers
	 */
	public static function passThrough(array $rArgv): int {
		if (self::$rRunner !== null) {
			return (self::$rRunner)($rArgv, false);
		}
		// An argv list, no shell: each element one argument, and the caller's own
		// constant words and paths.
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, [0 => ['file', '/dev/null', 'r']], $rPipes);
		if (!is_resource($rProc)) {
			return 127;
		}
		return proc_close($rProc);
	}

	/** @var (callable(non-empty-list<string>, ?string): array{0: int, 1: string})|null Tests: capture(). */
	private static $rCapturer = null;

	/** Tests: answer capture() with $rCapturer (argv, stdin => [status, stdout]); null restores proc_open. */
	public static function useCapturer(?callable $rCapturer): void {
		self::$rCapturer = $rCapturer;
	}

	/**
	 * Run $rArgv, wait for it, and return its exit status and what it wrote
	 * to stdout (at most $rMax bytes; its stderr goes to /dev/null). For a
	 * program whose answer the caller reads, such as `xc_agent keygen`.
	 * $rInput is written to its stdin, which is /dev/null without it: what
	 * argv would show every user in `ps`.
	 *
	 * @param non-empty-list<string> $rArgv
	 * @return array{0: int, 1: string} [exit status (127: it did not start), stdout]
	 */
	public static function capture(array $rArgv, int $rMax = 65536, ?string $rInput = null): array {
		if (self::$rCapturer !== null) {
			return (self::$rCapturer)($rArgv, $rInput);
		}
		// An argv list, no shell: each element one argument, and the caller's own
		// constant words and paths, or values it validated.
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, [0 => $rInput === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, ''];
		}
		if ($rInput !== null) {
			fwrite($rPipes[0], $rInput);
			fclose($rPipes[0]);
		}
		$rOut = '';
		while (!feof($rPipes[1])) {
			$rChunk = fread($rPipes[1], self::CHUNK);
			if ($rChunk === false) {
				break;
			}
			if (strlen($rOut) < $rMax) {
				$rOut .= substr($rChunk, 0, $rMax - strlen($rOut));
			}
		}
		fclose($rPipes[1]);
		return [proc_close($rProc), $rOut];
	}
}

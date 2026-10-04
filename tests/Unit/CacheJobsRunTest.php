<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\CacheJobs;
use XcVm\Core\Process\ProcessRunner;

/**
 * CacheJobs::run() with no shell (Semgrep's php.lang.security.exec-use): a
 * movie's delete removes the files `rm <MAIN_HOME>content/vod/<id>.*`
 * removed, in PHP, and MAIN's cache rebuilds start cron:cache_engine from an
 * argv list, their ids integers.
 *
 * The deletes and the end-to-end checks run CacheJobs::run() in a child PHP
 * whose MAIN_HOME is a throwaway deploy root and whose PHP_BIN is a stand-in
 * that logs the arguments it gets; the argv lists are checked in this
 * process through ProcessRunner's seam, where nothing starts.
 */
final class CacheJobsRunTest extends TestCase {
	private string $rHome;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-cachejobs-' . bin2hex(random_bytes(4)) . '/';
		foreach (['content/vod', 'tmp/opened_cons', 'bin/php/bin', 'elsewhere'] as $rDir) {
			mkdir($this->rHome . $rDir, 0777, true);
		}
		// The deploy root's PHP: logs each call's arguments, one JSON list a line.
		file_put_contents($this->rHome . 'bin/php/bin/php', "#!" . PHP_BINARY . "\n<?php\nfile_put_contents(dirname(__DIR__, 3) . '/calls.log', json_encode(array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\n");
		chmod($this->rHome . 'bin/php/bin/php', 0755);
	}

	protected function tearDown(): void {
		ProcessRunner::useRunner(null);
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	/**
	 * CacheJobs::run($rJobs) in a child PHP, in the throwaway deploy root.
	 *
	 * @param list<array<string, mixed>> $rJobs
	 * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
	 */
	private function child(array $rJobs): array {
		file_put_contents($this->rHome . 'jobs.json', json_encode($rJobs));
		$rScript = $this->rHome . 'run.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			define('MAIN_HOME', getenv('XCVM_TEST_HOME'));
			define('PHP_BIN', MAIN_HOME . 'bin/php/bin/php');
			define('CONS_TMP_PATH', MAIN_HOME . 'tmp/opened_cons/');
			require getenv('XCVM_TEST_SRC') . 'vendor/autoload.php';
			\XcVm\Core\Cluster\CacheJobs::run(json_decode((string) file_get_contents(MAIN_HOME . 'jobs.json'), true));
			echo "done\n";
			PHP);
		$rProc = proc_open(['timeout', '60', PHP_BINARY, $rScript], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, ['XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_SRC' => MAIN_HOME, 'PATH' => (string) getenv('PATH')]);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		return [proc_close($rProc), $rOut, $rErr];
	}

	/** @return list<list<string>> the arguments of each call of the deploy root's PHP, in order */
	private function calls(): array {
		$rFile = $this->rHome . 'calls.log';
		return is_file($rFile) ? array_map(static fn(string $rLine): array => json_decode($rLine, true), file($rFile, FILE_IGNORE_NEW_LINES)) : [];
	}

	/** @return list<string> what content/vod/ holds, sorted, a directory's entries as `<dir>/<name>` */
	private function vod(): array {
		$rDir = $this->rHome . 'content/vod/';
		$rOut = [];
		foreach (array_diff(scandir($rDir), ['.', '..']) as $rName) {
			$rOut[] = $rName;
			if (is_dir($rDir . $rName) && !is_link($rDir . $rName)) {
				foreach (array_diff(scandir($rDir . $rName), ['.', '..']) as $rInner) {
					$rOut[] = $rName . '/' . $rInner;
				}
			}
		}
		sort($rOut);
		return $rOut;
	}

	/**
	 * `delete_vod` and `delete_vods` remove what `rm <vod>/<id>.*` removed:
	 * every entry named `<id>.` and anything, a symlink itself and not what
	 * it points to, but no directory (rm had no -r), and nothing whose name
	 * only starts with the id (`10.mp4` for 1) or has no dot after it. An id
	 * with no file, or an entry left, says nothing, and nothing is started.
	 */
	public function testADeleteRemovesItsIdsFilesAndNothingElse(): void {
		$rVod = $this->rHome . 'content/vod/';
		foreach (['1.mp4', '1.srt', '1.', '10.mp4', '11.ts', '01.mp4', '1x.mp4', '1', '.1.mp4', '21.mp4', '8.mkv', '8.nfo', '12.mp4'] as $rFile) {
			file_put_contents($rVod . $rFile, $rFile);
		}
		mkdir($rVod . '1.d');
		file_put_contents($rVod . '1.d/1.mp4', 'inside');
		file_put_contents($this->rHome . 'elsewhere/movie.mkv', 'the source');
		mkdir($this->rHome . 'elsewhere/folder');
		symlink($this->rHome . 'elsewhere/movie.mkv', $rVod . '7.mkv');
		symlink($this->rHome . 'elsewhere/folder', $rVod . '7.dir');
		symlink($this->rHome . 'elsewhere/gone.mkv', $rVod . '7.old');
		[$rCode, $rOut, $rErr] = $this->child([
			['type' => 'delete_vod', 'id' => 1],
			['type' => 'delete_vod', 'id' => 7],
			['type' => 'delete_vods', 'id' => [8, 9]],
			['type' => 'delete_vod', 'id' => 99],
		]);
		$this->assertSame([0, "done\n", ''], [$rCode, $rOut, $rErr], 'silent');
		$this->assertSame(['.1.mp4', '01.mp4', '1', '1.d', '1.d/1.mp4', '10.mp4', '11.ts', '12.mp4', '1x.mp4', '21.mp4'], $this->vod());
		$this->assertFileExists($this->rHome . 'elsewhere/movie.mkv', 'a symlink goes, not its target');
		$this->assertDirectoryExists($this->rHome . 'elsewhere/folder');
		$this->assertSame([], $this->calls());
	}

	/**
	 * No value of a job reaches a shell, nor becomes an argument unless it is
	 * an integer: a delete takes its id's integer as before (`7;…` deletes
	 * 7's files), a rebuild keeps the ids that are integers (as digits or
	 * not) and drops the rest, and starts nothing when none is left. Each
	 * rebuild is one cron:cache_engine, its type and its ids (joined by
	 * commas) one argument each, as the quoted words of the old line were.
	 */
	public function testNoValueReachesAShell(): void {
		$rPwned = $this->rHome . 'pwned';
		foreach (['7.mp4', '70.mp4', '8.mp4'] as $rFile) {
			touch($this->rHome . 'content/vod/' . $rFile);
		}
		[$rCode, $rOut, $rErr] = $this->child([
			['type' => 'delete_vod', 'id' => '7;touch ' . $rPwned],
			['type' => 'delete_vods', 'id' => ['8$(touch ' . $rPwned . ')']],
			['type' => 'update_streams', 'id' => [1, '2;touch ' . $rPwned, '3', '$(touch ' . $rPwned . ')', '`touch ' . $rPwned . '`', 1]],
			['type' => 'update_stream', 'id' => '4 && touch ' . $rPwned],
			['type' => 'update_lines', 'id' => ['5|touch ' . $rPwned, "6\ntouch " . $rPwned]],
			['type' => 'update_line', 'id' => '> ' . $rPwned],
		]);
		$this->assertSame([0, "done\n", ''], [$rCode, $rOut, $rErr]);
		$this->assertFileDoesNotExist($rPwned, 'nothing in a value ran');
		$this->assertSame(['70.mp4'], $this->vod());
		$this->assertSame([[$this->rHome . 'console.php', 'cron:cache_engine', 'streams_update', '1,3']], $this->calls(), 'no lines rebuild: none of its ids is one');
	}

	/**
	 * The rebuilds run last, streams then lines, each once for all the jobs:
	 * [PHP_BIN, <MAIN_HOME>console.php, cron:cache_engine, <type>, <ids>],
	 * the ids in the order first named, each once, their stderr the caller's.
	 */
	public function testTheCacheRebuildsRunFromArgvLists(): void {
		$rRuns = [];
		ProcessRunner::useRunner(static function (array $rArgv, bool $rQuiet) use (&$rRuns): int {
			$rRuns[] = [$rArgv, $rQuiet];
			return 0;
		});
		CacheJobs::run([
			['type' => 'update_line', 'id' => 5],
			['type' => 'update_stream', 'id' => 3],
			['type' => 'update_streams', 'id' => [1, '2', 3]],
			['type' => 'update_lines', 'id' => ['6', 5, 7]],
			['type' => 'update_stream', 'id' => '03'],
		]);
		$this->assertSame([
			[[PHP_BIN, MAIN_HOME . 'console.php', 'cron:cache_engine', 'streams_update', '3,1,2'], false],
			[[PHP_BIN, MAIN_HOME . 'console.php', 'cron:cache_engine', 'lines_update', '5,6,7'], false],
		], $rRuns);

		$rRuns = [];
		CacheJobs::run([
			['type' => 'update_streams', 'id' => ['x', 0, -1, 1.5, null, [2], true, '']],
			['type' => 'update_line', 'id' => 'abc'],
			['type' => 'update_line', 'id' => '1e3'],
		]);
		$this->assertSame([], $rRuns, 'no integer id, no rebuild');
	}
}

<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Http\ApiClient;
use XcVm\Public\Controllers\Api\InternalApiController;

/**
 * `scandir_recursive`'s `stat` option: the node answers files with their
 * modification times (path => unix time), so MAIN's watch scan of a load
 * balancer's folder skips a file still being written; without it the node
 * answers the plain list as before. Runs the node's real find command.
 */
final class NodeListingStatTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-listing-' . bin2hex(random_bytes(4));
		mkdir($this->rDir . '/Show S01', 0777, true);
		touch($this->rDir . '/Movie (2020).mkv', 1700000000);
		touch($this->rDir . '/Show S01/e01.mp4', 1700000100);
		touch($this->rDir . '/Show S01/e01.srt', 1700000200);
	}

	protected function tearDown(): void {
		NodeRpc::useTransport(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(InternalApiController::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	private function find(?string $rAllowed, bool $rStat): array {
		exec(self::call('findCommand', $this->rDir, $rAllowed, $rStat), $rLines);
		return $rLines;
	}

	public function testWithoutStatTheListIsAsBefore(): void {
		$rLines = $this->find('mkv|mp4', false);
		sort($rLines);
		$this->assertSame([$this->rDir . '/Movie (2020).mkv', $this->rDir . '/Show S01/e01.mp4'], $rLines);
		$this->assertSame('/usr/bin/find ' . escapeshellarg('/x') . ' -regex ".*\\.\\(' . escapeshellcmd('mkv|mp4') . '\\)"', self::call('findCommand', '/x', 'mkv|mp4', false), 'the command is unchanged');
	}

	public function testWithStatEachFileComesWithItsModificationTime(): void {
		$rTimes = self::call('findTimes', $this->find('mkv|mp4', true));
		ksort($rTimes);
		$this->assertSame([$this->rDir . '/Movie (2020).mkv' => 1700000000, $this->rDir . '/Show S01/e01.mp4' => 1700000100], $rTimes);
		$this->assertCount(3, self::call('findTimes', $this->find(null, true)), 'files only: the folder itself is not listed');
		$this->assertSame([], self::call('findTimes', ['not a stat line', "x\t", "\t/path"]), 'malformed lines are dropped');
	}

	/**
	 * `allowed` reaches find's -regex: a value with paired quotes used to close
	 * that argument and add find primaries (here -delete). Only extensions pass.
	 */
	public function testAnAllowedListThatIsNotExtensionsIsRefused(): void {
		$this->assertNull(self::call('findCommand', $this->rDir, 'mkv" -o -delete -o -name "z', false));
		$this->assertNull(self::call('findCommand', $this->rDir, 'mkv|$(id)', true));
		$this->assertNotNull(self::call('findCommand', $this->rDir, null, false), 'no filter (an empty `allowed` arrives as null)');
		$this->assertNotNull(self::call('findCommand', $this->rDir, 'mkv|mp4|3gp', false));
		$this->assertFileExists($this->rDir . '/Movie (2020).mkv', 'nothing deleted');
	}

	/** A page is an object even when empty: `{"files":{},"next":null}`, never `[]`. */
	public function testAnEmptyPageIsAnObject(): void {
		$this->assertSame('{"files":{},"next":null}', json_encode(self::call('findPage', [], null)));
		$this->assertSame('{"files":{},"next":null}', json_encode(self::call('findPage', ['/a.mkv' => 1], '/a.mkv')), 'nothing after the last');
	}

	/**
	 * A big folder comes a page at a time, each under the command queue's
	 * 64 KB, every file once; a file removed between two pages makes no other
	 * one skipped (the cursor is a path, not a position).
	 */
	public function testPagesFollowThePathCursorAndFitTheQueue(): void {
		$rTimes = [];
		for ($i = 0; $i < 3000; $i++) {
			$rTimes[sprintf('/mnt/films/a fairly long folder name for the test/movie %05d (2020).mkv', $i)] = 1700000000 + $i;
		}
		$rSeen = [];
		$rAfter = null;
		$rPages = 0;
		do {
			$rPage = self::call('findPage', $rTimes, $rAfter);
			$this->assertLessThan(65536, strlen(json_encode($rPage, JSON_UNESCAPED_UNICODE)));
			$rSeen += (array) $rPage['files'];
			$rAfter = $rPage['next'];
			$rPages++;
			if ($rPages === 2) {
				unset($rTimes[array_key_first($rTimes)]); // removed while the listing runs
			}
		} while ($rAfter !== null);
		$this->assertGreaterThan(2, $rPages);
		$this->assertCount(3000, $rSeen, 'every file once, none skipped');
	}

	/** MAIN reads every page; a page that fails, or pages without end, give no listing rather than part of one. */
	public function testApiClientReadsEveryPageOrNone(): void {
		$rTimes = [];
		for ($i = 0; $i < 2500; $i++) {
			$rTimes[sprintf('/mnt/films/a fairly long folder name for the test/movie %05d.mkv', $i)] = 1700000000;
		}
		$rFindPage = new ReflectionMethod(InternalApiController::class, 'findPage');
		$rFindPage->setAccessible(true);
		$rCalls = 0;
		NodeRpc::useTransport(static function (string $rKind, array $rServers, array $rData) use ($rTimes, $rFindPage, &$rCalls): string {
			$rCalls++;
			return (string) json_encode($rFindPage->invoke(null, $rTimes, $rData['after'] ?? null), JSON_UNESCAPED_UNICODE);
		});
		$this->assertSame($rTimes, ApiClient::scanRecursive(2, '/mnt/films', ['mkv'], true));
		$this->assertGreaterThan(1, $rCalls);

		NodeRpc::useTransport(static fn(): string => '{"files":{"/a.mkv":1},"next":"/a.mkv"}');
		$this->assertNull(ApiClient::scanRecursive(2, '/mnt/films', ['mkv'], true), 'pages without end');
		$rPages = ['{"files":{"/a.mkv":1},"next":"/a.mkv"}', '{"files":{"/b.mkv":1},"ne'];
		NodeRpc::useTransport(static function () use (&$rPages): string {
			return array_shift($rPages);
		});
		$this->assertNull(ApiClient::scanRecursive(2, '/mnt/films', ['mkv'], true), 'a page cut short (the queue truncated it)');
		NodeRpc::useTransport(static fn(): string => '{"result":false}');
		$this->assertSame(['result' => false], ApiClient::scanRecursive(2, '/etc', ['mkv'], true), 'a refusal as it is');
		NodeRpc::useTransport(static fn(): string => '["/mnt/films/a.mkv"]');
		$this->assertSame(['/mnt/films/a.mkv'], ApiClient::scanRecursive(2, '/mnt/films', ['mkv'], true), 'a node from before stat: its plain list');
	}

	/**
	 * One file's size, for MAIN's auto-upgrade of a folder it scans here: the
	 * node answers a file with its bytes, a file that is gone with nothing, and
	 * MAIN reads both, or knows nothing (a node from before `size`, a refusal).
	 */
	public function testAFilesSizeIsAskedOfTheNodeThatHoldsIt(): void {
		$rFile = $this->rDir . '/Movie (2020).mkv';
		file_put_contents($rFile, str_repeat('x', 1234));
		touch($rFile, 1700000000);

		$this->assertSame('{"files":{' . json_encode($rFile) . ':1700000000},"sizes":{' . json_encode($rFile) . ':1234},"next":null}', json_encode(self::call('fileStat', $rFile)));
		$this->assertSame('{"files":{},"sizes":{},"next":null}', json_encode(self::call('fileStat', $this->rDir . '/gone.mkv')));
		$this->assertSame('{"files":{},"sizes":{},"next":null}', json_encode(self::call('fileStat', $this->rDir . '/Show S01')), 'a folder is no file');

		// MAIN: one request per file, the path encoded once more for the node's urldecode().
		$rAsked = [];
		NodeRpc::useTransport(static function (string $rKind, array $rServers, array $rData) use (&$rAsked): string {
			$rAsked[] = $rData;
			$rPath = urldecode($rData['dir']);
			return (string) json_encode($rPath === '/lb/gone.mkv' ? ['files' => (object) [], 'sizes' => (object) [], 'next' => null] : ['files' => [$rPath => 1], 'sizes' => [$rPath => 500], 'next' => null]);
		});
		$this->assertSame(['/lb/a+b 100%.mkv' => 500, '/lb/gone.mkv' => null], ApiClient::fileSizes(2, ['/lb/a+b 100%.mkv', '/lb/gone.mkv']));
		$this->assertSame(['action' => 'scandir_recursive', 'dir' => '%2Flb%2Fa%2Bb%20100%25.mkv', 'allowed' => '', 'stat' => 1, 'size' => 1], $rAsked[0]);

		NodeRpc::useTransport(static fn(): string => '{"files":{"/lb/a.mkv":1},"next":null}');
		$this->assertNull(ApiClient::fileSizes(2, ['/lb/a.mkv']), 'a node from before size: nothing is known');
		NodeRpc::useTransport(static fn(): string => '{"result":false}');
		$this->assertNull(ApiClient::fileSizes(2, ['/etc/shadow']), 'a refusal');
		NodeRpc::useTransport(static fn(): string => '');
		$this->assertNull(ApiClient::fileSizes(2, ['/lb/a.mkv']), 'no answer');
	}

	public function testApiClientAsksForTimesOnlyWhenTold(): void {
		$rSent = [];
		NodeRpc::useTransport(static function (string $rKind, array $rServers, array $rData) use (&$rSent): string {
			$rSent[] = $rData;
			return '[]';
		});
		ApiClient::scanRecursive(2, '/mnt/films', ['mkv', 'mp4'], true);
		ApiClient::scanRecursive(2, '/mnt/films', null);
		$this->assertSame(['action' => 'scandir_recursive', 'dir' => '/mnt/films', 'allowed' => 'mkv|mp4', 'stat' => 1], $rSent[0]);
		$this->assertSame(['action' => 'scandir_recursive', 'dir' => '/mnt/films', 'allowed' => ''], $rSent[1], 'no filter is no longer a TypeError');
	}
}

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

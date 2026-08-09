<?php

use PHPUnit\Framework\TestCase;
use XcVm\Infrastructure\Signal\SignalQueue;

/**
 * Unit tests for SignalQueue — the file-backed deferred-work queue that
 * producers (auth / ISP / forced_country / flood) push to and the cache-handler
 * daemon drains. Locks the on-disk contract (cache_<md5(key)> holding
 * [key, data]) that the consumer's switch depends on.
 */
final class SignalQueueTest extends TestCase {

	public static function setUpBeforeClass(): void {
		if (!defined('SIGNALS_TMP_PATH')) {
			define('SIGNALS_TMP_PATH', sys_get_temp_dir() . '/xcvm-sig-test/');
		}
		if (!is_dir(SIGNALS_TMP_PATH)) {
			mkdir(SIGNALS_TMP_PATH, 0777, true);
		}
	}

	protected function setUp(): void {
		foreach (glob(SIGNALS_TMP_PATH . 'cache_*') ?: array() as $rFile) {
			unlink($rFile);
		}
	}

	public function testPushWritesCanonicalFileFormat(): void {
		SignalQueue::push('isp/42', json_encode(array('Comcast', 7922)));
		$rPath = SIGNALS_TMP_PATH . 'cache_' . md5('isp/42');
		$this->assertFileExists($rPath);
		$this->assertSame(array('isp/42', json_encode(array('Comcast', 7922))), json_decode(file_get_contents($rPath), true));
	}

	public function testPathForMatchesPushLocation(): void {
		$this->assertSame(SIGNALS_TMP_PATH . 'cache_' . md5('forced_country/5'), SignalQueue::pathFor('forced_country/5'));
	}

	public function testPushIsIdempotentPerKey(): void {
		SignalQueue::push('expiring/9', 100);
		SignalQueue::push('expiring/9', 200); // same key overwrites the pending record
		$rPending = SignalQueue::pending();
		$this->assertCount(1, $rPending);
		$this->assertSame(200, $rPending[0][2]);
	}

	public function testPendingReturnsFileKeyDataTuples(): void {
		SignalQueue::push('forced_country/1', 'DE');
		SignalQueue::push('isp/2', json_encode(array('ISP', 1)));
		$rByKey = array();
		foreach (SignalQueue::pending() as list($rFile, $rKey, $rData)) {
			$this->assertFileExists($rFile);
			$rByKey[$rKey] = $rData;
		}
		$this->assertSame('DE', $rByKey['forced_country/1']);
		$this->assertSame(json_encode(array('ISP', 1)), $rByKey['isp/2']);
	}

	public function testPendingSkipsMalformedRecords(): void {
		file_put_contents(SIGNALS_TMP_PATH . 'cache_bad', 'not-json');
		SignalQueue::push('isp/3', 1);
		$rPending = SignalQueue::pending();
		$this->assertCount(1, $rPending);
		$this->assertSame('isp/3', $rPending[0][1]);
	}
}

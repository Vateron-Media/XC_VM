<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Streaming\Delivery\HLSGenerator;
use XcVm\Streaming\Delivery\HlsSequence;

/**
 * The segment gateway's playlist refresh (Phase 12.4) answers as live.php
 * does: tests/Support/gateway_live_vectors.json fixes the HLS connection id,
 * rawurlencode, HlsSequence::reconcile and tokenizeDaemonPlaylist, and
 * xc_fanout's internal/gateway passes the same file. This test is the file's
 * generator in reverse.
 */
final class GatewayLiveVectorsTest extends TestCase {
	/** @var array<string, mixed> */
	private static array $rV;

	private string $rDir;

	public static function setUpBeforeClass(): void {
		self::$rV = json_decode((string) file_get_contents(dirname(__DIR__) . '/Support/gateway_live_vectors.json'), true);
	}

	protected function tearDown(): void {
		ViewerKey::useFile(null);
		if (isset($this->rDir)) {
			exec('rm -rf ' . escapeshellarg($this->rDir));
		}
	}

	public function testTheHlsConnectionId(): void {
		foreach (self::$rV['hls_keys'] as $rK) {
			$this->assertSame($rK['ua_html'], htmlentities(trim($rK['user_agent'])), $rK['user_agent']);
			$this->assertSame($rK['uuid'], ConnectionTracker::hlsConnectionKey($rK['hmac_id'], $rK['identifier'], $rK['user_id'], $rK['stream_id'], $rK['ip'], $rK['ua_html']));
		}
	}

	public function testRawurlencode(): void {
		foreach (self::$rV['rawurlencode'] as $rCase) {
			$this->assertSame($rCase['out'], rawurlencode((string) hex2bin($rCase['in_hex'])));
		}
	}

	public function testTheSequenceReanchoring(): void {
		foreach (self::$rV['reconcile'] as $rCase) {
			$this->assertSame([$rCase['seq'], $rCase['next']], HlsSequence::reconcile($rCase['daemon'], $rCase['floor'], $rCase['state'], $rCase['now'], $rCase['target']));
		}
	}

	public function testTheTokenizedPlaylist(): void {
		$this->rDir = sys_get_temp_dir() . '/gwlive-test-' . bin2hex(random_bytes(4));
		mkdir($this->rDir, 0700, true);
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', $this->rDir . '/');
		}
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', self::$rV['server_id']);
		}
		$rP = self::$rV['playlist'];
		// The tokens carry this server's id (SERVER_ID): the file's, where this process has another.
		$rServerField = '/' . $rP['uuid'] . '/';
		file_put_contents(STREAMS_PATH . $rP['stream_id'] . '_.iv', hex2bin($rP['iv_hex']));
		foreach ($rP['cases'] as $rCase) {
			$rFile = $this->rDir . '/viewer';
			@unlink($rFile);
			if ($rCase['viewer_key_hex'] !== null) {
				file_put_contents($rFile, json_encode(['current' => hex2bin($rCase['viewer_key_hex']), 'previous' => null, 'previous_valid_until' => null]));
			}
			ViewerKey::useFile($rFile);
			$rOut = HLSGenerator::tokenizeDaemonPlaylist($rP['input'], $rCase['settings'], $rCase['username'], $rCase['password'], $rP['stream_id'], $rP['uuid'], $rP['ip'], $rCase['hmac_id'], $rCase['identifier'], $rP['codec'], $rP['on_demand'], self::$rV['server_id'], null);
			$rExpected = str_replace($rServerField . self::$rV['server_id'] . '/', $rServerField . SERVER_ID . '/', self::opened($rCase['output'], $rCase));
			$this->assertSame($rExpected, self::opened((string) $rOut, $rCase), $rCase['name']);
		}
	}

	/** The playlist with each token replaced by what it opens to. */
	private static function opened(string $rPlaylist, array $rCase): string {
		$rKey = $rCase['viewer_key_hex'] !== null ? hex2bin($rCase['viewer_key_hex']) : $rCase['settings']['live_streaming_pass'];
		return (string) preg_replace_callback('#/(hls|key)/([A-Za-z0-9_-]+)#', static function ($rM) use ($rKey, $rCase) {
			$rPlain = Encryption::open($rM[2], $rKey, OPENSSL_EXTRA);
			if ($rPlain === false && $rCase['viewer_key_hex'] === null) {
				$rPlain = Encryption::decrypt($rM[2], $rKey, OPENSSL_EXTRA);
			}
			return '/' . $rM[1] . '/{' . $rPlain . '}';
		}, $rPlaylist);
	}
}

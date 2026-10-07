<?php

use PHPUnit\Framework\TestCase;

/**
 * An RTMP check of a direct source (rtmp.php, MAIN's rtmp_auth for a load
 * balancer) is refused rather than redirected: StreamRedirector would
 * otherwise answer with the source's URL and exit, in the middle of the
 * cluster API's signed reply.
 */
final class StreamRedirectorRtmpTest extends TestCase {
	public function testAnRtmpCheckOfADirectSourceIsRefusedNotRedirected(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-redirect-' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		$rCode = 'define("STREAMS_TMP_PATH", ' . var_export($rDir, true) . ');' . "\n"
			. 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ";\n"
			. 'class StubBouquets { public static function getMapEntry($rID) { return []; } }' . "\n"
			. 'class_alias("StubBouquets", "XcVm\\\\Domain\\\\Bouquet\\\\BouquetService");' . "\n"
			. 'file_put_contents(STREAMS_TMP_PATH . "stream_5", igbinary_serialize(["info" => ["direct_source" => 1, "direct_proxy" => 0, "stream_source" => json_encode(["http://source.example/live.m3u8"])], "servers" => []]));' . "\n"
			. 'var_export(\XcVm\Streaming\Delivery\StreamRedirector::redirectStream(true, [], [], 5, "rtmp", null, "", "", "live", 7));' . "\n"
			. 'echo " and on";';
		try {
			$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-r', $rCode], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
			$this->assertIsResource($rProc);
			$rOut = (string) stream_get_contents($rPipes[1]);
			$rErr = (string) stream_get_contents($rPipes[2]);
			proc_close($rProc);
			$this->assertSame('', $rErr);
			$this->assertSame('false and on', $rOut, 'refused, and the caller goes on');
		} finally {
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}
}

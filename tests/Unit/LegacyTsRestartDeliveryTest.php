<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/** Exercise the production legacy delivery loop with real files and a real replacement pid. */
final class LegacyTsRestartDeliveryTest extends TestCase {
	private static function packet(string $payload, int $counter): string {
		return "\x47\x41\x00" . chr(0x10 | $counter) . str_repeat($payload, 184);
	}

	public static function situations(): array {
		return [
			'missing old segment' => [false, false],
			'producer dies mid segment' => [true, false],
			'restreamers keep reconnect policy' => [false, true],
		];
	}

	#[DataProvider('situations')]
	public function testMissingOldSegmentResumesSameRequestAtNewLiveEdge(bool $midSegment, bool $restreamer): void {
		$directory = sys_get_temp_dir() . '/xcvm-ts-delivery-' . bin2hex(random_bytes(5));
		mkdir($directory);
		$producer = proc_open([PHP_BINARY, '-r', 'sleep(20);', '42'], [], $pipes);
		$this->assertIsResource($producer);
		$pid = proc_get_status($producer)['pid'];
		$oldProducer = $midSegment ? proc_open([PHP_BINARY, '-r', 'sleep(20);', '42'], [], $oldPipes) : null;
		$oldPid = is_resource($oldProducer) ? proc_get_status($oldProducer)['pid'] : 0;
		try {
			file_put_contents($directory . '/42_.pid', (string) $pid);
			touch($directory . '/42_.pid', time() - 2);
			file_put_contents($directory . '/42_.m3u8', "#EXTM3U\n#EXTINF:1,\n42_3.ts\n#EXTINF:1,\n42_4.ts\n");
			file_put_contents($directory . '/42_3.ts', self::packet('Z', 9));
			file_put_contents($directory . '/42_4.ts', self::packet('A', 0));
			file_put_contents($directory . '/42_5.ts', self::packet('B', 1));
			file_put_contents($directory . '/42_99.ts', self::packet('O', 5));
			$child = <<<'PHP'
<?php
require $argv[1] . '/tests/bootstrap.php';
define('STREAMS_PATH', $argv[2] . '/');
define('SIGNALS_PATH', $argv[2] . '/');
$source = file_get_contents($argv[1] . '/src/Public/stream/live.php');
$start = strpos($source, '$rFails = 0;' . "\n\t\t\t" . '$rTotalFails =');
$end = strpos($source, "\n\t\t\t}\n\t}\n} else {", $start);
if ($start === false || $end === false) { throw new RuntimeException('Delivery loop not found.'); }
$body = substr($source, $start, $end + strlen("\n\t\t\t}") - $start);
$rSettings = ['segment_wait_time' => 1, 'read_buffer_size' => 8192, 'monitor_connection_status' => 0];
$rSegmentSettings = ['seg_time' => 1];
$rChannelInfo = ['pid' => (int) $argv[3]];
$rUserInfo = ['is_restreamer' => (int) $argv[4]];
$rStreamID = 42;
$rCurrent = (int) $argv[5]; // Old next segment is missing, or producer dies mid-read.
$rConSpeedFile = STREAMS_PATH . 'speed';
$rTokenData = ['uuid' => 'test-viewer'];
eval('use XcVm\Streaming\AsyncFileOperations; use XcVm\Core\Process\ProcessManager; use XcVm\Core\Cluster\NodeLease; use XcVm\Streaming\Delivery\TsProducerRecovery; use XcVm\Streaming\Delivery\TsDiscontinuity;' . $body);
PHP;
			file_put_contents($directory . '/viewer.php', $child);
			$viewer = proc_open([PHP_BINARY, $directory . '/viewer.php', dirname(__DIR__, 2), $directory, (string) $oldPid, $restreamer ? '1' : '0', $midSegment ? '98' : '99'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $viewerPipes);
			$this->assertIsResource($viewer);
			$output = '';
			if (is_resource($oldProducer)) {
				// Wait for the old bytes to reach the viewer before killing and
				// reaping its producer, rather than racing a fixed startup sleep.
				$output = stream_get_contents($viewerPipes[1], 188);
				proc_terminate($oldProducer);
				proc_close($oldProducer);
				$oldProducer = null;
			}
			$output .= stream_get_contents($viewerPipes[1]);
			$error = stream_get_contents($viewerPipes[2]);
			fclose($viewerPipes[1]);
			fclose($viewerPipes[2]);
			$this->assertSame(0, proc_close($viewer), $error);
			$reset = pack('C6', 0x47, 1, 0, 0x2f, 183, 0x80) . str_repeat("\xff", 182);
			$expectedBytes = $restreamer ? '' : (($midSegment ? self::packet('O', 5) : '') . $reset . self::packet('A', 0) . self::packet('B', 1));
			$this->assertSame($expectedBytes, $output, $error);
		} finally {
			if (is_resource($oldProducer)) {
				proc_terminate($oldProducer);
				proc_close($oldProducer);
			}
			proc_terminate($producer);
			proc_close($producer);
			foreach (glob($directory . '/*') ?: [] as $file) {
				unlink($file);
			}
			rmdir($directory);
		}
	}
}

<?php

use PHPUnit\Framework\TestCase;

final class LiveStreamReadLoopTest extends TestCase {
	private function runLoop(bool $alive): array {
		$source = file_get_contents(MAIN_HOME . 'Public/stream/live.php');
		$start = strpos($source, 'while ($rFails <= $rTotalFails && !file_exists(STREAMS_PATH . $rNextSegment))');
		// Execute the deployed read loop itself, before its existing recovery arm.
		$end = strpos($source, "\n\t\t\t\tif (ProcessManager::isStreamAlive", $start);
		$this->assertNotFalse($start);
		$this->assertNotFalse($end);
		$loop = substr($source, $start, $end - $start);
		$code = <<<'CHILD'
class ProcessManager { public static function isStreamAlive(...$args) { return $GLOBALS['alive']; } }
class AsyncFileOperations {
    public static int $sleeps = 0;
    public static function efficientSleep($us) {
        if (++self::$sleeps > 3) { throw new RuntimeException('Unbounded read loop after producer death'); }
    }
}
define('STREAMS_PATH', sys_get_temp_dir().'/no-segment-'.bin2hex(random_bytes(8)).'/');
$GLOBALS['alive'] = %ALIVE%;
$rFails=0; $rTotalFails=2; $rNextSegment='next.ts';
$rChannelInfo=['pid'=>123]; $rStreamID=1; $rSettings=['read_buffer_size'=>4096];
$rFP=fopen('php://memory','r+');fwrite($rFP,'packet');rewind($rFP);
ob_start();
%LOOP%
$output=ob_get_clean();
fclose($rFP);
echo json_encode(['output'=>$output,'fails'=>$rFails,'sleeps'=>AsyncFileOperations::$sleeps]);
CHILD;
		$code = str_replace(['%ALIVE%', '%LOOP%'], [$alive ? 'true' : 'false', $loop], $code);
		$process = proc_open([PHP_BINARY, '-r', $code], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $error);
		return json_decode($output, true);
	}

	public function testDeadProducerLeavesTheReadLoopForRestartRecovery(): void {
		$this->assertSame(['output'=>'packet','fails'=>0,'sleeps'=>0], $this->runLoop(false));
	}

	public function testLiveProducerStillDrainsDataAndBoundsAnEmptySegment(): void {
		$this->assertSame(['output'=>'packet','fails'=>3,'sleeps'=>3], $this->runLoop(true));
	}
}

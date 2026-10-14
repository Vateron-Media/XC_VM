<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The real dispatcher and ApiClient, with process/transport stand-ins in a child PHP. */
final class StreamControlDispatcherTest extends TestCase {
	private const HARNESS = <<<'PHP'
namespace XcVm\Core\Cluster { class NodeRpc { public static function request($id,$payload,$timeout) { $GLOBALS['remote'][]=[$id,$payload,$timeout]; return $GLOBALS['replies'][$payload['stream_ids'][0]] ?? $GLOBALS['reply']; } } }
namespace XcVm\Core\License { class LicenseGate { public static function licensed() { return $GLOBALS['licensed']; } } }
namespace XcVm\Core\Logging { class FileLogger { public static function log(...$args) {} } }
namespace XcVm\Domain\Server { class ServerRepository { public static function getAll() { return [1=>['server_online'=>true],2=>['server_online'=>true],3=>['server_online'=>false]]; } } }
namespace XcVm\Infrastructure\Database { class DatabaseFactory { public static function get() { return new class { public function query(...$args) {return true;} public function get_rows() {return $GLOBALS['assignments'];} }; } } }
namespace XcVm\Domain\Stream {
 class StreamSource { public static function streamRow(...$args) { return ['id'=>$args[0]]; } public static function serverRow($id) { return null; } }
 class StreamProcess {
 const MONITOR_PHP='php', MONITOR_FANOUT='fanout';
 public static function startMonitor(...$args) { $GLOBALS['local'][]=['startMonitor',$args]; return $GLOBALS['monitor']; }
 public static function stopStream(...$args) { $GLOBALS['local'][]=['stopStream',$args]; }
 public static function stopMovie(...$args) { $GLOBALS['local'][]=['stopMovie',$args]; }
 public static function startMovie($id,$before=null) { if ($before) { $before(); } $GLOBALS['local'][]=['startMovie',[$id]]; return $GLOBALS['pid']; }
 public static function queueMovie(...$args) { $GLOBALS['local'][]=['queueMovie',$args]; return $GLOBALS['queue']; }
 }
}
namespace {
 define('SERVER_ID',1);
 $dir=sys_get_temp_dir().'/xcvm-controls-'.bin2hex(random_bytes(8)); mkdir($dir);mkdir($dir.'/live');mkdir($dir.'/vod');
 define('STREAMS_PATH',$dir.'/live/');define('VOD_PATH',$dir.'/vod/');
 register_shutdown_function(static function() use($dir) { foreach (['live','vod'] as $sub) { foreach (scandir($dir.'/'.$sub) as $name) { if ($name !== '.' && $name !== '..') { unlink($dir.'/'.$sub.'/'.$name); } } rmdir($dir.'/'.$sub); } rmdir($dir); });
 $input=json_decode(stream_get_contents(STDIN),true);
 $GLOBALS['assignments']=[['server_id'=>1,'stream_id'=>10],['server_id'=>2,'stream_id'=>10],['server_id'=>3,'stream_id'=>10]];
 $GLOBALS['licensed']=true; $GLOBALS['monitor']='php'; $GLOBALS['reply']='{"result":true}';
 $GLOBALS['pid']=123; $GLOBALS['queue']=true; $GLOBALS['local']=[]; $GLOBALS['remote']=[];
 foreach ($input['options'] as $key=>$value) { $GLOBALS[$key]=$value; }
 require %GUARD%;
 require %DISPATCHER%;
 require %CLIENT%;
 $result=\XcVm\Core\Http\ApiClient::request($input['request']);
 echo json_encode(['answer'=>json_decode($result,true),'local'=>$GLOBALS['local'],'remote'=>$GLOBALS['remote']]);
}
PHP;

	private function dispatch(array $rRequest = [], array $rOptions = []): array {
		$rCode = str_replace(['%GUARD%', '%DISPATCHER%', '%CLIENT%'], [var_export(MAIN_HOME . 'Domain/Stream/StreamControlGuard.php', true), var_export(MAIN_HOME . 'Domain/Stream/StreamControlDispatcher.php', true), var_export(MAIN_HOME . 'Core/Http/ApiClient.php', true)], self::HARNESS);
		$rProcess = proc_open([PHP_BINARY, '-r', $rCode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProcess);
		fwrite($rPipes[0], json_encode(['request' => array_replace(['action' => 'stream', 'sub' => 'start', 'stream_ids' => [10], 'servers' => [1]], $rRequest), 'options' => $rOptions]));
		fclose($rPipes[0]);
		$rOutput = stream_get_contents($rPipes[1]);
		$rError = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProcess), $rError . $rOutput);
		$rResult = json_decode($rOutput, true);
		$this->assertIsArray($rResult, $rOutput);
		return $rResult;
	}

	public function testMainRestartUsesLocalMonitorWithoutHttp(): void {
		$rResult = $this->dispatch(['sub' => 'restart']);
		$this->assertTrue($rResult['answer']['result']);
		$this->assertSame([10, 1], array_slice($rResult['local'][0][1], 0, 2));
		$this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $rResult['local'][0][1][2]);
		$this->assertSame([], $rResult['remote']);
	}

	public function testRemoteStartUsesRpcWithoutStartingMain(): void {
		$rResult = $this->dispatch(['servers' => [2]]);
		$this->assertTrue($rResult['answer']['result']);
		$this->assertSame([], $rResult['local']);
		$this->assertSame([[2, ['action' => 'stream', 'function' => 'start', 'stream_ids' => [10]], 5]], $rResult['remote']);
	}

	public function testRemoteFailureRetainsAcceptedLocalWork(): void {
		$rResult = $this->dispatch(['servers' => [1, 2]], ['reply' => '<html>404</html>']);
		$this->assertFalse($rResult['answer']['result']);
		$this->assertSame([['server_id' => 1, 'stream_id' => 10]], $rResult['answer']['accepted']);
		$this->assertSame([['server_id' => 2, 'stream_id' => 10, 'code' => 'REMOTE_CONTROL_FAILED']], $rResult['answer']['errors']);
	}

	public function testUnlicensedStartIsDeniedButStopRemainsPossible(): void {
		$rResult = $this->dispatch([], ['licensed' => false]);
		$this->assertSame('LICENSE_DENIED', $rResult['answer']['error']);
		$this->assertSame([], $rResult['local']);
		$rResult = $this->dispatch(['sub' => 'stop'], ['licensed' => false]);
		$this->assertTrue($rResult['answer']['result']);
		$this->assertSame([['stopStream', [10, true]]], $rResult['local']);
	}

	#[DataProvider('refusedControls')]
	public function testRefusalsReturnFailure(array $rRequest, array $rOptions, string $rCode): void {
		$rResult = $this->dispatch($rRequest, $rOptions);
		$this->assertFalse($rResult['answer']['result']);
		$this->assertSame([], $rResult['answer']['accepted']);
		$this->assertSame($rCode, $rResult['answer']['errors'][0]['code']);
	}

	public static function refusedControls(): array {
		return [
			'empty selection' => [['servers' => []], [], 'INVALID_SERVER_IDS'],
			'zero selection' => [['servers' => 0], [], 'INVALID_SERVER_IDS'],
			'null selection' => [['servers' => null], [], 'INVALID_SERVER_IDS'],
			'false selection' => [['servers' => false], [], 'INVALID_SERVER_IDS'],
			'empty string selection' => [['servers' => ''], [], 'INVALID_SERVER_IDS'],
			'unknown server' => [['servers' => [999]], [], 'UNKNOWN_SERVER'],
			'negative server' => [['servers' => [-1]], [], 'INVALID_SERVER_IDS'],
			'unassigned stream' => [['stream_ids' => [11]], [], 'STREAM_NOT_ASSIGNED_TO_TARGET'],
			'malformed id' => [['stream_ids' => ['1;drop']], [], 'INVALID_STREAM_IDS'],
			'no streams' => [['stream_ids' => []], [], 'INVALID_STREAM_IDS'],
			'offline server' => [['servers' => [3]], [], 'SERVER_OFFLINE'],
			'fenced local' => [[], ['monitor' => 'fenced'], 'LOCAL_CONTROL_REFUSED'],
			'queue refusal' => [['action' => 'vod'], ['queue' => false], 'LOCAL_CONTROL_REFUSED'],
			'encoder refusal' => [['action' => 'vod', 'force' => true], ['pid' => false], 'LOCAL_CONTROL_REFUSED'],
		];
	}

	public function testRemoteBatchReportsEachStreamOutcome(): void {
		$rResult = $this->dispatch(['servers' => [2], 'stream_ids' => [10, 11]], ['assignments' => [['server_id' => 2, 'stream_id' => 10], ['server_id' => 2, 'stream_id' => 11]], 'replies' => [10 => '{"result":false}', 11 => '{"result":true}']]);
		$this->assertFalse($rResult['answer']['result']);
		$this->assertSame([['server_id' => 2, 'stream_id' => 11]], $rResult['answer']['accepted']);
		$this->assertCount(2, $rResult['remote']);
		$this->assertSame(10, $rResult['answer']['errors'][0]['stream_id']);
	}

	public function testExplicitTargetsReportMissingAssignments(): void {
		$rResult = $this->dispatch(['servers' => [1, 2]], ['assignments' => [['server_id' => 1, 'stream_id' => 10]]]);
		$this->assertFalse($rResult['answer']['result']);
		$this->assertSame([['server_id' => 1, 'stream_id' => 10]], $rResult['answer']['accepted']);
		$this->assertSame([['server_id' => 2, 'stream_id' => 10, 'code' => 'STREAM_NOT_ASSIGNED_TO_TARGET']], $rResult['answer']['errors']);
	}

	public function testVodForceAndQueueUseTheirExistingPaths(): void {
		$rResult = $this->dispatch(['action' => 'vod', 'force' => true]);
		$this->assertTrue($rResult['answer']['result']);
		$this->assertSame([['stopMovie', [10, true]], ['startMovie', [10]]], $rResult['local']);
		$rResult = $this->dispatch(['action' => 'vod']);
		$this->assertTrue($rResult['answer']['result']);
		$this->assertSame([['queueMovie', [10]]], $rResult['local']);
	}
}

<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real AJAX entry points must propagate backend rejection, including bulk operations. */
final class StreamControlAjaxTest extends TestCase {
	private const HARNESS = <<<'CHILD'
namespace XcVm\Core\Auth { class Authorization { public static function check(...$args) { return $GLOBALS['allowed']; } } }
namespace XcVm\Core\Http {
 class RequestManager { public static function get($key) { return $GLOBALS['request'][$key] ?? null; } }
 class ApiClient {
  public static function request($request) {
   $accepted=$errors=[];
   foreach ($request['servers'] as $server) {
    if ($server === 2) { $errors[]=['server_id'=>2,'code'=>'REMOTE_CONTROL_FAILED']; }
    else { foreach ($request['stream_ids'] as $stream) { $accepted[]=['server_id'=>$server,'stream_id'=>(int)$stream]; } }
   }
   return json_encode(['result'=>$errors===[],'accepted'=>$accepted,'errors'=>$errors]);
  }
 }
}
namespace {
 $input=json_decode(stream_get_contents(STDIN),true);
 $GLOBALS['request']=$input['request']; $GLOBALS['allowed']=$input['allowed'];
 $_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
 $db=new class {
  public function query($sql,...$args) { $this->sql=$sql; }
  public function get_rows() { return str_contains($this->sql,'SELECT `id` FROM `streams`') ? [['id'=>10]] : [['server_id'=>1,'stream_id'=>10],['server_id'=>2,'stream_id'=>10]]; }
  public function num_rows() { return 2; }
 };
 require %BASE%; require %CONTROLLER%;
 $controller=new $input['controller'](); $method=$input['method']; $controller->$method();
}
CHILD;

	#[DataProvider('controls')]
	public function testControlErrorsReachThePanel(string $rController, string $rMethod, array $rRequest, bool $rAllowed, bool $rPartial): void {
		$rDirectory = MAIN_HOME . 'Public/Controllers/Admin/Ajax/';
		$rCode = str_replace(['%BASE%', '%CONTROLLER%'], [var_export($rDirectory . 'BaseAjaxController.php', true), var_export($rDirectory . $rController . '.php', true)], self::HARNESS);
		$rProcess = proc_open([PHP_BINARY, '-r', $rCode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProcess);
		fwrite($rPipes[0], json_encode(['request' => $rRequest, 'allowed' => $rAllowed, 'controller' => 'XcVm\\Public\\Controllers\\Admin\\Ajax\\' . $rController, 'method' => $rMethod]));
		fclose($rPipes[0]);
		$rOutput = stream_get_contents($rPipes[1]);
		$rError = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProcess), $rError . $rOutput);
		$rAnswer = json_decode($rOutput, true);
		$this->assertIsArray($rAnswer, $rOutput);
		$this->assertFalse($rAnswer['result']);
		if (!$rAllowed) {
			$this->assertSame(['result' => false], $rAnswer);
			return;
		}
		$this->assertSame([['server_id' => 2, 'code' => 'REMOTE_CONTROL_FAILED']], $rAnswer['errors']);
		$this->assertSame($rPartial ? [['server_id' => 1, 'stream_id' => 10]] : [], $rAnswer['accepted']);
	}

	public static function controls(): array {
		return [
			'single live' => ['StreamAjaxController', 'stream', ['stream_id' => 10, 'server_id' => 2, 'sub' => 'start'], true, false],
			'single VOD' => ['StreamAjaxController', 'movie', ['stream_id' => 10, 'server_id' => 2, 'sub' => 'start'], true, false],
			'server streams' => ['ServerAjaxController', 'server', ['server_id' => 2, 'sub' => 'start'], true, false],
			'bulk streams' => ['MultiAjaxController', 'multi', ['type' => 'stream', 'ids' => '[10]', 'sub' => 'start'], true, true],
			'bulk servers' => ['MultiAjaxController', 'multi', ['type' => 'server', 'ids' => '[1,2]', 'sub' => 'start'], true, true],
			'permission gate' => ['StreamAjaxController', 'stream', ['stream_id' => 10, 'server_id' => 2, 'sub' => 'start'], false, false],
		];
	}
}

<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The admin retry endpoint gates each VOD type and forwards the corrected title. */
final class VodImportRetryAjaxTest extends TestCase {
	private const HARNESS = <<<'PHP'
namespace XcVm\Core\Auth { class Authorization { public static function check($type,$key) { return in_array($key,$GLOBALS['permissions'],true); } } }
namespace XcVm\Core\Http { class RequestManager { public static function get($key) { return $GLOBALS['request'][$key] ?? null; } } }
namespace XcVm\Domain\Vod {
 class VodImportLog {
  public static function retry($id,$title,$type) {
   $GLOBALS['called']=['id'=>$id,'title'=>$title,'type'=>$type];
   return $GLOBALS['retry'];
  }
 }
}
namespace {
 $input=json_decode(stream_get_contents(STDIN),true);
 $GLOBALS['request']=$input['request']; $GLOBALS['permissions']=$input['permissions']; $GLOBALS['retry']=$input['retry']; $GLOBALS['called']=null;
 $_SERVER['HTTP_X_REQUESTED_WITH']='XMLHttpRequest';
 require %BASE%; require %CONTROLLER%;
 register_shutdown_function(function(){ file_put_contents('php://stderr',json_encode($GLOBALS['called'])); });
 (new XcVm\Public\Controllers\Admin\Ajax\VodImportAjaxController())->retry();
}
PHP;

	#[DataProvider('requests')]
	public function testRetryEndpoint(array $rRequest, array $rPermissions, bool $rRetry, bool $rResult, ?array $rCalled): void {
		$rDirectory = MAIN_HOME . 'Public/Controllers/Admin/Ajax/';
		$rCode = str_replace(['%BASE%', '%CONTROLLER%'], [var_export($rDirectory . 'BaseAjaxController.php', true), var_export($rDirectory . 'VodImportAjaxController.php', true)], self::HARNESS);
		$rProcess = proc_open([PHP_BINARY, '-r', $rCode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProcess);
		fwrite($rPipes[0], json_encode(['request' => $rRequest, 'permissions' => $rPermissions, 'retry' => $rRetry]));
		fclose($rPipes[0]);
		$rOutput = stream_get_contents($rPipes[1]);
		$rError = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProcess), $rError . $rOutput);
		$this->assertSame($rResult, json_decode($rOutput, true)['result'] ?? null, $rOutput);
		$this->assertSame($rCalled, json_decode($rError, true), $rError);
	}

	public static function requests(): array {
		return [
			'movie' => [['id' => '7', 'type' => '1', 'title' => 'Corrected Movie'], ['import_movies'], true, true, ['id' => 7, 'title' => 'Corrected Movie', 'type' => 1]],
			'series episode' => [['id' => '8', 'type' => '2', 'title' => 'Anne (2016) S01E01'], ['import_episodes'], true, true, ['id' => 8, 'title' => 'Anne (2016) S01E01', 'type' => 2]],
			'wrong permission' => [['id' => '8', 'type' => '2', 'title' => 'Anne'], ['import_movies'], true, false, null],
			'domain refusal' => [['id' => '8', 'type' => '2', 'title' => ''], ['import_episodes'], false, false, ['id' => 8, 'title' => '', 'type' => 2]],
		];
	}
}

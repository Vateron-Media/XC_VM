<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Actual locks, generation checks and VOD rollback with simulated encoder launches. */
final class StreamControlGuardTest extends TestCase {
	private const HARNESS = <<<'CHILD'
namespace XcVm\Cli\Commands { function posix_getpwuid($id) { return ['name'=>'xc_vm']; } }
namespace XcVm\Domain\Stream {
 class StreamSource { public static function serverRow($id) { return null; } }
 class StreamProcess {
  public static function stopMovie($id,$force) { if (file_exists(VOD_PATH.$id.'.mp4')) { unlink(VOD_PATH.$id.'.mp4'); } }
  public static function startMovie($id,$before) { $before(); file_put_contents(VOD_PATH.$id.'.mp4','replacement'); return $GLOBALS['mode']==='accepted' ? 123 : false; }
 }
}
namespace {
 $mode=$GLOBALS['mode']=trim(stream_get_contents(STDIN));
 $dir=sys_get_temp_dir().'/xcvm-guard-'.bin2hex(random_bytes(8));mkdir($dir);
 define('STREAMS_PATH',$dir.'/');define('VOD_PATH',$dir.'/');
 require %GUARD%;
 $guard='XcVm\\Domain\\Stream\\StreamControlGuard';
 if ($mode==='lock' || $mode==='stale') {
  $lock=$guard::acquire(10);$old=$guard::advance($lock);$new=$guard::advance($lock);
  $other=fopen($dir.'/.control-10','c+');$exclusive=!flock($other,LOCK_EX|LOCK_NB);fclose($other);
  $guard::release($lock);
  if ($mode==='stale') {
   require %INTERFACE%;require %MONITOR%;
   $monitor=new \XcVm\Cli\Commands\MonitorCommand();
   echo json_encode(['exit'=>$monitor->execute([10,1,$old])]);
  } else { echo json_encode(['exclusive'=>$exclusive,'advanced'=>$new!==$old]); }
 } else {
  file_put_contents($dir.'/10.mp4','original');
  $lock=$guard::acquire(10,true);$accepted=$guard::replaceMovie(10);$guard::release($lock);
  echo json_encode(['accepted'=>$accepted,'media'=>file_get_contents($dir.'/10.mp4'),'backups'=>glob($dir.'/.replacement-*')]);
 }
 foreach (scandir($dir) as $file) { if ($file!=='.' && $file!=='..') { unlink($dir.'/'.$file); } } rmdir($dir);
}
CHILD;

	#[DataProvider('operations')]
	public function testGuard(string $rMode, array $rExpected): void {
		$rCode = str_replace(['%GUARD%', '%INTERFACE%', '%MONITOR%'], [var_export(MAIN_HOME . 'Domain/Stream/StreamControlGuard.php', true), var_export(MAIN_HOME . 'Cli/CommandInterface.php', true), var_export(MAIN_HOME . 'Cli/Commands/MonitorCommand.php', true)], self::HARNESS);
		$rProcess = proc_open([PHP_BINARY, '-r', $rCode], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProcess);
		fwrite($rPipes[0], $rMode);
		fclose($rPipes[0]);
		$rOutput = stream_get_contents($rPipes[1]);
		$rError = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProcess), $rError . $rOutput);
		$this->assertSame($rExpected, json_decode($rOutput, true), $rOutput);
	}

	public static function operations(): array {
		return [
			'lock excludes another writer' => ['lock', ['exclusive' => true, 'advanced' => true]],
			'old monitor start exits before producing' => ['stale', ['exit' => 0]],
			'failed launch restores old media' => ['rollback', ['accepted' => false, 'media' => 'original', 'backups' => []]],
			'accepted replacement releases backup' => ['accepted', ['accepted' => true, 'media' => 'replacement', 'backups' => []]],
		];
	}
}

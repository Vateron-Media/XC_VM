<?php

use PHPUnit\Framework\TestCase;

final class ToolsImagesModeTwoTest extends TestCase {
	private function images(bool $rOwned): array {
		$rCode = <<<'CODE'
namespace XcVm\Core\Cluster {
 class NodeRole { public static function refusesConnects(){return true;} }
 class ReplicaStreamCache {
 public static function owned(){return $GLOBALS['owned'];}
 public static function index(){return [10=>[],11=>[]];}
 public static function get($id){return ['stream'=>$id===10?['stream_icon'=>'s:2:/images/icon.jpg','movie_properties'=>json_encode(['movie_image'=>'s:2:/images/movie.jpg','cover_big'=>'s:2:/images/cover.jpg','backdrop_path'=>['s:2:/images/backdrop.jpg']])]:['stream_icon'=>'s:3:/images/foreign.jpg','movie_properties'=>json_encode(['cover_big'=>'s:2:/images/h_irreversible.jpg'])]];}
 }
}
namespace XcVm\Core\Config { class SettingsManager { public static function get($key){return 'test-key';} } }
namespace XcVm\Core\Util {
 class Encryption { public static function decrypt($value,...$args){return 'https://images.test/'.$value.'.jpg';} }
 class ImageUtils { public static function downloadImage($url){$GLOBALS['downloads'][]=$url;} }
}
namespace XcVm\Infrastructure\Database { class DatabaseFactory { public static function get(){throw new \LogicException('Mode 2 touched the SQL factory');} } }
namespace {
 require %AUTOLOAD%;require %TOOLS%;define('SERVER_ID',2);define('IMAGES_PATH',sys_get_temp_dir().'/missing-xcvm-images-fixture/');define('OPENSSL_EXTRA','fixture');
 $GLOBALS['owned']=%OWNED%;$GLOBALS['downloads']=[];ob_start();$m=new \ReflectionMethod(\XcVm\Cli\Commands\ToolsCommand::class,'processImages');$m->invoke(new \XcVm\Cli\Commands\ToolsCommand());$output=ob_get_clean();echo json_encode(['downloads'=>$GLOBALS['downloads'],'output'=>$output]);
}
CODE;
		$rCode = str_replace(['%AUTOLOAD%', '%TOOLS%', '%OWNED%'], [var_export(MAIN_HOME . 'vendor/autoload.php', true), var_export(MAIN_HOME . 'Cli/Commands/ToolsCommand.php', true), $rOwned ? 'true' : 'false'], $rCode);
		$rProc = proc_open([PHP_BINARY, '-r', $rCode], [['file', '/dev/null', 'r'], ['pipe', 'w'], ['pipe', 'w']], $rPipes);
		$this->assertIsResource($rProc);
		$rOut = stream_get_contents($rPipes[1]);
		$rError = stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rError);
		return json_decode($rOut, true, 512, JSON_THROW_ON_ERROR);
	}

	public function testModeTwoRestoresOwnReplicaIconsAndMovieCoversWithoutAnySql(): void {
		$this->assertSame(['https://images.test/icon.jpg', 'https://images.test/movie.jpg', 'https://images.test/cover.jpg', 'https://images.test/backdrop.jpg'], $this->images(true)['downloads']);
	}

	public function testMissingReplicaNeverFallsBackToSql(): void {
		$rResult = $this->images(false);
		$this->assertSame([], $rResult['downloads']);
		$this->assertStringContainsString('local stream replica', $rResult['output']);
	}
}

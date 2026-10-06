<?php

use PHPUnit\Framework\TestCase;

/**
 * The image resizer works within fixed limits, whatever a request asks for:
 * no side of the picture it draws is over 1920 px, a remote body over 16 MiB
 * is not taken, a remote source is decoded only when its size can be read and
 * is no more than 12 megapixels. The web player's route to it is for lines of
 * this panel only.
 *
 * The resizer answers and exits, so each case runs in a child PHP. GD and cURL
 * are replaced there by stand-ins that keep sizes only: nothing is drawn or
 * fetched, and a canvas prints as "<width>x<height>".
 */
final class AuditExternalFetchResizeTest extends TestCase {
	private const STAND_INS = <<<'PHP'
		namespace XcVm\Core\Util {
			// A decoder reads more than the size check does: a body with no size to read decodes to 100x100.
			function imagecreatefromstring($rData) { $rSize = \getimagesizefromstring($rData); return $rSize && $rSize[0] > 0 && $rSize[1] > 0 ? [$rSize[0], $rSize[1]] : [100, 100]; }
			function imagesx($rImage) { return $rImage[0]; }
			function imagesy($rImage) { return $rImage[1]; }
			function imagecreatetruecolor($rWidth, $rHeight) { return [(int) $rWidth, (int) $rHeight]; }
			function imagepng($rImage, $rPath = null) { $rOut = $rImage[0] . 'x' . $rImage[1]; return $rPath === null ? print($rOut) : (bool) \file_put_contents($rPath, $rOut); }
			function imagealphablending() {}
			function imagesavealpha() {}
			function imagecopyresampled() {}
			function imagecolorallocatealpha() { return 0; }
			function imagefill() {}
			function imagedestroy() {}
			function curl_init() { return new \stdClass(); }
			function curl_setopt_array($rHandle, array $rOptions) { $rHandle->rOptions = $rOptions; return true; }
			// As libcurl does: the bytes received so far go to the progress function,
			// when one is switched on, and any answer but 0 ends the transfer.
			function curl_exec($rHandle) {
				$rProgress = ($rHandle->rOptions[\CURLOPT_NOPROGRESS] ?? true) ? null : ($rHandle->rOptions[\CURLOPT_PROGRESSFUNCTION] ?? null);
				if ($rProgress !== null && $rProgress($rHandle, 0, \BODY_BYTES, 0, 0) !== 0) {
					return false;
				}
				return \file_get_contents(\IMAGES_PATH . \basename($rHandle->rOptions[\CURLOPT_URL]));
			}
			function curl_getinfo() { return 200; }
			function curl_close() {}
		}
		PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-audit-resize-' . bin2hex(random_bytes(6)) . '/';
		mkdir($this->rDir . 'player', 0775, true);
	}

	protected function tearDown(): void {
		foreach (array_merge(glob($this->rDir . 'player/*') ?: [], glob($this->rDir . '*') ?: []) as $rPath) {
			is_dir($rPath) ? rmdir($rPath) : unlink($rPath);
		}
		rmdir($this->rDir);
	}

	public function testTheSizeAViewAsksForIsDrawnAsAsked(): void {
		$this->assertSame('267x400', $this->resize(['url' => 'poster.png', 'w' => '267', 'h' => '400'], 100, 150));
		$this->assertSame('512x341', $this->resize(['url' => 'logo.png', 'maxw' => '512', 'maxh' => '512'], 3000, 2000));
		$this->assertSame('48x32', $this->resize(['url' => 'icon.png', 'icon' => '1'], 3000, 2000));
	}

	public function testNoSideOfTheOutputIsOverTheLimit(): void {
		$this->assertSame('1920x1920', $this->resize(['url' => 'a.png', 'w' => '10000', 'h' => '10000'], 100, 150));
		$this->assertSame('1920x400', $this->resize(['url' => 'b.png', 'width' => '99999999999999999999', 'height' => '400'], 100, 150));
		$this->assertSame('1920x1280', $this->resize(['url' => 'c.png', 'max' => '50000'], 3000, 2000));
		$this->assertSame('960x1920', $this->resize(['url' => 'd.png', 'maxw' => '9000'], 2000, 4000));
		$this->assertSame('960x1920', $this->resize(['url' => 'e.png', 'w' => '1500'], 2000, 4000));
		$this->assertSame('1920x960', $this->resize(['url' => 'f.png', 'h' => '5000'], 4000, 2000));
	}

	public function testALargerRequestSharesTheCacheFileOfTheLimit(): void {
		$this->resize(['url' => 'poster.png', 'w' => '10000', 'h' => '10000'], 100, 150);
		$this->resize(['url' => 'poster.png', 'w' => '20000', 'h' => '30000'], 100, 150);

		$this->assertSame([md5('poster.png') . '_1920_1920.png'], array_map('basename', glob($this->rDir . 'player/*')));
	}

	public function testARemoteSourceWithTooManyPixelsIsNotDecoded(): void {
		$this->assertSame('100x56', $this->resize(['url' => 'http://8.8.8.8/4k.png', 'max' => '100'], 3840, 2160), 'a 4K frame is within the limit');
		$this->assertSame('100x75', $this->resize(['url' => 'http://8.8.8.8/at.png', 'max' => '100'], 4000, 3000), 'a source of the limit itself is decoded');
		$this->assertSame('1x1', $this->resize(['url' => 'http://8.8.8.8/larger.png', 'max' => '100'], 4000, 3001));
		$this->assertSame([], glob($this->rDir . 'player/' . md5('http://8.8.8.8/larger.png') . '*'), 'nothing is cached for it');
	}

	public function testARemoteSourceOfUnreadableSizeIsNotDecoded(): void {
		$this->assertSame('1x1', $this->resize(['url' => 'http://8.8.8.8/unsized', 'max' => '100'], 0, 0, rBody: 'a body with no size to read'));
		$this->assertSame('1x1', $this->resize(['url' => 'http://8.8.8.8/empty.png', 'max' => '100'], 0, 0), 'a size of nothing is no size');
		$this->assertSame([], glob($this->rDir . 'player/*'), 'nothing is cached for either');
	}

	public function testARemoteBodyOverTheLimitIsNotTaken(): void {
		$this->assertSame('100x67', $this->resize(['url' => 'http://8.8.8.8/at.png', 'max' => '100'], 3000, 2000, 16 * 1024 * 1024), 'a body of the limit itself is taken');
		$this->assertSame('1x1', $this->resize(['url' => 'http://8.8.8.8/over.png', 'max' => '100'], 3000, 2000, 16 * 1024 * 1024 + 1));
	}

	public function testThePlayerRouteServesALineOfThisPanel(): void {
		$this->assertSame('267x400', $this->playerRoute(['id' => 7]));
	}

	public function testThePlayerRouteIsClosedToAnExternalServerSession(): void {
		$this->assertSame('', $this->playerRoute(['id' => 999999, 'is_external_xc' => true]));
		$this->assertSame([], glob($this->rDir . 'player/*'));
	}

	public function testThePlayerRouteIsClosedWithoutASession(): void {
		$this->assertSame('', $this->playerRoute(null));
	}

	/** What the resizer answers to $rQuery when the source is $rWidth x $rHeight (or is $rBody) and a remote one comes as $rBodyBytes. */
	private function resize(array $rQuery, int $rWidth, int $rHeight, int $rBodyBytes = 1024, ?string $rBody = null): string {
		return $this->child($rQuery, $rWidth, $rHeight, 'define("BODY_BYTES", ' . $rBodyBytes . ');'
			. '\XcVm\Core\Util\ImageResizeService::serve(["cacheDir" => IMAGES_PATH . "player/", "extraParams" => true]);', $rBody);
	}

	/** What the web player's resize route answers to a poster request in a session of $rUserInfo. */
	private function playerRoute(?array $rUserInfo): string {
		return $this->child(['url' => 'poster.png', 'w' => '267', 'h' => '400'], 100, 150, '$GLOBALS["rUserInfo"] = ' . var_export($rUserInfo, true) . ';'
			. '(new \XcVm\Public\Controllers\Player\PlayerResizeController())->index();');
	}

	private function child(array $rQuery, int $rWidth, int $rHeight, string $rCall, ?string $rBody = null): string {
		// Signature and header are all the stand-ins read of a PNG.
		$rHeader = 'IHDR' . pack('NNC5', $rWidth, $rHeight, 8, 2, 0, 0, 0);
		file_put_contents($this->rDir . basename($rQuery['url']), $rBody ?? "\x89PNG\r\n\x1a\n" . pack('N', 13) . $rHeader . pack('N', crc32($rHeader)));

		$rCode = self::STAND_INS . ' namespace {'
			. 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'define("IMAGES_PATH", ' . var_export($this->rDir, true) . ');'
			. '$_GET = ' . var_export($rQuery, true) . ';'
			. $rCall
			. '}';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		return $rOut;
	}
}

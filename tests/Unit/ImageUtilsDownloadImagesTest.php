<?php

use PHPUnit\Framework\TestCase;

/**
 * An import fetches its icons several at a time (ImageUtils::downloadImages):
 * each URL gets the reference downloadImage() gave it, at most six transfers
 * are in flight, an error answer stores nothing, a host failing three times in
 * a row is given up for the rest of the import, and an icon already cached is
 * not fetched again.
 *
 * IMAGES_PATH and SERVER_ID are constants, so the downloads run in a child PHP.
 * The icon host is a local `php -S` with several workers that answers after
 * 150 ms and logs when each request started and ended.
 */
final class ImageUtilsDownloadImagesTest extends TestCase {
	private const ROUTER = <<<'PHP'
<?php
$rStart = microtime(true);
usleep(150000);
if (str_starts_with($_SERVER['REQUEST_URI'], '/err/')) {
	http_response_code(503);
	echo 'busy';
} elseif (str_starts_with($_SERVER['REQUEST_URI'], '/gone/')) {
	http_response_code(404);
	echo '<html>Not Found</html>';
} elseif (str_starts_with($_SERVER['REQUEST_URI'], '/moved/')) {
	header('Location: /logo/elsewhere.png', true, 302);
	echo '<html>Moved</html>';
} elseif (str_starts_with($_SERVER['REQUEST_URI'], '/page/')) {
	header('Content-Type: text/html');
	echo '<html>Sign in</html>';
} else {
	header('Content-Type: image/png');
	// A real image (1x1), then the request: each URL's bytes are its own.
	echo base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=') . $_SERVER['REQUEST_URI'];
}
file_put_contents(getenv('XCVM_ICON_LOG'), sprintf("%.6f %.6f %s\n", $rStart, microtime(true), $_SERVER['REQUEST_URI']), FILE_APPEND | LOCK_EX);
PHP;

	private const CHILD = <<<'PHP'
<?php
require %BOOTSTRAP%;
$rIn = json_decode($argv[1], true);
define('IMAGES_PATH', $rIn['dir'] . 'images/');
define('SERVER_ID', 1);
\XcVm\Core\Config\SettingsManager::set(['live_streaming_pass' => 'icons-test-pass']);
$rOut = ['batch' => \XcVm\Core\Util\ImageUtils::downloadImages($rIn['urls'], $rIn['parallel'])];
if ($rIn['single']) {
	ini_set('default_socket_timeout', '0'); // as an import runs downloadImage()
	foreach ($rIn['urls'] as $rURL) {
		$rOut['single'][] = is_string($rURL) ? \XcVm\Core\Util\ImageUtils::downloadImage($rURL, 1) : $rURL;
	}
}
echo json_encode($rOut);
PHP;

	private string $rDir;

	/** @var resource|null */
	private $rServer = null;

	private int $rPort;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-icons-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'images', 0777, true);
		file_put_contents($this->rDir . 'router.php', self::ROUTER);
		file_put_contents($this->rDir . 'child.php', str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), self::CHILD));

		$rProbe = stream_socket_server('tcp://127.0.0.1:0');
		$this->rPort = (int) explode(':', stream_socket_get_name($rProbe, false))[1];
		fclose($rProbe);
		$rNull = ['file', '/dev/null', 'w'];
		$this->rServer = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $this->rPort, $this->rDir . 'router.php'], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes, null, ['PHP_CLI_SERVER_WORKERS' => '8', 'XCVM_ICON_LOG' => $this->rDir . 'requests.log']) ?: null;
		for ($i = 0; $i < 100 && !@fsockopen('127.0.0.1', $this->rPort); $i++) {
			usleep(50000);
		}
	}

	protected function tearDown(): void {
		if ($this->rServer !== null) {
			proc_terminate($this->rServer, 9);
			proc_close($this->rServer);
		}
		exec('pkill -9 -f ' . escapeshellarg('127.0.0.1:' . $this->rPort));
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function url(string $rPath): string {
		return 'http://127.0.0.1:' . $this->rPort . $rPath;
	}

	/** @return array{batch: array<string, string>, single?: list<mixed>} */
	private function download(array $rURLs, int $rParallel = 6, bool $rSingle = false): array {
		$rIn = ['dir' => $this->rDir, 'urls' => $rURLs, 'parallel' => $rParallel, 'single' => $rSingle];
		exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $this->rDir . 'child.php', (string) json_encode($rIn)])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$rAnswer = json_decode(implode("\n", $rOut), true);
		$this->assertIsArray($rAnswer, implode("\n", $rOut));
		return $rAnswer;
	}

	/** @return list<array{0: float, 1: float, 2: string}> the requests the icon host answered */
	private function requests(): array {
		$rFile = $this->rDir . 'requests.log';
		return is_file($rFile) ? array_map(static fn(string $rLine): array => [(float) explode(' ', $rLine)[0], (float) explode(' ', $rLine)[1], explode(' ', $rLine)[2]], file($rFile, FILE_IGNORE_NEW_LINES)) : [];
	}

	/** The most requests the host was answering at once. */
	private function mostAtOnce(): int {
		$rEvents = [];
		foreach ($this->requests() as [$rStart, $rEnd]) {
			$rEvents[] = [$rStart, 1];
			$rEvents[] = [$rEnd, -1];
		}
		usort($rEvents, static fn(array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
		$rNow = $rMost = 0;
		foreach ($rEvents as [, $rStep]) {
			$rNow += $rStep;
			$rMost = max($rMost, $rNow);
		}
		return $rMost;
	}

	public function testEachURLGetsWhatDownloadImageGaveIt(): void {
		$rURLs = [$this->url('/logo/1.png'), $this->url('/logo/2.JPG'), $this->url('/logo/1.png'), $this->url('/logo/noext'), 'ftp://example.test/a.png', '/local/a.png', '', null, 5];

		$rAnswer = $this->download($rURLs, 6, true);

		$rStrings = array_values(array_filter($rURLs, 'is_string'));
		foreach ($rStrings as $i => $rURL) {
			$this->assertSame($rAnswer['single'][array_search($rURL, $rURLs, true)], $rAnswer['batch'][$rURL], $rURL);
		}
		$this->assertStringStartsWith('s:1:/images/', $rAnswer['batch'][$this->url('/logo/1.png')]);
		$this->assertSame($this->url('/logo/noext'), $rAnswer['batch'][$this->url('/logo/noext')], 'no type probe');
		$this->assertCount(2, array_filter($this->requests(), static fn(array $rRequest): bool => $rRequest[2] === '/logo/1.png' || $rRequest[2] === '/logo/2.JPG'), 'the batch asked once per URL, then the files were there');
	}

	/**
	 * Only an image is cached as one. A 404 page, a redirect's body and a page
	 * answered 200 were each written under the image's name and, the file
	 * being there, never fetched again.
	 */
	public function testAnAnswerThatIsNoImageIsNotCached(): void {
		$rURLs = [$this->url('/gone/a.png'), $this->url('/moved/b.png'), $this->url('/page/c.png'), $this->url('/logo/d.png')];

		$rAnswer = $this->download($rURLs, 6, true);

		foreach (array_slice($rURLs, 0, 3) as $rIndex => $rURL) {
			$this->assertSame($rURL, $rAnswer['single'][$rIndex], 'downloadImage: ' . $rURL);
			$this->assertSame($rURL, $rAnswer['batch'][$rURL], 'downloadImages: ' . $rURL);
		}
		$this->assertStringStartsWith('s:1:/images/', $rAnswer['batch'][$rURLs[3]]);
		$this->assertCount(1, glob($this->rDir . 'images/*') ?: [], 'the one image, and no page under an image\'s name');
	}

	public function testUpToSixTransfersAtOnceAndNoneAgainOnceCached(): void {
		$rURLs = array_map(fn(int $i): string => $this->url('/logo/' . $i . '.png'), range(1, 14));

		$rAnswer = $this->download($rURLs);

		$this->assertCount(14, array_filter($rAnswer['batch'], static fn(string $rValue): bool => str_starts_with($rValue, 's:1:/images/')));
		$this->assertCount(14, glob($this->rDir . 'images/*.png'));
		$this->assertGreaterThanOrEqual(2, $this->mostAtOnce());
		$this->assertLessThanOrEqual(6, $this->mostAtOnce());

		$this->download($rURLs);
		$this->assertCount(14, $this->requests(), 'cached icons are not fetched again');
	}

	public function testAnErrorAnswerStoresNothingAndAFailingHostIsGivenUp(): void {
		$rURLs = array_map(fn(int $i): string => $this->url('/err/' . $i . '.png'), range(1, 30));

		$rAnswer = $this->download($rURLs, 4);

		$this->assertSame(array_combine($rURLs, $rURLs), $rAnswer['batch']);
		$this->assertSame([], glob($this->rDir . 'images/*'));
		$this->assertLessThanOrEqual(6, count($this->requests()), 'three failures, and what was already in flight');
	}
}

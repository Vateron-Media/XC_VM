<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\GeoIP\GeoLiteReleaseUpdater;
use XcVm\Core\Updates\GitHubReleases;

/** The updater with its download step callable, and its version file kept in memory. */
class AuditCoreDataGeoLiteUpdater extends GeoLiteReleaseUpdater {
	/** @var array<string,mixed> */
	public array $rRecorded = [];

	/** @param array{fileurl: string, path: string, md5: ?string} $rFile */
	public function fetch(array $rFile, bool $rForce = false): ?bool {
		return $this->downloadReleaseFile($rFile, $rForce);
	}

	protected function recordVersion(string $rKey, mixed $rVersion): void {
		$this->rRecorded[$rKey] = $rVersion;
	}
}

/**
 * The GeoIP databases that come from a GitHub release replace the ones in
 * use only when the release answered with a database: a 200 whose body the
 * MaxMind reader opens. Anything else (an error page, a truncated or foreign
 * body) leaves the current database where it is, and the new one is swapped
 * in whole, so a lookup never reads half a file.
 *
 * The release is a local HTTP listener that serves the files of a directory.
 */
final class AuditCoreDataGeoLiteDownloadTest extends TestCase {
	private string $rDir;

	private int $rPort;

	/** @var resource|null */
	private $rListener = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-geolite-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'release', 0777, true);
		mkdir($this->rDir . 'maxmind', 0777, true);

		file_put_contents($this->rDir . 'listener.php', <<<'PHP'
<?php
$rServer = stream_socket_server('tcp://127.0.0.1:0', $rErrNo, $rErr);
file_put_contents($argv[1] . 'port.tmp', explode(':', stream_socket_get_name($rServer, false))[1]);
rename($argv[1] . 'port.tmp', $argv[1] . 'port');
$rUntil = time() + 30;
while (time() < $rUntil) {
	$rConn = @stream_socket_accept($rServer, 1);
	if (!$rConn) {
		continue;
	}
	stream_set_timeout($rConn, 2);
	$rRequest = (string) fgets($rConn);
	while (($rLine = fgets($rConn)) !== false && trim($rLine) !== '') {
	}
	$rFile = $argv[1] . 'release/' . basename((string) (explode(' ', $rRequest)[1] ?? ''));
	$rBody = is_file($rFile) ? file_get_contents($rFile) : '<html><body>Not Found</body></html>';
	fwrite($rConn, 'HTTP/1.1 ' . (is_file($rFile) ? '200 OK' : '404 Not Found') . "\r\nContent-Length: " . strlen($rBody) . "\r\nConnection: close\r\n\r\n" . $rBody);
	fclose($rConn);
}
PHP);
		$rNull = ['file', '/dev/null', 'w'];
		$this->rListener = proc_open([PHP_BINARY, $this->rDir . 'listener.php', $this->rDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 250 && !file_exists($this->rDir . 'port'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . 'port', 'the listener did not start');
		$this->rPort = (int) file_get_contents($this->rDir . 'port');

		ob_start(); // the updater prints a status line per file
	}

	protected function tearDown(): void {
		ob_end_clean();
		if ($this->rListener !== null) {
			proc_terminate($this->rListener, 9);
			proc_close($this->rListener);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A MaxMind database with no network in it: one node, both of its records "no data". $rDescription: up to 28 bytes. */
	private static function database(string $rDescription): string {
		$rString = static fn(string $rText): string => chr(0x40 | strlen($rText)) . $rText;
		return "\x00\x00\x01\x00\x00\x01" . str_repeat("\x00", 16) . "\xAB\xCD\xEFMaxMind.com" . "\xE9"
			. $rString('binary_format_major_version') . "\xA1\x02"
			. $rString('binary_format_minor_version') . "\xA0"
			. $rString('build_epoch') . "\x04\x02" . pack('N', 1800000000)
			. $rString('database_type') . $rString('GeoLite2-Country')
			. $rString('description') . "\xE1" . $rString('en') . $rString($rDescription)
			. $rString('ip_version') . "\xA1\x04"
			. $rString('languages') . "\x01\x04" . $rString('en')
			. $rString('node_count') . "\xC1\x01"
			. $rString('record_size') . "\xA1\x18";
	}

	private function updater(): AuditCoreDataGeoLiteUpdater {
		return new AuditCoreDataGeoLiteUpdater(new GitHubReleases('Vateron-Media', 'XC_VM_Update', 'stable'));
	}

	/** @return array{fileurl: string, path: string, md5: ?string} what the release is asked for $rAsset */
	private function spec(string $rAsset, ?string $rMd5 = null): array {
		return ['fileurl' => 'http://127.0.0.1:' . $this->rPort . '/' . $rAsset, 'path' => $this->rDir . 'maxmind/GeoLite2-Country.mmdb', 'md5' => $rMd5];
	}

	public function testAnAnswerThatIsNotADatabaseLeavesTheCurrentOneInPlace(): void {
		$rPath = $this->rDir . 'maxmind/GeoLite2-Country.mmdb';
		$rCurrent = self::database('current');
		file_put_contents($rPath, $rCurrent);
		file_put_contents($this->rDir . 'release/page.mmdb', '<html><body>Too many requests</body></html>');
		file_put_contents($this->rDir . 'release/cut.mmdb', substr(self::database('next'), 0, 30));

		// The release has no such asset: a 404 with a page for a body.
		$this->assertNull($this->updater()->fetch($this->spec('absent.mmdb')));
		$this->assertSame($rCurrent, file_get_contents($rPath), 'after a 404');

		// A 200 whose body is not a database, whatever the checksum file says of it.
		foreach (['page.mmdb', 'cut.mmdb'] as $rAsset) {
			foreach ([null, md5_file($this->rDir . 'release/' . $rAsset)] as $rMd5) {
				$this->assertNull($this->updater()->fetch($this->spec($rAsset, $rMd5), true), $rAsset);
				$this->assertSame($rCurrent, file_get_contents($rPath), 'after ' . $rAsset);
			}
		}
		$this->assertSame(['.', '..', 'GeoLite2-Country.mmdb'], scandir($this->rDir . 'maxmind'), 'nothing is left beside it');
	}

	public function testADatabaseReplacesTheCurrentOne(): void {
		$rPath = $this->rDir . 'maxmind/GeoLite2-Country.mmdb';
		file_put_contents($rPath, self::database('current'));
		$rNext = self::database('next');
		file_put_contents($this->rDir . 'release/GeoLite2-Country.mmdb', $rNext);

		$this->assertTrue($this->updater()->fetch($this->spec('GeoLite2-Country.mmdb', md5($rNext))));
		$this->assertSame($rNext, file_get_contents($rPath));
		clearstatcache();
		$this->assertSame(0750, fileperms($rPath) & 0777);
		$this->assertSame(['.', '..', 'GeoLite2-Country.mmdb'], scandir($this->rDir . 'maxmind'));
		$rReader = new \MaxMind\Db\Reader($rPath);
		$this->assertNull($rReader->get('203.0.113.5'));
		$rReader->close();

		// Already the release's file: not fetched again.
		$this->assertFalse($this->updater()->fetch($this->spec('absent.mmdb', md5($rNext))));

		// The checksum file can lag a release: a database is kept without its agreement, or without it.
		foreach (['0123456789abcdef0123456789abcdef', null] as $i => $rMd5) {
			file_put_contents($this->rDir . 'release/GeoLite2-Country.mmdb', $rNext = self::database('later ' . $i));
			$this->assertTrue($this->updater()->fetch($this->spec('GeoLite2-Country.mmdb', $rMd5)));
			$this->assertSame($rNext, file_get_contents($rPath));
		}
	}

	public function testTheVersionIsRecordedOnlyWhenEveryFileCameThrough(): void {
		$rRepo = $this->getMockBuilder(GitHubReleases::class)
			->setConstructorArgs(['Vateron-Media', 'XC_VM_Update', 'stable'])
			->onlyMethods(['getReleases', 'assetUrl', 'getAssetHash'])
			->getMock();
		$rRepo->method('getReleases')->willReturn(['29062026']);
		$rRepo->method('assetUrl')->willReturn('http://127.0.0.1:' . $this->rPort . '/absent.mmdb');
		$rRepo->method('getAssetHash')->willReturn(null);

		$rUpdater = $this->getMockBuilder(AuditCoreDataGeoLiteUpdater::class)
			->setConstructorArgs([$rRepo])
			->onlyMethods(['downloadReleaseFile'])
			->getMock();
		$rUpdater->method('downloadReleaseFile')->willReturnOnConsecutiveCalls(true, null, true, false);

		$this->assertTrue($rUpdater->updateGeoLite(false), 'one of the two failed');
		$this->assertSame([], $rUpdater->rRecorded);

		$this->assertFalse($rUpdater->updateGeoLite(false), 'one fetched, one already current');
		$this->assertSame(['geolite2_version' => '29062026'], $rUpdater->rRecorded);
	}
}

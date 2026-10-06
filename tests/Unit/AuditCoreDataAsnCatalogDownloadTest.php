<?php

use PHPUnit\Framework\TestCase;

/**
 * The ASN catalogue that comes from a GitHub release replaces the local copy
 * only when the release answered 200. sync() prunes every reference row the
 * local copy does not name, so the body of an error answer must never become
 * that copy.
 *
 * The release is a local HTTP listener that serves the files of a directory;
 * the download runs in a child PHP, where what it would write under
 * /home/xc_vm goes to a throwaway directory instead.
 */
final class AuditCoreDataAsnCatalogDownloadTest extends TestCase {
	private string $rDir;

	private int $rPort;

	/** @var resource|null */
	private $rListener = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-asn-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'release', 0777, true);
		mkdir($this->rDir . 'written', 0777, true);

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
	$rBody = is_file($rFile) ? file_get_contents($rFile) : '{"message":"Not Found","status":"404"}';
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
	}

	protected function tearDown(): void {
		if ($this->rListener !== null) {
			proc_terminate($this->rListener, 9);
			proc_close($this->rListener);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** AsnCatalogSync::download(true) against the listener: whether it says a new file was written. */
	private function downloaded(): bool {
		$rScript = $this->rDir . 'download.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Core\Updates {
				// The newest release, its assets answered by the listener.
				final class GitHubReleases {
					public function __construct(mixed ...$rArgs) {
					}

					public function getReleases(): array {
						return ['29062026'];
					}

					public function assetUrl(string $rVersion, string $rAsset): string {
						return $GLOBALS['argv'][2] . $rAsset;
					}

					public function getAssetHash(string $rVersion, string $rAsset): ?string {
						return null;
					}
				}
			}

			namespace XcVm\Core\GeoIP {
				// The catalogue's place is fixed under /home/xc_vm: this child writes it to the throwaway directory.
				function file_put_contents(string $rPath, string $rData): int|false {
					return \file_put_contents($GLOBALS['argv'][1] . 'written/' . basename($rPath), $rData);
				}

				function chown(string $rPath, string $rUser): bool {
					return true;
				}

				function chmod(string $rPath, int $rMode): bool {
					return true;
				}
			}

			namespace {
				define('GIT_OWNER', 'Vateron-Media');
				define('GIT_REPO_UPDATE', 'XC_VM_Update');
				require $argv[3] . 'vendor/autoload.php';
				$rDownload = new \ReflectionMethod(\XcVm\Core\GeoIP\AsnCatalogSync::class, 'download');
				$rDownload->setAccessible(true);
				echo json_encode($rDownload->invoke(null, true));
			}
			PHP);
		exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $rScript, $this->rDir, 'http://127.0.0.1:' . $this->rPort . '/', MAIN_HOME])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		$this->assertContains(implode("\n", $rOut), ['true', 'false']);
		return implode("\n", $rOut) === 'true';
	}

	public function testAnErrorAnswerIsNotSavedAsTheCatalogue(): void {
		// The release has no such asset: a 404 whose body is JSON, as the catalogue is.
		$this->assertFalse($this->downloaded());
		$this->assertSame(['.', '..'], scandir($this->rDir . 'written'));
	}

	public function testTheCatalogueOfTheReleaseIsSaved(): void {
		$rCatalogue = gzencode((string) json_encode([['asn' => 64496, 'isp' => 'Example', 'domain' => 'example.net', 'country' => 'NL', 'num_ips' => 256, 'type' => 'hosting']]));
		file_put_contents($this->rDir . 'release/blocked_asns.json.gz', $rCatalogue);

		$this->assertTrue($this->downloaded());
		$this->assertSame($rCatalogue, file_get_contents($this->rDir . 'written/blocked_asns.json.gz'));
	}
}

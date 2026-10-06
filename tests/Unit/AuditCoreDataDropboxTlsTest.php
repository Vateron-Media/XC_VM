<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Storage\DropboxClient;

/**
 * The Dropbox client carries the account's token and the database dumps, so
 * it talks only to a server whose certificate a trusted authority signed.
 *
 * Every request leaves through DropboxClient::createCurl(). The test points a
 * handle it built at a local TLS listener that presents a self-signed
 * certificate for Dropbox's name.
 */
final class AuditCoreDataDropboxTlsTest extends TestCase {
	private const HOST = 'api.dropboxapi.com';

	private string $rDir;

	/** @var resource|null */
	private $rListener = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-dropbox-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0777, true);
	}

	protected function tearDown(): void {
		if ($this->rListener !== null) {
			proc_terminate($this->rListener, 9);
			proc_close($this->rListener);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Starts the listener and returns its port; it logs every request it is sent to requests.log. */
	private function listen(): int {
		$rKey = @openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$rRequest = $rKey === false ? false : @openssl_csr_new(['commonName' => self::HOST], $rKey);
		if ($rRequest === false) {
			$this->markTestSkipped('This OpenSSL cannot make the listener\'s certificate.');
		}
		$rCert = openssl_csr_sign($rRequest, null, $rKey, 1);
		openssl_x509_export($rCert, $rCertPem);
		openssl_pkey_export($rKey, $rKeyPem);
		file_put_contents($this->rDir . 'cert.pem', $rCertPem . $rKeyPem);

		file_put_contents($this->rDir . 'listener.php', <<<'PHP'
<?php
$rServer = stream_socket_server('ssl://127.0.0.1:0', $rErrNo, $rErr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => ['local_cert' => $argv[1] . 'cert.pem']]));
file_put_contents($argv[1] . 'port.tmp', explode(':', stream_socket_get_name($rServer, false))[1]);
rename($argv[1] . 'port.tmp', $argv[1] . 'port');
$rUntil = time() + 20;
while (time() < $rUntil) {
	$rConn = @stream_socket_accept($rServer, 1);
	if (!$rConn) {
		continue;
	}
	stream_set_timeout($rConn, 2);
	$rRequest = '';
	while (!str_contains($rRequest, "\r\n\r\n") && ($rLine = fgets($rConn)) !== false) {
		$rRequest .= $rLine;
	}
	file_put_contents($argv[1] . 'requests.log', $rRequest, FILE_APPEND);
	fwrite($rConn, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: 2\r\nConnection: close\r\n\r\n{}");
	fclose($rConn);
}
PHP);
		$rNull = ['file', '/dev/null', 'w'];
		$this->rListener = proc_open([PHP_BINARY, $this->rDir . 'listener.php', $this->rDir], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes) ?: null;
		for ($i = 0; $i < 250 && !file_exists($this->rDir . 'port'); $i++) {
			usleep(20000);
		}
		$this->assertFileExists($this->rDir . 'port', 'the listener did not start');
		return (int) file_get_contents($this->rDir . 'port');
	}

	public function testARequestIsNotSentToAServerWhoseCertificateNoAuthoritySigned(): void {
		$rPort = $this->listen();

		$rCreate = new ReflectionMethod(DropboxClient::class, 'createCurl');
		$rCreate->setAccessible(true);
		$rCurl = $rCreate->invoke(
			new DropboxClient(['app_key' => '', 'app_secret' => '']),
			'https://' . self::HOST . ':' . $rPort . '/2/files/list_folder',
			['method' => 'POST', 'header' => 'Authorization: Bearer the-account-token', 'content' => '{"path":""}']
		);
		// Dropbox's name, answered by the listener: nothing leaves this machine.
		curl_setopt($rCurl, CURLOPT_RESOLVE, [self::HOST . ':' . $rPort . ':127.0.0.1']);
		curl_setopt($rCurl, CURLOPT_TIMEOUT, 10);

		$rAnswer = curl_exec($rCurl);
		$rError = curl_errno($rCurl) . ' ' . curl_error($rCurl);
		curl_close($rCurl);

		$this->assertFalse($rAnswer, 'the listener\'s answer was accepted');
		$this->assertStringStartsWith('60 ', $rError, 'refused for its certificate');
		$this->assertStringNotContainsString('the-account-token', (string) @file_get_contents($this->rDir . 'requests.log'));
	}
}

<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\StartupCommand;

/**
 * StartupCommand::hardenTls: a node on the archive's placeholder TLS key (in
 * git, so shared) gets its own, and the archive's old ssl.conf (SSLv3, TLS 1.1)
 * becomes TLS 1.2/1.3; what anyone else wrote is left alone.
 */
final class StartupTlsTest extends TestCase {
	private const CONF = MAIN_HOME . 'bin/nginx/conf/';

	private string $dir;

	protected function setUp(): void {
		$this->dir = sys_get_temp_dir() . '/xcvm_tls_' . uniqid() . '/';
		mkdir($this->dir);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->dir));
	}

	public function testTheShippedFilesAreTheOnesItKnows(): void {
		$this->assertContains(hash_file('sha256', self::CONF . 'server.key'), StartupCommand::PLACEHOLDER_KEYS, 'a new placeholder key: add its sha256 to PLACEHOLDER_KEYS');
		$this->assertSame(StartupCommand::SSL_CONF, file_get_contents(self::CONF . 'ssl.conf'));
	}

	public function testAPlaceholderKeyAndTheOldSslConfAreReplacedOnce(): void {
		copy(self::CONF . 'server.key', $this->dir . 'server.key');
		copy(self::CONF . 'server.crt', $this->dir . 'server.crt');
		file_put_contents($this->dir . 'ssl.conf', 'ssl_certificate server.crt; ssl_certificate_key server.key; ssl_protocols SSLv3 TLSv1.1 TLSv1.2;');
		$this->assertSame(StartupCommand::OLD_SSL_CONF, hash_file('sha256', $this->dir . 'ssl.conf'));

		ob_start();
		$rChanged = StartupCommand::hardenTls($this->dir);
		ob_end_clean();
		$this->assertTrue($rChanged);

		$rKey = file_get_contents($this->dir . 'server.key');
		$this->assertNotContains(hash('sha256', $rKey), StartupCommand::PLACEHOLDER_KEYS);
		$this->assertTrue(openssl_x509_check_private_key(file_get_contents($this->dir . 'server.crt'), $rKey), 'the new certificate is the new key\'s');
		$this->assertSame(['CN' => gethostname() ?: 'xc_vm'], openssl_x509_parse(file_get_contents($this->dir . 'server.crt'))['subject'], 'only the host name, no sample subject');
		$this->assertSame(0600, fileperms($this->dir . 'server.key') & 0777);
		$this->assertSame(StartupCommand::SSL_CONF, file_get_contents($this->dir . 'ssl.conf'));
		$this->assertSame([], glob($this->dir . '.tls_*'));

		$this->assertFalse(StartupCommand::hardenTls($this->dir), 'nothing left to change');
		$this->assertSame($rKey, file_get_contents($this->dir . 'server.key'));
	}

	public function testAKeyAndAnSslConfOfItsOwnAreLeftAlone(): void {
		file_put_contents($this->dir . 'server.key', "an operator's key\n");
		$rConf = "ssl_certificate server.crt; ssl_certificate_key server.key; ssl_protocols TLSv1 TLSv1.1 TLSv1.2;\n";
		file_put_contents($this->dir . 'ssl.conf', $rConf);

		$this->assertFalse(StartupCommand::hardenTls($this->dir));
		$this->assertSame("an operator's key\n", file_get_contents($this->dir . 'server.key'));
		$this->assertSame($rConf, file_get_contents($this->dir . 'ssl.conf'));
	}
}

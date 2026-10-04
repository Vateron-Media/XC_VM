<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\InstallCredentials;

/**
 * SSH credentials for server:install no longer travel on argv (visible in
 * ps and /proc) or sit in a plaintext bin/install/<id>.json: the panel writes
 * a 0600 <id>.cred that the command reads and deletes. The node's SSH host key
 * is pinned: an expected fingerprint, else the one stored at first install.
 */
final class InstallCredentialsTest extends TestCase {

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm_install_' . uniqid('', true) . '/';
		mkdir($this->rDir, 0750, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
		@rmdir($this->rDir);
	}

	public function testCredFileIsPrivateAndReadOnce(): void {
		$rPath = InstallCredentials::write(7, 'root', "p'a\"ss w0rd", $this->rDir);
		$this->assertSame($this->rDir . '7.cred', $rPath);
		$this->assertSame(0600, fileperms($rPath) & 0777);

		$this->assertSame(['username' => 'root', 'password' => "p'a\"ss w0rd"], InstallCredentials::consume($rPath, $this->rDir));
		$this->assertFileDoesNotExist($rPath, 'deleted as soon as it is read');
		$this->assertNull(InstallCredentials::consume($rPath, $this->rDir));
	}

	public function testConsumeOnlyAcceptsCredFilesInTheInstallDir(): void {
		$rOther = sys_get_temp_dir() . '/xcvm_' . uniqid('', true) . '.cred';
		file_put_contents($rOther, json_encode(['u' => 'x', 'p' => 'y']));
		$this->assertNull(InstallCredentials::consume($rOther, $this->rDir), 'outside the install dir');
		$this->assertFileExists($rOther, 'and not deleted either');
		@unlink($rOther);

		file_put_contents($this->rDir . '7.json', json_encode(['u' => 'x', 'p' => 'y']));
		$this->assertNull(InstallCredentials::consume($this->rDir . '7.json', $this->rDir), 'not a .cred');
		$this->assertNull(InstallCredentials::consume($this->rDir . '../' . basename($this->rDir) . '/nope.cred', $this->rDir));
	}

	public function testCommandCarriesNoPassword(): void {
		$rCommand = InstallCredentials::command(2, 9, 22, 'root', 'S3cret!pw', ['80', '443', '1'], 'SHA1:' . rtrim(base64_encode(sha1('k', true)), '='));
		$this->assertStringNotContainsString('S3cret!pw', $rCommand);
		$this->assertStringContainsString('server:install 2 9 22 - - 80 443 1 --cred-file=', $rCommand);
		$this->assertStringContainsString('--expect-hostkey=' . sha1('k'), $rCommand);
		$this->assertStringNotContainsString('--forget-hostkey', $rCommand, 'only when the admin asks');
		$this->assertStringContainsString(' --forget-hostkey=1 > ', InstallCredentials::command(2, 9, 22, 'root', 'S3cret!pw', ['80', '443', '1'], '', true));
		$rCred = InstallCredentials::dir() . '9.cred';
		$this->assertFileExists($rCred);
		$this->assertSame('S3cret!pw', InstallCredentials::consume($rCred)['password']);
	}

	public function testSplitOptions(): void {
		[$rArgs, $rOpts] = InstallCredentials::splitOptions(['2', '9', '22', '-', '-', '--cred-file=/x/9.cred', '80', '--expect-hostkey=ab']);
		$this->assertSame(['2', '9', '22', '-', '-', '80'], $rArgs);
		$this->assertSame(['cred-file' => '/x/9.cred', 'expect-hostkey' => 'ab'], $rOpts);
	}

	public function testHostKeyFormats(): void {
		$rHex = sha1('hostkey');
		$this->assertSame($rHex, InstallCredentials::normalizeHostKey(strtoupper($rHex)));
		$this->assertSame($rHex, InstallCredentials::normalizeHostKey(implode(':', str_split($rHex, 2))));
		$rKeygen = '256 SHA1:' . rtrim(base64_encode(sha1('hostkey', true)), '=') . ' root@lb (ED25519)';
		$this->assertSame($rHex, InstallCredentials::normalizeHostKey($rKeygen));
		$this->assertNull(InstallCredentials::normalizeHostKey(''));
		$this->assertNull(InstallCredentials::normalizeHostKey('SHA256:abcdef'));
		$this->assertNull(InstallCredentials::normalizeHostKey('abc'));
	}

	public function testHostKeyPinning(): void {
		$rA = sha1('a');
		$rB = sha1('b');
		$this->assertNull(InstallCredentials::checkHostKey($rA, null, null), 'first install: trust on first use');
		$this->assertNull(InstallCredentials::checkHostKey($rA, null, $rA), 'reinstall, same key');
		$this->assertNotNull(InstallCredentials::checkHostKey($rB, null, $rA), 'reinstall, key changed');
		$this->assertStringContainsString('Forget the saved SSH host key', (string) InstallCredentials::checkHostKey($rB, null, $rA), 'the refusal names the way out');
		$this->assertNull(InstallCredentials::checkHostKey($rB, $rB, $rA), 'admin supplied the rebuilt node\'s key');
		$this->assertNotNull(InstallCredentials::checkHostKey($rA, $rB, null), 'expected key not presented');
		$this->assertNotNull(InstallCredentials::checkHostKey('', null, null), 'no key at all');
	}

	public function testLegacyMetadataIsScrubbed(): void {
		file_put_contents($this->rDir . '3.json', json_encode(['root_username' => 'root', 'root_password' => 'old', 'ssh_port' => 22]));
		file_put_contents($this->rDir . '4.json', json_encode(['root_username' => 'root', 'ssh_port' => 22]));
		$this->assertSame(1, InstallCredentials::scrubLegacyMetadata($this->rDir));
		$this->assertSame(['root_username' => 'root', 'ssh_port' => 22], json_decode(file_get_contents($this->rDir . '3.json'), true));
	}

	public function testNothingWritesThePasswordToMetadataOrArgv(): void {
		$rRoot = MAIN_HOME;
		foreach (['Cli/Commands/LbInstallFlow.php', 'Cli/Commands/ProxyInstallFlow.php'] as $rFile) {
			$this->assertStringNotContainsString("'root_password' =>", (string) file_get_contents($rRoot . $rFile), $rFile);
		}
		foreach (['Domain/Server/ServerService.php', 'Public/Controllers/Admin/Ajax/ServerAjaxController.php'] as $rFile) {
			$this->assertStringNotContainsString("escapeshellarg(\$rData['root_password'])", (string) file_get_contents($rRoot . $rFile), $rFile);
			$this->assertStringNotContainsString("escapeshellarg(\$rParams['root_password'])", (string) file_get_contents($rRoot . $rFile), $rFile);
		}
	}
}

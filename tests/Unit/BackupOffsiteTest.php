<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Backup\BackupTargets;
use XcVm\Domain\Backup\RecoveryBundle;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Off-site backups (Domain\Backup): S3 requests signed as AWS signs them, a
 * copy, a listing and the pruning against an S3 of the test's own, the
 * targets' settings and secrets, and the recovery bundle that opens with its
 * passphrase only.
 */
final class BackupOffsiteTest extends TestCase {
	private string $rDir;

	/** @var resource|null */
	private $rServer = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-offsite-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		if ($this->rServer !== null) {
			proc_terminate($this->rServer);
			proc_close($this->rServer);
		}
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** AWS's own example (S3 docs, "Signature Calculations ... Example: GET Object"). */
	public function testRequestsAreSignedAsAwsSignsThem(): void {
		$rConfig = ['access_key' => 'AKIAIOSFODNN7EXAMPLE', 'secret_key' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'region' => 'us-east-1'];
		$rHeaders = ['host' => 'examplebucket.s3.amazonaws.com', 'range' => 'bytes=0-9', 'x-amz-content-sha256' => hash('sha256', ''), 'x-amz-date' => '20130524T000000Z'];
		$rAuth = BackupTargets::s3Sign($rConfig, 'GET', '/test.txt', '', $rHeaders, hash('sha256', ''), (int) gmmktime(0, 0, 0, 5, 24, 2013));
		$this->assertSame('AWS4-HMAC-SHA256 Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request, SignedHeaders=host;range;x-amz-content-sha256;x-amz-date, Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41', $rAuth);
	}

	public function testTheBucketIsInTheHostOrThePath(): void {
		$rConfig = ['endpoint' => 'https://s3.eu-central-003.backblazeb2.com', 'bucket' => 'my-bucket'];
		$this->assertSame(['https://my-bucket.s3.eu-central-003.backblazeb2.com/xc/backup_1.sql', 'my-bucket.s3.eu-central-003.backblazeb2.com', '/xc/backup_1.sql'], BackupTargets::s3Url($rConfig, 'xc/backup_1.sql'));
		$this->assertSame(['http://127.0.0.1:9000/my-bucket/a%20b.sql?list-type=2', '127.0.0.1:9000', '/my-bucket/a%20b.sql'], BackupTargets::s3Url(['endpoint' => 'http://127.0.0.1:9000', 'bucket' => 'my-bucket', 'path_style' => '1'], 'a b.sql', 'list-type=2'));
		$this->assertSame([['xc/backup_1.sql', 'xc/a&b.sql'], 'tok=1'], BackupTargets::s3ParseList('<ListBucketResult><IsTruncated>true</IsTruncated><Contents><Key>xc/backup_1.sql</Key></Contents><Contents><Key>xc/a&amp;b.sql</Key></Contents><NextContinuationToken>tok=1</NextContinuationToken></ListBucketResult>'));
	}

	public function testATargetsSettingsAreCheckedAndItsSecretsKept(): void {
		$rS3 = ['endpoint' => 'https://s3.amazonaws.com', 'region' => 'us-east-1', 'bucket' => 'b-1', 'access_key' => 'AK', 'secret_key' => 'SK'];
		$this->assertNull(BackupTargets::invalid('s3', $rS3));
		$this->assertSame('bucket', BackupTargets::invalid('s3', ['bucket' => 'Bad_Bucket'] + $rS3));
		$this->assertSame('endpoint', BackupTargets::invalid('s3', ['endpoint' => 'ftp://x'] + $rS3));
		$rSftp = ['host' => 'backup.example.com', 'port' => '22', 'username' => 'u', 'password' => 'p', 'private_key' => '', 'path' => '/srv/xc'];
		$this->assertNull(BackupTargets::invalid('sftp', $rSftp));
		$this->assertSame('path', BackupTargets::invalid('sftp', ['path' => 'relative'] + $rSftp));
		$this->assertSame('private_key', BackupTargets::invalid('sftp', ['private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nAAAA\n-----END OPENSSH PRIVATE KEY-----"] + $rSftp));

		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('backup_targets'));
		DatabaseFactory::set($rDb);
		$rSaved = BackupTargets::save(['type' => 's3', 'name' => 'B2', 'enabled' => '1', 'keep' => '5', 'region' => 'us-east-1', 'bucket' => 'b-1', 'access_key' => 'AK', 'secret_key' => 'SK', 'endpoint' => '']);
		$this->assertTrue($rSaved['result']);
		$this->assertSame('https://s3.amazonaws.com', BackupTargets::find($rSaved['id'])['config']['endpoint'], 'AWS when none is given');
		$this->assertSame('********', BackupTargets::forPage(BackupTargets::find($rSaved['id']))['config']['secret_key']);
		BackupTargets::save(['id' => $rSaved['id'], 'type' => 's3', 'name' => 'B2', 'region' => 'us-east-1', 'bucket' => 'b-1', 'access_key' => 'AK2', 'secret_key' => '********']);
		$this->assertSame(['AK2', 'SK', 0], [BackupTargets::find($rSaved['id'])['config']['access_key'], BackupTargets::find($rSaved['id'])['config']['secret_key'], BackupTargets::find($rSaved['id'])['enabled']]);
	}

	public function testACopyListingAndPruningOnS3(): void {
		// A bucket of the test's own: objects are files in the server's directory.
		file_put_contents($this->rDir . 'router.php', <<<'PHP'
<?php
$d = __DIR__ . '/bucket/';
@mkdir($d);
file_put_contents(__DIR__ . '/requests.log', $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI'] . ' ' . (str_starts_with($_SERVER['HTTP_AUTHORIZATION'] ?? '', 'AWS4-HMAC-SHA256 Credential=AK/') ? 'signed' : 'unsigned') . "\n", FILE_APPEND);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$key = rawurldecode(substr($path, strlen('/b-1/')));
switch ($_SERVER['REQUEST_METHOD']) {
	case 'PUT':
		file_put_contents($d . str_replace('/', '__', $key), file_get_contents('php://input'));
		return;
	case 'DELETE':
		@unlink($d . str_replace('/', '__', $key));
		http_response_code(204);
		return;
	default:
		parse_str($_SERVER['QUERY_STRING'] ?? '', $q);
		$keys = array_map(static fn($f) => str_replace('__', '/', $f), array_diff(scandir($d), ['.', '..']));
		echo '<ListBucketResult>' . implode('', array_map(static fn($k) => '<Contents><Key>' . htmlspecialchars($k) . '</Key></Contents>', array_filter($keys, static fn($k) => str_starts_with($k, $q['prefix'] ?? '')))) . '<IsTruncated>false</IsTruncated></ListBucketResult>';
}
PHP);
		file_put_contents($this->rDir . 'start.php', '<?php $s = stream_socket_server("tcp://127.0.0.1:0"); $p = (int) substr(strrchr(stream_socket_get_name($s, false), ":"), 1); fclose($s); echo $p, "\n"; flush(); pcntl_exec(PHP_BINARY, ["-S", "127.0.0.1:" . $p, ' . var_export($this->rDir . 'router.php', true) . ']);');
		$this->rServer = proc_open([PHP_BINARY, $this->rDir . 'start.php'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $rPipes);
		$rPort = (int) trim((string) fgets($rPipes[1]));
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $rPort); $i++) {
			usleep(100000);
		}
		$rTarget = ['id' => 1, 'type' => 's3', 'keep' => 2, 'config' => ['endpoint' => 'http://127.0.0.1:' . $rPort, 'region' => 'us-east-1', 'bucket' => 'b-1', 'prefix' => 'xc', 'access_key' => 'AK', 'secret_key' => 'SK', 'path_style' => '1']];
		file_put_contents($this->rDir . 'dump.sql', str_repeat('-- dump', 1000));
		foreach (['backup_2026-10-01.sql', 'backup_2026-10-02.sql', 'backup_2026-10-03.sql', 'recovery_2026-10-01.bundle'] as $rName) {
			$this->assertNull(BackupTargets::upload($rTarget, $this->rDir . 'dump.sql', $rName));
		}
		$this->assertSame(7000, filesize($this->rDir . 'bucket/xc__backup_2026-10-03.sql'), 'the whole file, under the folder');
		$this->assertSame(['backup_2026-10-01.sql', 'backup_2026-10-02.sql', 'backup_2026-10-03.sql', 'recovery_2026-10-01.bundle'], BackupTargets::listing($rTarget));
		$this->assertSame(1, BackupTargets::prune($rTarget), 'the oldest backup; the one bundle stays');
		$this->assertSame(['backup_2026-10-02.sql', 'backup_2026-10-03.sql', 'recovery_2026-10-01.bundle'], BackupTargets::listing($rTarget));
		$this->assertStringNotContainsString('unsigned', (string) file_get_contents($this->rDir . 'requests.log'));
		$this->assertNull(BackupTargets::test($rTarget));
	}

	public function testABundleOpensWithItsPassphraseOnly(): void {
		$rFiles = ['config.ini' => "[XC_VM]\nhostname = \"127.0.0.1\"\n", 'cluster-keys.export' => random_bytes(64)];
		$rSealed = RecoveryBundle::seal($rFiles, 'correct horse battery staple', 1800000000);
		$this->assertStringStartsWith(RecoveryBundle::MAGIC, $rSealed);
		$this->assertStringNotContainsString('127.0.0.1', $rSealed);
		$this->assertSame(['made' => 1800000000, 'files' => $rFiles], RecoveryBundle::open($rSealed, 'correct horse battery staple'));
		$this->assertNull(RecoveryBundle::open($rSealed, 'wrong horse battery staple!'));
		$this->assertNull(RecoveryBundle::open('not a bundle', 'correct horse battery staple'));
		$rTampered = $rSealed;
		$rTampered[strlen($rTampered) - 1] = chr(ord($rTampered[strlen($rTampered) - 1]) ^ 1);
		$this->assertNull(RecoveryBundle::open($rTampered, 'correct horse battery staple'), 'a changed byte');
	}
}

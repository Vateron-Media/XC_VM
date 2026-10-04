<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterRotateStreamSecretCommand;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Cluster\StreamSecretRotation;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * `cluster:rotate-stream-secret` (plan, section 10, step 4): the viewer-token
 * secret replaced, with what is stored under it — `hmac_keys.key` and the
 * image cache's names and the references to them — re-encrypted, resumably,
 * and only once the data plane is on across the fleet.
 */
final class StreamSecretRotationTest extends TestCase {
	private const OLD = 'old-secret-0123456789abcdefghijklmnopqrstuv';

	private TestDb $rDb;

	private string $rDir;

	private string $rImages;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands'] as $rName) {
			$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql');
			foreach (array_filter(array_map('trim', explode(';', (string) $rSql))) as $rStatement) {
				$this->rDb->exec($rStatement);
			}
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `live_streaming_pass` varchar(512))');
		$this->rDb->exec("INSERT INTO `settings` VALUES (1, '" . self::OLD . "')");
		$this->rDb->exec('CREATE TABLE `hmac_keys` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `key` varchar(255), `enabled` tinyint DEFAULT 1)');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_icon` text, `movie_properties` text)');
		$this->rDb->exec('CREATE TABLE `streams_series` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `cover` text, `cover_big` text, `backdrop_path` text, `seasons` text)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		$this->rDir = sys_get_temp_dir() . '/xcvm-rot-' . bin2hex(random_bytes(4));
		$this->rImages = $this->rDir . '/images/';
		mkdir($this->rImages, 0755, true);
		StreamSecret::useFile($this->rDir . '/stream_secret.prev');
		SettingsManager::set(['live_streaming_pass' => self::OLD]);
	}

	protected function tearDown(): void {
		StreamSecret::useFile(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function enc(string $rPlain, string $rKey = self::OLD): string {
		return Encryption::encrypt($rPlain, $rKey, OPENSSL_EXTRA);
	}

	private function secret(): string {
		$this->rDb->query('SELECT `live_streaming_pass` FROM `settings`');
		return (string) $this->rDb->get_row()['live_streaming_pass'];
	}

	/** Three HMAC keys, one image referenced from each kind of column, and one LB image. */
	private function seed(): array {
		foreach (['alpha-key', 'beta-key', 'gamma-key'] as $rPlain) {
			$this->rDb->query('INSERT INTO `hmac_keys` (`key`) VALUES (?)', $this->enc($rPlain));
		}
		$rUrl = 'http://images.example/poster.jpg';
		$rName = $this->enc($rUrl) . '.jpg';
		file_put_contents($this->rImages . $rName, 'jpeg');
		file_put_contents($this->rImages . 'h_' . str_repeat('a', 64) . '.png', 'png');
		$rRef = 's:1:/images/' . $rName;
		$this->rDb->query('INSERT INTO `streams` (`stream_icon`, `movie_properties`) VALUES (?, ?)', $rRef, json_encode(['movie_image' => $rRef, 'backdrop_path' => [$rRef]]));
		$this->rDb->query('INSERT INTO `streams_series` (`cover`, `cover_big`, `backdrop_path`, `seasons`) VALUES (?, ?, ?, ?)', $rRef, $rRef, json_encode([$rRef]), json_encode([['cover' => $rRef]]));
		return ['url' => $rUrl, 'name' => $rName];
	}

	public function testARotationReEncryptsEverythingStoredUnderTheSecret(): void {
		$rImage = $this->seed();
		$rOut = (new StreamSecretRotation(null, $this->rImages))->run('new-secret-abcdefghijklmnopqrstuvwxyz012345', null, 1800000000);
		$rNew = 'new-secret-abcdefghijklmnopqrstuvwxyz012345';

		$this->assertSame($rNew, $this->secret());
		$this->assertSame(self::OLD, StreamSecret::previous(1800000000), 'the old value stays readable for its window');
		$this->assertSame(['resumed' => false, 'hmac' => 3, 'images' => 1, 'notified' => 0], $rOut);

		$this->rDb->query('SELECT `key` FROM `hmac_keys` ORDER BY `id`');
		$this->assertSame(['alpha-key', 'beta-key', 'gamma-key'], array_map(fn(array $rRow) => Encryption::decrypt($rRow['key'], $rNew, OPENSSL_EXTRA), $this->rDb->get_rows()));

		$rNewName = Encryption::encrypt($rImage['url'], $rNew, OPENSSL_EXTRA) . '.jpg';
		$this->assertFileExists($this->rImages . $rNewName);
		$this->assertFileDoesNotExist($this->rImages . $rImage['name']);
		$this->assertFileExists($this->rImages . 'h_' . str_repeat('a', 64) . '.png', 'a hashed name is not the secret\'s');
		$this->rDb->query('SELECT * FROM `streams`');
		$rStream = $this->rDb->get_row();
		$this->rDb->query('SELECT * FROM `streams_series`');
		$rSeries = $this->rDb->get_row();
		foreach ([$rStream['stream_icon'], $rStream['movie_properties'], $rSeries['cover'], $rSeries['cover_big'], $rSeries['backdrop_path'], $rSeries['seasons']] as $rValue) {
			$this->assertStringContainsString($rNewName, (string) $rValue);
			$this->assertStringNotContainsString($rImage['name'], (string) $rValue);
		}

		$this->assertNull(StreamSecretRotation::inProgress(), 'the job\'s state goes when it ends');
		$this->assertNotNull(ClusterMeta::get(StreamSecretRotation::DONE_META));
	}

	/** A run cut short is finished by the next, and never re-encrypts a row twice. */
	public function testACutRunIsResumedWhereItStopped(): void {
		$this->seed();
		$rNew = 'resumed-secret-abcdefghijklmnopqrstuvwxyz0123';
		// A run that switched the secret, re-encrypted the first key, and died.
		$this->rDb->query('UPDATE `settings` SET `live_streaming_pass` = ?', $rNew);
		$this->rDb->query('UPDATE `hmac_keys` SET `key` = ? WHERE `id` = 1', $this->enc('alpha-key', $rNew));
		ClusterMeta::set(StreamSecretRotation::META, (string) json_encode(['id' => 'cut', 'phase' => 'hmac', 'old' => self::OLD, 'new' => $rNew, 'started_at' => 1799999000, 'cursor' => 0, 'hmac' => 1, 'images' => 0]));

		$rOut = (new StreamSecretRotation(null, $this->rImages))->run('ignored-when-resuming', null, 1800000000);
		$this->assertTrue($rOut['resumed']);
		$this->assertSame(3, $rOut['hmac'], 'the first key was not taken again');
		$this->assertSame($rNew, $this->secret());
		$this->rDb->query('SELECT `key` FROM `hmac_keys` ORDER BY `id`');
		$this->assertSame(['alpha-key', 'beta-key', 'gamma-key'], array_map(fn(array $rRow) => Encryption::decrypt($rRow['key'], $rNew, OPENSSL_EXTRA), $this->rDb->get_rows()));
	}

	/** An image whose references moved but whose file did not (cut between the two) is finished. */
	public function testAnImageCutMidMoveIsFinished(): void {
		$rImage = $this->seed();
		$rNew = 'mid-move-secret-abcdefghijklmnopqrstuvwxyz0123';
		$rNewName = Encryption::encrypt($rImage['url'], $rNew, OPENSSL_EXTRA) . '.jpg';
		$this->rDb->query('UPDATE `streams` SET `stream_icon` = ?', 's:1:/images/' . $rNewName);
		ClusterMeta::set(StreamSecretRotation::META, (string) json_encode(['id' => 'cut', 'phase' => 'images', 'old' => self::OLD, 'new' => $rNew, 'started_at' => 1799999000, 'cursor' => '', 'hmac' => 3, 'images' => 0]));
		(new StreamSecretRotation(null, $this->rImages))->run(null, null, 1800000000);
		$this->assertFileExists($this->rImages . $rNewName);
		$this->assertFileDoesNotExist($this->rImages . $rImage['name']);
		$this->rDb->query('SELECT `stream_icon` FROM `streams`');
		$this->assertSame('s:1:/images/' . $rNewName, $this->rDb->get_row()['stream_icon']);
	}

	/** Nodes that take commands are told at once. */
	public function testNodesThatTakeCommandsAreTold(): void {
		$rCrypto = new FakeClusterCrypto();
		NodeRegistry::startEnrolment(17, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(17, ['state' => 'active', 'mode' => 2, 'flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_DATAPLANE]);
		$rOut = (new StreamSecretRotation(null, $this->rImages))->run(null, $rCrypto, 1800000000);
		$this->assertSame(1, $rOut['notified']);
		$rDoc = json_decode(CommandBus::pending(17, 0)[0]['doc'], true);
		$this->assertSame(['config.changed', ['sections' => ['secrets']]], [$rDoc['type'], $rDoc['args']]);
		$this->assertSame(40, strlen($this->secret()), 'a fresh value, as cron:root_signals makes one');
	}

	/** The rotation waits for the data plane on every enrolled node, or --force. */
	public function testTheCommandRefusesUntilTheDataPlaneIsFleetWide(): void {
		NodeRegistry::startEnrolment(17, '0f8fad5b-d9cb-469f-a165-70867728950e', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(17, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_COMMANDS]);
		$this->assertSame([17], StreamSecretRotation::blockers());
		$rCommand = new ClusterRotateStreamSecretCommand(new StreamSecretRotation(null, $this->rImages), static fn() => null);
		ob_start();
		$rCode = $rCommand->execute([]);
		$rText = (string) ob_get_clean();
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('DATAPLANE flow is off on server(s) 17', $rText);
		$this->assertSame(self::OLD, $this->secret(), 'nothing rotated');

		ob_start();
		$rCode = $rCommand->execute(['--force']);
		$rText = (string) ob_get_clean();
		$this->assertSame(0, $rCode, $rText);
		$this->assertStringContainsString('WARNING', $rText);
		$this->assertNotSame(self::OLD, $this->secret());

		NodeRegistry::update(17, ['flows' => NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_DATAPLANE]);
		$this->assertSame([], StreamSecretRotation::blockers());
	}

	/** An HMAC link minted under the old secret keeps validating while a row is still the old value's. */
	public function testValidateHmacTriesThePreviousSecretInItsWindow(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Core/Auth/AuthService.php');
		$this->assertMatchesRegularExpression('/\$rPrevious = StreamSecret::previous\(\);\s*foreach \(array_filter\(\[\$rSettings\[\'live_streaming_pass\'\], \$rPrevious\]/', $rSrc);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheCommandIsMainOnly(): void {
		$rMake = (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile');
		$this->assertStringContainsString('Cli/Commands/ClusterRotateStreamSecretCommand.php', $rMake);
		$rVerify = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/ci/verify-lb-archive.sh');
		$this->assertStringContainsString('"Cli/Commands/ClusterRotateStreamSecretCommand.php"', $rVerify);
	}
}

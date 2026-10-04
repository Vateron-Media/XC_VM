<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ReplicaEtagCache;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Domain\Cluster\BlocklistDelta;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterMeta;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The node replica's secrets (cluster plan, sections 9 and 10, Phase 7). The
 * `settings` section never carries a secret: only the keys the LB build reads
 * (lb_settings_keys.php, kept current by make gates), less the withheld ones.
 * The sealed `secrets` section carries only `live_streaming_pass` and
 * OPENSSL_EXTRA, each as {kid, current, previous, previous_valid_until}, goes
 * only to an active node in mode 1 or 2, and is never kept unsealed on MAIN.
 * No other section carries a secret, and none is built from a failed read.
 */
final class ReplicaBuilderSecretsTest extends TestCase {
	/** Settings whose names look secret but are not: flags and public keys. */
	private const NOT_SECRET = ['disable_mag_token', 'secure_stream_tokens', 'recaptcha_v2_site_key', 'stb_change_pass', 'lb_token_rotation_min', 'maxmind_account_id', 'restreamer_bypass_proxy'];

	/** MAIN's secrets in the settings row. */
	private const SECRETS = [
		'api_pass' => 'sekret-api', 'live_streaming_pass' => 'sekret-live', 'redis_password' => 'sekret-redis', 'license' => 'sekret-licence',
		'tmdb_api_key' => 'sekret-tmdb', 'maxmind_license_key' => 'sekret-maxmind', 'recaptcha_v2_secret_key' => 'sekret-recaptcha',
		'dropbox_token' => 'sekret-dropbox', 'platform_api_key' => 'sekret-platform',
	];

	private ?string $rDir = null;

	protected function setUp(): void {
		// MAIN's previous OPENSSL_EXTRA: this test's own file, never the deploy root's.
		OpensslExtra::usePrevFile($this->dir() . '/openssl_extra.prev');
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		ClusterClock::fix(null);
		OpensslExtra::usePrevFile(null);
		ReplicaEtagCache::useDir(false); // the suite's default (tests/bootstrap.php)
		if ($this->rDir !== null) {
			exec('rm -rf ' . escapeshellarg($this->rDir));
		}
	}

	private function dir(): string {
		$this->rDir ??= sys_get_temp_dir() . '/xcvm-secrets-' . bin2hex(random_bytes(4));
		if (!is_dir($this->rDir)) {
			mkdir($this->rDir, 0777, true);
		}
		return $this->rDir;
	}

	/** MAIN's database: the settings row with every secret, one node, the crontab and the blocklist. */
	private function mainDb(): TestDb {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::serversTable());
		$rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		$rDb->exec(InstallSchema::migration('029_create_cluster_nodes'));
		$rDb->exec(InstallSchema::migration('034_create_cluster_changes'));
		$rDb->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `filename` varchar(255), `time` varchar(128), `enabled` int, `role` varchar(8))');
		$rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('streams', '* * * * *', 1, 'all')");
		$rDb->exec(InstallSchema::table('bouquets'));
		$rDb->exec(InstallSchema::table('streams_categories'));
		$rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`) VALUES (1, 'B', '[1]')");
		$rDb->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`) VALUES (1, 'live', 'C')");
		$rColumns = ['id' => 1, 'server_name' => 'XC', 'seg_time' => 6, 'cloudflare' => 1, 'mag_legacy_redirect' => 0] + self::SECRETS;
		$rDb->exec('CREATE TABLE `settings` (`' . implode('` text, `', array_keys($rColumns)) . '` text)');
		$rDb->query('INSERT INTO `settings` VALUES (' . implode(', ', array_fill(0, count($rColumns), '?')) . ')', ...array_values($rColumns));
		$rDb->query("INSERT INTO `servers` (`id`, `server_name`, `server_ip`, `is_main`, `enabled`) VALUES (5, 'LB', '10.0.0.5', 0, 1)");
		$rDb->query(
			"INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `node_sign_pub`, `node_box_pub`, `epoch`, `created_at`, `updated_at`) VALUES (5, 'uuid-5', 'active', 1, 0, 1, ?, ?, 1, 1, 1)",
			str_repeat('s', 32),
			str_repeat('b', 32)
		);
		$rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39), `notes` text, `date` int)');
		$rDb->exec('CREATE TABLE `blocked_uas` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_agent` varchar(255), `exact_match` int DEFAULT 0)');
		$rDb->exec('CREATE TABLE `blocked_isps` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `isp` text, `blocked` int DEFAULT 0)');
		$rDb->exec('CREATE TABLE `blocked_asns` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `asn` int, `blocked` int DEFAULT 0)');
		$rDb->exec('CREATE TABLE `rtmp_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(255), `password` varchar(128), `push` int, `pull` int)');
		$rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.1')");
		DatabaseFactory::set($rDb);
		return $rDb;
	}

	public function testNoSecretLookingKeyIsAllowlisted(): void {
		$rKeys = ReplicaBuilder::settingsKeys();
		$this->assertGreaterThan(100, count($rKeys));
		$rSuspect = array_values(array_filter($rKeys, static fn(string $rKey): bool => (bool) preg_match('/pass|secret|token|licen|_key$|password|salt|private/', $rKey) && !in_array($rKey, self::NOT_SECRET, true)));
		$this->assertSame([], $rSuspect, 'a settings key that looks secret: withhold it (tools/ci/lb_settings_keys.php SECRETS) or vet it here');
		$rList = require MAIN_HOME . 'Core/Cluster/lb_settings_keys.php';
		foreach (['api_pass', 'license', 'live_streaming_pass', 'redis_password', 'tmdb_api_key', 'recaptcha_v2_secret_key'] as $rSecret) {
			$this->assertNotContains($rSecret, $rKeys);
		}
		$this->assertSame([], array_values(array_intersect($rKeys, $rList['withheld'])));
		// Columns a migration adds after the first ADD of one ALTER TABLE (016), which the LB build reads (UpdateChannels).
		$this->assertSame(['update_channel_bin', 'update_channel_fanout'], array_values(array_intersect(['update_channel_bin', 'update_channel_fanout'], $rKeys)));
	}

	public function testTheSectionHoldsOnlyAllowlistedKeys(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `api_pass` text, `live_streaming_pass` text, `redis_password` text, `seg_time` int, `not_read_by_lbs` text)');
		$rDb->exec("INSERT INTO `settings` VALUES (1, 'XC', 'p1', 'p2', 'p3', 6, 'x')");
		DatabaseFactory::set($rDb);
		$this->assertSame(['id' => '1', 'seg_time' => '6', 'server_name' => 'XC'], ReplicaBuilder::settingsData());
	}

	public function testALockedDownMainSendsANodeHoldingItsKeyTheSecretsHashNotItsValue(): void {
		$rDb = $this->mainDb();
		$rNode = ['server_id' => 5, 'mode' => 2, 'state' => 'active'];
		$rKid = ViewerKey::kid(ViewerKey::derive('sekret-live', 5));
		$rDb->query('UPDATE `servers` SET `viewer_key_fp` = ? WHERE `id` = 5', $rKid);
		$this->assertArrayHasKey('live_streaming_pass', ReplicaBuilder::secretsData(5, $rNode), 'not locked down: the value');

		ClusterMeta::set(DbAllowlist::LOCKDOWN_META, '{"at":1}');
		$rHashed = ReplicaBuilder::secretsData(5, $rNode);
		$this->assertSame(['openssl_extra', ViewerKey::NAME, ViewerKey::PASS_HASH], array_keys($rHashed));
		$this->assertSame(hash('sha256', 'sekret-live'), $rHashed[ViewerKey::PASS_HASH]['current']);
		$this->assertStringNotContainsString('sekret-live', (string) json_encode($rHashed));

		$this->assertArrayHasKey('live_streaming_pass', ReplicaBuilder::secretsData(5, ['mode' => 1] + $rNode), 'a node in mode 1: the value');
		foreach ([null, ViewerKey::kid(ViewerKey::derive('sekret-live', 6)), ViewerKey::kid(ViewerKey::derive('another', 5))] as $rFp) {
			$rDb->query('UPDATE `servers` SET `viewer_key_fp` = ? WHERE `id` = 5', $rFp);
			$this->assertArrayHasKey('live_streaming_pass', ReplicaBuilder::secretsData(5, $rNode), 'reports no key MAIN mints with: ' . var_export($rFp, true));
		}
	}

	public function testTheSecretsSectionCarriesOnlyTheStreamSecretOpensslExtraAndTheNodesOwnKey(): void {
		$this->mainDb();
		$rData = ReplicaBuilder::section(new FakeClusterCrypto(), ['server_id' => 5, 'mode' => 1, 'state' => 'active'], ReplicaSections::SECRETS, [], [])['data'];

		$this->assertSame(['live_streaming_pass', 'openssl_extra'], ReplicaSections::SECRET_KEYS);
		$this->assertSame([...ReplicaSections::SECRET_KEYS, ViewerKey::NAME], array_keys($rData), 'the allowed keys, and nothing else');
		$this->assertSame(ViewerKey::entry('sekret-live', 5, null), $rData[ViewerKey::NAME], 'server 5\'s own key');
		$this->assertNotSame(ViewerKey::derive('sekret-live', 6), $rData[ViewerKey::NAME]['current'], 'not another node\'s');
		foreach ($rData as $rName => $rEntry) {
			$this->assertSame(['current', 'kid', 'previous', 'previous_valid_until'], array_keys($rEntry), $rName);
			$this->assertSame($rEntry, ReplicaSections::secret($rEntry), $rName . ': an entry the node takes');
		}
		$this->assertSame(['current' => 'sekret-live', 'kid' => ReplicaSections::kid('live_streaming_pass', 'sekret-live'), 'previous' => null, 'previous_valid_until' => null], $rData['live_streaming_pass']);
		$this->assertSame(['current' => OPENSSL_EXTRA, 'kid' => OpensslExtra::fingerprint(OPENSSL_EXTRA), 'previous' => null, 'previous_valid_until' => null], $rData['openssl_extra'], 'the kid is the fingerprint every node publishes');
		$rJson = (string) json_encode($rData);
		foreach (self::SECRETS as $rKey => $rSecret) {
			if ($rKey !== 'live_streaming_pass') {
				$this->assertStringNotContainsString($rSecret, $rJson, $rKey);
			}
		}
	}

	public function testTheKidNamesTheValueWithoutRevealingIt(): void {
		$this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', ReplicaSections::kid('live_streaming_pass', 'sekret-live'));
		$this->assertNotSame(ReplicaSections::kid('live_streaming_pass', 'a'), ReplicaSections::kid('live_streaming_pass', 'b'));
		$this->assertNotSame(ReplicaSections::kid('live_streaming_pass', 'a'), ReplicaSections::kid('openssl_extra', 'a'), 'per secret');
		$this->assertSame(OpensslExtra::fingerprint('x'), ReplicaSections::kid('openssl_extra', 'x'));
	}

	public function testMainsPreviousOpensslExtraTravelsUntilItsWindowCloses(): void {
		$this->mainDb();
		$rNow = 1800000000;
		ClusterClock::fix($rNow * 1000);
		file_put_contents($this->dir() . '/openssl_extra.prev', json_encode(['value' => 'mains-old', 'valid_until' => $rNow + 3600]));
		OpensslExtra::usePrevFile($this->dir() . '/openssl_extra.prev');
		$rEntry = ReplicaBuilder::secretsData()['openssl_extra'];
		$this->assertSame(['mains-old', $rNow + 3600], [$rEntry['previous'], $rEntry['previous_valid_until']]);
		$this->assertSame($rEntry, ReplicaSections::secret($rEntry));

		ClusterClock::fix(($rNow + 3601) * 1000);
		$rEntry = ReplicaBuilder::secretsData()['openssl_extra'];
		$this->assertSame([null, null], [$rEntry['previous'], $rEntry['previous_valid_until']], 'closed on MAIN\'s clock');
	}

	public function testNoSecretLeavesInAnyOtherSection(): void {
		$this->mainDb();
		$rCrypto = new FakeClusterCrypto();
		$rNode = ['server_id' => 5, 'mode' => 1, 'state' => 'active'];
		$rSettings = ['cluster_policy_ver' => 1] + self::SECRETS;
		$rMain = ['id' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 80];
		$rSections = ['blocklist' => BlocklistDelta::snapshot()];
		foreach (ReplicaBuilder::WHOLE as $rSection) {
			$rSections[$rSection] = ReplicaBuilder::section($rCrypto, $rNode, $rSection, $rSettings, $rMain)['data'];
		}
		$this->assertNotContains(ReplicaSections::SECRETS, ReplicaBuilder::WHOLE, 'the one section that carries secrets is served on its own terms');
		$this->assertSame(['203.0.113.1'], $rSections['blocklist']['ip']);
		$this->assertSame('XC', $rSections['settings']['server_name']);
		foreach ($rSections as $rSection => $rData) {
			$rJson = (string) json_encode($rData);
			foreach (self::SECRETS + ['openssl_extra' => OPENSSL_EXTRA] as $rKey => $rSecret) {
				$this->assertStringNotContainsString($rSecret, $rJson, $rSection . ' carries ' . $rKey);
				$this->assertStringNotContainsString('"' . $rKey . '"', $rJson, $rSection . ' names ' . $rKey);
			}
		}
	}

	public function testTheSecretsAreNeverCachedUnsealedOnMain(): void {
		$rDb = $this->mainDb();
		ReplicaEtagCache::useDir($this->dir() . '/etags/');
		ClusterClock::fix(1800000000000);
		$rNode = ['server_id' => 5, 'mode' => 1, 'state' => 'active'];
		$rCrypto = new FakeClusterCrypto();
		$rFirst = ReplicaBuilder::section($rCrypto, $rNode, ReplicaSections::SECRETS, [], []);
		foreach (ReplicaBuilder::WHOLE as $rSection) {
			ReplicaBuilder::section($rCrypto, $rNode, $rSection, [], []);
		}
		$this->assertNotEmpty(glob($this->dir() . '/etags/*.json'), 'the other sections are cached');
		foreach (scandir($this->dir() . '/etags/') ?: [] as $rName) {
			if (is_file($this->dir() . '/etags/' . $rName)) {
				$rBody = (string) file_get_contents($this->dir() . '/etags/' . $rName);
				$this->assertStringNotContainsString('sekret-live', $rBody, $rName);
				$this->assertStringNotContainsString(OPENSSL_EXTRA, $rBody, $rName);
			}
		}
		$this->assertFileDoesNotExist($this->dir() . '/etags/secrets.json');

		// Not even through the cache's own door.
		ReplicaEtagCache::put(ReplicaSections::SECRETS, 1800000000000, ReplicaEtagCache::generation(), $rFirst['etag'], $rFirst['data']);
		$this->assertFileDoesNotExist($this->dir() . '/etags/secrets.json');
		$this->assertNull(ReplicaEtagCache::get(ReplicaSections::SECRETS, 1800000000000));

		// Read afresh for every node that asks: a new value is seen at once, within the 10 s.
		$rDb->exec("UPDATE `settings` SET `live_streaming_pass` = 'sekret-new'");
		$rNext = ReplicaBuilder::section($rCrypto, $rNode, ReplicaSections::SECRETS, [], []);
		$this->assertSame('sekret-new', $rNext['data']['live_streaming_pass']['current']);
		$this->assertNotSame($rFirst['etag'], $rNext['etag']);
		$this->assertSame($rNext['etag'], ReplicaBuilder::etag($rNext['data']));
	}

	public function testASectionIsNeverBuiltFromAFailedReadANoRowOrAnUnsetSecret(): void {
		$rDb = $this->mainDb();
		$rCrypto = new FakeClusterCrypto();
		$rNode = ['server_id' => 5, 'mode' => 1, 'state' => 'active', 'node_uuid' => 'uuid-5', 'gen' => 1, 'node_box_pub' => str_repeat('b', 32)];
		$rLog = new QueryLogDb($rDb);
		DatabaseFactory::set($rLog);
		$rThrows = function (string $rWhy, callable $rBuild): void {
			$rThrown = null;
			try {
				$rBuild();
			} catch (\RuntimeException $rE) {
				$rThrown = $rE;
			}
			$this->assertMatchesRegularExpression('/^(replica|blocklist): /', $rThrown?->getMessage() ?? 'built', $rWhy);
			$this->assertStringNotContainsString('sekret', $rThrown->getMessage(), $rWhy);
		};
		// A read that fails as Database::query fails: false, while get_row()
		// still holds the last result (here that same read's, a moment ago).
		foreach ([
			'settings' => 'settings', 'secrets' => 'settings', 'node' => 'settings',
			'servers' => 'servers', 'crontab' => 'crontab', 'bouquets' => 'bouquets', 'categories' => 'streams_categories',
		] as $rSection => $rTable) {
			$rLog->rRefuse = '/FROM `' . $rTable . '`/';
			$rDb->query('SELECT * FROM `' . $rTable . '`');
			$rThrows($rSection, static fn() => ReplicaBuilder::section($rCrypto, $rNode, $rSection, [], []));
		}
		foreach (['the range' => '/MIN\(`id`\)/', 'a table' => '/FROM `blocked_ips`/'] as $rWhy => $rRefuse) {
			$rLog->rRefuse = $rRefuse;
			$rThrows('blocklist, ' . $rWhy, static fn() => ReplicaBuilder::blocklist($rCrypto, $rNode, 0, ''));
		}
		$rLog->rRefuse = null;

		// No settings row: no settings, secrets or node section.
		$rDb->exec('DELETE FROM `settings`');
		foreach (['settings', 'secrets', 'node'] as $rSection) {
			$rThrows($rSection . ' without a row', static fn() => ReplicaBuilder::section($rCrypto, $rNode, $rSection, [], []));
		}

		// An unset stream secret (cron:root_signals sets one within the minute): no entry with an empty `current`.
		$rDb->exec("INSERT INTO `settings` (`id`, `server_name`, `live_streaming_pass`) VALUES (1, 'XC', '')");
		$rThrows('an empty stream secret', static fn() => ReplicaBuilder::section($rCrypto, $rNode, ReplicaSections::SECRETS, [], []));
		$rDb->exec('UPDATE `settings` SET `live_streaming_pass` = NULL');
		$rThrows('no stream secret', static fn() => ReplicaBuilder::section($rCrypto, $rNode, ReplicaSections::SECRETS, [], []));
		$this->assertSame('XC', ReplicaBuilder::section($rCrypto, $rNode, ReplicaSections::SETTINGS, [], [])['data']['server_name'], 'the settings section does not need it');
	}

	public function testOnlyAnActiveNodeInModeOneOrTwoIsServedTheSecrets(): void {
		foreach ([[0, 'active', false], [1, 'active', true], [2, 'active', true], [1, 'quarantined', false], [1, 'revoked', false], [1, 'enrolling', false]] as [$rMode, $rState, $rServed]) {
			$this->assertSame($rServed, ReplicaBuilder::serves(['mode' => $rMode, 'state' => $rState], ReplicaSections::SECRETS), $rMode . '/' . $rState);
		}
		foreach (ReplicaBuilder::WHOLE as $rSection) {
			$this->assertTrue(ReplicaBuilder::serves(['mode' => 0, 'state' => 'active'], $rSection), $rSection);
		}
	}
}

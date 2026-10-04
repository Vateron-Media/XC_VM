<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterApplyCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Domain\Cluster\ReplicaBuilder;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * cluster:apply (cluster plan, Phase 7): the blocklist the agent verified
 * becomes the node's caches in cron:cache's shapes. In shadow it only counts
 * how they differ; with CONFIG on it writes them, and the readers stop
 * rebuilding them from MAIN's database. The same goes for the whole sections
 * `servers` with `node` (the servers cache in ServerRepository::getAll's
 * shape) and `crontab` (the jobs the node's crontab runs), once an apply has
 * built their caches: until then, and again after CONFIG was off or a
 * section was refused, their readers keep MAIN's database. `cluster` is only
 * compared with the agent's own policy. A missing, foreign or malformed
 * section never writes a cache. The `settings` section becomes the settings
 * cache together with the sealed `secrets` section, whose OPENSSL_EXTRA goes
 * to config/openssl_extra; a report never holds a secret.
 */
final class ReplicaApplyTest extends TestCase {
	private string $rDir;

	private mixed $rSettingsBackup = null;

	private mixed $rDbBackup = null;

	private const DATA = [
		'ip' => ['203.0.113.1', '203.0.113.2'],
		'asn' => [64500],
		'ua' => [['id' => 3, 'user_agent' => 'Curl', 'exact_match' => 1]],
		'isp' => [['id' => 1, 'isp' => 'isp', 'blocked' => 1]],
		'rtmp' => [['id' => 1, 'ip' => '198.51.100.9', 'password' => 'pw', 'push' => 1, 'pull' => 0]],
	];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-apply-' . bin2hex(random_bytes(4));
		mkdir($this->rDir . '/replica', 0777, true);
		mkdir($this->rDir . '/cache', 0777, true);
		mkdir($this->rDir . '/config', 0777, true);
		ReplicaApply::useDir($this->rDir . '/replica/');
		ReplicaApply::useConfigDir($this->rDir . '/config/');
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . '/cache/'));
		$this->flows(0);
		$this->rSettingsBackup = $GLOBALS['rSettings'] ?? null;
		$this->rDbBackup = $GLOBALS['db'] ?? null;
	}

	protected function tearDown(): void {
		ViewerKey::useFile(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		$GLOBALS['rSettings'] = $this->rSettingsBackup;
		$GLOBALS['db'] = $this->rDbBackup;
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		OpensslExtra::usePrevFile(null);
		NodeFlows::usePath(null);
		NodeRole::useServers(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	private function replica(array $rData): void {
		file_put_contents($this->rDir . '/replica/blocklist.json', json_encode(['seq' => 9, 'etag' => str_repeat('a', 64), 'data' => $rData]));
	}

	public function testTheCachesKeepCronCachesShapes(): void {
		$this->assertSame([
			'blocked_ips' => ['203.0.113.1', '203.0.113.2'],
			'blocked_servers' => [64500],
			'blocked_ua' => [3 => ['id' => 3, 'exact_match' => 1, 'blocked_ua' => 'curl']],
			'blocked_isp' => [['id' => 1, 'isp' => 'isp', 'blocked' => 1]],
			'rtmp_ips' => ['198.51.100.9' => ['password' => 'pw', 'push' => true, 'pull' => false]],
		], ReplicaApply::caches(self::DATA));
		$this->assertNull(ReplicaApply::caches(['ip' => [['not' => 'a string']]]));
		$this->assertNull(ReplicaApply::caches(['asn' => ['64500']]));
	}

	public function testShadowOnlyCountsTheDifference(): void {
		// What cron:cache wrote from MAIN's database (strings, as the driver reads them).
		FileCache::setCache('blocked_ips', ['203.0.113.1', '203.0.113.9']);
		FileCache::setCache('blocked_servers', ['64500']);
		FileCache::setCache('blocked_ua', [3 => ['id' => '3', 'exact_match' => '1', 'blocked_ua' => 'curl']]);
		FileCache::setCache('blocked_isp', []);
		// RTMP publishers, which an LB reads from MAIN's database.
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `rtmp_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(255), `password` varchar(128), `push` int, `pull` int)');
		$rDb->exec("INSERT INTO `rtmp_ips` VALUES (1, '198.51.100.9', 'old', 1, 0)");
		DatabaseFactory::set($rDb);
		$this->replica(self::DATA);

		$rReport = ReplicaApply::run(false, 1800000000);
		$this->assertSame('shadow', $rReport['mode']);
		$this->assertSame(9, $rReport['seq']);
		$this->assertSame([
			'blocked_ips' => ['missing' => 1, 'extra' => 1],
			'blocked_servers' => ['missing' => 0, 'extra' => 0],
			'blocked_ua' => ['missing' => 0, 'extra' => 0],
			'blocked_isp' => ['missing' => 0, 'extra' => 1],
			'rtmp_ips' => ['missing' => 1, 'extra' => 1], // the password changed
		], $rReport['diff']);
		$this->assertSame(['203.0.113.1', '203.0.113.9'], FileCache::getCache('blocked_ips'), 'nothing written in shadow');
		$this->assertSame($rReport, json_decode((string) file_get_contents($this->rDir . '/replica/apply.json'), true));
	}

	public function testWithConfigOnTheReplicaIsTheCache(): void {
		FileCache::setCache('blocked_ips', ['203.0.113.9']);
		$this->replica(self::DATA);
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame('applied', ReplicaApply::run(true)['mode']);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], FileCache::getCache('blocked_ips'));
		// The readers take the replica's caches however old, never the database.
		touch($this->rDir . '/cache/blocked_ips', time() - 3600);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], BlocklistService::getBlockedIPs());
		$this->assertSame([64500], BlocklistService::getBlockedServers(true));
		$this->assertSame(['198.51.100.9' => ['password' => 'pw', 'push' => true, 'pull' => false]], BlocklistService::getAllowedRTMP());
	}

	public function testIptablesFollowsTheReplicaAndNeverAMissingOne(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39))');
		$rDb->exec("INSERT INTO `blocked_ips` VALUES (1, '203.0.113.7')");
		$this->assertSame(['203.0.113.7'], RootSignalsCronJob::blockedIPs($rDb), 'CONFIG off: MAIN\'s table');

		$this->flows(NodeFlows::CONFIG);
		$this->assertNull(RootSignalsCronJob::blockedIPs($rDb), 'no replica cache yet: leave iptables alone');
		FileCache::setCache('blocked_ips', ['203.0.113.1', '203.0.113.1', '203.0.113.2']);
		$this->assertSame(['203.0.113.1', '203.0.113.2'], RootSignalsCronJob::blockedIPs(null));
	}

	public function testNothingToApply(): void {
		$this->assertNull(ReplicaApply::run(false));
		file_put_contents($this->rDir . '/replica/blocklist.json', '{"data": {"ip": "nope"}}');
		$this->assertNull(ReplicaApply::run(false));
	}

	public function testSettingsWithoutTheSecretsStayMainsDatabasesAndNameWhatDiffers(): void {
		FileCache::setCache('settings', ['seg_time' => 6, 'server_name' => 'XC', 'api_ips' => ['10.0.0.1'], 'redis_password' => 'kept']);
		file_put_contents($this->rDir . '/replica/settings.json', json_encode(['etag' => str_repeat('b', 64), 'data' => ['seg_time' => '6', 'server_name' => 'Renamed', 'api_ips' => '10.0.0.1']]));
		$this->assertSame(['etag' => str_repeat('b', 64), 'mode' => 'shadow', 'keys' => 3, 'differ' => ['server_name']], ReplicaApply::run(false)['settings'], 'decoded as the panel reads them');
		$this->flows(NodeFlows::CONFIG);
		$rReport = ReplicaApply::run(true);
		$this->assertSame(['etag' => str_repeat('b', 64), 'mode' => 'incomplete', 'keys' => 3, 'differ' => ['server_name']], $rReport['settings'], 'the section withholds what the node reads');
		$this->assertArrayNotHasKey('seq', $rReport, 'no blocklist to apply');
		$this->assertSame('XC', FileCache::getCache('settings')['server_name'], 'not written until the secrets section exists');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS));
	}

	// ── The settings and secrets sections ─────────────────────────────────

	private const LIVE = 'mains-live-pass';

	/** The settings section MAIN would send: raw values, as strings. */
	private const SETTINGS = ['server_name' => 'Renamed', 'seg_time' => '8', 'api_ips' => '10.0.0.1,10.0.0.2', 'allow_countries' => '["DE"]'];

	/**
	 * Write the secrets section MAIN would send: its stream secret and its
	 * OPENSSL_EXTRA, with the value MAIN replaced while that is accepted.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function secrets(string $rLive = self::LIVE, string $rExtra = OPENSSL_EXTRA, ?string $rPrevious = null, ?int $rUntil = null): array {
		$rData = [
			'live_streaming_pass' => ['current' => $rLive, 'kid' => ReplicaSections::kid('live_streaming_pass', $rLive), 'previous' => null, 'previous_valid_until' => null],
			'openssl_extra' => ['current' => $rExtra, 'kid' => ReplicaSections::kid('openssl_extra', $rExtra), 'previous' => $rPrevious, 'previous_valid_until' => $rUntil],
		];
		$this->whole('secrets', $rData);
		return $rData;
	}

	public function testTheNodesOwnViewerKeyIsKeptAndReported(): void {
		$rDb = $this->mainDb();
		$this->whole('settings', self::SETTINGS);
		$rEntry = ViewerKey::entry(self::LIVE, (int) SERVER_ID, null);
		$this->whole('secrets', $this->secrets() + [ViewerKey::NAME => $rEntry]);
		$this->flows(NodeFlows::CONFIG);

		$this->assertSame('applied', ReplicaApply::run(true)['secrets']['mode']);
		$this->assertSame($this->rDir . '/config/viewer_key', ViewerKey::file());
		$this->assertSame([$rEntry['current']], ViewerKey::own());
		$rDb->query('SELECT `viewer_key_fp` FROM `servers` WHERE `id` = ?', SERVER_ID);
		$this->assertSame($rEntry['kid'], $rDb->get_row()['viewer_key_fp'], 'reported, so MAIN mints with it');
		$this->assertStringNotContainsString($rEntry['current'], (string) file_get_contents($this->rDir . '/replica/apply.json'));
	}

	public function testANodeSentTheSecretsHashKeepsItAndNoValue(): void {
		$this->mainDb();
		FileCache::setCache('settings', ['server_name' => 'XC', 'live_streaming_pass' => self::LIVE]);
		file_put_contents($this->rDir . '/config/stream_secret.prev', json_encode(['value' => 'older', 'valid_until' => time() + 60]));
		$this->whole('settings', self::SETTINGS);
		$rKey = [ViewerKey::NAME => ViewerKey::entry(self::LIVE, (int) SERVER_ID, null)];
		$rHash = hash('sha256', self::LIVE);
		$rData = $this->secrets();
		unset($rData['live_streaming_pass']);
		$this->whole('secrets', $rData + $rKey + [ViewerKey::PASS_HASH => ['current' => $rHash, 'kid' => ReplicaSections::kid(ViewerKey::PASS_HASH, $rHash), 'previous' => null, 'previous_valid_until' => null]]);
		$this->flows(NodeFlows::CONFIG);

		$this->assertSame('applied', ReplicaApply::run(true)['secrets']['mode']);
		$this->assertSame('', FileCache::getCache('settings')['live_streaming_pass'], 'no value on the node');
		$this->assertSame($rHash, ViewerKey::passHash());
		$this->assertFileDoesNotExist($this->rDir . '/config/stream_secret.prev', 'nor the value it replaced');
		$this->assertTrue(ViewerKey::passMatches('', self::LIVE));

		// The value again (lockdown undone): the hash goes.
		$this->whole('secrets', $this->secrets() + $rKey);
		$this->assertSame('applied', ReplicaApply::run(true)['secrets']['mode']);
		$this->assertNull(ViewerKey::passHash());
		$this->assertSame(self::LIVE, FileCache::getCache('settings')['live_streaming_pass']);
	}

	public function testASecretsSectionWithBothTheValueAndItsHashIsRefused(): void {
		$this->whole('settings', self::SETTINGS);
		$rHash = hash('sha256', self::LIVE);
		$this->whole('secrets', $this->secrets() + [ViewerKey::PASS_HASH => ['current' => $rHash, 'kid' => ReplicaSections::kid(ViewerKey::PASS_HASH, $rHash), 'previous' => null, 'previous_valid_until' => null]]);
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame(['mode' => 'refused'], ReplicaApply::run(true)['secrets']);
	}

	public function testSecretsInShadowOnlySayWhichDifferAndNeverWhat(): void {
		FileCache::setCache('settings', ['server_name' => 'XC', 'live_streaming_pass' => 'old-pass']);
		$this->whole('settings', ['server_name' => 'XC']);
		$rData = $this->secrets('new-pass');
		$rReport = ReplicaApply::run(false);
		$this->assertSame(['mode' => 'shadow', 'differ' => ['live_streaming_pass']], $rReport['secrets']);
		$this->assertSame('shadow', $rReport['settings']['mode']);
		$this->assertSame('old-pass', FileCache::getCache('settings')['live_streaming_pass'], 'nothing written in shadow');
		$this->assertFileDoesNotExist($this->rDir . '/config/openssl_extra');

		$this->secrets('old-pass', 'mains-extra');
		$this->assertSame(['mode' => 'shadow', 'differ' => ['openssl_extra']], ReplicaApply::run(false)['secrets']);
		$this->assertFileDoesNotExist($this->rDir . '/config/openssl_extra');

		// The report (apply.json, and cluster:apply's output the agent may log) holds no value, kid or hash of one.
		$rOut = json_encode($rReport) . file_get_contents($this->rDir . '/replica/apply.json');
		foreach (['new-pass', 'old-pass', 'mains-extra', OPENSSL_EXTRA, $rData['live_streaming_pass']['kid'], $rData['openssl_extra']['kid'], ReplicaBuilder::etag($rData)] as $rSecret) {
			$this->assertStringNotContainsString($rSecret, $rOut);
		}
	}

	public function testWithConfigOnTheSettingsAndSecretsBecomeTheSettingsCache(): void {
		FileCache::setCache('settings', ['server_name' => 'XC', 'live_streaming_pass' => 'old-pass', 'redis_password' => 'from-mains-db', 'seg_time' => 6]);
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		$rReport = ReplicaApply::run(true);
		$this->assertSame(['mode' => 'applied', 'keys' => 4], array_intersect_key($rReport['settings'], ['mode' => 1, 'keys' => 1]));
		$this->assertSame(['mode' => 'applied', 'differ' => ['live_streaming_pass']], $rReport['secrets']);
		$rCache = FileCache::getCache('settings');
		$this->assertSame(SettingsRepository::decode(['live_streaming_pass' => self::LIVE] + self::SETTINGS), $rCache, 'MAIN\'s row as the settings loader decodes it');
		$this->assertSame([['10.0.0.1', '10.0.0.2'], ['DE'], '8'], [$rCache['api_ips'], $rCache['allow_countries'], $rCache['seg_time']]);
		$this->assertArrayNotHasKey('redis_password', $rCache, 'what the replica withholds is not the node\'s');
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SETTINGS));
		$this->assertStringNotContainsString(self::LIVE, (string) file_get_contents($this->rDir . '/replica/apply.json'));

		// Every reader takes it, however old, forced or not, and never MAIN's database.
		$GLOBALS['db'] = null;
		touch($this->rDir . '/cache/settings', time() - 3600);
		$this->assertSame($rCache, SettingsRepository::getAll());
		$this->assertSame($rCache, SettingsRepository::getAll(true));
		// Gone: rebuilt from the replica on disk.
		FileCache::delCache('settings');
		$this->assertSame($rCache, SettingsRepository::getAll());
		$this->assertSame($rCache, FileCache::getCache('settings'));
	}

	public function testAMalformedSecretsSectionIsRefusedAndWritesNothing(): void {
		FileCache::setCache('settings', ['server_name' => 'XC', 'live_streaming_pass' => 'old-pass']);
		$this->whole('settings', self::SETTINGS);
		$this->flows(NodeFlows::CONFIG);
		$rGood = $this->secrets(self::LIVE, 'mains-extra');
		foreach ([
			'no OPENSSL_EXTRA' => ['live_streaming_pass' => $rGood['live_streaming_pass']],
			'an empty stream secret' => ['live_streaming_pass' => ['current' => ''] + $rGood['live_streaming_pass']] + $rGood,
			'a number' => ['openssl_extra' => ['current' => 7] + $rGood['openssl_extra']] + $rGood,
			'a previous value without its end' => ['openssl_extra' => ['previous' => 'old'] + $rGood['openssl_extra']] + $rGood,
			'an end without a previous value' => ['openssl_extra' => ['previous_valid_until' => 1] + $rGood['openssl_extra']] + $rGood,
			'no kid' => ['openssl_extra' => ['kid' => null] + $rGood['openssl_extra']] + $rGood,
			'not an object' => ['live_streaming_pass' => 'x', 'openssl_extra' => 'y'],
		] as $rWhy => $rData) {
			$this->whole('secrets', $rData);
			$rReport = ReplicaApply::run(true);
			$this->assertSame(['mode' => 'refused'], $rReport['secrets'], $rWhy);
			$this->assertSame('incomplete', $rReport['settings']['mode'], $rWhy);
		}
		file_put_contents($this->rDir . '/replica/secrets.json', '{"etag": "x", "data": ');
		$this->assertSame(['mode' => 'refused'], ReplicaApply::run(true)['secrets'], 'unreadable');
		$this->assertSame('old-pass', FileCache::getCache('settings')['live_streaming_pass']);
		$this->assertFileDoesNotExist($this->rDir . '/config/openssl_extra');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS));

		// A key a later MAIN adds is left for the node that knows it.
		$this->whole('secrets', $rGood + ['tickets' => ['kid' => 'x']]);
		$this->assertSame('applied', ReplicaApply::run(true)['secrets']['mode']);
		$this->assertSame(self::LIVE, FileCache::getCache('settings')['live_streaming_pass']);
	}

	public function testWithConfigOnOpensslExtraIsWrittenWhereTheNodeReadsIt(): void {
		$rConfig = $this->rDir . '/config/';
		$rNow = 1800000000;
		FileCache::setCache('settings', ['live_streaming_pass' => self::LIVE]);
		$this->whole('settings', self::SETTINGS);
		$this->secrets(self::LIVE, 'mains-extra');
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame(['mode' => 'applied', 'differ' => ['openssl_extra']], ReplicaApply::run(true, $rNow)['secrets']);
		$this->assertSame('mains-extra', file_get_contents($rConfig . 'openssl_extra'));
		$this->assertSame(0600, fileperms($rConfig . 'openssl_extra') & 0777);
		$this->assertSame(0600, fileperms($rConfig . 'openssl_extra.prev') & 0777);
		$rPrev = ['value' => OPENSSL_EXTRA, 'valid_until' => $rNow + OpensslExtra::PREVIOUS_WINDOW];
		$this->assertSame($rPrev, json_decode((string) file_get_contents($rConfig . 'openssl_extra.prev'), true), 'the value it replaced still opens the tokens it minted');

		// Applied again (cron:cache, every minute): nothing to write, the window not extended.
		$this->assertSame(['mode' => 'applied', 'differ' => []], ReplicaApply::run(true, $rNow + 60)['secrets']);
		$this->assertSame($rPrev, json_decode((string) file_get_contents($rConfig . 'openssl_extra.prev'), true));

		// MAIN rotated: its previous value stays accepted until MAIN says.
		$this->secrets(self::LIVE, 'mains-next', 'mains-extra', $rNow + 7200);
		ReplicaApply::run(true, $rNow + 120);
		$this->assertSame('mains-next', file_get_contents($rConfig . 'openssl_extra'));
		$rPrev = ['value' => 'mains-extra', 'valid_until' => $rNow + 7200];
		$this->assertSame($rPrev, json_decode((string) file_get_contents($rConfig . 'openssl_extra.prev'), true));
		OpensslExtra::usePrevFile($rConfig . 'openssl_extra.prev');
		$this->assertSame('mains-extra', OpensslExtra::previous($rNow + 7200));
		$this->assertNull(OpensslExtra::previous($rNow + 7201));
		ReplicaApply::run(true, $rNow + 180);
		$this->assertSame($rPrev, json_decode((string) file_get_contents($rConfig . 'openssl_extra.prev'), true));

		// Turned off: back to shadow, and nothing on the node is touched.
		$this->flows(0);
		$this->secrets(self::LIVE, 'mains-third');
		$this->assertSame(['mode' => 'shadow', 'differ' => ['openssl_extra']], ReplicaApply::run(false, $rNow + 240)['secrets']);
		$this->assertSame('mains-next', file_get_contents($rConfig . 'openssl_extra'));
	}

	public function testTurningConfigOffHandsTheSettingsBackToMainsDatabase(): void {
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SETTINGS));

		$this->flows(0);
		ReplicaApply::run(false);
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS));
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `live_streaming_pass` text)');
		$rDb->exec("INSERT INTO `settings` VALUES (1, 'FromDb', 'db-pass')");
		$GLOBALS['db'] = $rDb;
		touch($this->rDir . '/cache/settings', time() - 3600);
		$this->assertSame(['FromDb', 'db-pass'], [SettingsRepository::getAll()['server_name'], SettingsRepository::getAll()['live_streaming_pass']]);
		$this->assertSame('FromDb', FileCache::getCache('settings')['server_name']);
	}

	public function testTheSettingsGoBackToMainsDatabaseWhenASectionGoesBad(): void {
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame('applied', ReplicaApply::run(true)['settings']['mode']);
		rename($this->rDir . '/replica/secrets.json', $this->rDir . '/secrets.bak');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS), 'secrets.json gone: not owned even before the next apply');
		rename($this->rDir . '/secrets.bak', $this->rDir . '/replica/secrets.json');
		$this->whole('secrets', ['live_streaming_pass' => 'x']);
		$this->assertSame('incomplete', ReplicaApply::run(true)['settings']['mode']);
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS));
		$this->secrets();
		ReplicaApply::run(true);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SETTINGS));
		// Not a raw row: an empty or partial one would be the node's whole settings, every flag it lacks unset.
		foreach (['an empty section' => [], 'no server_name' => ['seg_time' => '8'], 'a nested value' => ['server_name' => ['x']], 'a list' => ['a', 'b']] as $rWhy => $rData) {
			$this->whole('settings', $rData);
			$this->assertSame('refused', ReplicaApply::run(true)['settings']['mode'], $rWhy);
			$this->assertFalse(ReplicaApply::owns(ReplicaSections::SETTINGS), $rWhy);
			$this->assertSame('refused', ReplicaApply::run(false)['settings']['mode'], $rWhy);
		}
	}

	public function testASettingsReadRacingAnApplyNeverOverwritesTheReplicasCache(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `settings` (`id` int, `server_name` text, `live_streaming_pass` text, `redis_password` text)');
		$rDb->exec("INSERT INTO `settings` VALUES (1, 'FromDb', 'db-pass', 'db-only')");
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		// The agent's apply lands while getAll() (cron:cache's) reads MAIN's database.
		$rLog = new QueryLogDb($rDb);
		$rApplied = false;
		$rLog->rBefore = static function (string $rQuery) use (&$rApplied): void {
			if ($rQuery === 'SELECT * FROM `settings`' && !$rApplied) {
				$rApplied = true;
				ReplicaApply::run(true);
			}
		};
		$GLOBALS['db'] = $rLog;
		$this->assertSame('FromDb', SettingsRepository::getAll(true)['server_name'], 'not owned when it started: MAIN\'s database');
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SETTINGS));
		$rCache = FileCache::getCache('settings');
		$this->assertSame([self::LIVE, 'Renamed'], [$rCache['live_streaming_pass'], $rCache['server_name']], 'the replica\'s cache, not the row read before it');
		$this->assertArrayNotHasKey('redis_password', $rCache);
	}

	public function testAFailedOpensslExtraWriteIsReportedAndChangesNothing(): void {
		$rConfig = $this->rDir . '/config/';
		// A directory where adopt() writes .prev's temporary file: that write fails, even as root.
		$rBlock = $rConfig . 'openssl_extra.prev.' . getmypid() . '.tmp';
		mkdir($rBlock);
		FileCache::setCache('settings', ['live_streaming_pass' => self::LIVE]);
		$this->whole('settings', self::SETTINGS);
		$this->secrets(self::LIVE, 'mains-extra');
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame(['mode' => 'failed', 'differ' => ['openssl_extra']], ReplicaApply::run(true, 1800000000)['secrets']);
		$this->assertFileDoesNotExist($rConfig . 'openssl_extra', '.prev first: never the new value without the one it replaces');

		// cluster:apply prints the report (no secret in it) and exits 3, so the agent logs it.
		ob_start();
		$rExit = (new ClusterApplyCommand())->execute([]);
		$rOut = (string) ob_get_clean();
		$this->assertSame(ClusterApplyCommand::EXIT_FAILED, $rExit);
		$this->assertSame('failed', json_decode($rOut, true)['secrets']['mode']);
		foreach ([self::LIVE, 'mains-extra', OPENSSL_EXTRA] as $rSecret) {
			$this->assertStringNotContainsString($rSecret, $rOut);
		}

		@chmod($rBlock, 0700);
		rmdir($rBlock);
		ob_start();
		$rExit = (new ClusterApplyCommand())->execute([]);
		ob_end_clean();
		$this->assertSame(0, $rExit);
		$this->assertSame('mains-extra', file_get_contents($rConfig . 'openssl_extra'));
	}

	// ── The whole sections servers, node, crontab and cluster ─────────────

	/** A node's view of MAIN's database: every servers column, three servers (one disabled), the crontab. */
	private function mainDb(): TestDb {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1); // as the suite's other tests define it
		}
		// Another test's injected connection would stand in for this one.
		(new \ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::serversTable());
		$rDb->exec('CREATE TABLE `users` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `reseller_dns` text, `status` int)');
		$rDb->exec('CREATE TABLE `settings` (`id` int, `cloudflare` int, `mag_legacy_redirect` int)');
		$rDb->exec('INSERT INTO `settings` VALUES (1, 1, 0)');
		$rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `gen` int, `state` varchar(16), `node_sign_pub` blob)');
		$rDb->exec('CREATE TABLE `crontab` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `filename` varchar(255), `time` varchar(128), `enabled` int, `role` varchar(8))');
		$rDb->exec("INSERT INTO `crontab` (`filename`, `time`, `enabled`, `role`) VALUES ('streams', '* * * * *', 1, 'all'), ('tmdb', '0 * * * *', 1, 'main'), ('cache', '* * * * *', 1, 'all')");
		foreach ([SERVER_ID => ['limit_requests' => 30, 'total_services' => 6, 'governor' => '["performance"]'], SERVER_ID + 100 => ['is_main' => 1, 'limit_requests' => 99, 'domain_name' => 'main.example.com', 'enable_https' => 1], SERVER_ID + 200 => ['enabled' => 0]] as $rID => $rFields) {
			$rRow = $rFields + [
				'id' => $rID, 'server_type' => 0, 'server_name' => 'S' . $rID, 'server_ip' => '10.0.0.' . ($rID % 250), 'private_ip' => '', 'is_main' => 0, 'enabled' => 1,
				'parent_id' => '[]', 'geoip_countries' => '["DE"]', 'isp_names' => '[]', 'http_broadcast_port' => 8080, 'https_broadcast_port' => 8443, 'rtmp_port' => 8880,
				'http_ports_add' => '', 'https_ports_add' => '', 'status' => 1, 'last_check_ago' => time(), 'watchdog_data' => '{"cpu":3}', 'php_pids' => '[1]',
				'whitelist_ips' => '["10.9.9.9"]', 'network_interface' => 'eth0', 'time_offset' => 1, 'enable_https' => 0, 'domain_name' => '',
			];
			$rDb->query('INSERT INTO `servers` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ')', ...array_values($rRow));
		}
		DatabaseFactory::set($rDb);
		$GLOBALS['rSettings'] = null;
		SettingsManager::set(['live_streaming_pass' => 'local-pass']);
		return $rDb;
	}

	/** Write a whole section as the agent does: replica/<name>.json = {etag, data}. */
	private function whole(string $rName, array $rData): void {
		file_put_contents($this->rDir . '/replica/' . $rName . '.json', json_encode(['etag' => ReplicaBuilder::etag($rData), 'data' => $rData]));
	}

	/** The replica MAIN would send this node, from the same database. */
	private function sections(): void {
		$this->whole('servers', ReplicaBuilder::serversData());
		$this->whole('node', ReplicaBuilder::nodeData(SERVER_ID));
		$this->whole('crontab', ReplicaBuilder::crontabData(1));
	}

	public function testWithConfigOnTheReplicaWritesVerifyHostsList(): void {
		$rDb = $this->mainDb();
		$rDb->exec("INSERT INTO `users` VALUES (1, 'reseller.example.net', 1), (2, 'gone.example.net', 0)");
		$rFromDb = ServerRepository::getAllowedDomains(true);
		FileCache::delCache('allowed_domains');
		$this->sections();
		$this->flows(NodeFlows::CONFIG);

		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertEqualsCanonicalizing($rFromDb, FileCache::getCache('allowed_domains'), 'the list cron:cache builds from MAIN\'s database');
		$this->assertContains('reseller.example.net', $rFromDb);

		// A MAIN before `reseller_dns`: no list, rather than one that refuses resellers' viewers.
		$rServers = ReplicaBuilder::serversData();
		unset($rServers['reseller_dns']);
		$this->whole('servers', $rServers);
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertFalse(FileCache::getCache('allowed_domains'));
	}

	public function testWithConfigOnTheServersCacheKeepsGetAllsShape(): void {
		$rDb = $this->mainDb();
		$rOther = SERVER_ID + 100;
		$rFromDb = ServerRepository::getAll(true);
		$this->sections();
		FileCache::delCache('servers');
		$this->flows(NodeFlows::CONFIG);

		$rReport = ReplicaApply::run(true, null, SERVER_ID);
		$this->assertSame(['mode' => 'applied', 'rows' => 3], array_intersect_key($rReport['servers'], ['mode' => 1, 'rows' => 1]));
		$rCache = FileCache::getCache('servers');
		$this->assertSame(array_keys($rFromDb), array_keys($rCache));
		foreach ($rFromDb as $rID => $rRow) {
			ksort($rRow);
			$rMine = $rCache[$rID];
			ksort($rMine);
			$this->assertSame(array_keys($rRow), array_keys($rMine), 'the same keys as getAll builds');
			$rSkip = array_merge(ReplicaSections::SERVER_LOCAL, ['watchdog', 'cluster_health'], $rID === SERVER_ID ? [] : array_diff(array_keys(ReplicaSections::NODE_FIELDS), array_keys(ReplicaSections::SERVER_FIELDS)));
			$this->assertSame(array_diff_key($rRow, array_flip($rSkip)), array_diff_key($rMine, array_flip($rSkip)), 'server ' . $rID);
		}
		$this->assertSame('http://10.0.0.' . (SERVER_ID % 250) . ':8080/api?password=local-pass', $rCache[SERVER_ID]['api_url'], 'built here, from this node\'s own settings');
		$this->assertSame([30, 6, '["performance"]', 1], [$rCache[SERVER_ID]['limit_requests'], $rCache[SERVER_ID]['total_services'], $rCache[SERVER_ID]['governor'], $rCache[SERVER_ID]['time_offset']], 'the node section');
		$this->assertSame([null, null, null, null, true], [$rCache[$rOther]['limit_requests'], $rCache[$rOther]['status'], $rCache[$rOther]['watchdog_data'], $rCache[$rOther]['watchdog'], $rCache[$rOther]['server_online']], 'no other node\'s own settings, no liveness');
		$this->assertFalse($rCache[SERVER_ID + 200]['server_online'], 'a disabled server is never tried');

		// Every reader takes it, however old, never MAIN's database.
		$rDb->query('UPDATE `servers` SET `http_broadcast_port` = 1 WHERE `id` = ?', $rOther);
		touch($this->rDir . '/cache/servers', time() - 3600);
		$this->assertSame(8080, ServerRepository::getAll()[$rOther]['http_broadcast_port']);
		$this->assertSame(8080, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);

		// Without the replica's sections the node reads MAIN's database as before.
		unlink($this->rDir . '/replica/node.json');
		$this->assertSame(1, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);
	}

	public function testReadingTheFlowsEndsWhenTheReplicaOwnsTheServers(): void {
		// The agent's file is ignored on MAIN, which NodeRole reads from the
		// servers, which on this node the replica owns: asked of NodeFlows again.
		NodeRole::useServers(null);
		$this->mainDb();
		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		$this->assertTrue(NodeFlows::on(NodeFlows::CONFIG));
		$this->assertSame(8080, ServerRepository::getAll(true)[SERVER_ID + 100]['http_broadcast_port']);

		$rCache = FileCache::getCache('servers');
		$rCache[SERVER_ID]['is_main'] = 1;
		FileCache::setCache('servers', $rCache);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		$this->assertFalse(NodeFlows::on(NodeFlows::CONFIG), 'MAIN never has flows, whatever a stray file says');
	}

	public function testServersInShadowOnlyNameWhatDiffers(): void {
		$this->mainDb();
		$rOther = SERVER_ID + 100;
		ServerRepository::getAll(true); // cron:cache's cache, from MAIN's database
		$rBefore = FileCache::getCache('servers');
		$rServers = ReplicaBuilder::serversData();
		$rServers['servers'][1]['rtmp_port'] = 1935;
		$rServers['servers'][] = ['id' => 999] + ReplicaSections::typed([], ReplicaSections::SERVER_FIELDS);
		$this->whole('servers', $rServers);
		$this->whole('node', ReplicaBuilder::nodeData(SERVER_ID));
		$this->flows(0);

		$rReport = ReplicaApply::run(false, null, SERVER_ID)['servers'];
		$this->assertSame(['shadow', 4, [], [999], [$rOther . '.rtmp_port']], [$rReport['mode'], $rReport['rows'], $rReport['missing'], $rReport['extra'], $rReport['differ']]);
		$this->assertSame($rBefore, FileCache::getCache('servers'), 'nothing written in shadow');
	}

	public function testAMissingForeignOrMalformedSectionNeverTouchesTheServersCache(): void {
		$this->mainDb();
		$this->flows(NodeFlows::CONFIG);
		FileCache::setCache('servers', ['kept' => true]);
		$rServers = ReplicaBuilder::serversData();
		$rNode = ReplicaBuilder::nodeData(SERVER_ID);

		$this->whole('servers', $rServers);
		$this->assertSame('incomplete', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], 'no node section yet');
		foreach ([
			'another node\'s section' => [$rServers, ['id' => SERVER_ID + 1] + $rNode],
			'no row for this node' => [['servers' => [$rServers['servers'][1]], 'nodes' => []], $rNode],
			'not a list' => [['servers' => 'x', 'nodes' => []], $rNode],
			'a bad node entry' => [['servers' => $rServers['servers'], 'nodes' => [['sid' => 5, 'gen' => 1, 'state' => 'gone', 'ed_pub' => '']]], $rNode],
			'a duplicate id' => [['servers' => array_merge($rServers['servers'], [$rServers['servers'][1]]), 'nodes' => []], $rNode],
		] as $rWhy => [$rS, $rN]) {
			$this->whole('servers', $rS);
			$this->whole('node', $rN);
			$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], $rWhy);
		}
		file_put_contents($this->rDir . '/replica/servers.json', '{"etag": "x", "data": ');
		$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode'], 'unreadable');
		$this->assertSame(['kept' => true], FileCache::getCache('servers'));
	}

	public function testTheCrontabSectionIsTheJobsTheNodesCrontabRuns(): void {
		$rDb = $this->mainDb();
		$this->assertSame(['streams', 'tmdb', 'cache'], array_column(ReplicaApply::cronJobs($rDb), 'filename'), 'CONFIG off: MAIN\'s table');
		$this->sections();

		$rReport = ReplicaApply::run(false, null, SERVER_ID)['crontab'];
		$this->assertSame(['shadow', 2, ['tmdb'], []], [$rReport['mode'], $rReport['jobs'], $rReport['missing'], $rReport['extra']], 'MAIN\'s own rows stay on MAIN');
		$this->assertFalse(FileCache::getCache(ReplicaApply::CRON_CACHE));

		$this->flows(NodeFlows::CONFIG);
		$this->assertCount(3, ReplicaApply::cronJobs($rDb), 'stored but not applied yet: MAIN\'s table until an apply builds the jobs');
		$this->assertSame('applied', ReplicaApply::run(true, null, SERVER_ID)['crontab']['mode']);
		$rJobs = [['filename' => 'streams', 'time' => '* * * * *'], ['filename' => 'cache', 'time' => '* * * * *']];
		$this->assertSame($rJobs, ReplicaApply::cronJobs(null));
		$this->assertSame($rJobs, ReplicaApply::cronJobs($rDb), 'never MAIN\'s table once applied');

		// A job a shell or cron would read more into refuses the whole section:
		// its jobs are never written, and the crontab is MAIN's table's again.
		foreach ([
			'a command after the name' => ['filename' => 'streams; rm -rf /', 'time' => '* * * * *'],
			'a command after the schedule' => ['filename' => 'streams', 'time' => '* * * * * /bin/sh'],
			'six fields' => ['filename' => 'streams', 'time' => '* * * * * *'],
			'four fields' => ['filename' => 'streams', 'time' => '* * * *'],
			'a newline' => ['filename' => 'streams', 'time' => "* * * * *\n* * * * *"],
			'a trailing newline' => ['filename' => 'streams', 'time' => "* * * * *\n"],
			'a percent sign' => ['filename' => 'streams', 'time' => '*/5%2 * * * *'],
			'a macro' => ['filename' => 'streams', 'time' => '@daily'],
			'a day name' => ['filename' => 'streams', 'time' => '0 4 * * MON'],
			'a dot in the name' => ['filename' => 'stre.ams', 'time' => '* * * * *'],
			'a slash in the name' => ['filename' => '../streams', 'time' => '* * * * *'],
			'an upper-case name' => ['filename' => 'Streams', 'time' => '* * * * *'],
			'a 65-character name' => ['filename' => str_repeat('a', 65), 'time' => '* * * * *'],
			'a newline after the name' => ['filename' => "streams\n", 'time' => '* * * * *'],
			'a time that is not a string' => ['filename' => 'streams', 'time' => 5],
			'no name' => ['time' => '* * * * *'],
		] as $rWhy => $rJob) {
			$this->assertNull(ReplicaSections::cronJob($rJob), $rWhy);
			$this->whole('crontab', ['jobs' => [['filename' => 'cache', 'time' => '* * * * *'], $rJob]]);
			$this->assertSame('refused', ReplicaApply::run(true, null, SERVER_ID)['crontab']['mode'], $rWhy);
			$this->assertSame($rJobs, FileCache::getCache(ReplicaApply::CRON_CACHE), $rWhy . ': nothing written');
			$this->assertCount(3, ReplicaApply::cronJobs($rDb), $rWhy . ': MAIN\'s table again');
		}
		$this->assertSame(['filename' => str_repeat('a', 64), 'time' => '*/5 0-23 1,15 * *'], ReplicaSections::cronJob(['filename' => str_repeat('a', 64), 'time' => '*/5 0-23 1,15 * *']));
		$this->whole('crontab', ReplicaBuilder::crontabData(1));
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertSame($rJobs, ReplicaApply::cronJobs($rDb), 'a good section again: the replica\'s');

		unlink($this->rDir . '/replica/crontab.json');
		$this->assertCount(3, ReplicaApply::cronJobs($rDb), 'no crontab section: MAIN\'s table, as before');
	}

	public function testTheCrontabTextIsWhatBothReadersWrite(): void {
		$rDb = $this->mainDb();
		$rLine = static fn(string $rTime, string $rName): string => $rTime . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:' . $rName . ' # XC_VM';
		$this->assertSame(implode("\n", [$rLine('* * * * *', 'streams'), $rLine('0 * * * *', 'tmdb'), $rLine('* * * * *', 'cache')]), ReplicaApply::crontabText($rDb), 'CONFIG off: MAIN\'s table');
		$this->assertNull(ReplicaApply::crontabText(null), 'no database: leave the crontab as it is');
		$rDb->exec('UPDATE `crontab` SET `enabled` = 0');
		$this->assertSame('', ReplicaApply::crontabText($rDb), 'no job at all: an empty crontab, not "leave it"');
		$rDb->exec('UPDATE `crontab` SET `enabled` = 1');

		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		$rReplica = implode("\n", [$rLine('* * * * *', 'streams'), $rLine('* * * * *', 'cache')]);
		$this->assertSame($rReplica, ReplicaApply::crontabText($rDb));
		$this->assertSame($rReplica, ReplicaApply::crontabText(null));
		FileCache::delCache(ReplicaApply::CRON_CACHE);
		$this->assertNull(ReplicaApply::crontabText($rDb), 'owned but its jobs are gone: leave the crontab as it is, never an empty one');
	}

	public function testTurningConfigOnWaitsForAnApplyAndNeverFreezesMainsCopy(): void {
		$rDb = $this->mainDb();
		$rOther = SERVER_ID + 100;
		// Shadow: cron:cache's copy of MAIN's database, the other server offline right then.
		$rDb->query('UPDATE `servers` SET `status` = 0 WHERE `id` = ?', $rOther);
		FileCache::setCache('servers', ServerRepository::getAll(true));
		$this->sections();
		ReplicaApply::run(false, null, SERVER_ID);

		// CONFIG on, no section changed: nothing has applied the replica yet.
		$this->flows(NodeFlows::CONFIG);
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SERVERS), 'not before an apply built the cache');
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::CRONTAB));
		$rDb->query('UPDATE `servers` SET `status` = 1 WHERE `id` = ?', $rOther);
		touch($this->rDir . '/cache/servers', time() - 86400);
		$this->assertTrue(ServerRepository::getAll()[$rOther]['server_online'], 'MAIN\'s database, refreshed as before');

		// The first apply (the agent's, or cron:cache's within the minute) hands it over.
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SERVERS));
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::CRONTAB));
		$rDb->query('UPDATE `servers` SET `status` = 0, `http_broadcast_port` = 1 WHERE `id` = ?', $rOther);
		$rAll = ServerRepository::getAll(true);
		$this->assertSame([true, 8080], [$rAll[$rOther]['server_online'], $rAll[$rOther]['http_broadcast_port']], 'the replica\'s rows: enabled is online, never MAIN\'s liveness');

		// CONFIG off: the caches are MAIN's database's again, and on again they wait for an apply.
		$this->flows(0);
		ReplicaApply::run(false, null, SERVER_ID);
		$this->flows(NodeFlows::CONFIG);
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SERVERS));
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::CRONTAB));
		$this->assertSame(1, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);
		ReplicaApply::run(true, null, SERVER_ID);
		ReplicaApply::disown(); // as cron:cache does every minute while CONFIG is off
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SERVERS));
	}

	public function testTheCrontabIsNeverTakenBackStaleAfterConfigWasOff(): void {
		$rDb = $this->mainDb();
		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertSame('* * * * *', ReplicaApply::cronJobs(null)[1]['time']);

		// CONFIG off, and the schedule changes meanwhile; the agent stores it.
		$this->flows(0);
		ReplicaApply::run(false, null, SERVER_ID);
		$rDb->exec("UPDATE `crontab` SET `time` = '0 3 * * *' WHERE `filename` = 'cache'");
		$this->whole('crontab', ReplicaBuilder::crontabData(1));

		$this->flows(NodeFlows::CONFIG);
		$this->assertNull(ReplicaApply::cronJobs(null), 'never the jobs applied before CONFIG went off');
		$this->assertSame('0 3 * * *', ReplicaApply::cronJobs($rDb)[2]['time'], 'MAIN\'s table until the next apply');
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertSame('0 3 * * *', ReplicaApply::cronJobs(null)[1]['time']);
	}

	public function testAnOwnedServersCacheThatIsGoneIsRebuiltFromTheReplica(): void {
		$rDb = $this->mainDb();
		$rOther = SERVER_ID + 100;
		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		FileCache::delCache('servers');
		$rDb->query('UPDATE `servers` SET `status` = 0, `http_broadcast_port` = 1 WHERE `id` = ?', $rOther);
		$rAll = ServerRepository::getAll();
		$this->assertSame([8080, true], [$rAll[$rOther]['http_broadcast_port'], $rAll[$rOther]['server_online']], 'rebuilt from the replica on disk, not MAIN\'s snapshot');
		touch($this->rDir . '/cache/servers', time() - 86400);
		$this->assertSame(8080, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);

		// A replica that is refused hands the cache back to MAIN's database,
		// which is read again each time, never frozen.
		FileCache::delCache('servers');
		$this->whole('node', ['id' => SERVER_ID + 1] + ReplicaBuilder::nodeData(SERVER_ID));
		$this->assertSame(1, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);
		$this->assertFalse(ReplicaApply::owns(ReplicaSections::SERVERS));
		$rDb->query('UPDATE `servers` SET `http_broadcast_port` = 2 WHERE `id` = ?', $rOther);
		$this->assertSame(2, ServerRepository::getAll(true)[$rOther]['http_broadcast_port']);
	}

	public function testADatabaseReadRacingAnApplyNeverOverwritesTheReplicasCache(): void {
		$rDb = $this->mainDb();
		$rOther = SERVER_ID + 100;
		$rDb->query('UPDATE `servers` SET `status` = 0 WHERE `id` = ?', $rOther);
		$this->sections();
		$this->flows(NodeFlows::CONFIG);
		// The agent's apply lands while getAll() reads MAIN's database.
		$rLog = new QueryLogDb($rDb);
		$rLog->rBefore = static function (string $rQuery): void {
			if ($rQuery === 'SELECT * FROM `servers`') {
				ReplicaApply::run(true, null, SERVER_ID);
			}
		};
		DatabaseFactory::set($rLog);
		$this->assertFalse(ServerRepository::getAll(true)[$rOther]['server_online'], 'not owned when it started: MAIN\'s database');
		$this->assertTrue(ReplicaApply::owns(ReplicaSections::SERVERS));
		$this->assertTrue(FileCache::getCache('servers')[$rOther]['server_online'], 'the replica\'s cache, not the rows read before it');
	}

	public function testTheNodeSectionIsTheNodesOwnRowWhichIsAlwaysOnline(): void {
		$rDb = $this->mainDb();
		$rDb->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ?', SERVER_ID);
		$this->whole('servers', ReplicaBuilder::serversData());
		$this->whole('node', ['http_broadcast_port' => 9090, 'https_broadcast_port' => 9443, 'enable_https' => 1] + ReplicaBuilder::nodeData(SERVER_ID));
		$this->flows(NodeFlows::CONFIG);
		$this->assertSame('applied', ReplicaApply::run(true, null, SERVER_ID)['servers']['mode']);
		$rOwn = FileCache::getCache('servers')[SERVER_ID];
		$this->assertSame([9090, 9443, 1, 'https', 9443], [$rOwn['http_broadcast_port'], $rOwn['https_broadcast_port'], $rOwn['enable_https'], $rOwn['server_protocol'], $rOwn['request_port']], 'the node section over its row in the servers section');
		$this->assertSame([0, true], [$rOwn['enabled'], $rOwn['server_online']], 'this node, even disabled');
		$this->assertSame(8080, FileCache::getCache('servers')[SERVER_ID + 100]['http_broadcast_port'], 'only its own row');
	}

	public function testTheClusterSectionIsComparedWithTheAgentsPolicy(): void {
		file_put_contents($this->rDir . '/agent.json', json_encode(['panel_sign_pub' => base64_encode(str_repeat('k', 32)), 'main_urls' => ['http://10.0.0.1:80/cluster/v1/'], 'policy_ver' => 3]));
		$rData = ['main_urls' => ['http://10.0.0.1:80/cluster/v1/'], 'urls_ver' => 4, 'policy_ver' => 4, 'transport' => 'auto', 'panel_sign_pub' => base64_encode(str_repeat('k', 32)), 'panel_box_pub' => base64_encode(str_repeat('b', 32)), 'min_proto' => 1, 'off_air' => []];
		$this->whole('cluster', $rData);
		$this->flows(NodeFlows::CONFIG);
		$rReport = ReplicaApply::run(true, null, 5)['cluster'];
		$this->assertSame(['shadow', 4, ['policy_ver']], [$rReport['mode'], $rReport['policy_ver'], $rReport['differ']], 'the agent adopts the policy; PHP reads none of it');

		$this->whole('cluster', ['panel_sign_pub' => base64_encode(str_repeat('z', 32))] + $rData);
		$this->assertSame(['panel_sign_pub', 'policy_ver'], ReplicaApply::run(true, null, 5)['cluster']['differ']);
		$this->whole('cluster', ['main_urls' => 'nope'] + $rData);
		$this->assertSame('refused', ReplicaApply::run(true, null, 5)['cluster']['mode']);
	}

	public function testTheServersCacheTakesTheStreamSecretFromTheSecretsSection(): void {
		$this->mainDb();
		$this->sections();
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		$this->assertStringEndsWith('/api?password=' . self::LIVE, FileCache::getCache('servers')[SERVER_ID]['api_url'], 'the settings this apply made, not the ones this process loaded');
	}

	public function testANodeWhoseReplicaOwnsTheSettingsLoadsThemWithoutMainsDatabase(): void {
		// Loading the settings asks NodeFlows, which rules out MAIN through the
		// servers, before any settings are loaded.
		NodeRole::useServers(null);
		$rDb = $this->mainDb();
		$this->sections();
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		SettingsManager::set([]);
		$GLOBALS['rSettings'] = null;
		$rLog = new QueryLogDb($rDb);
		$rLog->rBefore = function (string $rQuery): void {
			$this->fail('MAIN\'s database read: ' . $rQuery);
		};
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		$this->assertSame(['Renamed', self::LIVE], [SettingsRepository::getAll()['server_name'], SettingsRepository::getAll()['live_streaming_pass']]);
		$this->assertStringEndsWith('/api?password=' . self::LIVE, ServerRepository::getAll()[SERVER_ID]['api_url']);
	}

	public function testWithoutAnApplyRecordLoadingTheSettingsNeverReadsTheServers(): void {
		// MAIN, a legacy node, CONFIG off or not applied yet: nothing asks
		// NodeFlows, which would read the servers before the settings are loaded.
		NodeRole::useServers(null);
		$rDb = $this->mainDb();
		$this->flows(NodeFlows::CONFIG);
		NodeFlows::usePath($this->rDir . '/flows.json', true);
		SettingsManager::set([]);
		$GLOBALS['rSettings'] = null;
		$rLog = new QueryLogDb($rDb);
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		SettingsRepository::getAll(true);
		$this->assertSame(['SELECT * FROM `settings`'], $rLog->rQueries);
	}

	public function testAServersCacheRebuiltBeforeTheSettingsAreLoadedTakesTheSettingsCache(): void {
		$this->mainDb();
		$this->sections();
		$this->whole('settings', self::SETTINGS);
		$this->secrets();
		$this->flows(NodeFlows::CONFIG);
		ReplicaApply::run(true, null, SERVER_ID);
		SettingsManager::set([]);
		$GLOBALS['rSettings'] = null;
		FileCache::delCache('servers');
		$this->assertStringEndsWith('/api?password=' . self::LIVE, ServerRepository::getAll()[SERVER_ID]['api_url'], 'rebuilt from the replica with the settings cache, not empty settings');
	}

	public function testAServersReadBeforeTheSettingsAreLoadedTakesTheSettingsCache(): void {
		$this->mainDb();
		SettingsManager::set([]);
		FileCache::setCache('settings', ['live_streaming_pass' => 'cached-pass']);
		$this->assertStringEndsWith('/api?password=cached-pass', ServerRepository::getAll(true)[SERVER_ID]['api_url']);
	}
}

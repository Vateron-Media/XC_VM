<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterEndpointCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Server\SettingsService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\QueryLogDb;

if (!defined('STATUS_FAILURE')) {
	define('STATUS_FAILURE', 0);
}
if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_INVALID_DATA')) {
	define('STATUS_INVALID_DATA', 17);
}

/**
 * A settings save that changes the transport or MAIN's DNS name (plan §3,
 * "Endpoint changes"): `cluster_transport` and `cluster_main_host` decide
 * URLs of the policy, as MAIN's `servers` row does. When a save moves them
 * while a node may use them, the URLs the policy no longer lists stay in it,
 * last, for seven days, where the listing rules allow it: a change of the
 * DNS name keeps the old name's URLs, a change of the transport alone keeps
 * nothing, since the transport decides which schemes are listed. They are
 * stored in the save's own UPDATE, with the policy version, over the kept
 * lists as read.
 *
 * The saves go through SettingsService::edit(), the admin's save path, on
 * the TestDb.
 */
final class ClusterEndpointSettingsTest extends TestCase {
	/** The settings columns: name => [data_type, default]. */
	private const COLUMNS = [
		'cluster_api_enabled' => ['int', '0'],
		'cluster_api_port' => ['int', '0'],
		'cluster_transport' => ['varchar', 'auto'],
		'cluster_main_host' => ['varchar', ''],
		'cluster_policy_ver' => ['int', '1'],
		'cluster_legacy_ports' => ['varchar', ''],
		'cluster_legacy_urls' => ['mediumtext', ''],
		'cluster_offline_after_sec' => ['int', '30'],
		'search_items' => ['int', '15'],
		'disable_table_responsive' => ['int', '0'],
		'allowed_stb_types_for_local_recording' => ['text', ''],
		'allowed_stb_types' => ['text', ''],
		'maxmind_editions' => ['text', ''],
		'shared_mount_prefixes' => ['text', ''],
		'allow_countries' => ['text', ''],
		'dropbox_remote' => ['int', '0'],
	];

	private TestDb $rDb;

	private string $rDir;

	private int $rNow = 1800000000;

	/** MAIN's `servers` row. */
	private array $rMain = [
		'id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'domain_name' => 'panel.example.com',
		'enable_https' => 1, 'http_broadcast_port' => 25461, 'https_broadcast_port' => 25463, 'http_ports_add' => '', 'https_ports_add' => '',
	];

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-endpoint-settings-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin/nginx/conf/ports', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		file_put_contents($this->rDir . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		copy(MAIN_HOME . 'bin/nginx/conf/nginx.conf', $this->rDir . 'bin/nginx/conf/nginx.conf');
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', $this->rDir . 'cache/');
		}

		$this->rDb = new TestDb();
		$rDdl = [];
		foreach (self::COLUMNS as $rName => [$rType, $rDefault]) {
			$rDdl[] = '`' . $rName . '` ' . match ($rType) {
				'int' => "int DEFAULT '" . $rDefault . "'",
				'mediumtext' => 'text',
				default => "varchar(255) DEFAULT '" . $rDefault . "'",
			};
		}
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, ' . implode(', ', $rDdl) . ')');
		$this->rDb->exec('INSERT INTO `settings` (`id`, `cluster_api_enabled`) VALUES (1, 1)');
		$this->rDb->exec("CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `is_main` int NOT NULL DEFAULT 0, `server_ip` varchar(255) DEFAULT '', `private_ip` varchar(255) DEFAULT '', `domain_name` varchar(255) DEFAULT '', `enable_https` int NOT NULL DEFAULT 0, `http_broadcast_port` int DEFAULT NULL, `https_broadcast_port` int DEFAULT NULL, `http_ports_add` varchar(255) DEFAULT '', `https_ports_add` varchar(255) DEFAULT '')");
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `state` varchar(16) NOT NULL DEFAULT 'enrolling', `mode` int NOT NULL DEFAULT 1, `enrol_deadline` int DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `policy_ver` int NOT NULL DEFAULT 0, `main_port` int DEFAULT NULL, `features` varchar(255) DEFAULT NULL)");
		$this->rDb->exec('CREATE TABLE `cluster_enrol_codes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int NOT NULL, `created_at` int NOT NULL DEFAULT 0, `exp` int NOT NULL, `used_at` int DEFAULT NULL)');
		$this->rDb->exec("CREATE TABLE `cluster_enrol_requests` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int NOT NULL, `state` varchar(20) NOT NULL DEFAULT 'pending_approval', `created_at` int NOT NULL)");
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams_arguments` (`argument_key` varchar(64), `argument_default_value` varchar(255))');
		DatabaseFactory::set($this->rDb);
		// QueryHelper::verifyPostTable() reads the global handler.
		$GLOBALS['db'] = $this->rDb;
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		$this->main([]);
		$this->rSettingsBefore = SettingsManager::getAll();
		SettingsManager::set($this->settings());
		unset($_COOKIE['lang']);
		Translator::init(MAIN_HOME . 'Core/Localization/lang');

		ClusterClock::fix($this->rNow * 1000);
		ClusterSettings::useHttpsProbe(static fn(array $rMain): array => ['ok' => true, 'reason' => 'OK', 'host' => 'panel.example.com', 'days_left' => 80]);
		// nginx, for a save that also moves cluster_api_port.
		ClusterNginxConfig::useBase($this->rDir, (string) (posix_getpwuid(posix_geteuid())['name'] ?? ''));
		ClusterNginxConfig::useRunner(static fn(array $rArgv): array => [0, '']);
		ClusterNginxConfig::useProbe(static fn(string $rCheck, int $rPort): bool => true);
	}

	protected function tearDown(): void {
		ClusterNginxConfig::useRunner(null);
		ClusterNginxConfig::useProbe(null);
		ClusterNginxConfig::useBase(null);
		ClusterSettings::useHttpsProbe(null);
		ClusterClock::fix(null);
		SettingsManager::set($this->rSettingsBefore);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		unset($GLOBALS['db']);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** MAIN's row with $rOver: stored, and in the servers cache the settings form reads. */
	private function main(array $rOver): void {
		$this->rMain = $rOver + $this->rMain;
		$this->rDb->query('DELETE FROM `servers` WHERE `id` = 1');
		$this->rDb->query(
			'INSERT INTO `servers` (`id`, `is_main`, `server_ip`, `private_ip`, `domain_name`, `enable_https`, `http_broadcast_port`, `https_broadcast_port`, `http_ports_add`, `https_ports_add`) VALUES (1, 1, ?, ?, ?, ?, ?, ?, ?, ?)',
			$this->rMain['server_ip'],
			$this->rMain['private_ip'],
			$this->rMain['domain_name'],
			$this->rMain['enable_https'],
			$this->rMain['http_broadcast_port'],
			$this->rMain['https_broadcast_port'],
			$this->rMain['http_ports_add'],
			$this->rMain['https_ports_add']
		);
		FileCache::setCache('servers', [1 => $this->rMain]);
	}

	private function settings(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	/** A settings column as another path stored it; the panel's settings cache follows. */
	private function store(string $rColumn, int|string $rValue): void {
		$this->rDb->query('UPDATE `settings` SET `' . $rColumn . '` = ?', $rValue);
		SettingsManager::set($this->settings());
	}

	/**
	 * A node and its server: heard 1 s ago, saying it dials policy $rVer (0:
	 * an agent that does not say) and reached MAIN on $rPort.
	 */
	private function node(int $rServerID, string $rState = 'active', int $rMode = 1, int $rVer = 0, ?int $rPort = null): void {
		$this->rDb->query('DELETE FROM `servers` WHERE `id` = ?', $rServerID);
		$this->rDb->query('INSERT INTO `servers` (`id`) VALUES (?)', $rServerID);
		$this->rDb->query('DELETE FROM `cluster_nodes` WHERE `server_id` = ?', $rServerID);
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `state`, `mode`, `last_seen_at`, `policy_ver`, `main_port`) VALUES (?, ?, ?, ?, ?, ?)', $rServerID, $rState, $rMode, $this->rNow * 1000 - 1000, $rVer, $rPort);
	}

	/** The admin saves Settings (the Cluster tab's fields among the rest). Returns the status. */
	private function save(array $rFields): int {
		$rResult = SettingsService::edit($rFields + ['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15']);
		SettingsManager::set($this->settings());
		return (int) $rResult['status'];
	}

	private function ver(): int {
		return (int) $this->settings()['cluster_policy_ver'];
	}

	/** @return array<string, int> the kept URLs, as stored: url => expiry */
	private function kept(): array {
		return ClusterEndpoint::legacyUrls($this->settings());
	}

	/** @return list<string> the URLs the policy sends the nodes */
	private function urls(): array {
		return ClusterPolicy::current($this->settings(), $this->rMain)['main_urls'];
	}

	/** @return list<array{event: string, actor: string, detail: array<string, mixed>}> */
	private function audit(): array {
		$this->rDb->query('SELECT `event`, `actor`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): array => ['event' => (string) $rRow['event'], 'actor' => (string) $rRow['actor'], 'detail' => (array) json_decode((string) $rRow['detail'], true)], $this->rDb->get_rows());
	}

	/**
	 * MAIN's DNS name changes: its URLs, plain HTTP and HTTPS (the name is
	 * also the TLS name), stay in the policy after the new ones. Moving back
	 * puts them where they were, and keeps the name just left.
	 */
	public function testANewDnsNameKeepsTheOldNamesUrls(): void {
		$this->store('cluster_transport', 'https_preferred');
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;
		$rBefore = [
			'https://a.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://a.example.com:25461/cluster/v1/',
		];
		$this->assertSame($rBefore, $this->urls());

		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(2, $this->ver(), 'agents refetch the policy');
		$this->assertSame(['https://a.example.com:25463/cluster/v1/' => $rUntil, 'http://a.example.com:25461/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame('', (string) $this->settings()['cluster_legacy_ports'], 'no port moved');
		$this->assertSame([
			'https://b.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://b.example.com:25461/cluster/v1/',
			'https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/',
		], $this->urls(), 'the new name first, the old one last');
		$this->assertNull(ClusterNginxConfig::render($this->settings(), [25461, 25463], $this->rNow)[ClusterNginxConfig::OLD_PORT], 'MAIN serves both ports already');

		$rAudit = $this->audit();
		$this->assertSame([['cluster.endpoint_change', 'admin']], array_map(static fn(array $rRow): array => [$rRow['event'], $rRow['actor']], $rAudit));
		$this->assertSame([
			'urls_from' => $rBefore,
			'urls_to' => ['https://b.example.com:25463/cluster/v1/', 'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://b.example.com:25461/cluster/v1/'],
			'kept_urls' => ['https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'],
			'kept_ports' => [],
			'settings_from' => ['cluster_main_host' => 'a.example.com'],
			'settings_to' => ['cluster_main_host' => 'b.example.com'],
		], $rAudit[0]['detail']);

		// Back to the first name a minute later: in use again, its URLs leave
		// the kept list, and the name just left joins it.
		ClusterClock::fix(($this->rNow + 60) * 1000);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'a.example.com']));
		$this->assertSame(3, $this->ver());
		$this->assertSame(['https://b.example.com:25463/cluster/v1/' => $rUntil + 60, 'http://b.example.com:25461/cluster/v1/' => $rUntil + 60], $this->kept());
		$this->assertSame(array_merge($rBefore, ['https://b.example.com:25463/cluster/v1/', 'http://b.example.com:25461/cluster/v1/']), $this->urls());
	}

	/**
	 * A name set where there was none adds its URLs and keeps nothing, unless
	 * it replaces domain_name's as the TLS name; a name cleared keeps its
	 * URLs; a kept URL that is current again leaves the list.
	 */
	public function testADnsNameSetOrClearedKeepsTheUrlsItReplaces(): void {
		$this->node(2);
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;

		// auto lists plain HTTP alone: the name adds a URL, and nothing is gone.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'main.example.com']));
		$this->assertSame(2, $this->ver());
		$this->assertSame([], $this->kept());
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://main.example.com:25461/cluster/v1/'], $this->urls());
		$this->assertSame([[]], array_map(static fn(array $rRow): array => $rRow['detail']['kept_urls'], $this->audit()), 'announced, nothing kept');

		// Cleared: its URL is kept.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => '']));
		$this->assertSame(3, $this->ver());
		$this->assertSame(['http://main.example.com:25461/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://main.example.com:25461/cluster/v1/'], $this->urls(), 'last, as a kept URL');

		// HTTPS listed, under domain_name's name: added, nothing gone.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_preferred']));
		$this->assertSame(4, $this->ver());
		$this->assertSame(['http://main.example.com:25461/cluster/v1/' => $rUntil], $this->kept());

		// The name again: it is the TLS name now, so domain_name's HTTPS URL
		// is kept, and the name's own URL is current again.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'main.example.com']));
		$this->assertSame(5, $this->ver());
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame([
			'https://main.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://main.example.com:25461/cluster/v1/',
			'https://panel.example.com:25463/cluster/v1/',
		], $this->urls());

		// Cleared under HTTPS: both of its URLs are kept, domain_name's is current again.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => '']));
		$this->assertSame(6, $this->ver());
		$this->assertSame(['https://main.example.com:25463/cluster/v1/' => $rUntil, 'http://main.example.com:25461/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame([
			'https://panel.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/',
			'https://main.example.com:25463/cluster/v1/', 'http://main.example.com:25461/cluster/v1/',
		], $this->urls());
	}

	/**
	 * A row with no HTTPS port (ServerService stores NULL): the policy names
	 * port 443, which nginx never served. An old name's https:// URL is not
	 * kept on it, as for a change of MAIN's row.
	 */
	public function testAnHttpsUrlIsKeptOnlyOnThePortTheRowStores(): void {
		$this->main(['https_broadcast_port' => null]);
		$this->store('cluster_transport', 'https_preferred');
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$this->assertSame('https://a.example.com:443/cluster/v1/', $this->urls()[0]);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(2, $this->ver());
		$this->assertSame(['http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()), '443 is not kept');
	}

	/**
	 * The transport decides which schemes the policy lists, and a kept URL is
	 * listed only with a scheme the transport lists. A change of the
	 * transport alone moves no host and no port, so what it drops is a whole
	 * scheme, which no kept URL could be listed with: it keeps nothing. It is
	 * announced all the same, and the URLs kept before stay stored, listed
	 * again once the transport lists their scheme.
	 */
	public function testATransportChangeKeepsNothing(): void {
		// A node still enrolling may use the URLs, and does not block https_required.
		$this->node(2, 'enrolling');
		$rEarlier = ['https://old.example.com:25463/cluster/v1/' => $this->rNow + 3600, 'http://10.0.0.9:25461/cluster/v1/' => $this->rNow + 3600];
		$this->store('cluster_legacy_urls', (string) json_encode($rEarlier, JSON_UNESCAPED_SLASHES));
		$rPlain = ['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'];
		$this->assertSame(array_merge($rPlain, ['http://10.0.0.9:25461/cluster/v1/']), $this->urls());

		// HTTPS added: nothing gone.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_preferred']));
		$this->assertSame(2, $this->ver());
		$this->assertSame(array_merge(['https://panel.example.com:25463/cluster/v1/'], $rPlain, array_keys($rEarlier)), $this->urls());

		// https_required: plain HTTP goes, and is not kept (never listed under it).
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_required']));
		$this->assertSame(3, $this->ver());
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/', 'https://old.example.com:25463/cluster/v1/'], $this->urls());

		// auto (no HTTPS until the self-probe feeds the policy): the HTTPS URL goes, and is not kept.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'auto']));
		$this->assertSame(4, $this->ver());
		$this->assertSame(array_merge($rPlain, ['http://10.0.0.9:25461/cluster/v1/']), $this->urls());

		$this->assertSame($rEarlier, $this->kept(), 'the URLs kept before stay, untouched');
		$rAudit = $this->audit();
		$this->assertSame(['cluster.endpoint_change', 'cluster.endpoint_change', 'cluster.endpoint_change'], array_column($rAudit, 'event'));
		$this->assertSame([[], [], []], array_map(static fn(array $rRow): array => $rRow['detail']['kept_urls'], $rAudit));
		$this->assertSame([['cluster_transport' => 'https_required'], ['cluster_transport' => 'auto']], [$rAudit[2]['detail']['settings_from'], $rAudit[2]['detail']['settings_to']]);

		// http lists the same URLs as auto: the policy's transport changed, so
		// the version goes up as before; nothing else happens.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'http']));
		$this->assertSame(5, $this->ver());
		$this->assertCount(3, $this->audit());
		$this->assertSame($rEarlier, $this->kept());
	}

	/**
	 * A new name under https_required keeps the old name's https:// URL
	 * alone; a save that changes the transport and the name at once keeps
	 * the old name's URLs of the schemes the new transport lists.
	 */
	public function testATransportAndANameChangeKeepWhatTheNewTransportLists(): void {
		$this->store('cluster_transport', 'https_required');
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rUntil = $this->rNow + ClusterEndpoint::GRACE;

		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(['https://a.example.com:25463/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame(['https://b.example.com:25463/cluster/v1/', 'https://a.example.com:25463/cluster/v1/'], $this->urls());

		// Away from https_required (a node active: only the switch to it is guarded).
		ClusterClock::fix(($this->rNow + 1) * 1000);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'https_preferred', 'cluster_main_host' => 'c.example.com']));
		$this->assertSame(['https://b.example.com:25463/cluster/v1/' => $rUntil + 1, 'https://a.example.com:25463/cluster/v1/' => $rUntil], $this->kept());
		$this->assertSame([
			'https://c.example.com:25463/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://c.example.com:25461/cluster/v1/',
			'https://b.example.com:25463/cluster/v1/', 'https://a.example.com:25463/cluster/v1/',
		], $this->urls());

		// To plain HTTP with another name: the old name's http:// URL is kept,
		// its https:// one is not (http lists no HTTPS).
		ClusterClock::fix(($this->rNow + 2) * 1000);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_transport' => 'http', 'cluster_main_host' => 'd.example.com']));
		$this->assertSame(['http://c.example.com:25461/cluster/v1/', 'https://b.example.com:25463/cluster/v1/', 'https://a.example.com:25463/cluster/v1/'], array_keys($this->kept()));
		$this->assertSame(['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://d.example.com:25461/cluster/v1/', 'http://c.example.com:25461/cluster/v1/'], $this->urls());
		$this->assertSame(4, $this->ver());
	}

	/**
	 * Nothing is kept unless a node may use MAIN's URLs: the API on, a node in
	 * mode ≥ 1 and not revoked (one still enrolling counts). The version still
	 * goes up with a new name or transport, as before; a save that changes
	 * neither leaves it.
	 */
	public function testNothingIsKeptWithoutANodeThatMayUseTheUrls(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame([2, []], [$this->ver(), $this->kept()], 'no node');
		$this->node(2, 'revoked');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'c.example.com']));
		$this->assertSame([3, []], [$this->ver(), $this->kept()], 'a revoked node');
		$this->node(3, 'active', 0);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'd.example.com']));
		$this->assertSame([4, []], [$this->ver(), $this->kept()], 'a node in mode 0');
		$this->node(4);
		$this->store('cluster_api_enabled', 0);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'e.example.com']));
		$this->assertSame([5, []], [$this->ver(), $this->kept()], 'the API off');
		$this->assertSame([], $this->audit());

		// A save that turns the API off with the name: nothing is kept either.
		$this->store('cluster_api_enabled', 1);
		$this->assertTrue(ClusterEndpoint::storeSettings('`cluster_api_enabled` = ?,`cluster_main_host` = ?', [0, 'f.example.com'], ['cluster_api_enabled' => 0, 'cluster_main_host' => 'f.example.com'], $this->rMain));
		$this->assertSame([6, [], 'f.example.com'], [$this->ver(), $this->kept(), $this->settings()['cluster_main_host']]);

		// A node still enrolling dials the URLs of its cluster.json.
		$this->store('cluster_api_enabled', 1);
		$this->rDb->query('DELETE FROM `cluster_nodes`');
		$this->node(5, 'enrolling');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'g.example.com']));
		$this->assertSame([7, ['http://f.example.com:25461/cluster/v1/']], [$this->ver(), array_keys($this->kept())]);

		// A save that changes neither: nothing.
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'g.example.com', 'cluster_transport' => 'auto']));
		$this->assertSame(7, $this->ver());
		$this->assertCount(1, $this->audit());
	}

	/**
	 * The kept lists and the version are MAIN's own: the settings form cannot
	 * post them, even with a new name, and the backup settings form (which
	 * stores any settings column posted to it) can set no cluster setting,
	 * which only the settings form checks and announces.
	 */
	public function testTheFormsCannotWriteTheKeptLists(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rForged = '{"https://attacker.example:22/cluster/v1/":4102444800}';
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com', 'cluster_legacy_urls' => $rForged, 'cluster_legacy_ports' => '{"22":4102444800}', 'cluster_policy_ver' => '1']));
		$this->assertSame([2, ['http://a.example.com:25461/cluster/v1/'], ''], [$this->ver(), array_keys($this->kept()), (string) $this->settings()['cluster_legacy_ports']]);

		$rCluster = static fn(array $rRow): array => array_filter($rRow, static fn(string $rKey): bool => str_starts_with($rKey, 'cluster_'), ARRAY_FILTER_USE_KEY);
		$rBefore = $rCluster($this->settings());
		$this->assertSame(STATUS_SUCCESS, SettingsService::editBackup(['dropbox_remote' => '1', 'cluster_main_host' => 'evil.example.com', 'cluster_transport' => 'http', 'cluster_api_port' => '9999', 'cluster_api_enabled' => '0', 'cluster_offline_after_sec' => '300', 'cluster_legacy_urls' => $rForged, 'cluster_policy_ver' => '1'])['status']);
		$this->assertSame(1, (int) $this->settings()['dropbox_remote'], 'the backup settings are stored');
		$this->assertSame($rBefore, $rCluster($this->settings()), 'no cluster setting or state changed');
	}

	/**
	 * The early release (ClusterEndpoint::release()) goes for these kept URLs
	 * too: once every node dials the current policy, a kept URL goes when no
	 * node reaches MAIN on its port. One on a port a node uses stays (which
	 * name the node dialled is not known), until its 7 days are up.
	 */
	public function testTheEarlyReleaseStillWorksForTheKeptUrls(): void {
		$this->store('cluster_transport', 'https_preferred');
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2, 'active', 1, 1, 25463);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(['https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()));
		$this->assertFalse(ClusterEndpoint::release($this->settings()), 'the node has not fetched version 2');
		$this->node(2, 'active', 1, 0, 25463);
		$this->assertFalse(ClusterEndpoint::release($this->settings()), 'an agent that does not say which policy it dials');

		$this->node(2, 'active', 1, 2, 25463);
		$this->assertTrue(ClusterEndpoint::release($this->settings()));
		$this->assertSame(['https://a.example.com:25463/cluster/v1/'], array_keys($this->kept()), 'no node on 25461: the plain URL goes');
		$this->assertSame(3, $this->ver());
		$this->assertSame(['ports' => [25461], 'kept' => [], 'kept_urls' => ['https://a.example.com:25463/cluster/v1/'], 'policy_ver' => 2], $this->audit()[1]['detail']);

		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE + 1) * 1000);
		$this->assertTrue(ClusterEndpoint::prune($this->settings()));
		$this->assertSame([], $this->kept(), 'and the rest after 7 days');
	}

	/**
	 * A save that also moves cluster_api_port: both URL lists are compared on
	 * the new port, so the old name is kept on it; the old port is kept for
	 * the current names once the port is stored (recordApiPortChange()).
	 */
	public function testANameAndApiPortChangeAtOnce(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_api_port' => '31200', 'cluster_main_host' => 'b.example.com']));
		$this->assertSame(3, $this->ver(), 'the name with the save, the port after it');
		$this->assertSame(['http://a.example.com:31200/cluster/v1/'], array_keys($this->kept()));
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame([
			'http://192.168.0.1:31200/cluster/v1/', 'http://10.0.0.1:31200/cluster/v1/', 'http://b.example.com:31200/cluster/v1/',
			'http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://b.example.com:25461/cluster/v1/',
			'http://a.example.com:31200/cluster/v1/',
		], $this->urls());
	}

	/**
	 * The save goes over the endpoint state as read. cron:root_signals
	 * rewrites MAIN's server_ip (and keeps its old URL) between the save's
	 * read and its UPDATE: the save reads again, MAIN's row too, and keeps
	 * both.
	 */
	public function testAChangeStoredBeforeTheSaveIsNotLost(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rLog = new QueryLogDb($this->rDb);
		$rRan = false;
		$rLog->rBefore = function (string $rQuery) use (&$rRan, $rLog): void {
			if (!$rRan && str_starts_with($rQuery, 'UPDATE `settings` SET') && str_contains($rQuery, 'WHERE')) {
				$rRan = true;
				DatabaseFactory::set($this->rDb);
				RootSignalsCronJob::rewriteServerIP($this->rDb, 1, $this->rMain, '10.0.0.9');
				DatabaseFactory::set($rLog);
			}
		};
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertTrue($rRan);
		$this->assertSame(['http://a.example.com:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'], array_keys($this->kept()), 'both kept');
		$this->assertSame(3, $this->ver());
		$this->assertSame(['system', 'admin'], array_column($this->audit(), 'actor'));
		$this->assertSame(
			['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.9:25461/cluster/v1/', 'http://b.example.com:25461/cluster/v1/', 'http://a.example.com:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/'],
			ClusterPolicy::current($this->settings(), ['server_ip' => '10.0.0.9'] + $this->rMain)['main_urls']
		);
	}

	/**
	 * The other way round: the settings save lands between cron:root_signals'
	 * read and its write. The rewrite goes over the kept lists as read too,
	 * so it reads them again, with the name the save stored (its own
	 * settings were loaded before the save), and keeps both. Were the name
	 * taken from its settings, the old name's kept URL would look current and
	 * leave the list.
	 */
	public function testASaveStoredDuringAnAddressRewriteIsNotLost(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rLog = new QueryLogDb($this->rDb);
		$rRan = false;
		$rLog->rBefore = function (string $rQuery) use (&$rRan, $rLog): void {
			if (!$rRan && str_starts_with($rQuery, 'UPDATE `settings` SET `cluster_legacy_ports`')) {
				$rRan = true;
				DatabaseFactory::set($this->rDb);
				$rCron = SettingsManager::getAll();
				$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
				SettingsManager::set($rCron);
				DatabaseFactory::set($rLog);
			}
		};
		DatabaseFactory::set($rLog);
		RootSignalsCronJob::rewriteServerIP($rLog, 1, $this->rMain, '10.0.0.9');
		$this->assertTrue($rRan);
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()), 'both kept');
		$this->assertSame(3, $this->ver());
		$this->assertSame(['admin', 'system'], array_column($this->audit(), 'actor'));
		$this->assertSame(
			['http://192.168.0.1:25461/cluster/v1/', 'http://10.0.0.9:25461/cluster/v1/', 'http://b.example.com:25461/cluster/v1/', 'http://10.0.0.1:25461/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'],
			ClusterPolicy::current($this->settings(), ['server_ip' => '10.0.0.9'] + $this->rMain)['main_urls']
		);
	}

	/**
	 * A save that keeps losing the race to other writers is stored as before,
	 * with the version raised and nothing kept, rather than over what they
	 * stored.
	 */
	public function testASaveThatKeepsLosingTheRaceIsStoredAsBefore(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rLog = new QueryLogDb($this->rDb);
		$rRaces = 0;
		$rLog->rBefore = function (string $rQuery) use (&$rRaces): void {
			if (str_starts_with($rQuery, 'UPDATE `settings` SET') && str_contains($rQuery, 'WHERE')) {
				$rRaces++;
				$this->rDb->query('UPDATE `settings` SET `cluster_legacy_urls` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1', (string) json_encode(['http://10.0.0.' . $rRaces . '0:25461/cluster/v1/' => $this->rNow + 60], JSON_UNESCAPED_SLASHES));
			}
		};
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(3, $rRaces);
		$this->assertSame('b.example.com', $this->settings()['cluster_main_host']);
		$this->assertSame(5, $this->ver(), 'three other writers, and the save');
		$this->assertSame(['http://10.0.0.30:25461/cluster/v1/'], array_keys($this->kept()), 'what the last writer stored');
		$this->assertSame([], $this->audit());
	}

	/**
	 * The fail-safe paths: when the settings row cannot be read, or before
	 * migration 044 (no cluster_legacy_urls column), the save is stored as
	 * before, with the version raised. Before migration 044 a save that
	 * changes neither is stored without raising it.
	 */
	public function testWithoutTheStoredStateTheSaveIsStoredAsBefore(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^SELECT \* FROM `settings`/';
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame([2, 'b.example.com', []], [$this->ver(), $this->settings()['cluster_main_host'], $this->kept()]);

		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;
		$this->rDb->exec('ALTER TABLE `settings` DROP COLUMN `cluster_legacy_urls`');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'c.example.com']));
		$this->assertSame([3, 'c.example.com'], [$this->ver(), $this->settings()['cluster_main_host']]);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'c.example.com', 'cluster_transport' => 'auto', 'search_items' => '20']));
		$this->assertSame([3, 20], [$this->ver(), (int) $this->settings()['search_items']]);
	}

	/**
	 * The database from now on, with $rRace run once, as another process,
	 * just before the save's UPDATE over the endpoint state as read.
	 */
	private function raceBeforeTheUpdate(\Closure $rRace): QueryLogDb {
		$rLog = new QueryLogDb($this->rDb);
		$rRan = false;
		$rLog->rBefore = function (string $rQuery) use (&$rRan, $rLog, $rRace): void {
			if (!$rRan && str_starts_with($rQuery, 'UPDATE `settings` SET') && str_contains($rQuery, 'WHERE')) {
				$rRan = true;
				DatabaseFactory::set($this->rDb);
				$GLOBALS['db'] = $this->rDb;
				$rRace();
				DatabaseFactory::set($rLog);
				$GLOBALS['db'] = $rLog;
			}
		};
		DatabaseFactory::set($rLog);
		$GLOBALS['db'] = $rLog;
		return $rLog;
	}

	/**
	 * Another admin saves the same new name between this save's read and its
	 * UPDATE. The row then holds this save's name, yet its UPDATE matched no
	 * row (the version moved) and stored none of its columns: a save counts
	 * as stored only when its UPDATE changed the row. It reads again, finds
	 * the name stored, and stores its columns without raising the version
	 * again or a second audit event; with no node (nothing kept, the kept
	 * list as it was) and with one.
	 */
	public function testTheSameChangeStoredMeanwhileDoesNotSwallowTheSave(): void {
		$this->store('cluster_main_host', 'a.example.com');
		foreach ([false, true] as $rNode) {
			if ($rNode) {
				$this->node(2);
				$this->store('cluster_main_host', 'a.example.com');
			}
			$rVer = $this->ver();
			$this->raceBeforeTheUpdate(function (): void {
				$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com', 'search_items' => '30']));
			});
			$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com', 'search_items' => '20']));
			$this->assertSame(['b.example.com', 20, $rVer + 1], [$this->settings()['cluster_main_host'], (int) $this->settings()['search_items'], $this->ver()], 'the other save raised it');
			DatabaseFactory::set($this->rDb);
			$GLOBALS['db'] = $this->rDb;
		}
		$this->assertSame(['http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()));
		$this->assertCount(1, $this->audit(), "the other save's");
	}

	/**
	 * A transport change stored between the save's read and its UPDATE moves
	 * the version alone, not the kept lists. The version in the UPDATE's
	 * condition makes the save read again, so what it keeps follows the
	 * transport as stored: both of the old name's URLs under
	 * https_preferred, not the plain one alone. With no node, nothing is
	 * kept, and the name is stored all the same.
	 */
	public function testATransportChangeStoredMeanwhileIsNotOverwritten(): void {
		$rTransport = function (): void {
			$this->rDb->query("UPDATE `settings` SET `cluster_transport` = 'https_preferred', `cluster_policy_ver` = `cluster_policy_ver` + 1");
		};
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$this->raceBeforeTheUpdate($rTransport);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$this->assertSame(['https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()));
		$this->assertSame(['https_preferred', 'b.example.com', 3], [$this->settings()['cluster_transport'], $this->settings()['cluster_main_host'], $this->ver()]);

		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;
		$this->rDb->query('DELETE FROM `cluster_nodes`');
		$this->store('cluster_transport', 'auto');
		$rKept = $this->kept();
		$this->raceBeforeTheUpdate($rTransport);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'c.example.com']));
		$this->assertSame(['https_preferred', 'c.example.com', 5], [$this->settings()['cluster_transport'], $this->settings()['cluster_main_host'], $this->ver()]);
		$this->assertSame($rKept, $this->kept());
	}

	/**
	 * The settings form posts the name and the transport with every save,
	 * and the request's settings cache may predate another admin's save. A
	 * save is compared with the database: posting the stored name (the cache
	 * still has none) stores the other columns without raising the version,
	 * keeping anything or an audit event; posting the name a stale cache
	 * holds moves the stored name back, announced.
	 */
	public function testASaveIsComparedWithTheStoredSettingsNotTheCache(): void {
		$this->node(2);
		$this->rDb->query("UPDATE `settings` SET `cluster_main_host` = 'b.example.com', `cluster_policy_ver` = 2");
		$this->assertSame('', (string) SettingsManager::getAll()['cluster_main_host'], 'the cache predates it');
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com', 'search_items' => '20']));
		$this->assertSame([2, 20, [], []], [$this->ver(), (int) $this->settings()['search_items'], $this->kept(), $this->audit()]);

		SettingsManager::set(['cluster_main_host' => 'a.example.com'] + $this->settings());
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'a.example.com']));
		$this->assertSame([3, 'a.example.com', ['http://b.example.com:25461/cluster/v1/']], [$this->ver(), $this->settings()['cluster_main_host'], array_keys($this->kept())]);
		$rAudit = $this->audit();
		$this->assertSame([['cluster_main_host' => 'b.example.com'], ['cluster_main_host' => 'a.example.com']], [$rAudit[0]['detail']['settings_from'], $rAudit[0]['detail']['settings_to']]);
	}

	/** Each new name under https_preferred keeps two URLs: the kept list stays at MAX_URLS, the latest first. */
	public function testAtMostMaxUrlsAreKept(): void {
		$this->store('cluster_transport', 'https_preferred');
		$this->store('cluster_main_host', 'n0.example.com');
		$this->node(2);
		for ($i = 1; $i <= 6; $i++) {
			ClusterClock::fix(($this->rNow + $i) * 1000);
			$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'n' . $i . '.example.com']));
		}
		$rKept = array_keys($this->kept());
		$this->assertCount(ClusterEndpoint::MAX_URLS, $rKept);
		$this->assertSame(['https://n5.example.com:25463/cluster/v1/', 'http://n5.example.com:25461/cluster/v1/'], array_slice($rKept, 0, 2), 'the latest first');
		$this->assertNotContains('http://n1.example.com:25461/cluster/v1/', $rKept, 'the oldest went');
	}

	/**
	 * cluster:endpoint lists what is kept, and drops a kept URL, or every one
	 * of a host, at once: for an old name MAIN gives up. The policy version
	 * goes up and it is audited. The command is MAIN's alone.
	 */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testTheAdminDropsAKeptNameAtOnce(): void {
		$rRun = static function (array $rArgs): array {
			ob_start();
			$rCode = (new ClusterEndpointCommand())->execute($rArgs);
			return [$rCode, (string) ob_get_clean()];
		};
		$this->store('cluster_transport', 'https_preferred');
		$this->store('cluster_main_host', 'a.example.com');
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $this->rNow + 60]));
		$this->node(2);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$rOld = $this->rMain;
		$this->main(['server_ip' => '10.0.0.2']);
		$this->assertTrue(ClusterEndpoint::recordMainChange($rOld, $this->rMain, $this->settings()));
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/', 'https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'], array_keys($this->kept()));
		$this->assertSame(3, $this->ver());

		$rUntil = gmdate('Y-m-d H:i:s', $this->rNow + ClusterEndpoint::GRACE) . ' UTC';
		$this->assertSame([0, "Old plain-HTTP ports, served for the cluster API on MAIN's current addresses:\n  8080 until " . gmdate('Y-m-d H:i:s', $this->rNow + 60) . " UTC\nOld URLs, listed last in the policy (latest change first):\n  http://10.0.0.1:25461/cluster/v1/ until " . $rUntil . "\n  https://a.example.com:25463/cluster/v1/ until " . $rUntil . "\n  http://a.example.com:25461/cluster/v1/ until " . $rUntil . "\n"], $rRun([]));

		$this->assertSame([0, "Dropped; the nodes refetch the policy at their next heartbeat:\n  https://a.example.com:25463/cluster/v1/\n  http://a.example.com:25461/cluster/v1/\n"], $rRun(['drop', 'A.example.com']));
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], array_keys($this->kept()));
		$this->assertSame([8080], array_keys(ClusterEndpoint::legacyPorts($this->settings())), 'the kept port stays');
		$this->assertSame(4, $this->ver(), 'announced');
		$rAudit = $this->audit();
		$this->assertSame(['cluster.endpoint_dropped', 'admin', ['urls' => ['https://a.example.com:25463/cluster/v1/', 'http://a.example.com:25461/cluster/v1/'], 'kept_urls' => ['http://10.0.0.1:25461/cluster/v1/']]], [$rAudit[2]['event'], $rAudit[2]['actor'], $rAudit[2]['detail']]);

		// One URL, as listed; nothing that matches; a usage error.
		$this->assertSame([0, "Dropped; the nodes refetch the policy at their next heartbeat:\n  http://10.0.0.1:25461/cluster/v1/\n"], $rRun(['drop', 'http://10.0.0.1:25461/cluster/v1/']));
		$this->assertSame([[], 5], [$this->kept(), $this->ver()]);
		$this->assertSame([1, "No kept URL is a.example.com or on that host.\n"], $rRun(['drop', 'a.example.com']));
		$this->assertSame([1, "Usage: cluster:endpoint [list | drop <url|host>]\n"], $rRun(['drop']));
		$this->assertSame(5, $this->ver(), 'nothing announced');

		$rRoot = dirname(__DIR__, 2);
		$this->assertMatchesRegularExpression('#^\tCli/Commands/ClusterEndpointCommand\.php \\\\$#m', (string) file_get_contents($rRoot . '/Makefile'), 'stripped from the LB build');
		$this->assertStringContainsString('"Cli/Commands/ClusterEndpointCommand.php"', (string) file_get_contents($rRoot . '/tools/ci/verify-lb-archive.sh'), 'on the LEAK list');
	}

	/** A drop that keeps losing the race to other writers writes nothing over what they stored. */
	public function testADropOverAChangeStoredMeanwhileIsRetried(): void {
		$this->store('cluster_main_host', 'a.example.com');
		$this->node(2);
		$this->assertSame(STATUS_SUCCESS, $this->save(['cluster_main_host' => 'b.example.com']));
		$rLog = new QueryLogDb($this->rDb);
		$rRaces = 0;
		$rLog->rBefore = function (string $rQuery) use (&$rRaces): void {
			if (str_starts_with($rQuery, 'UPDATE `settings` SET `cluster_legacy_ports`') && $rRaces < 3) {
				$rRaces++;
				$this->rDb->query('UPDATE `settings` SET `cluster_legacy_urls` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1', (string) json_encode(['http://a.example.com:25461/cluster/v1/' => $this->rNow + 60, 'http://10.0.0.' . $rRaces . '0:25461/cluster/v1/' => $this->rNow + 60], JSON_UNESCAPED_SLASHES));
			}
		};
		DatabaseFactory::set($rLog);
		$this->assertNull(ClusterEndpoint::drop('a.example.com', $this->settings()));
		$this->assertSame(3, $rRaces);
		$this->assertSame(['http://a.example.com:25461/cluster/v1/', 'http://10.0.0.30:25461/cluster/v1/'], array_keys($this->kept()), 'what the last writer stored');
		$this->assertSame(5, $this->ver());

		$rRaces = 3;
		$this->assertSame(['http://a.example.com:25461/cluster/v1/'], ClusterEndpoint::drop('a.example.com', $this->settings()));
		$this->assertSame([['http://10.0.0.30:25461/cluster/v1/'], 6], [array_keys($this->kept()), $this->ver()]);
	}

	/**
	 * SettingsService stores every save that posts the name or the transport
	 * through ClusterEndpoint, which compares them with the database, on MAIN
	 * only.
	 */
	public function testTheCallSite(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Domain/Server/SettingsService.php');
		$this->assertSame(1, substr_count($rSrc, 'ClusterEndpoint::storeSettings($rPrepare[\'update\'], $rPrepare[\'data\'], $rArray, self::mainServer())'));
		$this->assertStringContainsString('if (array_intersect_key($rArray, array_flip(self::CLUSTER_POLICY)) !== [] && class_exists(ClusterEndpoint::class)) {', $rSrc, 'Domain\Cluster is not in the LB build');
		$this->assertStringContainsString("private const CLUSTER_POLICY = ['cluster_transport', 'cluster_main_host'];", $rSrc);
	}
}

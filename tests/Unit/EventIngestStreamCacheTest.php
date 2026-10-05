<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\MainDataPlane;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A node's stream event refreshes that stream's cache on MAIN.
 *
 * The cluster API's bootstrap loads no server list, and the cache job found
 * MAIN in that list (StreamProcess::updateStream): it was queued for server 0,
 * whose signals nobody reads. MAIN's cache went on saying that a stream a node
 * had just started was not running, and its viewers got the off-air clip,
 * until the next full rebuild (five minutes).
 */
final class EventIngestStreamCacheTest extends TestCase {
	private const SID = 5;
	private const MAIN = 3;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int, `movie_properties` text, `tv_archive_server_id` int, `tv_archive_pid` int, `vframes_server_id` int, `vframes_pid` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `parent_id` int, `pid` int, `to_analyze` int, `stream_status` int, `progress_info` text, `bitrate` int)');
		$this->rDb->exec('CREATE TABLE `signals` (`signal_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `time` int, `custom_data` text, `cache` tinyint DEFAULT 0)');
		$this->rDb->exec(InstallSchema::serversTable());
		$this->rDb->query('INSERT INTO `servers` (`id`, `server_name`, `is_main`) VALUES (?, ?, 1), (?, ?, 0)', self::MAIN, 'main', self::SID, 'node');
		$this->rDb->query('INSERT INTO `streams` (`id`, `type`) VALUES (100, 1)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (11, 100, ?, 0)', self::SID);
		DatabaseFactory::set($this->rDb);
		foreach ([EventIngest::class, MainDataPlane::class] as $rClass) {
			(new \ReflectionProperty($rClass, 'db'))->setValue(null, null);
		}
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(self::SID, '77777777-7777-4777-a777-777777777777', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(self::SID, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS]);
		SettingsManager::set(['enable_cache' => 1]);
		unset($GLOBALS['rServers']); // as the cluster API runs: no server list
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	public function testTheCacheJobIsQueuedForMain(): void {
		$rOut = EventIngest::ingest(NodeRegistry::byServer(self::SID), 'p0', 1, [
			['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['pid' => 42]]],
		]);
		$this->assertSame(1, $rOut['applied'] ?? null, (string) json_encode($rOut));
		$this->assertSame(
			[['server_id' => self::MAIN, 'cache' => 1, 'custom_data' => '{"type":"update_stream","id":100}']],
			array_map(static fn(array $rRow): array => ['server_id' => (int) $rRow['server_id'], 'cache' => (int) $rRow['cache'], 'custom_data' => $rRow['custom_data']], $this->rDb->pdo->query('SELECT `server_id`, `cache`, `custom_data` FROM `signals`')->fetchAll(PDO::FETCH_ASSOC))
		);
	}
}

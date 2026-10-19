<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ChannelService;
use XcVm\Domain\Stream\RadioService;
use XcVm\Domain\Stream\StreamService;
use XcVm\Infrastructure\Database\DatabaseFactory;

/** Real save services, dispatcher and database; only remote transport is replaced. */
final class StreamEditRestartTest extends TestCase {
	private TestDb $rDb;

	private mixed $rServers;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		foreach (['streams', 'streams_servers', 'bouquets'] as $rTable) {
			preg_match('/CREATE TABLE IF NOT EXISTS `' . $rTable . '` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rMatch);
			$this->rDb->exec($rMatch[0]);
		}
		$this->rDb->query("INSERT INTO streams (id,stream_display_name,category_id) VALUES (10,'Before','[]')");
		$this->rDb->query('INSERT INTO streams_servers (stream_id,server_id) VALUES (10,2)');
		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;
		$GLOBALS['rSettings'] = ['enable_cache' => 0];
		SettingsManager::set(['enable_cache' => 0]);
		$this->rServers = FileCache::getCache('servers');
		FileCache::setCache('servers', [1 => ['server_online' => true], 2 => ['server_online' => true]]);
	}

	protected function tearDown(): void {
		NodeRpc::useTransport(null);
		FileCache::setCache('servers', $this->rServers ?: []);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		unset($GLOBALS['db'], $GLOBALS['rSettings']);
	}

	public static function services(): array {
		return [[StreamService::class], [RadioService::class], [ChannelService::class]];
	}

	#[DataProvider('services')]
	public function testSaveQueuesRestartAfterWritingChangesAndNeverWaitsForRemoteAck(string $rService): void {
		$rCalls = [];
		$rField = $rService === ChannelService::class ? 'allow_record' : 'custom_sid';
		$rValue = $rField === 'allow_record' ? 1 : 123;
		NodeRpc::useTransport(function (string $rMethod, array $rServers, array $rPayload, int $rTimeout) use (&$rCalls, $rField, $rValue): array {
			$this->assertSame('broadcast', $rMethod, 'saving must not wait for a remote restart acknowledgement');
			$this->rDb->query('SELECT `' . $rField . '` FROM streams WHERE id=10');
			$this->assertSame($rValue, (int) $this->rDb->get_row()[$rField], 'save must finish before restart is submitted');
			$rCalls[] = [$rServers, $rPayload, $rTimeout];
			return ['result' => true];
		});
		$rResult = $rService::massEdit(['streams' => '[10]', 'c_' . $rField => 1, $rField => $rValue, 'restart_on_edit' => 1]);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame([[[2], ['action' => 'stream', 'function' => 'start', 'stream_ids' => [10]], 0]], $rCalls);
	}
}

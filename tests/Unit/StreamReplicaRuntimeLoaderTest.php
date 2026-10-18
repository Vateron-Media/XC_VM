<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * StreamReplica::runtimeLoader — the batched StreamSource snapshot that lets
 * buildSupervisorSpec compose a portable Spec for a serve-only target with no
 * per-stream query (Phase B1). It answers 'stream' / 'server' / 'arguments'
 * from one batched read each, mirroring StreamSource's own SQL (the stream
 * join is live-only, like streamRow(live: true)).
 *
 * @covers \XcVm\Domain\Cluster\StreamReplica
 */
final class StreamReplicaRuntimeLoaderTest extends TestCase {

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'streams_types', 'profiles', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `streams_types` VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0)");
		$this->rDb->exec("INSERT INTO `streams_arguments` VALUES (1, 'fetch', 'User Agent', 'shown in the form', 'http', 'user_agent', '-user_agent \"%s\"', 'text', 'VLC')");
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `notes`, `transcode_profile_id`, `enable_transcode`, `tv_archive_server_id`, `tv_archive_duration`, `vframes_server_id`, `tv_archive_pid`, `order`) VALUES
			(7, 1, 'News', '[\"http://src.example/a\"]', NULL, 0, 0, 0, 0, 0, 0, 1),
			(8, 2, 'Film', '[\"http://src.example/f\"]', NULL, 0, 0, 0, 0, 0, 0, 2)");
		$this->rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`, `pid`) VALUES (1, 7, 5, NULL, 1, 0)");
		$this->rDb->exec("INSERT INTO `streams_options` (`id`, `stream_id`, `argument_id`, `value`) VALUES (1, 7, 1, 'curl/8')");
		DatabaseFactory::set($this->rDb);
	}

	public function testRuntimeLoaderAnswersStreamServerArgumentsFromOneBatch(): void {
		$rLoader = StreamReplica::runtimeLoader(5, [7]);
		$this->assertSame(7, (int) $rLoader('stream', 7, ['live' => true])['id']);
		$this->assertSame(1, (int) $rLoader('server', 7, ['server_id' => 5])['on_demand']);
		$this->assertSame('user_agent', $rLoader('arguments', 7, ['keyed' => false])[0]['argument_key']);
		$this->assertArrayHasKey('user_agent', $rLoader('arguments', 7, ['keyed' => true]));
		$this->assertNull($rLoader('stream', 999, ['live' => true]), 'an id not in the batch is null');
		$this->assertSame([], $rLoader('source', 7, []), 'source is not answered from the snapshot');
	}

	public function testRuntimeLoaderExcludesNonLiveStreams(): void {
		// id 8 is a movie (its streams_types row has live = 0): absent from the
		// live join, so the loader returns null — a non-live stream is not transcoded.
		$this->assertNull(StreamReplica::runtimeLoader(5, [8])('stream', 8, ['live' => true]));
	}

	public function testRuntimeLoaderOnEmptySetReadsNothing(): void {
		$rLoader = StreamReplica::runtimeLoader(5, []);
		$this->assertNull($rLoader('stream', 7, ['live' => true]));
	}
}

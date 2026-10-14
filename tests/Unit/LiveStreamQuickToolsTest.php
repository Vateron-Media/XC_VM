<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\LiveStreamQuickTools;
use XcVm\Tests\Support\InstallSchema;

final class LiveStreamQuickToolsTest extends TestCase {
	public static function speeds(): array {
		return [
			[0.79, true], ['0.79x', true], ['x0.79', true], [0, true],
			[0.8, false], ['1x', false], [5, false], ['5.01x', true],
			['6e1x', true], ['1e-1x', true], ['1e999x', false], ['N/A', false], ['NaN', false], ['bad 0.7x', false], [null, false], [[], false],
		];
	}

	#[DataProvider('speeds')]
	public function testOnlyKnownSpeedsOutsideStrictBoundsMatch(mixed $speed, bool $expected): void {
		$this->assertSame($expected, LiveStreamQuickTools::matches(['progress_info' => json_encode(['speed' => $speed])], LiveStreamQuickTools::PROGRESS));
	}

	public function testTargetsDoNotRestartHealthyReplicasOrDuplicatePairs(): void {
		$rows = [
			['server_id' => 1, 'stream_id' => 10, 'audio_codec' => '', 'video_codec' => 'h264'],
			['server_id' => 2, 'stream_id' => 10, 'audio_codec' => 'aac', 'video_codec' => 'h264'],
			['server_id' => 2, 'stream_id' => 20, 'audio_codec' => 'aac', 'video_codec' => null],
		];
		$this->assertSame([1 => [10], 2 => [20]], LiveStreamQuickTools::targets(array_merge($rows, [$rows[0]]), LiveStreamQuickTools::CODECS));
	}

	public function testDatabaseSelectionAndRequestsStayLimitedToRunningLiveInstances(): void {
		$db = new TestDb();
		foreach (['streams', 'streams_servers'] as $table) {
			$db->exec(InstallSchema::table($table));
		}
		$db->exec("INSERT INTO streams (id, type, direct_source) VALUES (1, 1, 0), (2, 2, 0), (3, 4, 0), (4, 1, 1), (5, 1, 0), (6, 1, 0), (7, 1, 0), (8, 1, 0)");
		foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $id) {
			$db->query('INSERT INTO streams_servers (stream_id, server_id, pid, monitor_pid, stream_status, to_analyze, progress_info, audio_codec, video_codec) VALUES (?, 1, ?, 100, 0, ?, ?, ?, ?)', $id, $id === 5 ? null : 100, $id === 6 ? 1 : 0, json_encode(['speed' => $id === 7 ? '1x' : '0.5x']), 'aac', $id === 8 ? '' : 'h264');
		}
		$db->query('INSERT INTO streams_servers (stream_id, server_id, pid, monitor_pid, stream_status, to_analyze, progress_info, audio_codec, video_codec) VALUES (1, 2, 100, 100, 0, 0, ?, ?, ?)', '{"speed":"1x"}', 'aac', 'h264');
		// Daemon-supervised encoders run without a PHP monitor pid.
		$db->query('INSERT INTO streams_servers (stream_id, server_id, pid, monitor_pid, stream_status, to_analyze, progress_info, audio_codec, video_codec) VALUES (1, 3, 100, NULL, 0, 0, ?, ?, ?)', '{"speed":"6x"}', 'aac', 'h264');
		$calls = [];
		$result = LiveStreamQuickTools::restart($db, LiveStreamQuickTools::PROGRESS, static function ($payload) use (&$calls) { $calls[] = $payload; return '{"result":true}'; });
		$this->assertSame(['result' => true, 'matched' => 3, 'submitted' => 3], $result);
		$this->assertSame([1, 8], $calls[0]['stream_ids']);
		$this->assertSame([1], $calls[0]['servers']);
		$this->assertSame([3], $calls[1]['servers']);
		$this->assertSame([1], $calls[1]['stream_ids']);
		$calls = [];
		$result = LiveStreamQuickTools::restart($db, LiveStreamQuickTools::CODECS, static function ($payload) use (&$calls) { $calls[] = $payload; return false; });
		$this->assertFalse($result['result']);
		$this->assertSame(0, $result['submitted']);
		$this->assertSame([8], $calls[0]['stream_ids']);
	}
}

<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\Redactor;
use XcVm\Tests\Support\InstallSchema;

/**
 * Node log records reach MAIN through LogSink. The SQL backend writes the rows
 * the crons used to INSERT by hand, with bound values instead of hand-escaped
 * SQL. The redactor strips credentials before the future API backend journals
 * a record.
 */
final class LogSinkTest extends TestCase {

	private function recorder(bool $rResult = true): object {
		return new class($rResult) {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function __construct(private bool $rResult) {
			}
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return $this->rResult;
			}
		};
	}

	protected function tearDown(): void {
		LogSink::useSink(null);
	}

	public function testStreamLogsAreOneMultiRowInsert(): void {
		$rDb = $this->recorder();
		$this->assertTrue(LogSink::write('stream', [
			['stream_id' => 5, 'server_id' => 2, 'action' => 'STREAM_START', 'source' => "it's", 'date' => '100'],
			['stream_id' => 6, 'server_id' => 2, 'action' => 'STREAM_STOP', 'source' => '', 'date' => '101'],
		], $rDb));
		$this->assertSame([[
			'INSERT INTO `streams_logs` (`stream_id`,`server_id`,`action`,`source`,`date`) VALUES (?,?,?,?,?),(?,?,?,?,?);',
			[5, 2, 'STREAM_START', "it's", '100', 6, 2, 'STREAM_STOP', '', '101'],
		]], $rDb->rQueries);
	}

	public function testPanelErrorsUseInsertIgnoreAndMissingColumnsAreNull(): void {
		$rDb = $this->recorder();
		LogSink::write('panel_error', [['server_id' => 1, 'type' => 'php', 'log_message' => 'x', 'unique' => 'h']], $rDb);
		[$rSql, $rParams] = $rDb->rQueries[0];
		$this->assertStringStartsWith('INSERT IGNORE INTO `panel_logs` (`server_id`,`type`,`log_message`,`log_extra`,`line`,`date`,`file`,`env`,`version`,`unique`)', $rSql);
		$this->assertSame([1, 'php', 'x', null, null, null, null, null, null, 'h'], $rParams);
	}

	public function testLargeBatchesAreChunkedAndFailureIsReported(): void {
		$rRows = array_fill(0, LogSink::CHUNK + 1, ['user_id' => 1, 'stream_id' => 2, 'ip' => '1.1.1.1', 'time' => 3]);
		$rDb = $this->recorder();
		$this->assertTrue(LogSink::write('restream', $rRows, $rDb));
		$this->assertCount(2, $rDb->rQueries);
		$this->assertCount(4, $rDb->rQueries[1][1]);

		$this->assertFalse(LogSink::write('restream', $rRows, $this->recorder(false)));
	}

	public function testEmptyAndUnknown(): void {
		$rDb = $this->recorder();
		$this->assertTrue(LogSink::write('client', [], $rDb));
		$this->assertSame([], $rDb->rQueries);
		$this->expectException(InvalidArgumentException::class);
		LogSink::write('nope', [['a' => 1]], $rDb);
	}

	public function testSinkReceivesTypeAndRows(): void {
		$rSeen = [];
		LogSink::useSink(function (string $rType, array $rRows) use (&$rSeen): bool {
			$rSeen[] = [$rType, $rRows];
			return true;
		});
		LogSink::write('client', [['stream_id' => 1]]);
		$this->assertSame([['client', [['stream_id' => 1]]]], $rSeen);
	}

	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testEveryLogWriterGoesThroughTheSink(): void {
		$rRoot = MAIN_HOME;
		foreach (['streams_logs', 'lines_logs', 'streams_errors', 'panel_logs', 'detect_restream_logs'] as $rTable) {
			foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rRoot, FilesystemIterator::SKIP_DOTS)) as $rFile) {
				if ($rFile->getExtension() !== 'php' || str_contains($rFile->getPathname(), '/vendor/') || str_ends_with($rFile->getPathname(), 'Cluster/LogSink.php')) {
					continue;
				}
				$this->assertDoesNotMatchRegularExpression('/INSERT\s+(IGNORE\s+)?INTO\s+`?' . $rTable . '`?/i', (string) file_get_contents($rFile->getPathname()), $rFile->getPathname());
			}
		}
	}

	public function testActivityRowsAlsoPointEachLineAtItsNewestRow(): void {
		$rDb = new class() {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return true;
			}
			public function last_insert_id(): int {
				return 100;
			}
		};
		$this->assertTrue(LogSink::write('activity', [
			['server_id' => 1, 'user_id' => 7, 'stream_id' => 3, 'user_ip' => '192.0.2.7', 'date_end' => 50],
			['server_id' => 1, 'user_id' => 0, 'stream_id' => 4, 'user_ip' => '192.0.2.8'],
		], $rDb));

		$this->assertStringStartsWith('INSERT INTO `lines_activity` (`server_id`,`proxy_id`,`user_id`,', $rDb->rQueries[0][0]);
		// Only the line that exists is pointed at its row, and at the right id.
		$this->assertStringContainsString('`last_activity` = CASE `id` WHEN 7 THEN 100 END', $rDb->rQueries[1][0]);
		$this->assertStringEndsWith('WHERE `id` IN (7);', $rDb->rQueries[1][0]);
		$this->assertSame(['192.0.2.7', '{"date_end":50,"stream_id":3}'], $rDb->rQueries[1][1]);
	}

	public function testEveryTypeWritesColumnsItsTableHas(): void {
		foreach (LogSink::TYPES as $rType => [$rTable, $rColumns]) {
			$rHas = InstallSchema::columns($rTable);
			$this->assertNotSame([], $rHas, $rTable . ' is not in the install schema');
			$this->assertSame([], array_diff($rColumns, $rHas), $rType . ' writes columns `' . $rTable . '` has not');
		}
	}

	public function testAnOnDemandCheckIsALogRecord(): void {
		$rDb = $this->recorder();
		// The row the scanner reports, in the plan's log.ondemand_check shape.
		$this->assertTrue(LogSink::write('ondemand_check', [[
			'stream_id' => 9, 'server_id' => 2, 'status' => 1, 'source_id' => 0, 'source_url' => 'http://h/live/u/p/1.ts',
			'fps' => 25, 'video_codec' => 'h264', 'audio_codec' => 'aac', 'resolution' => 1080, 'response' => 120,
			'errors' => null, 'date' => 1700000000,
		]], $rDb));

		[$rSql, $rParams] = $rDb->rQueries[0];
		$this->assertStringStartsWith('INSERT INTO `ondemand_check` (`stream_id`,`server_id`,`status`,`source_id`,`source_url`,', $rSql);
		// The SQL backend keeps what it always wrote; the API backend redacts.
		$this->assertSame('http://h/live/u/p/1.ts', $rParams[4]);
		$this->assertCount(1, $rDb->rQueries, 'no pointer update here: the scanner sets it from the insert id');
	}

	public function testRedactorStripsCredentials(): void {
		$this->assertSame('http://h/get.php?username=***&password=***&type=m3u', Redactor::redact('http://h/get.php?username=bob&password=s3cret&type=m3u'));
		$this->assertSame('http://h:80/live/***/***/12.ts', Redactor::redact('http://h:80/live/bob/s3cret/12.ts'));
		$this->assertSame('ffmpeg -i rtmp://***@src/app/key -f mpegts', Redactor::redact('ffmpeg -i rtmp://bob:s3cret@src/app/key -f mpegts'));
		$this->assertSame('/api?token=***', Redactor::redact('/api?token=abcDEF123'));
		$this->assertSame('no credentials here', Redactor::redact('no credentials here'));
		$rOnce = Redactor::redact('http://u:p@h/live/a/b/1.ts?password=x');
		$this->assertSame($rOnce, Redactor::redact($rOnce), 'idempotent');
		$this->assertSame(['id' => 5, 'source' => 'http://h/movie/***/***/9.mkv'], Redactor::redactRow(['id' => 5, 'source' => 'http://h/movie/u/p/9.mkv']));
	}
}

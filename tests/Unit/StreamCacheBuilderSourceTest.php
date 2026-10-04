<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\StreamCacheBuilder;
use XcVm\Domain\Stream\StreamSource;

/**
 * The per-stream cache entry and the stream definition a node runs are each
 * built in one place (StreamCacheBuilder, StreamSource), so the cluster API
 * can feed them from MAIN's stream delta instead of the database.
 */
final class StreamCacheBuilderSourceTest extends TestCase {

	protected function tearDown(): void {
		StreamSource::useLoader(null);
	}

	private function recorder(array $rRows): object {
		return new class($rRows) {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];
			public function __construct(private array $rRows) {
			}
			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				return true;
			}
			public function num_rows(): int {
				return count($this->rRows);
			}
			public function get_row(): array {
				return $this->rRows[0];
			}
			public function get_rows(bool $rKeyed = false, string $rKey = ''): array {
				return $rKeyed ? array_column($this->rRows, null, $rKey) : $this->rRows;
			}
		};
	}

	public function testEntryHidesTheSourceUnlessDirect(): void {
		$rServers = [2 => ['stream_id' => 9, 'server_id' => 2, 'pid' => 5]];
		$rEntry = StreamCacheBuilder::entry(['id' => 9, 'direct_source' => 0, 'stream_source' => '["http://u:p@src"]'], [3, 4], $rServers);
		$this->assertSame(['info' => ['id' => 9, 'direct_source' => 0], 'bouquets' => [3, 4], 'servers' => $rServers], $rEntry);

		$rDirect = StreamCacheBuilder::entry(['id' => 9, 'direct_source' => 1, 'stream_source' => '["http://src"]'], [], []);
		$this->assertSame('["http://src"]', $rDirect['info']['stream_source']);
	}

	public function testServerColumnsAreTheRuntimeViewOnce(): void {
		$this->assertSame(array_values(array_unique(StreamCacheBuilder::SERVER_COLUMNS)), StreamCacheBuilder::SERVER_COLUMNS);
		$this->assertContains('parent_id', StreamCacheBuilder::SERVER_COLUMNS);
		$this->assertNotContains('cc_info', StreamCacheBuilder::SERVER_COLUMNS);
	}

	public function testStreamSourceQueries(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$rDb = $this->recorder([['id' => 4, 'argument_key' => 'user_agent', 'value' => 'UA']]);
		$this->assertSame(['id' => 4, 'argument_key' => 'user_agent', 'value' => 'UA'], StreamSource::streamRow(4, true, $rDb));
		$this->assertStringContainsString('AND t2.live = 1 ', $rDb->rQueries[0][0]);
		StreamSource::streamRow(4, false, $rDb);
		$this->assertStringContainsString('AND t2.live = 0 ', $rDb->rQueries[1][0]);
		StreamSource::serverRow(4, 7, $rDb);
		$this->assertSame([4, 7], $rDb->rQueries[2][1]);
		$this->assertSame(['user_agent' => ['id' => 4, 'argument_key' => 'user_agent', 'value' => 'UA']], StreamSource::arguments(4, true, $rDb));

		$rEmpty = $this->recorder([]);
		$this->assertNull(StreamSource::streamRow(4, true, $rEmpty));
		$this->assertNull(StreamSource::serverRow(4, 7, $rEmpty));
		$this->assertSame([], StreamSource::sourceRow(4, $rEmpty));
	}

	public function testLoaderReplacesTheDatabase(): void {
		$rCalls = [];
		StreamSource::useLoader(function (string $rWhat, int $rID, array $rOpts) use (&$rCalls) {
			$rCalls[] = [$rWhat, $rID, $rOpts];
			return $rWhat === 'arguments' ? [] : ['id' => $rID];
		});
		$this->assertSame(['id' => 3], StreamSource::streamRow(3, true));
		$this->assertSame(['id' => 3], StreamSource::serverRow(3, 2));
		$this->assertSame([], StreamSource::arguments(3, true));
		$this->assertSame([['stream', 3, ['live' => true]], ['server', 3, ['server_id' => 2]], ['arguments', 3, ['keyed' => true]]], $rCalls);
	}

	public function testNoNodeSideCodeQueriesStreamOptionsDirectly(): void {
		$rRoot = MAIN_HOME;
		foreach (['Domain/Stream/StreamProcess.php', 'Public/stream/live.php', 'Cli/Commands/ProxyCommand.php', 'Cli/Commands/MonitorCommand.php', 'Cli/Commands/ScannerCommand.php'] as $rFile) {
			$this->assertStringNotContainsString('`streams_options` t1', (string) file_get_contents($rRoot . $rFile), $rFile);
		}
	}
}

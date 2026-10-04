<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\RadioService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use PHPUnit\Framework\TestCase;

if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_INVALID_INPUT')) {
	define('STATUS_INVALID_INPUT', 34);
}

/**
 * RadioService::massEdit() — method-level characterization, to guard the
 * server_tree extraction / flattening that follows.
 *
 * massEdit has no verifyPostTable/exit(), and StreamProcess::updateStreams is a
 * no-op while enable_cache is off, so the whole method runs against the
 * TestDb. These lock the outer flow (invalid input) and the server-tree ADD path
 * (a new streams_servers row via the batch insert).
 */
final class RadioServiceMassEditTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id TEXT);
			 CREATE TABLE streams_servers (server_stream_id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_id INTEGER, server_id INTEGER, parent_id INTEGER, on_demand INTEGER);
			 CREATE TABLE bouquets (id INTEGER PRIMARY KEY AUTO_INCREMENT, bouquet_name TEXT, bouquet_channels TEXT, bouquet_movies TEXT, bouquet_series TEXT, bouquet_radios TEXT, bouquet_order INTEGER DEFAULT 0);'
		);
		DatabaseFactory::set($this->db);
		// BouquetService may carry an injected db from another test; override it.
		BouquetService::setDb($this->db);
		SettingsManager::set([]); // enable_cache off → StreamProcess::updateStreams is a no-op
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
	}

	public function testInvalidInputWhenStreamsNotAnArray(): void {
		$rResult = RadioService::massEdit(['streams' => 'not-json']);
		$this->assertSame(STATUS_INVALID_INPUT, $rResult['status']);
	}

	public function testServerTreeAddInsertsNewAttachment(): void {
		$rResult = RadioService::massEdit([
			'streams'          => json_encode([100]),
			'c_server_tree'    => '1',
			'server_type'      => 'ADD',
			'server_tree_data' => json_encode([
				['id' => '0', 'parent' => '#'],      // root — skipped
				['id' => '2', 'parent' => 'source'], // attach, parent NULL
			]),
			'on_demand'        => [],
		]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);

		$this->db->query('SELECT `stream_id`, `server_id`, `parent_id`, `on_demand` FROM `streams_servers`;');
		$rRows = $this->db->get_rows();
		$this->assertCount(1, $rRows, 'one attachment inserted (root skipped)');
		$this->assertSame(100, (int) $rRows[0]['stream_id']);
		$this->assertSame(2, (int) $rRows[0]['server_id']);
		$this->assertNull($rRows[0]['parent_id'], "'source' parent stored as NULL");
		$this->assertSame(0, (int) $rRows[0]['on_demand']);
	}

	/** #156: an empty id in the selection must not become a (0, server_id) row. */
	public function testEmptySelectedIdIsDropped(): void {
		$rResult = RadioService::massEdit([
			'streams'          => json_encode(['', 100, '0']),
			'c_server_tree'    => '1',
			'server_type'      => 'ADD',
			'server_tree_data' => json_encode([['id' => '2', 'parent' => 'source']]),
			'on_demand'        => [],
		]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);

		$this->db->query('SELECT `stream_id` FROM `streams_servers`;');
		$this->assertSame([100], array_map('intval', array_column($this->db->get_rows(), 'stream_id')));
	}
}

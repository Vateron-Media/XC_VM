<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\ChannelService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use PHPUnit\Framework\TestCase;

if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}

/**
 * ChannelService::massEdit() / setOrder() — characterization of the branches the
 * Rector pass rewrote (empty-else inversions across the server-tree, transcode
 * and flag handling). massEdit has no verifyPostTable/exit() and
 * StreamProcess::updateStreams is a no-op while enable_cache is off, so it runs
 * end-to-end against the TestDb.
 */
final class ChannelServiceTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id TEXT, transcode_profile_id INTEGER DEFAULT 0, enable_transcode INTEGER DEFAULT 0, allow_record INTEGER DEFAULT 0, rtmp_output INTEGER DEFAULT 0, `order` INTEGER DEFAULT 0);
			 CREATE TABLE streams_servers (server_stream_id INTEGER PRIMARY KEY AUTO_INCREMENT, stream_id INTEGER, server_id INTEGER, parent_id INTEGER, on_demand INTEGER);
			 CREATE TABLE bouquets (id INTEGER PRIMARY KEY AUTO_INCREMENT, bouquet_name TEXT, bouquet_channels TEXT, bouquet_movies TEXT, bouquet_series TEXT, bouquet_radios TEXT, bouquet_order INTEGER DEFAULT 0);'
		);
		DatabaseFactory::set($this->db);
		BouquetService::setDb($this->db);
		SettingsManager::set([]); // enable_cache off → StreamProcess::updateStreams is a no-op
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
	}

	private function seedStream(int $id): void {
		$this->db->query('INSERT INTO `streams`(`id`, `category_id`) VALUES(?, ?);', $id, '[]');
	}

	// ── transcode flag derivation (c_transcode_profile_id branch) ──────────────

	public function testTranscodeProfileEnablesTranscodeWhenPositive(): void {
		$this->seedStream(100);
		$rResult = ChannelService::massEdit([
			'streams'                => json_encode([100]),
			'c_transcode_profile_id' => '1',
			'transcode_profile_id'   => 5,
		]);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);

		$this->db->query('SELECT `transcode_profile_id`, `enable_transcode` FROM `streams` WHERE `id` = ?;', 100);
		$rRow = $this->db->get_row();
		$this->assertSame(5, (int) $rRow['transcode_profile_id']);
		$this->assertSame(1, (int) $rRow['enable_transcode'], 'positive profile enables transcode');
	}

	public function testTranscodeProfileDisablesTranscodeWhenZero(): void {
		$this->seedStream(100);
		ChannelService::massEdit([
			'streams'                => json_encode([100]),
			'c_transcode_profile_id' => '1',
			'transcode_profile_id'   => 0,
		]);

		$this->db->query('SELECT `enable_transcode` FROM `streams` WHERE `id` = ?;', 100);
		$this->assertSame(0, (int) $this->db->get_row()['enable_transcode'], 'zero profile disables transcode');
	}

	// ── boolean flag columns (allow_record / rtmp_output) ──────────────────────

	public function testCheckedFlagSetsOneAndUncheckedSetsZero(): void {
		$this->seedStream(100);
		// c_allow_record marks the column as edited; allow_record present => 1.
		ChannelService::massEdit([
			'streams'        => json_encode([100]),
			'c_allow_record' => '1',
			'allow_record'   => '1',
			'c_rtmp_output'  => '1', // edited but value absent => 0
		]);

		$this->db->query('SELECT `allow_record`, `rtmp_output` FROM `streams` WHERE `id` = ?;', 100);
		$rRow = $this->db->get_row();
		$this->assertSame(1, (int) $rRow['allow_record'], 'present checkbox => 1');
		$this->assertSame(0, (int) $rRow['rtmp_output'], 'edited but absent checkbox => 0');
	}

	// ── server tree ADD (parent 'source' stored as NULL, root '#' skipped) ─────

	public function testServerTreeAddInsertsAttachmentWithNullParentForSource(): void {
		$this->seedStream(100);
		$rResult = ChannelService::massEdit([
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

	// ── setOrder writes a sequential order by posted id list ───────────────────

	public function testSetOrderPersistsSequentialOrderByPostedIds(): void {
		foreach ([10, 20, 30] as $id) {
			$this->seedStream($id);
		}
		// Posted order: 30, 10, 20 => order 0, 1, 2 respectively.
		$rResult = ChannelService::setOrder(['stream_order_array' => json_encode([30, 10, 20])]);
		$this->assertSame(STATUS_SUCCESS, $rResult['status']);

		$this->db->query('SELECT `id`, `order` FROM `streams` ORDER BY `order` ASC;');
		$rRows = $this->db->get_rows();
		$this->assertSame([30, 10, 20], array_map(static fn($r) => (int) $r['id'], $rRows));
		$this->assertSame([0, 1, 2], array_map(static fn($r) => (int) $r['order'], $rRows));
	}
}

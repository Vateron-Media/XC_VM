<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use TestDb;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use XcVm\Domain\Epg\EpgSelectionGuide;
use XcVm\Domain\Epg\EpgMerge;
use XcVm\Tests\Support\InstallSchema;

final class EpgSelectionGuideTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		\XcVm\Infrastructure\Database\DatabaseFactory::set($this->db);
		foreach (['epg', 'epg_data', 'epg_channels', 'streams', 'streams_options', 'streams_servers', 'bouquets', 'servers', 'streams_categories'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		foreach ([1 => ['rtl.de' => ['display_name' => 'RTL DE', 'langs' => ['de']]], 2 => ['rtl.at' => ['display_name' => 'RTL AT', 'langs' => ['de']]]] as $id => $metadata) {
			$this->db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`data`) VALUES(?,?,?,?)', $id, 'Input ' . $id, 'http://example.invalid/' . $id, json_encode($metadata));
		}
	}

	private function sources(): array {
		$this->db->query('SELECT * FROM `epg`');
		return array_column($this->db->get_raw_rows(), null, 'id');
	}

	public function testAnUnselectedStreamNeverGetsMatchedByName(): void {
		$group = EpgSelectionGuide::group([1, 2]);
		$this->assertSame($group, EpgSelectionGuide::group([1, 2]));
		$streams = [['id' => 7, 'stream_display_name' => 'RTL DE', 'epg_id' => $group]];
		$config = EpgMerge::config(EpgSelectionGuide::resolve($this->sources(), $streams)[$group]);
		$this->assertSame([], $config['bindings']);
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testRealStreamSaveKeepsItsSourceAndQueuesCombinedEpg(): void {
		foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $name => $value) {
			defined($name) || define($name, $value);
		}
		\XcVm\Infrastructure\Database\DatabaseFactory::set($this->db);
		\XcVm\Domain\Stream\StreamService::setDb($this->db);
		$GLOBALS['db'] = $this->db;
		$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		$GLOBALS['rSettings'] = ['download_images' => 0];
		$GLOBALS['rServers'] = [];
		\XcVm\Core\Config\SettingsManager::set(['enable_cache' => 0, 'download_images' => 0]);
		$this->db->query('INSERT INTO `streams` (`id`,`type`,`stream_display_name`,`stream_source`,`category_id`) VALUES(7,1,?,?,?)', 'RTL DE', '["http://example.invalid/live.ts"]', '[]');
		$calls = [];
		\XcVm\Core\Process\ProcessRunner::useRunner(function ($argv) use (&$calls) {
			$calls[] = $argv;
			return 0;
		});
		$post = ['edit' => 7, 'stream_source' => ['http://example.invalid/live.ts'], 'server_tree_data' => '[]', 'category_id' => [], 'bouquets' => [], 'epg_pick_enabled' => 1, 'epg_picks' => '[{"source":2,"channel":"rtl.at"},{"source":1,"channel":"rtl.de"}]'];
		$result = \XcVm\Domain\Stream\StreamService::process($post);
		$this->assertSame(STATUS_SUCCESS, $result['status']);
		$this->db->query('SELECT * FROM `streams` WHERE `id` = 7');
		$stream = $this->db->get_raw_row();
		$this->assertSame(['http://example.invalid/live.ts'], json_decode($stream['stream_source'], true));
		$this->assertSame(EpgMerge::channelId((int) $stream['epg_id'], EpgSelectionGuide::target(7)), $stream['channel_id']);
		$this->assertTrue(EpgMerge::config($this->sources()[$stream['epg_id']])['selection']);
		$this->assertNotEmpty(array_filter($calls, static fn($args) => in_array('cron:epg', $args, true)));
		// Clearing the selection restores the ordinary single-source EPG fields.
		$result = \XcVm\Domain\Stream\StreamService::process(array_replace($post, ['epg_pick_enabled' => 0, 'epg_id' => 1, 'channel_id' => 'rtl.de']));
		$this->assertSame(STATUS_SUCCESS, $result['status']);
		$this->db->query('SELECT * FROM `streams` WHERE `id` = 7');
		$this->assertSame(1, (int) $this->db->get_raw_row()['epg_id']);
		$pickedPost = $post + ['epg_pick_enabled' => 1, 'epg_picks' => '[{"source":2,"channel":"rtl.at"},{"source":1,"channel":"rtl.de"}]'];
		$result = \XcVm\Domain\Stream\StreamService::process($pickedPost);
		$this->assertSame(STATUS_SUCCESS, $result['status']);
		$this->db->query('SELECT * FROM `streams` WHERE `id`=7');
		$this->assertSame([['source' => 2, 'channel' => 'rtl.at', 'lang' => 'de'], ['source' => 1, 'channel' => 'rtl.de', 'lang' => 'de']], json_decode($this->db->get_raw_row()['epg_bindings'], true));

		unset($post['edit']);
		$post['stream_display_name'] = 'RTL AT';
		$result = \XcVm\Domain\Stream\StreamService::process($post);
		$this->assertSame(STATUS_SUCCESS, $result['status']);
		$newId = (int) $result['data']['insert_id'];
		$this->assertNotSame(7, $newId);
		$this->db->query('SELECT * FROM `streams` WHERE `id` = ?', $newId);
		$new = $this->db->get_raw_row();
		$this->assertSame(EpgMerge::channelId((int) $new['epg_id'], EpgSelectionGuide::target($newId)), $new['channel_id']);
	}
}

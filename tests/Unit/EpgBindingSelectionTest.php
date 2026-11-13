<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Epg\EpgBindingSelection;
use XcVm\Domain\Epg\EpgSelectionGuide;
use XcVm\Domain\Epg\EpgMerge;
use XcVm\Tests\Support\InstallSchema;

final class EpgBindingSelectionTest extends TestCase {
	private function sources(): array {
		return [
			1 => ['id'=>1, 'epg_name'=>'Germany', 'epg_file'=>'one.xml', 'data'=>json_encode(['rtl.de'=>['display_name'=>'RTL HD DE','langs'=>['de']], 'rtl.backup'=>['display_name'=>'RTL backup','langs'=>['de']]])],
			2 => ['id'=>2, 'epg_name'=>'Austria', 'epg_file'=>'two.xml', 'data'=>json_encode(['rtl.at'=>['display_name'=>'RTL AT','langs'=>['de']]])],
			3 => ['id'=>3, 'epg_name'=>'Internal', 'epg_file'=>'', 'data'=>'{}', 'merge_config'=>'{"selection":true,"sources":[1,2]}'],
		];
	}

	public function testSelectionPreservesPriorityAndAllowsMultipleIdsFromOneSource(): void {
		$picks = [['source'=>1,'channel'=>'rtl.backup'],['source'=>2,'channel'=>'rtl.at'],['source'=>1,'channel'=>'rtl.de']];
		$validated = EpgBindingSelection::validate(json_encode($picks), $this->sources());
		$this->assertSame(['rtl.backup','rtl.at','rtl.de'], array_column($validated,'channel'));
		$this->assertSame(['de','de','de'], array_column($validated,'lang'));
	}

	public function testDuplicateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		EpgBindingSelection::validate('[{"source":1,"channel":"rtl.de"},{"source":1,"channel":"rtl.de"}]', $this->sources());
	}

	public function testUnknownIdIsNotSilentlyRematched(): void {
		$this->expectException(InvalidArgumentException::class);
		EpgBindingSelection::validate('[{"source":1,"channel":"rtl.typo"}]', $this->sources());
	}

	public function testSearchFindsRegionalVariantsAndHidesInternalGuides(): void {
		$result = EpgBindingSelection::search('RTL [DE] Live', $this->sources());
		$this->assertSame('rtl.de', $result['items'][0]['channel']);
		$this->assertContains('rtl.at', array_column($result['items'],'channel'));
		$this->assertNotContains(3, array_column($result['items'],'source'));
		$this->assertSame([2], array_unique(array_column(EpgBindingSelection::search('RTL', $this->sources(), 2)['items'],'source')));
	}

	public function testSecondIdInSameSourceSuppliesDataWhenFirstHasNoneAndFillsGaps(): void {
		$db = new TestDb();
		foreach (['epg','epg_data','epg_channels'] as $table) $db->exec(InstallSchema::table($table));
		$sources = $this->sources();
		foreach (array_slice($sources,0,2,true) as $id=>$source) $db->query('INSERT INTO `epg` (`id`,`epg_name`,`epg_file`,`data`) VALUES(?,?,?,?)',$id,$source['epg_name'],$source['epg_file'],$source['data']);
		$group = EpgSelectionGuide::group($db,[1,2]);
		$db->query('SELECT * FROM `epg`');$sources=array_column($db->get_raw_rows(),null,'id');
		$picks=EpgBindingSelection::validate('[{"source":1,"channel":"rtl.de"},{"source":1,"channel":"rtl.backup"},{"source":2,"channel":"rtl.at"}]',$sources);
		$key=EpgMerge::channelId($group,EpgSelectionGuide::target(7));
		$streams=[['id'=>7,'stream_display_name'=>'RTL DE','epg_id'=>$group,'channel_id'=>$key,'epg_bindings'=>json_encode($picks)]];
		$sources=EpgSelectionGuide::resolve($sources,$streams);
		$deps=EpgMerge::dependencies($sources,$streams);
		$this->assertArrayHasKey('rtl.de',$deps[1]);$this->assertArrayHasKey('rtl.backup',$deps[1]);
		$now=time();
		foreach ([[1,'rtl.backup',$now,$now+3600,'Second ID'],[2,'rtl.at',$now,$now+3600,'Overlapping duplicate'],[2,'rtl.at',$now+3600,$now+7200,'Third ID']] as $row) $db->query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`title`) VALUES(?,?,?,?,?)',...$row);
		EpgMerge::rebuild($db,$sources[$group],$sources,$streams);
		$db->query('SELECT * FROM `epg_data` WHERE `epg_id`=? ORDER BY `start`',$group);
		$this->assertSame(['Second ID','Third ID'],array_column($db->get_raw_rows(),'title'));
		// Keep the merged snapshot for the same picks, but never after changing IDs.
		$db->query('DELETE FROM `epg_data` WHERE `epg_id` IN (1,2)');
		$db->query('SELECT * FROM `epg`');
		$sources = EpgSelectionGuide::resolve(array_column($db->get_raw_rows(), null, 'id'), $streams);
		EpgMerge::rebuild($db, $sources[$group], $sources, $streams);
		$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = ? ORDER BY `start`', $group);
		$this->assertSame(['Second ID', 'Third ID'], array_column($db->get_raw_rows(), 'title'));
		$streams[0]['epg_bindings'] = '[{"source":1,"channel":"rtl.de","lang":"de"}]';
		$sources = EpgSelectionGuide::resolve($sources, $streams);
		EpgMerge::rebuild($db, $sources[$group], $sources, $streams);
		$db->query('SELECT `title` FROM `epg_data` WHERE `epg_id` = ?', $group);
		$this->assertSame([], $db->get_raw_rows());
	}

	public function testMigrationCanBeRepeated(): void {
		$db=new TestDb();$db->exec(InstallSchema::table('streams'));
		$db->exec('ALTER TABLE `streams` DROP COLUMN `epg_bindings`');
		$db->exec(InstallSchema::migration('104_add_stream_epg_bindings'));
		$db->exec(InstallSchema::migration('104_add_stream_epg_bindings'));
		$db->query('SHOW COLUMNS FROM `streams`');
		$this->assertContains('epg_bindings',array_column($db->get_raw_rows(),'Field'));
	}
}

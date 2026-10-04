<?php

use XcVm\Core\Config\SettingsRepository;
use PHPUnit\Framework\TestCase;

/**
 * SettingsRepository::getAll — loads the single settings row and normalises its
 * JSON/CSV fields. Driven with force = true (bypassing the read cache) against a
 * TestDb settings table; CACHE_TMP_PATH is pointed at a temp dir so the trailing
 * FileCache write has somewhere to go.
 */
final class SettingsRepositoryTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$dir = sys_get_temp_dir() . '/xcvm_settings_cache_test/';
			@mkdir($dir, 0755, true);
			define('CACHE_TMP_PATH', $dir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);

		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE settings (id INTEGER PRIMARY KEY AUTO_INCREMENT, allow_countries TEXT, allowed_stb_types TEXT, stalker_lock_images TEXT, bouquet_name TEXT, api_ips TEXT, shared_mount_prefixes TEXT);');
		$GLOBALS['db'] = $this->db;
	}

	protected function tearDown(): void {
		unset($GLOBALS['db']);
		foreach (glob(rtrim(CACHE_TMP_PATH, '/') . '/*') ?: [] as $f) {
			if (is_file($f)) {
				unlink($f);
			}
		}
	}

	private function seed(array $row): void {
		// Production settings columns are NOT NULL DEFAULT '', so unseeded fields
		// default to '' here (not null) to mirror the real row shape.
		$this->db->query(
			'INSERT INTO settings (id, allow_countries, allowed_stb_types, stalker_lock_images, bouquet_name, api_ips, shared_mount_prefixes) VALUES (1, ?, ?, ?, ?, ?, ?);',
			$row['allow_countries'] ?? '',
			$row['allowed_stb_types'] ?? '',
			$row['stalker_lock_images'] ?? '',
			$row['bouquet_name'] ?? '',
			$row['api_ips'] ?? '',
			$row['shared_mount_prefixes'] ?? ''
		);
	}

	public function testDecodesJsonAndCsvFields(): void {
		$this->seed([
			'allow_countries'       => '["US","GB"]',
			'allowed_stb_types'     => '["MAG250"," ",""]',
			'stalker_lock_images'   => 'null',
			'bouquet_name'          => 'My Bouquet',
			'api_ips'               => '1.2.3.4,5.6.7.8',
			'shared_mount_prefixes' => '["/mnt/a"," /mnt/b "]',
		]);

		$out = SettingsRepository::getAll(true);

		$this->assertSame(['US', 'GB'], $out['allow_countries']);
		$this->assertSame(['mag250'], $out['allowed_stb_types'], 'lowercased, trimmed, blanks dropped');
		$this->assertNull($out['stalker_lock_images']);
		$this->assertSame('My_Bouquet', $out['bouquet_name'], 'spaces become underscores');
		$this->assertSame(['1.2.3.4', '5.6.7.8'], $out['api_ips']);
		$this->assertSame(['/mnt/a', '/mnt/b'], $out['shared_mount_prefixes']);
	}

	public function testEmptyListFieldsCollapseToEmptyArrays(): void {
		$this->seed([
			'allowed_stb_types'     => '[""]',
			'api_ips'               => '',
			'shared_mount_prefixes' => '',
		]);

		$out = SettingsRepository::getAll(true);

		$this->assertSame([], $out['allowed_stb_types'], 'an "empty" multiselect ([""]) collapses');
		$this->assertSame([], $out['api_ips']);
		$this->assertSame([], $out['shared_mount_prefixes']);
	}

	public function testSharedMountPrefixesFallsBackToLegacyCsv(): void {
		$this->seed(['shared_mount_prefixes' => 'legacy1, legacy2']);

		$out = SettingsRepository::getAll(true);

		$this->assertSame(['legacy1', 'legacy2'], $out['shared_mount_prefixes'], 'non-JSON CSV self-heals');
	}
}

<?php

use XcVm\Domain\Stream\CategoryService;
use PHPUnit\Framework\TestCase;

/**
 * The TMDb genre → category/bouquet mapping (`watch_categories`), on the
 * TestDb: read per type, saved from the Settings → VOD Import form, and
 * cleared when its category is deleted.
 */
final class CategoryServiceGenreMapTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE watch_categories (id INTEGER PRIMARY KEY AUTO_INCREMENT, type INTEGER, genre_id INTEGER, genre TEXT, category_id INTEGER, bouquets TEXT);');
		$this->db->exec("INSERT INTO watch_categories (id, type, genre_id, genre, category_id, bouquets) VALUES (1, 1, 28, 'Action', 0, '[]'), (2, 2, 28, 'Action', 0, '[]'), (3, 1, 35, 'Comedy', 0, '[]'), (4, 3, 28, 'Plex Action', 0, '[]');");
		CategoryService::setDb($this->db);
	}

	private function row(int $rID): array {
		$this->db->query('SELECT `category_id`, `bouquets` FROM `watch_categories` WHERE `id` = ?;', $rID);
		$rRow = $this->db->get_row();
		return [(int) $rRow['category_id'], $rRow['bouquets']];
	}

	public function testGetGenreMapReadsOneTypeKeyedByGenre(): void {
		$this->assertSame([28, 35], array_keys(CategoryService::getGenreMap(1)));
		$this->assertSame('Action', CategoryService::getGenreMap(2)[28]['genre']);
	}

	public function testSaveGenreMapStoresMovieAndSeriesGenresSeparately(): void {
		CategoryService::saveGenreMap(['genre_28' => '5', 'bouquet_28' => ['3', '4'], 'genretv_28' => '9', 'genre_35' => '0', 'percentage_match' => '80', 'genre_x' => '7']);

		$this->assertSame([5, '[3,4]'], $this->row(1));
		$this->assertSame([9, '[]'], $this->row(2));
		$this->assertSame([0, '[]'], $this->row(3));
		$this->assertSame([0, '[]'], $this->row(4), "Plex's genres are not the form's");
	}

	public function testDeletingACategoryClearsTheGenresMappedToIt(): void {
		$this->db->exec('CREATE TABLE streams_categories (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_name TEXT);');
		$this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id TEXT);');
		$this->db->exec('CREATE TABLE streams_series (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id TEXT);');
		$this->db->exec('CREATE TABLE watch_folders (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id INTEGER, fb_category_id INTEGER);');
		$this->db->exec("INSERT INTO streams_categories (id, category_name) VALUES (5, 'Action'), (6, 'Comedy');");
		$this->db->exec('UPDATE watch_categories SET category_id = 5 WHERE id IN (1, 2);');
		$this->db->exec('UPDATE watch_categories SET category_id = 6 WHERE id = 3;');

		$this->assertTrue(CategoryService::deleteById(5));

		$this->assertSame([0, 0, 6], [$this->row(1)[0], $this->row(2)[0], $this->row(3)[0]]);
	}
}

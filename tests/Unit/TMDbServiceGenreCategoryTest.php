<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Vod\TMDbService;

final class TMDbServiceGenreCategoryTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE streams_categories (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_type VARCHAR(16), category_name VARCHAR(255));');
		$this->db->exec('CREATE TABLE watch_categories (id INTEGER PRIMARY KEY AUTO_INCREMENT, type INTEGER, genre_id INTEGER, genre VARCHAR(64), category_id INTEGER, bouquets TEXT);');
		$this->db->exec("INSERT INTO streams_categories (id, category_type, category_name) VALUES (5, 'movie', 'Action'), (6, 'series', 'Action & Adventure');");
		$this->db->exec("INSERT INTO watch_categories (type, genre_id, genre, category_id, bouquets) VALUES (1, 28, 'Action', 0, '[]'), (1, 35, 'Comedy', 99, '[]'), (2, 10759, 'Action & Adventure', 0, '[]');");
		TMDbService::setDb($this->db);
		TMDbService::requireLibrary();
	}

	protected function tearDown(): void {
		(new ReflectionProperty(TMDbService::class, 'db'))->setValue(null, null);
	}

	public function testImportingTmdbCategoriesFillsOnlyEmptyGenreMappings(): void {
		$rTMDB = new class {
			public function getMovieGenres(): array {
				return [new Genre(['id' => 28, 'name' => 'Action']), new Genre(['id' => 35, 'name' => 'Comedy'])];
			}

			public function getTVGenres(): array {
				return [new Genre(['id' => 10759, 'name' => 'Action & Adventure'])];
			}
		};

		$this->assertTrue(TMDbService::addCategories($rTMDB));

		$this->db->query('SELECT genre_id, type, category_id FROM watch_categories ORDER BY type, genre_id;');
		$rMappings = array_map(static fn(array $rRow): array => array_map('intval', $rRow), $this->db->get_raw_rows());
		$this->assertSame([
			['genre_id' => 28, 'type' => 1, 'category_id' => 5],
			['genre_id' => 35, 'type' => 1, 'category_id' => 99],
			['genre_id' => 10759, 'type' => 2, 'category_id' => 6],
		], $rMappings);
		$this->db->query('SELECT COUNT(*) FROM streams_categories;');
		$this->assertSame(3, (int) $this->db->get_col(), 'only the missing Comedy category is created');
	}

	public function testMigrationBackfillsExistingCategoriesWithoutOverwritingCustomMappings(): void {
		$this->db->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/104_map_tmdb_genre_categories.sql'));

		$this->db->query('SELECT genre_id, type, category_id FROM watch_categories ORDER BY type, genre_id;');
		$rMappings = array_map(static fn(array $rRow): array => array_map('intval', $rRow), $this->db->get_raw_rows());
		$this->assertSame([
			['genre_id' => 28, 'type' => 1, 'category_id' => 5],
			['genre_id' => 35, 'type' => 1, 'category_id' => 99],
			['genre_id' => 10759, 'type' => 2, 'category_id' => 6],
		], $rMappings);
	}
}

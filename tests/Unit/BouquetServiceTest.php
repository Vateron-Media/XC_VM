<?php

use XcVm\Domain\Bouquet\BouquetService;
use PHPUnit\Framework\TestCase;

/**
 * BouquetService::removeItems — regression for the assignment-in-condition bug.
 *
 * removeItems() finds each id in the bouquet's item list and unsets it by key:
 *   if (($rKey = array_search($rID, $rChannels)) !== false) { unset($rChannels[$rKey]); }
 *
 * A Rector inversion once dropped the parens (`$rKey = array_search(...) !== false`),
 * which assigns the boolean to $rKey and unsets offset 1 — removing the wrong
 * element. These tests remove elements NOT at index 1 so that broken variant
 * fails, and confirm the correct element is removed. Driven against TestDb.
 */
final class BouquetServiceTest extends TestCase {

	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE bouquets (id INTEGER PRIMARY KEY AUTO_INCREMENT, bouquet_channels TEXT, bouquet_movies TEXT, bouquet_radios TEXT, bouquet_series TEXT);'
		);
		$this->db->query(
			'INSERT INTO bouquets (id, bouquet_channels, bouquet_movies, bouquet_radios, bouquet_series) VALUES (1, ?, ?, "[]", "[]");',
			'[10,20,30]',
			'[5,6]'
		);
		BouquetService::setDb($this->db);
	}

	private function channels(): array {
		$this->db->query('SELECT `bouquet_channels` FROM `bouquets` WHERE `id` = 1;');
		return json_decode($this->db->get_col(), true);
	}

	private function movies(): array {
		$this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
		return json_decode($this->db->get_col(), true);
	}

	public function testRemovesTheLastElementNotIndexOne(): void {
		// id 30 is at index 2 — the broken variant would unset index 1 (=> 20).
		BouquetService::removeItems('stream', 1, [30]);
		$this->assertSame([10, 20], $this->channels());
	}

	public function testRemovesTheFirstElement(): void {
		// id 10 is at index 0 — the broken variant would unset index 1 (=> 20).
		BouquetService::removeItems('stream', 1, [10]);
		$this->assertSame([20, 30], $this->channels());
	}

	public function testRemovesMultipleIds(): void {
		BouquetService::removeItems('stream', 1, [10, 30]);
		$this->assertSame([20], $this->channels());
	}

	public function testRemovingAbsentIdLeavesListUnchanged(): void {
		BouquetService::removeItems('stream', 1, [999]);
		$this->assertSame([10, 20, 30], $this->channels());
	}

	public function testAcceptsAScalarId(): void {
		// array|int|string param: a single scalar is wrapped, not fatal.
		BouquetService::removeItems('stream', 1, 20);
		$this->assertSame([10, 30], $this->channels());
	}

	public function testTypeSelectsTheMoviesColumn(): void {
		BouquetService::removeItems('movie', 1, [5]);
		$this->assertSame([6], $this->movies());
		$this->assertSame([10, 20, 30], $this->channels(), 'channels untouched');
	}
}

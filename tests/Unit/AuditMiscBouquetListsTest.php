<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Tests\Support\InstallSchema;

/**
 * A bouquet keeps each of its lists as JSON in a column that may also be
 * empty: NULL, an empty text, or a value that is not a list.
 * BouquetService::addItems() and removeItems() read such a column as a list
 * with nothing in it.
 */
final class AuditMiscBouquetListsTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		$this->db->exec(InstallSchema::table('bouquets'));
		BouquetService::setDb($this->db);
	}

	protected function tearDown(): void {
		(new ReflectionProperty(BouquetService::class, 'db'))->setValue(null, null);
	}

	/** @return array<string, array{0: ?string}> */
	public static function emptyLists(): array {
		return ['NULL' => [null], 'an empty text' => [''], 'a number' => ['7'], 'text that is not JSON' => ['1,2']];
	}

	#[DataProvider('emptyLists')]
	public function testAnEmptyListTakesAndGivesUpItems(?string $rStored): void {
		$this->db->query('INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_channels`, `bouquet_movies`, `bouquet_radios`, `bouquet_series`) VALUES (1, ?, ?, ?, ?, ?);', 'Films', $rStored, $rStored, $rStored, $rStored);

		foreach (['stream' => 'bouquet_channels', 'movie' => 'bouquet_movies', 'radio' => 'bouquet_radios', 'series' => 'bouquet_series'] as $rType => $rColumn) {
			BouquetService::removeItems($rType, 1, 5);
			$this->db->query('SELECT `' . $rColumn . '` FROM `bouquets` WHERE `id` = 1;');
			$this->assertSame($rStored, $this->db->get_col(), 'nothing to remove leaves the ' . $rType . ' list as it was');

			BouquetService::addItems($rType, 1, [5, 6]);
			$this->db->query('SELECT `' . $rColumn . '` FROM `bouquets` WHERE `id` = 1;');
			$this->assertSame('[5,6]', $this->db->get_col(), $rType);
		}
	}
}

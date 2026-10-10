<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Tmdb\TmdbApiService;

/**
 * A search for a number answers the TMDb record of that id (a way to pick one
 * by id) and the titles that match it. Only the first was answered: "1917" or
 * "300" gave whatever film has that id, and the film of that name could not
 * be found.
 */
final class TmdbSearchNumericTitleTest extends TestCase {
	private array $rSettings;

	protected function setUp(): void {
		TmdbApiService::requireLibrary();
		$this->rSettings = SettingsManager::getAll();
		SettingsManager::set(['tmdb_api_key' => 'k', 'parse_type' => 'guessit']);
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSettings);
	}

	public function testANumberIsAnIdAndATitle(): void {
		$rClient = new FakeTmdbClient([
			'getMovie' => fn() => new Movie(['id' => 1917, 'title' => 'Some Other Film']),
			'searchMovie' => fn() => [new Movie(['id' => 530915, 'title' => '1917']), new Movie(['id' => 1917, 'title' => 'Some Other Film'])],
		]);

		$rOut = TmdbApiService::search('1917', 'movie', null, null, $rClient);

		$this->assertTrue($rOut['result']);
		$this->assertSame([1917, 530915], array_column($rOut['data'], 'id'), 'the record of that id first, then the titles, each once');
	}

	public function testATitleInWordsIsSearchedAsBefore(): void {
		$rClient = new FakeTmdbClient(['searchMovie' => fn() => [new Movie(['id' => 7, 'title' => 'Heat'])]]);
		$this->assertSame([7], array_column(TmdbApiService::search('Heat', 'movie', null, null, $rClient)['data'], 'id'));
	}
}

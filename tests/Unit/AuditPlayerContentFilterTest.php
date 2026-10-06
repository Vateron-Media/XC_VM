<?php

use PHPUnit\Framework\TestCase;

/**
 * The web player lists, finds and opens what the line's bouquets include, and
 * nothing else: a line whose bouquets hold no movie, series or radio station
 * is shown none, the same as playback would allow it.
 *
 * The controllers answer and exit, so each request runs in a child PHP against
 * its own database: one live channel, one movie, one radio station and one
 * series with one episode.
 */
final class AuditPlayerContentFilterTest extends TestCase {
	private const NOTHING = ['live_ids' => [], 'vod_ids' => [], 'series_ids' => [], 'radio_ids' => []];
	private const EVERYTHING = ['live_ids' => [1], 'vod_ids' => [2], 'series_ids' => [3], 'radio_ids' => [4]];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-filter-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir, 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Ask $rController for $rRequest as a line with the ids $rIds.
	 *
	 * @return array{0: string, 1: int|false} What it printed, and the response code it set.
	 */
	private function ask(string $rController, array $rRequest, array $rIds): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. '$db = new TestDb();'
			. 'foreach (["streams", "streams_servers", "streams_series", "streams_episodes", "streams_categories"] as $rTable) {'
			. ' $db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));'
			. '}'
			. '$db->exec("INSERT INTO `streams_categories` (`id`, `category_type`, `category_name`) VALUES (1, \'movie\', \'Films\')");'
			. '$db->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `category_id`) VALUES'
			. ' (1, 1, \'Catalogue News\', \'[1]\'), (2, 2, \'Catalogue Film\', \'[1]\'), (4, 4, \'Catalogue Radio\', \'[1]\'), (5, 5, \'Catalogue Pilot\', \'[1]\')");'
			. '$db->query("UPDATE `streams` SET `stream_source` = ? WHERE `id` = 4", json_encode(["http://source.test/radio.mp3"]));'
			. '$db->exec("INSERT INTO `streams_series` (`id`, `title`, `category_id`) VALUES (3, \'Catalogue Show\', \'[1]\'), (6, \'Second Show\', \'[1]\')");'
			. '$db->exec("INSERT INTO `streams_episodes` (`season_num`, `episode_num`, `series_id`, `stream_id`) VALUES (1, 1, 3, 5), (1, 1, 6, 5)");'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. 'define("SERVER_ID", 1);'
			. 'define("CACHE_TMP_PATH", ' . var_export($this->rDir, true) . ');'
			. '$rServers = [1 => ["enable_proxy" => 0, "server_protocol" => "http", "http_broadcast_port" => 8080, "domain_name" => "my.tv", "server_ip" => "198.51.100.9", "server_type" => 0, "is_main" => 1]];'
			. '$rSettings = ["keep_protocol" => 0, "use_mdomain_in_lists" => 0, "channel_number_type" => "bouquet"];'
			. '\XcVm\Core\Config\SettingsManager::set($rSettings);'
			. 'require_once MAIN_HOME . "Infrastructure/Bootstrap/player_utility_functions.php";'
			. '$rUserInfo = ' . var_export($rIds + ['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'allowed_outputs' => [1, 2, 3], 'bouquet' => [1]], true) . ';'
			. '\XcVm\Core\Http\RequestManager::set(' . var_export($rRequest, true) . ');'
			. '$_SERVER["HTTP_X_SPA_REQUEST"] = "1";' // a page comes back as its own markup, without the layout around it
			. 'register_shutdown_function(static function () { echo "\n" . json_encode(http_response_code()); });'
			. '(new \XcVm\Public\Controllers\PlayerV2\\' . $rController . '())->index();';

		$rProc = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rCut = strrpos($rOut, "\n");
		$this->assertNotFalse($rCut, $rOut . $rErr);
		$this->assertStringNotContainsString('Fatal error', $rOut, $rErr);

		return [substr($rOut, 0, $rCut), json_decode(substr($rOut, $rCut + 1))];
	}

	/** @return list<string> The titles a JSON answer lists under $rKey. */
	private function titles(string $rController, array $rRequest, array $rIds, string $rKey, string $rField = 'title'): array {
		[$rOut] = $this->ask($rController, $rRequest, $rIds);
		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rOut);

		return array_column($rAnswer[$rKey], $rField);
	}

	public function testALineWithoutMoviesIsListedNone(): void {
		$this->assertSame([], $this->titles('MoviesController', ['ajax' => '1'], self::NOTHING, 'movies'));
		$this->assertSame(['Catalogue Film'], $this->titles('MoviesController', ['ajax' => '1'], self::EVERYTHING, 'movies'));
	}

	/** The category list counts the line's own movies, not the catalogue's. */
	public function testCategoriesCountOnlyTheLinesOwn(): void {
		$rBadge = '<span class="badge bg-label-secondary rounded-pill">1</span>';

		[$rOut] = $this->ask('MoviesController', [], self::NOTHING);
		$this->assertStringNotContainsString($rBadge, (string) (json_decode($rOut, true)['html'] ?? $rOut));

		[$rOut] = $this->ask('MoviesController', [], self::EVERYTHING);
		$this->assertStringContainsString($rBadge, (string) (json_decode($rOut, true)['html'] ?? ''), $rOut);
	}

	/** A type the line has nothing of offers no category either, whatever the category map holds. */
	public function testCategoriesOfATypeTheLineLacksAreNotListed(): void {
		$rListed = 'data-category-raw="Films"';

		[$rOut] = $this->ask('MoviesController', [], self::NOTHING);
		$this->assertStringNotContainsString($rListed, (string) (json_decode($rOut, true)['html'] ?? $rOut));

		[$rOut] = $this->ask('MoviesController', [], self::EVERYTHING);
		$this->assertStringContainsString($rListed, (string) (json_decode($rOut, true)['html'] ?? ''), $rOut);
	}

	public function testALineWithoutSeriesIsListedNone(): void {
		$this->assertSame([], $this->titles('SeriesController', ['ajax' => '1'], self::NOTHING, 'series'));
		$this->assertSame(['Catalogue Show'], $this->titles('SeriesController', ['ajax' => '1'], self::EVERYTHING, 'series'));
	}

	public function testALineWithoutRadioIsListedNone(): void {
		$this->assertSame([], $this->titles('RadioController', ['ajax' => '1'], self::NOTHING, 'stations', 'name'));
		$this->assertSame(['Catalogue Radio'], $this->titles('RadioController', ['ajax' => '1'], self::EVERYTHING, 'stations', 'name'));
	}

	/** A station's play address is answered for the stations the line has, and for no other. */
	public function testAStationTheLineDoesNotHaveIsNotPlayed(): void {
		[, $rStatus] = $this->ask('RadioController', ['stream' => '4'], self::NOTHING);
		$this->assertSame(404, $rStatus);

		[, $rStatus] = $this->ask('RadioController', ['stream' => '4'], ['radio_ids' => [9]] + self::NOTHING);
		$this->assertSame(404, $rStatus);

		[, $rStatus] = $this->ask('RadioController', ['stream' => '4'], self::EVERYTHING);
		$this->assertSame(302, $rStatus);
	}

	public function testSearchFindsOnlyWhatTheLineHas(): void {
		[$rOut] = $this->ask('SearchController', ['ajax' => '1', 'q' => 'Catalogue'], self::NOTHING);
		$this->assertSame(['live' => [], 'movies' => [], 'series' => [], 'episodes' => [], 'radio' => []], json_decode($rOut, true)['results'], $rOut);

		[$rOut] = $this->ask('SearchController', ['ajax' => '1', 'q' => 'Catalogue'], self::EVERYTHING);
		$this->assertSame(['live' => 1, 'movies' => 1, 'series' => 1, 'episodes' => 1, 'radio' => 1], array_map('count', json_decode($rOut, true)['results']), $rOut);
	}

	public function testASeriesTheLineDoesNotHaveIsNotOpened(): void {
		[$rOut, $rStatus] = $this->ask('SeriesController', ['id' => '3'], self::NOTHING);
		$this->assertStringNotContainsString('Catalogue Show', $rOut);
		$this->assertSame(302, $rStatus);

		[$rOut] = $this->ask('SeriesController', ['id' => '3'], self::EVERYTHING);
		$this->assertStringContainsString('Catalogue Show', $rOut);
	}

	/** An episode is the line's through its own series, whichever series the request names. */
	public function testAnEpisodeOfASeriesTheLineDoesNotHaveIsNotOpened(): void {
		[$rOut, $rStatus] = $this->ask('PlayerWatchController', ['type' => 'series', 'id' => '5', 'series_id' => '3'], self::NOTHING);
		$this->assertStringNotContainsString('Catalogue', $rOut);
		$this->assertSame(302, $rStatus);

		[$rOut, $rStatus] = $this->ask('PlayerWatchController', ['type' => 'series', 'id' => '5', 'series_id' => '8'], ['series_ids' => [8]] + self::NOTHING);
		$this->assertStringNotContainsString('Catalogue', $rOut);
		$this->assertSame(302, $rStatus);

		[$rOut] = $this->ask('PlayerWatchController', ['type' => 'series', 'id' => '5', 'series_id' => '3'], self::EVERYTHING);
		$this->assertStringContainsString('Catalogue Pilot', $rOut);
	}

	/** An episode two of the line's series share opens under the series the request names. */
	public function testAnEpisodeOpensUnderTheSeriesAsked(): void {
		$rBoth = ['series_ids' => [3, 6]] + self::NOTHING;

		[$rOut] = $this->ask('PlayerWatchController', ['type' => 'series', 'id' => '5', 'series_id' => '3'], $rBoth);
		$this->assertStringContainsString('Catalogue Show', $rOut);
		$this->assertStringNotContainsString('Second Show', $rOut);

		[$rOut] = $this->ask('PlayerWatchController', ['type' => 'series', 'id' => '5', 'series_id' => '6'], $rBoth);
		$this->assertStringContainsString('Second Show', $rOut);
		$this->assertStringNotContainsString('Catalogue Show', $rOut);
	}
}

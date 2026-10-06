<?php

use PHPUnit\Framework\TestCase;

/**
 * A line signed in to another provider's server sees that server's catalogue in
 * the web player. Whatever the server sends as an image address reaches the
 * page as the value of one attribute, or as one string in a handler: it never
 * adds markup or script of its own.
 *
 * The views run in a child PHP with the data the controllers' external-server
 * branches hand them (which leave some of the views' variables unset).
 */
final class AuditPlayerExternalContentTest extends TestCase {
	private const IMAGE = 'http://img.test/p.jpg?a=\';window.injected=1;\'&b="><b data-injected="1">';

	private function render(string $rView, array $rData): DOMXPath {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(0);'
			. '$_SERVER["XC_CODE"] = "play";'
			. 'extract(' . var_export($rData, true) . ');'
			. 'require MAIN_HOME . ' . var_export('Public/Views/player_v2/' . $rView . '.php', true) . ';';

		$rProc = proc_open([PHP_BINARY, '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		$this->assertSame(0, proc_close($rProc), $rOut . $rErr);

		$rDom = new DOMDocument();
		@$rDom->loadHTML('<?xml encoding="UTF-8">' . $rOut);

		return new DOMXPath($rDom);
	}

	/** Every image of the page carries the address as data, and every error handler is one assignment of one string. */
	private function assertImagesCarryTheAddressAsData(DOMXPath $rPage, int $rImages): void {
		$this->assertSame(0, $rPage->query('//*[@data-injected]')->length, 'the image address added an element to the page');
		$this->assertSame($rImages, $rPage->query('//img[@src=' . "concat('http://img.test/p.jpg?a=', \"'\", ';window.injected=1;', \"'\", '&b=\"><b data-injected=\"1\">')" . ']')->length, 'the image address did not arrive whole');
		foreach ($rPage->query('//img/@onerror') as $rHandler) {
			$this->assertMatchesRegularExpression('/^this\.src=(?:\'[^\']*\'|"(?:[^"\\\\]|\\\\.)*");$/', $rHandler->value, 'the image address runs as script in the error handler');
		}
	}

	public function testMoviePage(): void {
		$rPage = $this->render('movie', [
			'movie' => ['id' => 5, 'stream_display_name' => 'Film', 'year' => '2020', 'rating' => '7'],
			'props' => ['plot' => '', 'genre' => '', 'cast' => '', 'director' => '', 'duration' => '', 'release_date' => ''],
			'posterUrl' => self::IMAGE,
			'backdropUrl' => self::IMAGE,
			'backdrops' => [self::IMAGE],
			'streamUrl' => 'http://media.test/movie/u/p/5.mp4',
			'categoryNames' => ['Films'],
			'similarMovies' => [],
			'baseUrl' => '/play/',
		]);

		$this->assertImagesCarryTheAddressAsData($rPage, 2);
	}

	public function testSeriesPage(): void {
		$rEpisode = ['season_num' => 1, 'episode_num' => 1, 'stream_id' => 9, 'episode_id' => 9, 'title' => 'Pilot', 'cover' => self::IMAGE, 'duration' => '', 'quality_badge' => 'HD', 'quality_color' => 'primary', 'stream_url' => 'http://media.test/series/u/p/9.mp4'];
		$rPage = $this->render('series_detail', [
			'series' => ['id' => 3, 'title' => 'Show', 'year' => '2020', 'rating' => '7', 'plot' => '', 'genre' => '', 'director' => '', 'cast' => '', 'cover' => self::IMAGE],
			// The panel's own series also pass their seasons; the cards share the poster's fallback.
			'seasons' => [['season_number' => 1, 'name' => 'Season 1', 'cover' => self::IMAGE, 'episode_count' => 1]],
			'posterUrl' => self::IMAGE,
			'backdropUrl' => self::IMAGE,
			'backdrops' => [self::IMAGE],
			'trailerId' => '',
			'castList' => [],
			'categoryNames' => ['Shows'],
			'episodesMap' => [1 => [$rEpisode]],
			'seasonsList' => [1],
			'totalEpisodes' => 1,
			'firstEpisode' => $rEpisode,
			'similarSeries' => [],
			'baseUrl' => '/play/',
		]);

		$this->assertImagesCarryTheAddressAsData($rPage, 4);
		$this->assertSame(3, $rPage->query('//img/@onerror')->length);
	}

	/** The account name comes from the sign-in form; its initials are text like the name itself. */
	public function testProfilePage(): void {
		$rPage = $this->render('profile', [
			'lineData' => ['id' => 0, 'username' => '<b data-injected="1">', 'password' => 'secret', 'created_at' => 1700000000, 'max_connections' => 1, 'is_trial' => 0, 'status' => 'Active', 'bouquet' => [], 'exp_date' => null],
			'activationCode' => '',
			'activeConsCount' => 0,
			'activeSessions' => [],
			'userBouquets' => [],
			'outputDevices' => [],
			'serverPublicUrl' => 'http://other.test',
			'expTimestamp' => null,
			'isExpired' => false,
			'isExpiringSoon' => false,
			'daysRemaining' => null,
			'totalLive' => 0,
			'totalVod' => 0,
			'totalSeries' => 0,
			'totalRadio' => 0,
			'isExternalXc' => true,
			'externalServer' => 'http://other.test',
			'externalServerInfo' => [],
			'externalUserInfo' => [],
		]);

		$this->assertSame(0, $rPage->query('//b[not(@class)]')->length, 'the account name added an element to the page');
		$this->assertSame('<B', trim($rPage->query('//div[contains(@class, "user-profile-avatar-initials")]')->item(0)->textContent));
	}

	public function testHomePage(): void {
		$rMovie = ['type' => 'movie', 'id' => 5, 'title' => 'Film', 'year' => '2020', 'rating' => '7', 'cover' => self::IMAGE, 'backdrop' => self::IMAGE, 'plot' => '', 'genre' => 'Movie', 'duration' => 'Feature Film'];
		$rPage = $this->render('index', [
			'rUserInfo' => ['username' => 'viewer', 'exp_date' => null],
			'heroSlides' => [$rMovie],
			'top10Items' => [$rMovie],
			'rMovies' => ['streams' => []],
			'rSeries' => ['streams' => [['id' => 3, 'series_id' => 3, 'title' => 'Show', 'stream_display_name' => 'Show', 'cover' => self::IMAGE, 'plot' => '', 'cast' => '', 'director' => '', 'genre' => '', 'year' => '2020', 'rating' => '7', 'category_id' => 1]]],
			'rLiveChannels' => ['streams' => []],
			'rRadioStreams' => ['streams' => []],
			'totalLive' => 0,
			'totalVod' => 1,
			'totalSeries' => 1,
			'totalRadio' => 0,
		]);

		$this->assertImagesCarryTheAddressAsData($rPage, 3);
	}
}

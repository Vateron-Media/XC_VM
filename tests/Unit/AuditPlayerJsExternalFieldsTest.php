<?php

use PHPUnit\Framework\TestCase;

/**
 * A line signed in to another provider's server is shown what that server
 * answers. The pages read those answers as text, numbers and image addresses,
 * so that is all the service hands on: a field of any other type is left out
 * (the page's default applies), an image address is kept only when it is an
 * http(s) one, and one malformed record does not cost the rest of a catalogue.
 *
 * Every case runs in a child PHP with the server's answer given in place of
 * the request.
 */
final class AuditPlayerJsExternalFieldsTest extends TestCase {
	private const IMAGE = 'http://img.test/poster.jpg';

	/** Addresses a page would load or run as something else than an image from the web. */
	private const NOT_IMAGES = ['javascript:alert(1)', 'data:text/html,x', '/local/path.png', 'ftp://img.test/a.png', ['http://img.test/in-a-list.jpg'], 7, true];

	private const CALL = <<<'PHP'
		// Output has started, so the service leaves the session alone.
		echo "\n";
		$rService = new class ($rAnswer) extends \XcVm\Domain\External\ExternalXtreamService {
			public function __construct(private array $rAnswer) {
				parent::__construct('http://provider.test', 'viewer', 'secret');
			}

			public function request(array $params = [], int $timeout = 10): ?array {
				return $this->rAnswer;
			}
		};
		try {
			echo json_encode(['result' => $rService->$rMethod(...$rArguments)]);
		} catch (\Throwable $rError) {
			echo json_encode(['error' => get_class($rError) . ': ' . $rError->getMessage()]);
		}
		PHP;

	/** What $rMethod returns when the server answers $rAnswer. */
	private function answer(string $rMethod, array $rAnswer, array $rArguments = []): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'extract(' . var_export(['rMethod' => $rMethod, 'rAnswer' => $rAnswer, 'rArguments' => $rArguments], true) . ');';

		$rProc = proc_open([PHP_BINARY, '-r', $rCode . self::CALL], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rResult = json_decode(trim((string) $rOut), true);
		$this->assertIsArray($rResult, $rOut . $rErr);
		$this->assertArrayNotHasKey('error', $rResult, 'the answer stopped the page');

		return $rResult['result'];
	}

	/** Every value in $rRecord is a text or a number, or an http(s) address (or none) where an image is expected. */
	private function assertRecordIsPlain(array $rRecord, array $rImages): void {
		foreach ($rRecord as $rName => $rValue) {
			if (in_array($rName, $rImages, true)) {
				$this->assertIsString($rValue, $rName);
				$this->assertMatchesRegularExpression('#^(https?://.+)?$#', $rValue, $rName);
			} else {
				$this->assertTrue($rValue === null || is_scalar($rValue), $rName . ' is ' . gettype($rValue));
			}
		}
	}

	public function testAWellFormedCatalogueIsHandedOnAsItIs(): void {
		$this->assertSame([[
			'id' => 5, 'stream_id' => 5, 'name' => 'News 24', 'stream_display_name' => 'News 24',
			'logo' => self::IMAGE, 'stream_icon' => self::IMAGE, 'category_id' => 12, 'archive' => true,
			'url' => 'http://provider.test/live/viewer/secret/5.m3u8', 'epg_channel_id' => 'news24.uk',
		]], $this->answer('getLiveStreams', [
			['num' => 1, 'name' => 'News 24', 'stream_type' => 'live', 'stream_id' => 5, 'stream_icon' => self::IMAGE, 'epg_channel_id' => 'news24.uk', 'category_id' => '12', 'tv_archive' => 1],
		]));

		$this->assertSame([[
			'id' => 8, 'stream_id' => 8, 'title' => 'A Film', 'stream_display_name' => 'A Film',
			'poster' => self::IMAGE, 'stream_icon' => self::IMAGE, 'category_id' => 3, 'rating' => 7.5, 'year' => '2020',
			'container_extension' => 'mkv', 'url' => 'http://provider.test/movie/viewer/secret/8.mkv',
		]], $this->answer('getVodStreams', [
			['name' => 'A Film', 'stream_id' => '8', 'stream_icon' => self::IMAGE, 'rating' => 7.5, 'year' => '2020', 'category_id' => '3', 'container_extension' => 'mkv', 'custom_sid' => null],
		]));

		$this->assertSame([[
			'id' => 3, 'series_id' => 3, 'title' => 'A Show', 'stream_display_name' => 'A Show', 'cover' => self::IMAGE,
			'plot' => 'What happens.', 'cast' => 'One, Two', 'director' => 'Three', 'genre' => 'Drama', 'year' => '2019',
			'rating' => '8', 'category_id' => 4,
		]], $this->answer('getSeries', [
			['name' => 'A Show', 'series_id' => 3, 'cover' => self::IMAGE, 'plot' => 'What happens.', 'cast' => 'One, Two', 'director' => 'Three', 'genre' => 'Drama', 'releaseDate' => '2019-05-01', 'rating' => '8', 'backdrop_path' => [self::IMAGE], 'category_id' => '4'],
		]));
	}

	/** A record with a list where a text belongs keeps its place, with the page's default for that field. */
	public function testAFieldOfAnotherTypeIsLeftOutOfACatalogue(): void {
		$rList = ['a', 'list'];
		$rGood = ['name' => 'Good', 'stream_id' => 6, 'series_id' => 6, 'stream_icon' => self::IMAGE, 'cover' => self::IMAGE];
		$rBad = ['name' => $rList, 'stream_id' => 5, 'series_id' => 5, 'stream_icon' => $rList, 'cover' => $rList, 'epg_channel_id' => $rList, 'category_id' => $rList, 'rating' => $rList, 'year' => $rList, 'container_extension' => $rList, 'plot' => $rList, 'cast' => $rList, 'director' => $rList, 'genre' => $rList, 'releaseDate' => $rList];

		foreach (['getLiveStreams' => ['Channel #5', ['logo', 'stream_icon']], 'getVodStreams' => ['Movie #5', ['poster', 'stream_icon']], 'getSeries' => ['Series #5', ['cover']]] as $rMethod => [$rDefault, $rImages]) {
			$rRows = $this->answer($rMethod, [$rBad, 'not a record', $rGood]);

			$this->assertCount(2, $rRows, $rMethod);
			$this->assertRecordIsPlain($rRows[0], $rImages);
			$this->assertSame($rDefault, $rRows[0]['stream_display_name'], $rMethod);
			$this->assertSame('Good', $rRows[1]['stream_display_name'], $rMethod);
			$this->assertSame(self::IMAGE, $rRows[1][$rImages[0]], $rMethod);
		}
	}

	/** A name the server sent as a number reaches the browser as the text it is searched and sorted as. */
	public function testANameIsAText(): void {
		$this->assertSame('24', $this->answer('getLiveStreams', [['name' => 24, 'stream_id' => 5]])[0]['name']);
		$this->assertSame('24', $this->answer('getVodStreams', [['name' => 24, 'stream_id' => 5]])[0]['title']);
		$this->assertSame('24', $this->answer('getSeries', [['name' => 24, 'series_id' => 5]])[0]['title']);
	}

	public function testOnlyAnHttpAddressIsKeptAsAnImage(): void {
		foreach (self::NOT_IMAGES as $rAddress) {
			$rSaid = json_encode($rAddress);
			$this->assertSame('', $this->answer('getLiveStreams', [['stream_id' => 5, 'stream_icon' => $rAddress]])[0]['logo'], $rSaid);
			$this->assertSame('', $this->answer('getVodStreams', [['stream_id' => 5, 'stream_icon' => $rAddress]])[0]['poster'], $rSaid);
			$this->assertSame('', $this->answer('getSeries', [['series_id' => 5, 'cover' => $rAddress]])[0]['cover'], $rSaid);
		}

		$this->assertSame('HTTPS://img.test/a.png', $this->answer('getLiveStreams', [['stream_id' => 5, 'stream_icon' => 'HTTPS://img.test/a.png']])[0]['logo']);
	}

	/** The fields the movie pages read: as they came when they are what a page expects, left out otherwise. */
	public function testMovieDetails(): void {
		$rInfo = ['name' => 'A Film', 'movie_image' => self::IMAGE, 'cover_big' => self::IMAGE, 'backdrop_path' => [self::IMAGE, 'javascript:alert(1)'], 'plot' => 'What happens.', 'genre' => 'Drama', 'cast' => 'One', 'director' => 'Two', 'releasedate' => '2020-01-01', 'rating' => 7.5, 'duration_secs' => '5400', 'duration' => '01:30:00', 'video' => ['codec_name' => 'h264']];
		$rData = ['stream_id' => 8, 'name' => 'A Film', 'category_id' => '3', 'container_extension' => 'mkv'];

		// The pages do not read the technical description of the file.
		$rExpected = array_replace($rInfo, ['backdrop_path' => [self::IMAGE], 'duration_secs' => 5400]);
		unset($rExpected['video']);

		$rDetails = $this->answer('getVodInfo', ['info' => $rInfo, 'movie_data' => $rData], [8]);
		$this->assertSame($rExpected, $rDetails['info']);
		$this->assertSame(array_replace($rData, ['category_id' => 3]), $rDetails['movie_data']);

		// One address in place of the list is the list of that one.
		$this->assertSame([self::IMAGE], $this->answer('getVodInfo', ['info' => ['backdrop_path' => self::IMAGE]], [8])['info']['backdrop_path']);

		$rList = ['a', 'list'];
		$rDetails = $this->answer('getVodInfo', [
			'info' => ['name' => $rList, 'movie_image' => 'javascript:alert(1)', 'cover_big' => self::IMAGE, 'backdrop_path' => 'data:text/html,x', 'plot' => $rList, 'rating' => $rList, 'releasedate' => $rList, 'duration_secs' => 'long', 'duration' => $rList, 'category_id' => 'none'],
			'movie_data' => ['name' => 'From the stream', 'container_extension' => $rList, 'category_id' => $rList],
		], [8]);
		$this->assertSame(['cover_big' => self::IMAGE, 'backdrop_path' => []], $rDetails['info']);
		$this->assertSame(['name' => 'From the stream'], $rDetails['movie_data']);

		// An answer that is no description at all.
		$this->assertSame(['info' => [], 'movie_data' => []], $this->answer('getVodInfo', ['info' => 'none', 'movie_data' => 5], [8]));
		$this->assertSame(['info' => [], 'movie_data' => []], $this->answer('getVodInfo', [], [8]));
	}

	public function testSeriesDetails(): void {
		$rInfo = ['name' => 'A Show', 'cover' => self::IMAGE, 'plot' => 'What happens.', 'cast' => 'One, Two', 'genre' => 'Drama', 'releaseDate' => '2019-05-01', 'rating' => '8', 'category_id' => '4'];
		$rEpisode = ['id' => '101', 'episode_num' => 1, 'title' => 'Pilot', 'container_extension' => 'mp4', 'info' => ['movie_image' => self::IMAGE, 'duration_secs' => 2700, 'duration' => '00:45:00']];

		$rDetails = $this->answer('getSeriesInfo', ['info' => $rInfo, 'episodes' => ['1' => [$rEpisode], '2' => []]], [3]);
		$this->assertSame(array_replace($rInfo, ['category_id' => 4]), $rDetails['info']);
		$this->assertEquals([1 => [$rEpisode], 2 => []], $rDetails['episodes']);

		$rList = ['a', 'list'];
		$rDetails = $this->answer('getSeriesInfo', [
			'info' => ['name' => $rList, 'cover' => 'javascript:alert(1)', 'backdrop_path' => [$rList, self::IMAGE], 'cast' => $rList],
			'episodes' => ['1' => [
				['id' => '101', 'episode_num' => $rList, 'title' => $rList, 'container_extension' => $rList, 'info' => ['movie_image' => $rList, 'duration' => $rList, 'duration_secs' => $rList]],
				'not an episode',
				['id' => '102', 'info' => 'none'],
			], '2' => 'not a season'],
		], [3]);
		$this->assertSame(['backdrop_path' => [self::IMAGE]], $rDetails['info']);
		$this->assertEquals([1 => [['id' => '101', 'info' => []], ['id' => '102', 'info' => []]], 2 => []], $rDetails['episodes']);

		$this->assertSame(['info' => [], 'episodes' => []], $this->answer('getSeriesInfo', ['info' => 'none', 'episodes' => 'none'], [3]));
	}
}

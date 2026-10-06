<?php

use PHPUnit\Framework\TestCase;

/**
 * A line signed in to another provider's server searches that server's
 * catalogue in the web player: the channels, the films and the series whose
 * name holds the words searched for, each under its name.
 *
 * The search ends in exit(), so it runs in a child PHP, with the catalogue the
 * service keeps in the session given in place of the requests.
 */
final class AuditMiscExternalSearchTest extends TestCase {
	private const CHILD = <<<'PHP'
		session_start();
		// An address no request is sent to; the catalogue below is read from the session.
		$_SESSION['is_external_xc'] = true;
		$_SESSION['external_xc'] = ['server' => 'http://192.0.2.10:8080', 'username' => 'viewer', 'password' => 'secret'];

		$rService = new class () extends \XcVm\Domain\External\ExternalXtreamService {
			public function request(array $params = [], int $timeout = 10): ?array {
				return [
					'get_live_categories' => [['category_id' => '12', 'category_name' => 'News']],
					'get_live_streams' => [['name' => 'Film Channel', 'stream_id' => 5, 'category_id' => '12'], ['name' => 'News 24', 'stream_id' => 6, 'category_id' => '12']],
					'get_vod_categories' => [['category_id' => '3', 'category_name' => 'Cinema']],
					'get_vod_streams' => [['name' => 'A Film', 'stream_id' => 8, 'category_id' => '3', 'rating' => '7.5', 'year' => '2020'], ['name' => 'Another', 'stream_id' => 9, 'category_id' => '3']],
					'get_series_categories' => [['category_id' => '4', 'category_name' => 'Shows']],
					'get_series' => [['name' => 'Film Show', 'series_id' => 3, 'category_id' => '4', 'genre' => 'Drama'], ['name' => 'Other Show', 'series_id' => 4, 'category_id' => '4', 'genre' => 'Comedy']],
				][$params['action']];
			}
		};
		foreach (['getLiveCategories', 'getLiveStreams', 'getVodCategories', 'getVodStreams', 'getSeriesCategories', 'getSeries'] as $rMethod) {
			$rService->$rMethod();
		}

		$rSearch = new ReflectionMethod(\XcVm\Public\Controllers\PlayerV2\SearchController::class, 'handleExternalSearch');
		$rSearch->setAccessible(true);
		$rSearch->invoke(new \XcVm\Public\Controllers\PlayerV2\SearchController(), 'film', 'all', true);
		PHP;

	public function testASearchAnswersWithTheMatchingChannelsFilmsAndSeries(): void {
		$rDir = sys_get_temp_dir() . '/xcvm-ext-search-' . getmypid() . '-' . bin2hex(random_bytes(4));
		mkdir($rDir, 0700, true);

		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';' . self::CHILD;
		$rProc = proc_open(
			[PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'session.save_path=' . $rDir, '-d', 'session.use_cookies=0', '-d', 'session.cache_limiter=', '-r', $rCode],
			[0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$rPipes
		);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		$rExit = proc_close($rProc);
		exec('rm -rf ' . escapeshellarg($rDir));

		$this->assertSame(0, $rExit, $rErr . $rOut);
		$rAnswer = json_decode($rOut, true);
		$this->assertIsArray($rAnswer, $rErr . $rOut);

		$rTitles = array_map(static fn(array $rFound): array => array_column($rFound, 'title', 'id'), $rAnswer['results']);
		$this->assertSame(['live' => [5 => 'Film Channel'], 'movies' => [8 => 'A Film'], 'series' => [3 => 'Film Show'], 'episodes' => [], 'radio' => []], $rTitles);
		$this->assertSame(3, $rAnswer['total']);
	}
}

<?php

use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::buildSeriesImportArray() — the series-match branch extracted
 * out of run(). Covers both the "series already exists" path (getSeriesByTMDB
 * finds a `streams_series` row) and the "brand-new series" path (an INSERT).
 * The new-series path unconditionally calls getSeriesTrailer(), a real
 * file_get_contents() HTTP request to api.themoviedb.org with no seam of its
 * own — buildSeriesImportArray() takes it as an injectable $rFetchTrailer
 * callable instead (defaulting to the real getSeriesTrailer in production),
 * so tests supply a fake here. download_images is left off in every test so
 * ImageUtils::downloadImage() (also a real HTTP fetch) never fires.
 */
final class VodItemImporterSeriesBuildTest extends TestCase {

    private TestDb $db;
    private $lockFile;

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, `order` INTEGER);');
        $this->db->exec('CREATE TABLE streams_series (id INTEGER PRIMARY KEY AUTO_INCREMENT, tmdb_id INTEGER, title TEXT, category_id TEXT, episode_run_time INTEGER, cover TEXT, cover_big TEXT, genre TEXT, plot TEXT, cast TEXT, rating REAL, director TEXT, release_date TEXT, last_modified INTEGER, seasons TEXT, backdrop_path TEXT, youtube_trailer TEXT, year INTEGER, tmdb_language TEXT);');
        if (!defined('SERVER_ID')) {
            define('SERVER_ID', 1);
        }
        \XcVm\Infrastructure\Tmdb\TmdbApiService::requireLibrary();
        VodItemImporter::setDb($this->db);
        $this->lockFile = WATCH_TMP_PATH . 'lock_1399';
    }

    protected function tearDown(): void {
        @unlink($this->lockFile);
        @unlink(WATCH_TMP_PATH . 'series_1399');
    }

    private function threadData(array $rOverrides = array()): array {
        return array_merge(array(
            'duplicate_tmdb' => false,
            'read_native' => 0,
            'movie_symlink' => 0,
            'remove_subtitles' => 0,
            'transcode_profile_id' => 0,
            'import' => false,
            'direct_source' => null,
            'direct_proxy' => null,
            'max_genres' => 5,
            'language' => null,
        ), $rOverrides);
    }

    private function showFixture(array $rOverrides = array()): TVShow {
        return new TVShow(array_merge(array(
            'id' => 1399,
            'name' => 'Game of Thrones',
            'seasons' => array(array('poster_path' => '/s1.jpg')),
            'overview' => 'Nine noble families fight for control.',
            'vote_average' => 8.4,
            'first_air_date' => '2011-04-17',
            'backdrop_path' => '/backdrop.jpg',
            'poster_path' => '/poster.jpg',
            'genres' => array(array('id' => 18, 'name' => 'Drama')),
            'credits' => array('cast' => array(), 'crew' => array()),
            'episode_run_time' => array(60),
        ), $rOverrides));
    }

    private function seasonFixture(array $rEpisodes): Season {
        return new Season(array('episodes' => $rEpisodes));
    }

    public function testUpdatesAnExistingSeriesSeasonsInsteadOfInserting(): void {
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()),
        ));

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => 1), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/s01e01.mkv', 2, 60, 1, 1, array(), array(), array(), null
        );

        $this->assertSame(5, $rBuilt['series']['id']);
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
        $this->assertSame(1, (int) $this->db->get_col());
        $this->db->query('SELECT `seasons` FROM `streams_series` WHERE `id` = 5;');
        $this->assertNotSame('[]', $this->db->get_col());
    }

    public function testBuildsTheEpisodeDisplayNameAndProperties(): void {
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        $rEpisode = array('episode_number' => 1, 'name' => 'Winter Is Coming', 'id' => 111, 'air_date' => '2011-04-17', 'overview' => 'Ned Stark...', 'vote_average' => 8.0, 'still_path' => null);
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array($rEpisode)),
        ));

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => 1), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/s01e01.mkv', 2, 60, 1, 1, array(), array(), array(), null
        );

        $this->assertSame('Game of Thrones - S01E01 - Winter Is Coming', $rBuilt['importArray']['stream_display_name']);
        $this->assertSame(111, $rBuilt['importArray']['movie_properties']['tmdb_id']);
        $this->assertArrayNotHasKey('movie_image', $rBuilt['importArray']['movie_properties']);
    }

    public function testBuildsADoubleEpisodeDisplayNameForATwoPartRelease(): void {
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()),
        ));

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => array(1, 2)), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/s01e01e02.mkv', 2, 60, 1, 1, array(), array(), array(), null
        );

        $this->assertSame('Game of Thrones - S01E01-02', $rBuilt['importArray']['stream_display_name']);
    }

    public function testFallsBackToNoEpisodeTitleWhenTheEpisodeIsNotInTheSeasonData(): void {
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()), // no episodes at all
        ));

        // Episode number never matches any entry from getSeason(), so the
        // stream_display_name built from the show name + SxxExx is
        // immediately overwritten by the still-empty check... actually the
        // SxxExx prefix IS non-empty, so this asserts the prefix survives
        // when no per-episode title/data was found to append to it.
        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => 1), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/s01e01.mkv', 2, 60, 1, 99, array(), array(), array(), null
        );

        $this->assertSame('Game of Thrones - S01E99', $rBuilt['importArray']['stream_display_name']);
    }

    public function testSkipsEpisodeLookupEntirelyWhenSeasonOrEpisodeIsUnparsed(): void {
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        // getSeason must never be called here — asserted by making it throw.
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => function () {
                throw new \RuntimeException('getSeason() must not be called without a parsed season/episode');
            },
        ));

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, null, $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/unknown.mkv', 2, 60, null, null, array(), array(), array(), null
        );

        $this->assertArrayNotHasKey('stream_display_name', $rBuilt['importArray']);
    }

    public function testCreatesANewSeriesRecordUsingTheInjectedTrailerFetcher(): void {
        // No pre-existing streams_series row for tmdb_id=1399: getSeriesByTMDB()
        // returns null, so this exercises the INSERT branch instead of UPDATE.
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()),
        ));
        $rTrailerCalls = array();
        $rFetchTrailer = function ($rID, $rLang) use (&$rTrailerCalls) {
            $rTrailerCalls[] = array($rID, $rLang);
            return 'trailer-key';
        };

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => 1), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array(18 => array('category_id' => 5, 'bouquets' => '[]'))),
            '/media/got/s01e01.mkv', 2, 60, 1, 1, array(), array(), array(), null, $rFetchTrailer
        );

        $this->assertSame(array(array(1399, null)), $rTrailerCalls);
        $this->assertNotNull($rBuilt['series']);
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
        $this->assertSame(1, (int) $this->db->get_col());
        $this->db->query('SELECT `tmdb_id`, `title`, `youtube_trailer`, `category_id`, `genre` FROM `streams_series` WHERE `id` = ?;', $rBuilt['series']['id']);
        $rRow = $this->db->get_row();
        $this->assertSame('1399', (string) $rRow['tmdb_id']);
        $this->assertSame('Game of Thrones', $rRow['title']);
        $this->assertSame('trailer-key', $rRow['youtube_trailer']);
        $this->assertSame('[5]', $rRow['category_id']);
        $this->assertSame('Drama', $rRow['genre']);
    }

    public function testNewSeriesWithNoCategoryResolvedHaltsInsteadOfInserting(): void {
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()),
        ));

        try {
            VodItemImporter::buildSeriesImportArray(
                $rTMDB, $rMatch, array('episode' => 1), $this->threadData(), array('download_images' => false, 'tmdb_language' => null),
                array(1 => array(), 2 => array()), // no watch_categories mapping for genre 18
                '/media/got/s01e01.mkv', 2, 60, 1, 1, array(), array(), array(), null,
                fn() => 'trailer-key'
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (\XcVm\Domain\Vod\VodItemImportHalt) {
        }

        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
        $this->assertSame(0, (int) $this->db->get_col());
        $this->assertFileDoesNotExist($this->lockFile);
    }

    public function testAppliesCommonStreamSettingsWithEnableTranscodeLeftOff(): void {
        // Series-match historically never sets enable_transcode (unlike
        // movie-match and no-match) — applyCommonStreamSettings($set=false).
        $this->db->query("INSERT INTO streams_series (id, tmdb_id, seasons) VALUES (5, 1399, '[]');");
        $rMatch = new TVShow(array('id' => 1399));
        $rTMDB = new FakeTmdbClient(array(
            'getTVShow' => fn() => $this->showFixture(),
            'getSeason' => fn() => $this->seasonFixture(array()),
        ));

        $rBuilt = VodItemImporter::buildSeriesImportArray(
            $rTMDB, $rMatch, array('episode' => 1), $this->threadData(array('transcode_profile_id' => 7)), array('download_images' => false, 'tmdb_language' => null),
            array(1 => array(), 2 => array()), '/media/got/s01e01.mkv', 2, 60, 1, 1, array(), array(), array(), null
        );

        $this->assertSame(7, $rBuilt['importArray']['transcode_profile_id']);
        $this->assertArrayNotHasKey('enable_transcode', $rBuilt['importArray']);
    }
}

<?php

use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Domain\Vod\VodItemImporter;
use XcVm\Domain\Vod\VodItemImportHalt;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::persistImport() — every path ends by throwing VodItemImportHalt
 * (the final INSERT into `streams` either succeeds or fails, and both the
 * "no category resolved" / "no series" guards bail out early), so each test
 * asserts DB state first and expects the halt last. The "INSERT into streams
 * failed" branch isn't covered: TestDb (PDO in exception mode) throws
 * PDOException on a bad query instead of returning false the way the
 * production Database wrapper apparently does, so there's no way to reach
 * that branch here without a fake that doesn't prove anything real.
 */
final class VodItemImporterPersistTest extends TestCase {

    private TestDb $db;
    /** @var VodImportResultEvent[] */
    private array $results = array();

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTO_INCREMENT, category_id TEXT, series_no INTEGER, movie_subtitles TEXT, added INTEGER, stream_display_name TEXT);');
        $this->db->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, parent_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_episodes (season_num INTEGER, series_id INTEGER, stream_id INTEGER, episode_num INTEGER);');
        if (!defined('SERVER_ID')) {
            define('SERVER_ID', 1);
        }
        VodItemImporter::setDb($this->db);
        \XcVm\Domain\Bouquet\BouquetService::setDb($this->db);
        EventDispatcher::listen(VodImportResultEvent::class, function (VodImportResultEvent $rEvent): void {
            $this->results[] = $rEvent;
        });
        // The importer reports its progress on stdout for the CLI run: not this test's output.
        ob_start();
    }

    protected function tearDown(): void {
        ob_end_clean();
        EventDispatcher::unlisten(VodImportResultEvent::class);
    }

    private function threadData(array $rOverrides = array()): array {
        return array_merge(array(
            'type' => 'movie',
            'fb_category_id' => null,
            'fb_bouquets' => null,
            'subtitles' => null,
            'import' => false,
            'auto_encode' => false,
        ), $rOverrides);
    }

    private function logStatus(): ?int {
        $rLast = end($this->results);
        return $rLast ? $rLast->status : null;
    }

    public function testMovieWithNoCategoryResolvedHaltsWithoutInserting(): void {
        try {
            VodItemImporter::persistImport($this->threadData(), 1, '/media/movie.mkv', null, null, null, null, array(), array(), array());
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(3, $this->logStatus());
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testMovieFallsBackToFbCategoryIdWhenNoCategoryWasResolved(): void {
        try {
            VodItemImporter::persistImport($this->threadData(array('fb_category_id' => 9)), 1, '/media/movie.mkv', null, null, null, null, array(), array(), array());
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(1, $this->logStatus());
        $this->db->query('SELECT `category_id` FROM `streams` LIMIT 1;');
        $this->assertSame('[9]', $this->db->get_col());
    }

    public function testMovieInsertsIntoStreamsAndLinksTheServerAndBouquet(): void {
        // import=true routes addToBouquet() through BouquetService (DB write)
        // instead of queuing a .bouquet file for checkBouquets() to pick up.
        $this->db->exec('CREATE TABLE bouquets (id INTEGER PRIMARY KEY AUTO_INCREMENT, bouquet_movies TEXT, bouquet_series TEXT);');
        $this->db->query("INSERT INTO bouquets (id, bouquet_movies, bouquet_series) VALUES (1, '[]', '[]');");

        try {
            VodItemImporter::persistImport(
                $this->threadData(array('import' => true, 'servers' => array(1))), 1, '/media/movie.mkv',
                null, null, null, null, array('stream_display_name' => 'The Matrix'), array(5), array(1)
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(1, $this->logStatus());
        $this->db->query('SELECT `stream_display_name`, `category_id` FROM `streams` WHERE `id` = 1;');
        $rRow = $this->db->get_row();
        $this->assertSame('The Matrix', $rRow['stream_display_name']);
        $this->assertSame('[5]', $rRow['category_id']);
        $this->db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = 1;');
        $this->assertSame((string) SERVER_ID, (string) $this->db->get_col());
        $this->db->query('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 1;');
        $this->assertSame('[1]', $this->db->get_col());
    }

    public function testSeriesWithNoSeriesRecordHaltsWithoutInserting(): void {
        try {
            VodItemImporter::persistImport($this->threadData(array('type' => 'series')), 2, '/media/ep.mkv', null, null, 1, 1, array(), array(), array());
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(4, $this->logStatus());
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }

    public function testSeriesInsertsIntoStreamsAndStreamsEpisodes(): void {
        try {
            VodItemImporter::persistImport(
                $this->threadData(array('type' => 'series')), 2, '/media/ep.mkv', null, array('id' => 7),
                3, 5, array('stream_display_name' => 'Show - S03E05'), array(), array()
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->assertSame(1, $this->logStatus());
        $this->db->query('SELECT `series_no` FROM `streams` WHERE `id` = 1;');
        $this->assertSame('7', (string) $this->db->get_col());
        $this->db->query('SELECT `season_num`, `series_id`, `episode_num` FROM `streams_episodes` WHERE `stream_id` = 1;');
        $rRow = $this->db->get_row();
        $this->assertSame('3', (string) $rRow['season_num']);
        $this->assertSame('7', (string) $rRow['series_id']);
        $this->assertSame('5', (string) $rRow['episode_num']);
    }

    public function testCopiesThreadDataSubtitlesOntoTheImportRow(): void {
        try {
            VodItemImporter::persistImport(
                $this->threadData(array('subtitles' => array('files' => array('/x.srt')))), 1, '/media/movie.mkv',
                null, null, null, null, array(), array(5), array()
            );
            $this->fail('Expected VodItemImportHalt');
        } catch (VodItemImportHalt) {
        }

        $this->db->query('SELECT `movie_subtitles` FROM `streams` LIMIT 1;');
        $this->assertSame(json_encode(array('files' => array('/x.srt'))), $this->db->get_col());
    }
}

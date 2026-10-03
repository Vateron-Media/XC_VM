<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::run() end to end for the Series → Import (M3U) path: the
 * thread data SeriesService::import() hands to `vod_import_item`, a fake TMDb
 * client, and an existing series row (a brand-new series would fetch its
 * trailer over HTTP). Under SQLite, QueryHelper::verifyPostTable()'s
 * `information_schema.columns` is an attached table.
 */
final class VodItemImporterRunTest extends TestCase {

    private const STREAM_COLUMNS = array(
        'type' => 'int', 'stream_source' => 'mediumtext', 'target_container' => 'varchar', 'read_native' => 'tinyint',
        'movie_symlink' => 'tinyint', 'remove_subtitles' => 'tinyint', 'transcode_profile_id' => 'int',
        'direct_source' => 'tinyint', 'direct_proxy' => 'tinyint', 'order' => 'int', 'stream_display_name' => 'mediumtext',
        'movie_properties' => 'mediumtext', 'movie_subtitles' => 'mediumtext', 'series_no' => 'int', 'added' => 'int',
        'tmdb_language' => 'varchar',
    );

    private TestDb $db;
    private array $settingsBefore;
    private $globalDbBefore;
    /** @var VodImportResultEvent[] */
    private array $results = array();

    protected function setUp(): void {
        if (!defined('SERVER_ID')) {
            define('SERVER_ID', 1);
        }
        \XcVm\Infrastructure\Tmdb\TmdbApiService::requireLibrary();

        $this->db = new TestDb();
        if ($this->db->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $this->markTestSkipped('Builds its own information_schema; SQLite only.');
        }
        $rColumns = array();
        foreach (array_keys(self::STREAM_COLUMNS) as $rName) {
            $rColumns[] = '`' . $rName . '` TEXT';
        }
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, ' . implode(', ', $rColumns) . ');');
        $this->db->exec('CREATE TABLE streams_servers (stream_id INTEGER, server_id INTEGER, parent_id INTEGER);');
        $this->db->exec('CREATE TABLE streams_episodes (season_num INTEGER, series_id INTEGER, stream_id INTEGER, episode_num INTEGER);');
        $this->db->exec('CREATE TABLE streams_series (id INTEGER PRIMARY KEY AUTOINCREMENT, tmdb_id INTEGER, title TEXT, seasons TEXT);');
        $this->db->exec("INSERT INTO streams_series (id, tmdb_id, title, seasons) VALUES (5, 1399, 'Les Psys', '[]');");

        $this->db->pdo->exec("ATTACH DATABASE ':memory:' AS `information_schema`");
        $this->db->pdo->exec('CREATE TABLE `information_schema`.`columns` (`table_schema` text, `table_name` text, `column_name` text, `column_default` text, `is_nullable` text, `data_type` text, `ordinal_position` int)');
        $this->db->pdo->sqliteCreateFunction('DATABASE', static fn(): string => 'xc_vm', 0);
        $rPosition = 0;
        foreach (self::STREAM_COLUMNS as $rName => $rType) {
            $this->db->query('INSERT INTO `information_schema`.`columns` VALUES (?, ?, ?, ?, ?, ?, ?)', 'xc_vm', 'streams', $rName, 'NULL', 'YES', $rType, ++$rPosition);
        }

        VodItemImporter::setDb($this->db);
        $this->globalDbBefore = $GLOBALS['db'] ?? null;
        $GLOBALS['db'] = $this->db;
        $this->settingsBefore = SettingsManager::getAll();
        SettingsManager::set(array('tmdb_api_key' => '', 'tmdb_language' => '', 'parse_type' => 'guessit', 'fallback_parser' => 0, 'percentage_match' => 80, 'download_images' => 0));
        EventDispatcher::listen(VodImportResultEvent::class, function (VodImportResultEvent $rEvent): void {
            $this->results[] = $rEvent;
        });
    }

    protected function tearDown(): void {
        EventDispatcher::unlisten(VodImportResultEvent::class);
        SettingsManager::set($this->settingsBefore);
        $GLOBALS['db'] = $this->globalDbBefore;
        @unlink(WATCH_TMP_PATH . 'lock_1399');
        @unlink(WATCH_TMP_PATH . 'series_1399');
    }

    /** The payload SeriesService::import() builds for one M3U entry. */
    private function threadData(): array {
        return array(
            'import' => true, 'type' => 'series', 'title' => 'Les Psys (2026) - S01E02 - Épisode 2',
            'file' => 'http://provider.example/series/u/p/377778.mkv', 'subtitles' => array(), 'servers' => array(SERVER_ID),
            'fb_category_id' => array(7), 'fb_bouquets' => array(), 'disable_tmdb' => false, 'ignore_no_match' => false,
            'bouquets' => array(), 'category_id' => array(), 'language' => '', 'watch_categories' => array(),
            'read_native' => 0, 'movie_symlink' => 0, 'remove_subtitles' => 0, 'direct_source' => 0, 'direct_proxy' => 0,
            'auto_encode' => false, 'auto_upgrade' => false, 'fallback_title' => false, 'ffprobe_input' => false,
            'transcode_profile_id' => null, 'target_container' => 'mkv', 'max_genres' => 0, 'duplicate_tmdb' => true,
        );
    }

    private function tmdb(array $rSearchResults): FakeTmdbClient {
        return new FakeTmdbClient(array(
            'searchTVShow' => fn() => $rSearchResults,
            'getTVShow' => fn() => new TVShow(array(
                'id' => 1399, 'name' => 'Les Psys', 'seasons' => array(array('poster_path' => '/s1.jpg')),
                'genres' => array(), 'credits' => array('cast' => array(), 'crew' => array()), 'episode_run_time' => array(45),
            )),
            'getSeason' => fn() => new Season(array('episodes' => array(
                array('episode_number' => 2, 'name' => 'Épisode 2', 'id' => 222, 'air_date' => '2026-01-08', 'overview' => '', 'vote_average' => 7.1, 'still_path' => null),
            ))),
        ));
    }

    public function testImportsAnM3uEpisodeIntoTheExistingSeries(): void {
        $this->expectOutputRegex('/Success!/');

        VodItemImporter::run($this->threadData(), 60, $this->tmdb(array(new TVShow(array('id' => 1399, 'name' => 'Les Psys', 'first_air_date' => '2026-01-01')))));

        $this->assertCount(1, $this->results);
        $this->assertSame(VodImportResultEvent::STATUS_IMPORTED, $this->results[0]->status);
        $this->assertSame(2, $this->results[0]->type);
        $rStreamID = $this->results[0]->streamId;

        $this->db->query('SELECT `stream_display_name`, `series_no`, `stream_source` FROM `streams` WHERE `id` = ?;', $rStreamID);
        $rRow = $this->db->get_row();
        $this->assertSame('Les Psys - S01E02 - Épisode 2', $rRow['stream_display_name']);
        $this->assertSame(5, (int) $rRow['series_no']);
        $this->assertSame(array('http://provider.example/series/u/p/377778.mkv'), json_decode($rRow['stream_source'], true));

        $this->db->query('SELECT `season_num`, `series_id`, `episode_num` FROM `streams_episodes` WHERE `stream_id` = ?;', $rStreamID);
        $this->assertSame(array(1, 5, 2), array_map('intval', array_values($this->db->get_row())));
        $this->db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);
        $this->assertSame((string) SERVER_ID, (string) $this->db->get_col());
    }

    public function testRunPayloadRefusesAnythingButBase64Json(): void {
        $this->expectOutputString("vod_import_item: payload must be base64-encoded JSON object\nvod_import_item: payload must be base64-encoded JSON object\nvod_import_item: unsupported type\n");

        VodItemImporter::runPayload('not base64!');
        VodItemImporter::runPayload(base64_encode('"a string"'));
        VodItemImporter::runPayload(base64_encode(json_encode(array('file' => 'x', 'import' => true, 'type' => 'radio'))));

        $this->assertSame(array(), $this->results);
    }

    public function testTheCommandRunsOnlyAsXcVm(): void {
        if (posix_getpwuid(posix_geteuid())['name'] === 'xc_vm') {
            $this->markTestSkipped('Running as xc_vm.');
        }
        $this->expectOutputString("Please run as XC_VM!\n");

        $this->assertSame(1, (new \XcVm\Cli\Commands\VodImportItemCommand())->execute(array(base64_encode('{}'))));
    }

    public function testReportsNoMatchAndInsertsNothingWhenTmdbFindsNothing(): void {
        $this->expectOutputRegex('/No match!/');

        VodItemImporter::run($this->threadData(), 60, $this->tmdb(array()));

        $this->assertCount(1, $this->results);
        $this->assertSame(VodImportResultEvent::STATUS_NO_MATCH, $this->results[0]->status);
        $this->db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
        $this->assertSame(0, (int) $this->db->get_col());
    }
}

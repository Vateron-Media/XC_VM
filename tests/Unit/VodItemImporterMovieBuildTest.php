<?php

use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::buildMovieImportArray() — the movie-match branch extracted out
 * of run(). Only covers the "no existing import to upgrade" path: getMovie()
 * looks up a WATCH_TMP_PATH cache file by TMDb id, and when that file is
 * absent (the common case — nothing cached from a prior import yet) it
 * returns null and applyUpgrade() (which can exit() the process) never runs.
 * download_images is left off in every test so ImageUtils::downloadImage()
 * (a real HTTP fetch) never fires.
 */
final class VodItemImporterMovieBuildTest extends TestCase {

    private TestDb $db;

    protected function setUp(): void {
        $this->db = new TestDb();
        $this->db->exec('CREATE TABLE streams (id INTEGER PRIMARY KEY AUTOINCREMENT, `order` INTEGER);');
        VodItemImporter::setDb($this->db);
        \XcVm\Infrastructure\Tmdb\TmdbApiService::requireLibrary();
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
        ), $rOverrides);
    }

    private function movieFixture(array $rOverrides = array()): Movie {
        return new Movie(array_merge(array(
            'id' => 603,
            'title' => 'The Matrix',
            'original_title' => 'The Matrix',
            'poster_path' => '/poster.jpg',
            'backdrop_path' => '/backdrop.jpg',
            'release_date' => '1999-03-30',
            'runtime' => 136,
            'vote_average' => 8.2,
            'overview' => 'A hacker discovers reality is a simulation.',
            'production_countries' => array(array('name' => 'United States of America')),
            'genres' => array(array('id' => 28, 'name' => 'Action'), array('id' => 878, 'name' => 'Science Fiction')),
            'credits' => array(
                'cast' => array(array('name' => 'Keanu Reeves')),
                'crew' => array(array('name' => 'Lana Wachowski', 'department' => 'Directing', 'known_for_department' => 'Directing')),
            ),
            'trailers' => array(),
        ), $rOverrides));
    }

    public function testFillsBasicStreamDisplayFieldsFromTmdb(): void {
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(), array('download_images' => false),
            array(1 => array(), 2 => array()), '/media/movie.mkv', 1, array(), array(), array(), null
        );

        $this->assertSame('The Matrix', $rBuilt['importArray']['stream_display_name']);
        $this->assertSame(1999, $rBuilt['importArray']['year']);
        $this->assertSame(603, $rBuilt['importArray']['tmdb_id']);
        $this->assertSame(8.2, $rBuilt['importArray']['rating']);
    }

    public function testBuildsMoviePropertiesFromCastCrewAndGenres(): void {
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(), array('download_images' => false),
            array(1 => array(), 2 => array()), '/media/movie.mkv', 1, array(), array(), array(), null
        );

        $rProps = $rBuilt['importArray']['movie_properties'];
        $this->assertSame('Keanu Reeves', $rProps['cast']);
        $this->assertSame('Lana Wachowski', $rProps['director']);
        $this->assertSame('Action, Science Fiction', $rProps['genre']);
        $this->assertSame('United States of America', $rProps['country']);
        $this->assertSame(8160, $rProps['duration_secs']); // 136 min * 60
    }

    public function testResolvesCategoryAndBouquetIdsFromGenresWhenNoneGiven(): void {
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));
        $rWatchCategories = array(
            1 => array(28 => array('category_id' => 5, 'bouquets' => '[10]'), 878 => array('category_id' => 6, 'bouquets' => '[11]')),
            2 => array(),
        );

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(), array('download_images' => false),
            $rWatchCategories, '/media/movie.mkv', 1, array(), array(), array(), null
        );

        $this->assertSame(array(5, 6), $rBuilt['categoryIDs']);
        $this->assertSame(array(10, 11), $rBuilt['bouquetIDs']);
    }

    public function testPreSuppliedCategoryAndBouquetIdsAreLeftUntouched(): void {
        // category_id/bouquets given explicitly by the watch-folder config
        // (or an import call) always win over genre-derived resolution.
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(), array('download_images' => false),
            array(1 => array(), 2 => array()), '/media/movie.mkv', 1, array(), array(99), array(88), null
        );

        $this->assertSame(array(99), $rBuilt['categoryIDs']);
        $this->assertSame(array(88), $rBuilt['bouquetIDs']);
    }

    public function testSkipsTheUpgradeCheckEntirelyWhenDuplicateTmdbIsSet(): void {
        // duplicate_tmdb bypasses getMovie() (the cache lookup) altogether —
        // asserted indirectly: with no cache file AND duplicate_tmdb off this
        // would already skip applyUpgrade(), so this only guards against a
        // regression that makes duplicate_tmdb=true call getMovie() anyway
        // (which would still resolve to null here, so the real risk is a
        // typo inverting the condition and skipping the *rest* of the build).
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(array('duplicate_tmdb' => true)), array('download_images' => false),
            array(1 => array(), 2 => array()), '/media/movie.mkv', 1, array(), array(), array(), null
        );

        $this->assertSame('The Matrix', $rBuilt['importArray']['stream_display_name']);
    }

    public function testAppliesCommonStreamSettingsFromThreadData(): void {
        $rMatch = new Movie(array('id' => 603));
        $rTMDB = new FakeTmdbClient(array('getMovie' => fn() => $this->movieFixture()));

        $rBuilt = VodItemImporter::buildMovieImportArray(
            $rTMDB, $rMatch, $this->threadData(array('transcode_profile_id' => 7)), array('download_images' => false),
            array(1 => array(), 2 => array()), '/media/movie.mkv', 1, array(), array(), array(), 'en'
        );

        $this->assertSame(7, $rBuilt['importArray']['transcode_profile_id']);
        $this->assertSame(1, $rBuilt['importArray']['enable_transcode']);
        $this->assertSame('en', $rBuilt['importArray']['tmdb_language']);
    }
}

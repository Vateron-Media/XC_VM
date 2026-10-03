<?php

use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * VodItemImporter::findBestTmdbMatch() — the TMDb search/scoring core extracted out
 * of run(). Driven entirely by FakeTmdbClient so it never touches the
 * network; the real \TMDB class cannot even be constructed offline (its
 * constructor makes a live curl_exec() and exit()s on failure).
 */
final class VodItemImporterMatchTest extends TestCase {

    protected function setUp(): void {
        \XcVm\Infrastructure\Tmdb\TmdbApiService::requireLibrary();
    }

    private function threadData(array $rOverrides = array()): array {
        return array_merge(array(
            'type' => 'movie',
            'alternative_titles' => false,
        ), $rOverrides);
    }

    private function settings(array $rOverrides = array()): array {
        return array_merge(array('percentage_match' => 80), $rOverrides);
    }

    public function testExactTitleMatchScoresOneHundredPercent(): void {
        $rMovie = new Movie(array('id' => 603, 'title' => 'The Matrix', 'original_title' => 'The Matrix', 'release_date' => '1999-03-30'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => fn() => array($rMovie),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'The Matrix', null, 1999);

        $this->assertNotNull($rResult);
        $this->assertSame(603, $rResult->get('id'));
    }

    public function testBelowThresholdSimilarityFindsNoMatch(): void {
        $rMovie = new Movie(array('id' => 1, 'title' => 'Completely Different Name', 'release_date' => '1999-01-01'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => fn() => array($rMovie),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'The Matrix', null, 1999);

        $this->assertNull($rResult);
    }

    public function testYearOutsideToleranceRejectsAnOtherwiseGoodTitleMatch(): void {
        // "The Matrix" 2010 re-release listing vs a 1999 search: title matches
        // exactly but the release year is outside the ±1 tolerance window.
        $rMovie = new Movie(array('id' => 603, 'title' => 'The Matrix', 'release_date' => '2010-01-01'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => function ($rTitle, $rYear) use ($rMovie) {
                // Only the year-scoped search call returns the candidate; the
                // retry-without-year call (below) returns nothing, so we can
                // tell which pass rejected it.
                return $rYear ? array($rMovie) : array();
            },
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'The Matrix', null, 1999);

        $this->assertNull($rResult);
    }

    public function testRetriesWithoutYearWhenTheYearScopedSearchFindsNothing(): void {
        $rMovie = new Movie(array('id' => 603, 'title' => 'The Matrix', 'release_date' => '1999-03-30'));
        $rTMDB = new FakeTmdbClient(array(
            // First call carries the year and finds nothing; the retry with
            // $rYear === null must be the one that returns the candidate.
            'searchMovie' => function ($rTitle, $rYear) use ($rMovie) {
                return $rYear === null ? array($rMovie) : array();
            },
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'The Matrix', 1999, 1999);

        $this->assertNotNull($rResult);
        $this->assertSame(603, $rResult->get('id'));
    }

    public function testExactAltTitleMatchWinsEvenBelowThePercentageThreshold(): void {
        // Alt-title exact match is a 100%-shortcut regardless of the primary
        // title's similarity score, as long as it clears percentage_match
        // via $rPercentageAlt (computed the same way as $rPercentage).
        $rMovie = new Movie(array('id' => 42, 'title' => 'Alt Title Exact', 'original_title' => 'Alt Title Exact', 'release_date' => '2020-01-01'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => fn() => array($rMovie),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'Unrelated Parsed Title', 'Alt Title Exact', 2020);

        $this->assertNotNull($rResult);
        $this->assertSame(42, $rResult->get('id'));
    }

    public function testPicksTheHighestScoringCandidateAmongMultipleMatches(): void {
        $rWeak = new Movie(array('id' => 1, 'title' => 'The Matriz', 'release_date' => '1999-01-01'));
        $rStrong = new Movie(array('id' => 2, 'title' => 'The Matrix', 'release_date' => '1999-01-01'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => fn() => array($rWeak, $rStrong),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(['percentage_match' => 50]), 'The Matrix', null, 1999);

        $this->assertNotNull($rResult);
        $this->assertSame(2, $rResult->get('id'));
    }

    public function testSeriesSearchUsesTheTvShowEndpointAndNameField(): void {
        $rShow = new TVShow(array('id' => 1399, 'name' => 'Game of Thrones', 'first_air_date' => '2011-04-17'));
        $rTMDB = new FakeTmdbClient(array(
            'searchTVShow' => fn() => array($rShow),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(array('type' => 'series')), $this->settings(), 'Game of Thrones', null, 2011);

        $this->assertNotNull($rResult);
        $this->assertSame(1399, $rResult->get('id'));
    }

    public function testAlternativeTitlesFallbackMatchesViaTmdbAlternativeTitlesLookup(): void {
        // Primary/original title both score below threshold (too much extra
        // text in the parsed release name), but the release year matches and
        // the parsed title is left-anchored on the TMDb title ("Boston Blue
        // Rewatch 2025" starts with "Boston Blue") — that unlocks a
        // getMovieTitles() lookup, where an alternative title matches the
        // parsed release title exactly.
        $rMovie = new Movie(array('id' => 9, 'title' => 'Boston Blue', 'original_title' => 'Boston Blue', 'release_date' => '2025-01-01'));
        $rTMDB = new FakeTmdbClient(array(
            'searchMovie' => fn() => array($rMovie),
            'getMovieTitles' => fn() => array('titles' => array(array('title' => 'Boston Blue Rewatch 2025'))),
        ));

        $rResult = VodItemImporter::findBestTmdbMatch(
            $rTMDB,
            $this->threadData(array('alternative_titles' => true)),
            $this->settings(['percentage_match' => 95]),
            'Boston Blue Rewatch 2025',
            null,
            2025
        );

        $this->assertNotNull($rResult);
        $this->assertSame(9, $rResult->get('id'));
    }

    public function testNoResultsAtAllYieldsNoMatch(): void {
        $rTMDB = new FakeTmdbClient();

        $rResult = VodItemImporter::findBestTmdbMatch($rTMDB, $this->threadData(), $this->settings(), 'Anything', null, null);

        $this->assertNull($rResult);
    }
}

<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * A series file that names its season and no episode number: the episode is
 * found by its title among the TMDb season's (matchEpisodeByTitle()), from
 * the title the name carries (releaseEpisodeTitle()), against a season
 * fetched once and kept (seasonEpisodes()). The match is refused rather than
 * guessed: a wrongly linked episode is worse than a file left unmatched.
 * run() with the option on and off is in VodItemImporterRunTest.
 */
final class VodItemImporterEpisodeByTitleTest extends TestCase {

    private const SEASON = array(1 => 'Dragonstone', 2 => 'Stormborn', 3 => 'The Long Night', 4 => 'The Longest Day');

    private array $settingsBefore;

    protected function setUp(): void {
        \XcVm\Infrastructure\Tmdb\TmdbApiService::requireLibrary();
        $this->settingsBefore = SettingsManager::getAll();
        SettingsManager::set(array('tmdb_language' => 'fr-FR'));
    }

    protected function tearDown(): void {
        SettingsManager::set($this->settingsBefore);
        array_map('unlink', glob(WATCH_TMP_PATH . 'season_990352_*.cache') ?: array());
    }

    public function testAnExactTitleNamesItsEpisode(): void {
        $this->assertSame(3, VodItemImporter::matchEpisodeByTitle('The Long Night', self::SEASON, $rScore));
        $this->assertEqualsWithDelta(100.0, $rScore, 0.01);
    }

    public function testCaseAndPunctuationDoNotCount(): void {
        $this->assertSame(3, VodItemImporter::matchEpisodeByTitle('the.long_night!', self::SEASON));
        $this->assertSame(2, VodItemImporter::matchEpisodeByTitle('STORMBORN', array(1 => 'Dragonstone', 2 => 'Storm-born?')));
    }

    public function testAClearWinnerIsTakenThoughNotExact(): void {
        $this->assertSame(3, VodItemImporter::matchEpisodeByTitle('The Long Nite', array(1 => 'Dragonstone', 2 => 'Stormborn', 3 => 'The Long Night'), $rScore));
        $this->assertEqualsWithDelta(88.9, $rScore, 0.1);
        // 100 against 87 for "Chapter Nine": numbered titles are still told apart.
        $this->assertSame(1, VodItemImporter::matchEpisodeByTitle('Chapter One', array(1 => 'Chapter One', 2 => 'Chapter Two', 9 => 'Chapter Nine')));
    }

    public function testATitleBelowSixtyFivePercentIsRefused(): void {
        // 64.3% for "The Longest Day", and nothing closer.
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Long Nite', array(1 => 'Dragonstone', 4 => 'The Longest Day'), $rScore));
        $this->assertNull($rScore);
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('Something Else Entirely', self::SEASON));
    }

    public function testPartOneAndPartTwoAreNeverToldApart(): void {
        $rSeason = array(1 => 'Dragonstone', 5 => 'The Beginning (Part 1)', 6 => 'The Beginning (Part 2)');

        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Beginning', $rSeason), 'both parts score the same');
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Beginning Part 1', $rSeason), '100 against 95: no clear lead');
    }

    public function testATitleTheSeasonHasTwiceIsRefused(): void {
        // Without the two "Home", "Homes" would be the best title left, and a wrong one.
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('Home', array(1 => 'Home', 2 => 'Home', 3 => 'Homes', 4 => 'Away')));
    }

    public function testAnUntitledEpisodeIsSkipped(): void {
        $this->assertSame(3, VodItemImporter::matchEpisodeByTitle('The Long Night', array(1 => '', 2 => null, 3 => 'The Long Night', 4 => '...')));
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Long Night', array(1 => '', 2 => null)));
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('', self::SEASON));
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Long Night', array()));
    }

    public function testAFileThatNamesTwoEpisodesIsRefused(): void {
        // 75.7% for "The Long Night" and 29.6% for "Home": a clear lead, and still two episodes.
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('The Long Night and Home', array(3 => 'The Long Night', 4 => 'Home')));
        $this->assertNull(VodItemImporter::matchEpisodeByTitle('Dragonstone & Stormborn', self::SEASON));
        // "Home" is part of the matched title, not a second episode.
        $this->assertSame(2, VodItemImporter::matchEpisodeByTitle('Coming Home', array(1 => 'Home', 2 => 'Coming Home')));
    }

    /** @dataProvider names */
    public function testTheEpisodeTitleIsWhatFollowsTheSeason(?string $rExpected, array $rRelease, string $rName, int $rSeason): void {
        $rTitle = VodItemImporter::releaseEpisodeTitle($rRelease, $rName, $rSeason);

        $this->assertSame($rExpected, $rTitle === null ? null : VodItemImporter::parseTitle($rTitle));
    }

    public static function names(): array {
        return array(
            'guessit read it, without the release tags' => array('the long night', array('title' => 'Show', 'season' => 2, 'episode_title' => 'The Long Night'), 'Show.S02.The.Long.Night.1080p.WEB-DL.x264-GRP', 2),
            'dashes (PTN reads the season only)' => array('the long night', array('title' => 'Show', 'season' => 2, 'group' => 'Night'), 'Show - S02 - The Long Night', 2),
            'dots' => array('the long night', array('season' => 2), 'Show.S02.The.Long.Night', 2),
            'underscores' => array('the long night', array('season' => 2), 'Show_s2_The_Long_Night', 2),
            'the word' => array('the long night', array('season' => 2), 'Show Season 02 The Long Night', 2),
            'nothing after the season' => array(null, array('season' => 2), 'Show S02', 2),
            'no season in the name' => array(null, array(), 'The Long Night', 2),
            'a season the name does not have (PTN read x264 as 64)' => array(null, array('season' => 64), 'Show.S02.The.Long.Night.x264', 64),
            'an s inside a word' => array(null, array('season' => 2), 'Paris2 The Return', 2),
            'an episode title that is no string' => array('the long night', array('season' => 2, 'episode_title' => array('A', 'B')), 'Show S02 The Long Night', 2),
        );
    }

    public function testASeasonIsFetchedOnceAndKeptAsTheFieldsTheImporterReads(): void {
        $rCalls = 0;
        $rTmdb = new FakeTmdbClient(array('getSeason' => function ($rShowID, $rSeason) use (&$rCalls) {
            $rCalls++;
            return new Season(array('episodes' => array(
                array('episode_number' => 3, 'name' => 'The Long Night', 'id' => 33, 'air_date' => '2019-04-28', 'overview' => 'o', 'vote_average' => 7.5, 'still_path' => '/s.jpg', 'crew' => array('x'), 'guest_stars' => array('y')),
                array('name' => 'an entry with no number'),
            )), $rShowID);
        }));
        $rExpected = array(array('episode_number' => 3, 'name' => 'The Long Night', 'id' => 33, 'air_date' => '2019-04-28', 'overview' => 'o', 'vote_average' => 7.5, 'still_path' => '/s.jpg'));

        $this->assertEquals($rExpected, VodItemImporter::seasonEpisodes($rTmdb, 990352, 8, null));
        $this->assertEquals($rExpected, VodItemImporter::seasonEpisodes($rTmdb, 990352, 8, ''));
        $this->assertSame(1, $rCalls, 'the second file of the season makes no call');
        $this->assertFileExists(WATCH_TMP_PATH . 'season_990352_8_fr-FR.cache', 'kept under the language the client was built with');

        VodItemImporter::seasonEpisodes($rTmdb, 990352, 8, 'de');
        VodItemImporter::seasonEpisodes($rTmdb, 990352, 7, null);
        $this->assertSame(3, $rCalls, 'another language and another season are each their own');

        touch(WATCH_TMP_PATH . 'season_990352_8_fr-FR.cache', time() - 7200);
        VodItemImporter::seasonEpisodes($rTmdb, 990352, 8, null);
        $this->assertSame(4, $rCalls, 'an old list is fetched again');
    }

    public function testASeasonWithNoEpisodesIsNotKept(): void {
        $rCalls = 0;
        $rTmdb = new FakeTmdbClient(array('getSeason' => function () use (&$rCalls) {
            $rCalls++;
            return new Season(array());
        }));

        $this->assertSame(array(), VodItemImporter::seasonEpisodes($rTmdb, 990352, 1, null));
        $this->assertSame(array(), VodItemImporter::seasonEpisodes($rTmdb, 990352, 1, null));
        $this->assertSame(2, $rCalls, 'a failed call is not remembered for an hour');
        $this->assertSame(array(), glob(WATCH_TMP_PATH . 'season_990352_*.cache'));
    }
}

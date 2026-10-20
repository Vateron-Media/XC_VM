<?php

use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Vod\VodItemImporter;
use PHPUnit\Framework\TestCase;

/**
 * How the importer reads a provider's title in any language: accents do not
 * count against a match, a year in the middle of a name is the year, a name
 * given in two scripts is searched by each, and a show TMDb does not list is
 * found among the series made in the panel.
 */
final class VodItemImporterTitlesTest extends TestCase {

    protected function tearDown(): void {
        ProcessRunner::useCapturer(null);
    }

    /** @dataProvider sameTitles */
    public function testATitleWrittenWithoutItsAccentsIsTheSameTitle(string $rProvider, string $rTmdb): void {
        $this->assertSame(VodItemImporter::parseTitle($rTmdb), VodItemImporter::parseTitle($rProvider));
    }

    public static function sameTitles(): array {
        return array(
            'turkish dotless and dotted i' => array('Alti Ustu Istanbul', 'Altı Üstü İstanbul'),
            'turkish cedilla and breve' => array('Gunesin Dogdugu Yer', 'Güneşin Doğduğu Yer'),
            'french' => array('Pokemon', 'Pokémon'),
            'german' => array('Die Strasse', 'Die Straße'),
            'decomposed accent' => array('Cafe', "Cafe\u{0301}"),
        );
    }

    public function testOtherScriptsAreLeftAsTheyAre(): void {
        $this->assertSame('حياة الاخرين', VodItemImporter::parseTitle('حياة الاخرين'));
        $this->assertSame('москва', VodItemImporter::parseTitle('Москва'));
    }

    /** @dataProvider yearTitles */
    public function testTheYearIsTakenWhereverItStands(string $rTitle, array $rExpected): void {
        $this->assertSame($rExpected, VodItemImporter::splitYear($rTitle));
    }

    public static function yearTitles(): array {
        return array(
            'at the end' => array('Boston Blue (2025)', array('Boston Blue', 2025)),
            'inside a two-language name' => array('Sumud (2026) صمود', array('Sumud صمود', 2026)),
            'the title is a year' => array('1923', array('1923', null)),
            'a number that is no year' => array('Daha 17', array('Daha 17', null)),
        );
    }

    public function testAProviderNameWithTheYearInsideParsesToTitleYearAndEpisode(): void {
        ProcessRunner::useCapturer(fn(array $rArgv): array => array(0, '{}'));

        $rRelease = VodItemImporter::parserelease('|TR| Sumud (2026) صمود - S01E05 - |TR| Sumud (2026) صمود - S01E05 - Épisode 5');

        $this->assertSame('Sumud صمود', $rRelease['title']);
        $this->assertSame(2026, $rRelease['year']);
        $this->assertSame(1, $rRelease['season']);
        $this->assertSame(5, $rRelease['episode']);
    }

    public function testATwoLanguageNameIsSearchedByEachScript(): void {
        $this->assertSame(
            array('Baskalarinin Hayati حياة الاخرين', 'Baskalarinin Hayati', 'حياة الاخرين'),
            VodItemImporter::titleCandidates('Baskalarinin Hayati حياة الاخرين', false)
        );
        $this->assertSame(array('Haysiyet'), VodItemImporter::titleCandidates('Haysiyet', true));
    }

    public function testDashPartsAndOneWordRunsAreOnlyCandidatesWhenAsked(): void {
        $this->assertSame(array('Mother - Anne - أمي', 'Mother - Anne'), VodItemImporter::titleCandidates('Mother - Anne - أمي', false), 'one word in Arabic named a Saudi show');
        $this->assertSame(array('Anne Yarısı - نصف الام', 'Anne Yarısı', 'نصف الام'), VodItemImporter::titleCandidates('Anne Yarısı - نصف الام', false));
        $this->assertSame(array('Mother - Anne - أمي', 'Mother - Anne', 'أمي', 'Mother', 'Anne'), VodItemImporter::titleCandidates('Mother - Anne - أمي', true));
    }

    public function testAHalfOfANameIsNeverSearchedWithoutItsYear(): void {
        $rAsked = array();
        $rTmdb = new FakeTmdbClient(array(
            'searchTVShow' => function ($rTitle, $rYear) use (&$rAsked) {
                $rAsked[] = array($rTitle, $rYear);
                return array();
            },
        ));

        $this->assertNull(VodItemImporter::findTmdbMatch($rTmdb, array('type' => 'series', 'alternative_titles' => false), array('percentage_match' => 80), 'Sumud صمود', null, 2026));

        $this->assertSame(array(array('Sumud صمود', 2026), array('Sumud صمود', null), array('Sumud', 2026), array('صمود', 2026)), $rAsked);
    }

    public function testTheLatinHalfMatchesTheShowWrittenWithItsAccents(): void {
        $rShow = new TVShow(array('id' => 7, 'name' => 'Upside Down Istanbul', 'original_name' => 'Altı Üstü İstanbul', 'first_air_date' => '2026-02-01'));
        $rTmdb = new FakeTmdbClient(array(
            'searchTVShow' => fn($rTitle) => $rTitle === 'Alti Ustu Istanbul' ? array($rShow) : array(),
        ));

        $rMatch = VodItemImporter::findTmdbMatch($rTmdb, array('type' => 'series', 'alternative_titles' => false), array('percentage_match' => 80), 'Alti Ustu Istanbul اسطنبول راسا على عقب', null, 2026);

        $this->assertSame(7, $rMatch->get('id'));
    }

    public function testASeriesMadeInThePanelIsFoundByItsNameAndYear(): void {
        $rDb = new TestDb();
        $rDb->exec('CREATE TABLE streams_series (id INTEGER PRIMARY KEY AUTO_INCREMENT, tmdb_id INTEGER, title TEXT, year INTEGER);');
        $rDb->exec("INSERT INTO streams_series (id, tmdb_id, title, year) VALUES (3, NULL, 'Başkalarının Hayatı', 2026), (4, 0, 'Anne', 2016), (5, NULL, 'Mother', NULL), (6, NULL, 'Haysiyet (2019)', NULL), (7, 1399, 'Sumud', 2026);");
        VodItemImporter::setDb($rDb);

        $this->assertSame(3, (int) VodItemImporter::findLocalSeries(array('Baskalarinin Hayati حياة الاخرين'), 2026)['id']);
        $this->assertNull(VodItemImporter::findLocalSeries(array('Mother - Anne - أمي'), null), 'two series answer');
        $this->assertNull(VodItemImporter::findLocalSeries(array('Haysiyet'), 2026), 'a year seven years off');
        $this->assertSame(6, (int) VodItemImporter::findLocalSeries(array('Haysiyet'), 2019)['id']);
        $this->assertNull(VodItemImporter::findLocalSeries(array('Sumud'), 2026), 'a series TMDb matches');
    }
}

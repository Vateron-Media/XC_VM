<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtInf;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExtInfTest extends TestCase
{
    public function testParseIssue23(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/issue23.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];
        /** @var ExtInf $extInf */
        $extInf = $entry->getExtTags()[0];

        self::assertSame(5.279, $extInf->getDuration());
    }

    public function testParseExtInf(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extinf.m3u');

        self::assertCount(6, $data);

        // basic
        /** @var M3uEntry $firstEntry */
        $firstEntry = $data[0];

        self::assertEquals('Alternative\everclear_SMFTA.mp3', $firstEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $firstEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('Everclear - So Much For The Afterglow', $extInf->getTitle());
        self::assertEquals(233, $extInf->getDuration());

        self::assertEquals([], $extInf->getAttributes());

        // cyrillic
        /** @var M3uEntry $secondEntry */
        $secondEntry = $data[1];

        self::assertEquals('http://176.51.55.8:1234/udp/233.7.70.200:5000', $secondEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $secondEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('Первый канал HD', $extInf->getTitle());
        self::assertEquals(-1, $extInf->getDuration());

        self::assertEquals([], $extInf->getAttributes());

        // attributes
        /** @var M3uEntry $thirdEntry */
        $thirdEntry = $data[2];

        self::assertEquals('http://109.225.233.1:30000/udp/239.255.10.160:5500', $thirdEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $thirdEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('Первый канал HD', $extInf->getTitle());
        self::assertEquals(-1, $extInf->getDuration());

        self::assertEquals([
            'tvg-logo' => 'Первый канал',
            'group-title' => 'Эфирные каналы',
            'tvg-name' => 'Первый_HD',
            'deinterlace' => '4',
        ], $extInf->getAttributes());

        // Comma in attribute value or title
        /** @var M3uEntry $fourthEntry */
        $fourthEntry = $data[3];

        self::assertEquals('http://117.210.233.1:3000/tcp/2', $fourthEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $fourthEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('Test with, comma 1/2', $extInf->getTitle());
        self::assertEquals(-1, $extInf->getDuration());

        self::assertEquals([
            'tvg-logo' => 'https://pngimage.net/wp-content/uploads/2018/05/iptv-png.png',
            'group-title' => '===test group title 1/2',
            'tvg-name' => 'Test with, comma 1/2',
        ], $extInf->getAttributes());

        // Spaces
        /** @var M3uEntry $fiveEntry */
        $fiveEntry = $data[4];

        self::assertEquals('http://127.0.0.1:3000', $fiveEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $fiveEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('Test with space', $extInf->getTitle());
        self::assertEquals(-1, $extInf->getDuration());

        self::assertEquals([
            'tvg-name' => 'Test with space',
        ], $extInf->getAttributes());

        // empty
        /** @var M3uEntry $sixEntry */
        $sixEntry = $data[5];

        self::assertEquals('name.mp3', $sixEntry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $sixEntry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtInf $extInf */
        $extInf = $extTags[0];
        self::assertInstanceOf(ExtInf::class, $extInf);

        self::assertEquals('', $extInf->getTitle());
        self::assertEquals(-1, $extInf->getDuration());

        self::assertEquals([
            'tvg-name' => 'Test empty name',
        ], $extInf->getAttributes());
    }

    public function testGenerateExtInf(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTINF:123 test-attr="test-attrname",extinf-title'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtInf())
                ->setDuration(123)
                ->setTitle('extinf-title')
                ->setAttribute('test-attr', 'test-attrname')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }

    public function testAttributeQuotes(): void
    {
        $testString = '#EXTINF:-1 tvg-id="" tvg-name="" tvg-logo="" tvg-chno="7529" channel-id="7529" group-title="S: (HULU) The Handmaid\'s Tale",The Handmaid\'s Tale S01 E01';

        $extInf = new ExtInf($testString);

        self::assertEquals($testString, (string) $extInf);
    }

    public function testParseUnquotedAttributeBeforeComma(): void
    {
        $extInf = new ExtInf('#EXTINF:-1 tvg-name=Первый_HD,Первый канал HD');

        self::assertEquals(-1, $extInf->getDuration());
        self::assertEquals('Первый канал HD', $extInf->getTitle());
        self::assertEquals(['tvg-name' => 'Первый_HD'], $extInf->getAttributes());
        self::assertSame('#EXTINF:-1 tvg-name="Первый_HD",Первый канал HD', (string) $extInf);
    }

    /**
     * @param array<string, string> $attributes
     */
    #[DataProvider('provideParse')]
    public function testParse(string $line, float $duration, string $title, array $attributes): void
    {
        $extInf = new ExtInf($line);

        self::assertSame($duration, $extInf->getDuration());
        self::assertSame($title, $extInf->getTitle());
        self::assertSame($attributes, $extInf->getAttributes());
    }

    /**
     * @return iterable<string, array{string, float, string, array<string, string>}>
     */
    public static function provideParse(): iterable
    {
        yield 'basic' => [
            '#EXTINF:233,Everclear - So Much For The Afterglow',
            233.0, 'Everclear - So Much For The Afterglow', [],
        ];
        yield 'negative duration' => [
            '#EXTINF:-1,Первый канал HD',
            -1.0, 'Первый канал HD', [],
        ];
        yield 'float duration' => [
            '#EXTINF:5.279,title',
            5.279, 'title', [],
        ];
        yield 'zero duration' => [
            '#EXTINF:0,title',
            0.0, 'title', [],
        ];
        yield 'duration with surrounding spaces' => [
            '#EXTINF: -1 tvg-name="Test with space" , Test with space',
            -1.0, 'Test with space', ['tvg-name' => 'Test with space'],
        ];
        yield 'unquoted attribute right before comma' => [
            '#EXTINF:-1 tvg-name=Первый_HD,Первый канал HD',
            -1.0, 'Первый канал HD', ['tvg-name' => 'Первый_HD'],
        ];
        yield 'mixed quoted and unquoted attributes' => [
            '#EXTINF:-1 tvg-name=Первый_HD tvg-logo="Первый канал" deinterlace=4 group-title="Эфирные каналы",Первый канал HD',
            -1.0, 'Первый канал HD', [
                'tvg-name' => 'Первый_HD',
                'tvg-logo' => 'Первый канал',
                'deinterlace' => '4',
                'group-title' => 'Эфирные каналы',
            ],
        ];
        yield 'comma inside quoted attribute value' => [
            '#EXTINF:-1 tvg-name="Test with, comma 1/2" group-title="===test group title 1/2",Test with, comma 1/2',
            -1.0, 'Test with, comma 1/2', [
                'tvg-name' => 'Test with, comma 1/2',
                'group-title' => '===test group title 1/2',
            ],
        ];
        yield 'comma in title' => [
            '#EXTINF:-1,Hello, world',
            -1.0, 'Hello, world', [],
        ];
        yield 'escaped quote inside attribute value' => [
            '#EXTINF:-1 group-title="some\"quoted",title',
            -1.0, 'title', ['group-title' => 'some"quoted'],
        ];
        yield 'equals sign inside quoted attribute value' => [
            '#EXTINF:-1 ext-tag="a=b",title',
            -1.0, 'title', ['ext-tag' => 'a=b'],
        ];
        yield 'backslash inside attribute value' => [
            '#EXTINF:-1 tvg-logo="C:\logos\1.png",title',
            -1.0, 'title', ['tvg-logo' => 'C:\logos\1.png'],
        ];
        yield 'empty title with attribute' => [
            '#EXTINF:-1 tvg-name="Test empty name",',
            -1.0, '', ['tvg-name' => 'Test empty name'],
        ];
        yield 'empty title without attributes' => [
            '#EXTINF:-1,',
            -1.0, '', [],
        ];
        yield 'equals sign in title' => [
            '#EXTINF:100,a=b c=d',
            100.0, 'a=b c=d', [],
        ];
        yield 'empty payload' => [
            '#EXTINF:',
            0.0, '', [],
        ];
        yield 'non-numeric duration' => [
            '#EXTINF:no-duration,title',
            0.0, 'title', [],
        ];
        yield 'no comma at all' => [
            '#EXTINF:-1',
            -1.0, '', [],
        ];
    }

    #[DataProvider('provideRoundTrip')]
    public function testRoundTrip(string $line): void
    {
        $first = new ExtInf($line);
        $second = new ExtInf((string) $first);

        self::assertSame($first->getDuration(), $second->getDuration());
        self::assertSame($first->getTitle(), $second->getTitle());
        self::assertSame($first->getAttributes(), $second->getAttributes());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideRoundTrip(): iterable
    {
        yield 'basic' => ['#EXTINF:233,Everclear - So Much For The Afterglow'];
        yield 'negative duration' => ['#EXTINF:-1,Первый канал HD'];
        yield 'float duration' => ['#EXTINF:5.279,title'];
        yield 'zero duration' => ['#EXTINF:0,'];
        yield 'quoted attribute' => ['#EXTINF:-1 tvg-name="Первый_HD",Первый канал HD'];
        yield 'unquoted attribute' => ['#EXTINF:-1 tvg-name=Первый_HD,Первый канал HD'];
        yield 'comma in quoted attribute' => ['#EXTINF:-1 tvg-name="Test with, comma 1/2",Test with, comma 1/2'];
        yield 'comma in title' => ['#EXTINF:-1,Hello, world'];
        yield 'escaped quote' => ['#EXTINF:-1 group-title="some\"quoted",title'];
        yield 'backslash' => ['#EXTINF:-1 tvg-logo="C:\logos\1.png",title'];
        yield 'empty title' => ['#EXTINF:-1 tvg-name="Test empty name",'];
    }
}

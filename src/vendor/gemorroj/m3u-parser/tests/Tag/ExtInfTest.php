<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtInf;
use M3uParser\Tag\ExtTagInterface;
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

        self::assertContainsOnlyInstancesOf(M3uEntry::class, $data);

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
}

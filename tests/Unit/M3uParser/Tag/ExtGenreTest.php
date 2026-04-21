<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtGenre;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class ExtGenreTest extends TestCase
{
    public function testParseExtGenre(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extgenre.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@127.0.0.1:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtGenre $extGenre */
        $extGenre = $extTags[0];
        self::assertInstanceOf(ExtGenre::class, $extGenre);

        self::assertEquals('Rock', $extGenre->getValue());
    }

    public function testGenerateExtGenre(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTGENRE:Rock'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtGenre())
                ->setValue('Rock')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

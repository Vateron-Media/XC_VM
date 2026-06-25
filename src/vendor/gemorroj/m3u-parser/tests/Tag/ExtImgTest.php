<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtImg;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class ExtImgTest extends TestCase
{
    public function testParseExtImg(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extimg.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('Alternative\everclear_SMFTA.mp3', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtImg $extImg */
        $extImg = $extTags[0];
        self::assertInstanceOf(ExtImg::class, $extImg);

        self::assertEquals('http://example.org/logo.png', $extImg->getValue());
    }

    public function testGenerateExtImg(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTIMG:http://example.org/logo.png'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtImg())
                ->setValue('http://example.org/logo.png')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

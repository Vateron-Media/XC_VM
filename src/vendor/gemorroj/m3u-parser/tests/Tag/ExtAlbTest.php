<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtAlb;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class ExtAlbTest extends TestCase
{
    public function testParseExtAlb(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extalb.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@127.0.0.1:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtAlb $extAlb */
        $extAlb = $extTags[0];
        self::assertInstanceOf(ExtAlb::class, $extAlb);

        self::assertEquals('some album', $extAlb->getValue());
    }

    public function testGenerateExtAlb(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTALB:some album'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtAlb())
                ->setValue('some album')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

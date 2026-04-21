<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtAlbumArtUrl;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class ExtAlbumArtUrlTest extends TestCase
{
    public function testParseExtAlbumArtUrl(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extalbumarturl.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@127.0.0.1:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtAlbumArtUrl $extAlbumArtUrl */
        $extAlbumArtUrl = $extTags[0];
        self::assertInstanceOf(ExtAlbumArtUrl::class, $extAlbumArtUrl);

        self::assertEquals('https://store.example.com/download/A32X5yz-1.jpg', $extAlbumArtUrl->getValue());
    }

    public function testGenerateExtAlbumArtUrl(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTALBUMARTURL:https://store.example.com/download/A32X5yz-1.jpg'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtAlbumArtUrl())
                ->setValue('https://store.example.com/download/A32X5yz-1.jpg')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

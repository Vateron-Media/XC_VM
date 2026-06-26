<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtTagInterface;
use M3uParser\Tag\ExtTv;
use PHPUnit\Framework\TestCase;

class ExtTvTest extends TestCase
{
    public function testParseExtTv(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/exttv.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@232.2.201.53:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtTv $extTv */
        $extTv = $extTags[0];
        self::assertInstanceOf(ExtTv::class, $extTv);

        self::assertEquals(['Slovenski', 'HD'], $extTv->getTags());
        self::assertEquals('slv', $extTv->getLanguage());
        self::assertEquals('SLO1HD', $extTv->getXmlTvId());
        self::assertNull($extTv->getIconUrl());
    }

    public function testGenerateExtTv(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTTV:hd,sd;ru;xml-tv-id;https://example.org/icon.png'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtTv())
                ->setIconUrl('https://example.org/icon.png')
                ->setLanguage('ru')
                ->setXmlTvId('xml-tv-id')
                ->setTags(['hd', 'sd'])
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

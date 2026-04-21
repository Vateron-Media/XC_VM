<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtTagInterface;
use M3uParser\Tag\ExtVlcOpt;
use PHPUnit\Framework\TestCase;

class ExtVlcOptTest extends TestCase
{
    public function testParseExtVlcOpt(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extvlcopt.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@127.0.0.1:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtVlcOpt $extVlcOpt */
        $extVlcOpt = $extTags[0];
        self::assertInstanceOf(ExtVlcOpt::class, $extVlcOpt);

        self::assertEquals('http-user-agent', $extVlcOpt->getKey());
        self::assertEquals('Lavf53.32.100', $extVlcOpt->getValue());
    }

    public function testGenerateExtVlcOpt(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTVLCOPT:some-key=some-value'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtVlcOpt())
                ->setKey('some-key')
                ->setValue('some-value')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

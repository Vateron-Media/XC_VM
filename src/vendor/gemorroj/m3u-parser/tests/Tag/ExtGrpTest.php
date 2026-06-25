<?php

declare(strict_types=1);

namespace M3uParser\Tests\Tag;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtGrp;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class ExtGrpTest extends TestCase
{
    public function testParseExtGrp(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/../fixtures/extgrp.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@127.0.0.1:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtGrp $extGrp */
        $extGrp = $extTags[0];
        self::assertInstanceOf(ExtGrp::class, $extGrp);

        self::assertEquals('Rock', $extGrp->getValue());
    }

    public function testGenerateExtGrp(): void
    {
        $expectedString = '#EXTM3U'."\n";
        $expectedString .= '#EXTGRP:Rock'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtGrp())
                ->setValue('Rock')
        );

        $data = new M3uData();
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }
}

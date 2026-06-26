<?php

declare(strict_types=1);

namespace M3uParser\Tests;

use M3uParser\Exception as M3uParserException;
use M3uParser\M3uEntry;
use M3uParser\M3uParser;
use M3uParser\Tag\ExtTagInterface;
use PHPUnit\Framework\TestCase;

class M3uParserTest extends TestCase
{
    public function testParseFileFail(): void
    {
        $this->expectException(M3uParserException::class);

        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $m3uParser->parseFile('fake_file');
    }

    public function testParseFileExtM3u(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/fixtures/extm3u.m3u');

        self::assertEquals([
            'url-tvg' => 'http://www.teleguide.info/download/new3/jtv.zip',
            'm3uautoload' => '1',
            'deinterlace' => '8',
            'cache' => '500',
        ], $data->getAttributes());
        self::assertEquals('http://www.teleguide.info/download/new3/jtv.zip', $data->getAttribute('url-tvg'));
    }

    public function testParseFileComment(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/fixtures/comment.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('http://nullwave.barricade.lan:8000/club', $entry->getPath());
        self::assertEmpty($entry->getExtTags());
    }

    public function testParseFileNoTags(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/fixtures/notags.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('http://scfire-ntc-aa07.stream.aol.com:80/stream/1048', $entry->getPath());
        self::assertEmpty($entry->getExtTags());
    }

    public function testParseFileCombinedExtTags(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addDefaultTags();
        $data = $m3uParser->parseFile(__DIR__.'/fixtures/combined.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('rtp://@232.2.201.53:5003', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(2, $extTags);

        self::assertContainsOnlyInstancesOf(ExtTagInterface::class, $extTags);
    }

    public function testParseFileExtCustomTag(): void
    {
        $m3uParser = new M3uParser();
        $m3uParser->addTag(ExtCustomTag::class);
        $data = $m3uParser->parseFile(__DIR__.'/fixtures/customtag.m3u');

        /** @var M3uEntry $entry */
        $entry = $data[0];

        self::assertEquals('http://nullwave.barricade.lan:8000/club', $entry->getPath());

        /** @var ExtTagInterface[] $extTags */
        $extTags = $entry->getExtTags();
        self::assertCount(1, $extTags);

        /** @var ExtCustomTag $extCustomTag */
        $extCustomTag = $extTags[0];
        self::assertInstanceOf(ExtCustomTag::class, $extCustomTag);

        self::assertEquals('123', $extCustomTag->getData());
    }
}

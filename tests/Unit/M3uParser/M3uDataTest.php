<?php

declare(strict_types=1);

namespace M3uParser\Tests;

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\Tag\ExtInf;
use M3uParser\Tag\ExtLogo;
use M3uParser\Tag\ExtTv;
use PHPUnit\Framework\TestCase;

class M3uDataTest extends TestCase
{
    public function testComplexDefaultEntryToString(): void
    {
        $expectedString = '#EXTM3U test-name="test-value"'."\n";
        $expectedString .= '#EXTINF:123 test-attr="test-attrname",extinf-title'."\n";
        $expectedString .= '#EXTTV:hd,sd;ru;xml-tv-id;https://example.org/icon.png'."\n";
        $expectedString .= '#EXTLOGO:https://example.org/logo.png'."\n";
        $expectedString .= 'test-path';

        $entry = new M3uEntry();
        $entry->setPath('test-path');
        $entry->addExtTag(
            (new ExtInf())
                ->setDuration(123)
                ->setTitle('extinf-title')
                ->setAttribute('test-attr', 'test-attrname')
        );
        $entry->addExtTag(
            (new ExtTv())
                ->setIconUrl('https://example.org/icon.png')
                ->setLanguage('ru')
                ->setXmlTvId('xml-tv-id')
                ->setTags(['hd', 'sd'])
        );
        $entry->addExtTag(
            (new ExtLogo())
                ->setValue('https://example.org/logo.png')
        );

        $data = new M3uData();
        $data->setAttribute('test-name', 'test-value');
        $data->append($entry);

        self::assertEquals($expectedString, (string) $data);
    }

    /**
     * @see https://github.com/Gemorroj/M3uParser/pull/14
     */
    public function testComplexDefaultEntriesToString(): void
    {
        $expectedString = '#EXTM3U test-name="test-value"'."\n";
        $expectedString .= '#EXTINF:123 test-attr="test-attrname1",extinf-title1'."\n";
        $expectedString .= '#EXTTV:hd,sd;ru;xml-tv-id;https://example.org/icon.png'."\n";
        $expectedString .= '#EXTLOGO:https://example.org/logo.png'."\n";
        $expectedString .= 'test-path1'."\n";
        $expectedString .= '#EXTINF:123 test-attr="test-attrname2",extinf-title2'."\n";
        $expectedString .= '#EXTTV:hd,sd;ru;xml-tv-id;https://example.org/icon.png'."\n";
        $expectedString .= '#EXTLOGO:https://example.org/logo.png'."\n";
        $expectedString .= 'test-path2';

        $entry1 = new M3uEntry();
        $entry1->setPath('test-path1');
        $entry1->addExtTag(
            (new ExtInf())
                ->setDuration(123)
                ->setTitle('extinf-title1')
                ->setAttribute('test-attr', 'test-attrname1')
        );
        $entry1->addExtTag(
            (new ExtTv())
                ->setIconUrl('https://example.org/icon.png')
                ->setLanguage('ru')
                ->setXmlTvId('xml-tv-id')
                ->setTags(['hd', 'sd'])
        );
        $entry1->addExtTag(
            (new ExtLogo())
                ->setValue('https://example.org/logo.png')
        );

        $entry2 = new M3uEntry();
        $entry2->setPath('test-path2');
        $entry2->addExtTag(
            (new ExtInf())
                ->setDuration(123)
                ->setTitle('extinf-title2')
                ->setAttribute('test-attr', 'test-attrname2')
        );
        $entry2->addExtTag(
            (new ExtTv())
                ->setIconUrl('https://example.org/icon.png')
                ->setLanguage('ru')
                ->setXmlTvId('xml-tv-id')
                ->setTags(['hd', 'sd'])
        );
        $entry2->addExtTag(
            (new ExtLogo())
                ->setValue('https://example.org/logo.png')
        );

        $data = new M3uData([$entry1, $entry2]);
        $data->setAttribute('test-name', 'test-value');

        self::assertEquals($expectedString, (string) $data);
    }
}

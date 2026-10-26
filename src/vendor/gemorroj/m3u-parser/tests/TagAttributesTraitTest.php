<?php

declare(strict_types=1);

namespace M3uParser\Tests;

use M3uParser\TagAttributesTrait;
use PHPUnit\Framework\TestCase;

class TagAttributesTraitTest extends TestCase
{
    use TagAttributesTrait;

    public function testParseAttributes(): void
    {
        $this->initAttributes('tvg-ID="" tvg-name="MEDI 1 SAT" group-title="ARABIC" tvg-name-custom=Первый_HD ext-tag="some\"quoted"');

        $result = $this->getAttributes();

        self::assertEquals([
            'tvg-ID' => '',
            'tvg-name' => 'MEDI 1 SAT',
            'group-title' => 'ARABIC',
            'tvg-name-custom' => 'Первый_HD',
            'ext-tag' => 'some"quoted',
        ], $result);

        self::assertEquals('Первый_HD', $this->getAttribute('tvg-name-custom'));
        self::assertNull($this->getAttribute('fake-attribute'));
    }

    public function testMakeAttributes(): void
    {
        $this->setAttribute('tvg-ID', '');
        $this->setAttribute('ext-tag', 'some"quoted');
        $this->setAttribute('tvg-name-custom', 'Первый HD');

        $result = $this->getAttributesString();

        self::assertSame('tvg-ID="" ext-tag="some\"quoted" tvg-name-custom="Первый HD"', $result);
    }

    public function testParseBackslashInAttribute(): void
    {
        $this->initAttributes('tvg-logo="C:\\logos\\1.png"');

        self::assertSame('C:\\logos\\1.png', $this->getAttribute('tvg-logo'));
    }

    public function testAttributesRoundTripWithBackslash(): void
    {
        $this->setAttribute('path', 'C:\\path');
        $this->setAttribute('quote', 'a"b');

        $result = $this->getAttributesString();

        self::assertSame('path="C:\\\\path" quote="a\\"b"', $result);

        $this->initAttributes($result);

        self::assertSame('C:\\path', $this->getAttribute('path'));
        self::assertSame('a"b', $this->getAttribute('quote'));
    }
}

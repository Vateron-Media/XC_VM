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

        self::assertIsArray($result);
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

        self::assertIsString($result);
        self::assertSame('tvg-ID="" ext-tag="some\"quoted" tvg-name-custom="Первый HD"', $result);
    }
}

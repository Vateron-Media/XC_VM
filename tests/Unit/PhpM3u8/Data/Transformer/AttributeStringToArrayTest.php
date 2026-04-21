<?php

declare(strict_types=1);

namespace Chrisyue\PhpM3u8\Test\Data\Transformer;

use Chrisyue\PhpM3u8\Data\Transformer\AttributeStringToArray;
use PHPUnit\Framework\TestCase;

class AttributeStringToArrayTest extends TestCase
{
    public function testInvoke(): void
    {
        $attr2Array = new AttributeStringToArray();

        $string = 'FOO=BAR,URI="http://example.com/?p=1"';
        $expected = [
            'FOO' => 'BAR',
            'URI' => '"http://example.com/?p=1"',
        ];

        $this->assertEquals($expected, $attr2Array($string));
    }
}

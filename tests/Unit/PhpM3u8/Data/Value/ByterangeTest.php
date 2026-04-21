<?php

declare(strict_types=1);

namespace Chrisyue\PhpM3u8\Test\Data\Value;

use Chrisyue\PhpM3u8\Data\Value\Byterange;
use PHPUnit\Framework\TestCase;

class ByterangeTest extends TestCase
{
    /**
     * @dataProvider dataProvider
     */
    public function testFromString($string, Byterange $byterange): void
    {
        $this->assertEquals($byterange, Byterange::fromString($string));
    }

    /**
     * @dataProvider dataProvider
     */
    public function testToString($string, Byterange $byterange): void
    {
        $this->assertEquals($string, (string) $byterange);
    }

    public function dataProvider(): array
    {
        return [
            ['2000', new Byterange(2000)],
            ['2000@1000', new Byterange(2000, 1000)],
        ];
    }
}

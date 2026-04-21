<?php

declare(strict_types=1);

namespace Chrisyue\PhpM3u8\Test\Stream;

use Chrisyue\PhpM3u8\Stream\TextStream;
use PHPUnit\Framework\TestCase;

class TextStreamTest extends TestCase
{
    public function testToString(): void
    {
        $text = "first\nsecond";
        $this->assertSame($text."\n", (string) new TextStream($text));

        $text = "first\nsecond\n";
        $this->assertSame($text, (string) new TextStream($text));
    }
}

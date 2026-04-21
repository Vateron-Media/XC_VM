<?php

declare(strict_types=1);

namespace Chrisyue\PhpM3u8\Test\Line;

use Chrisyue\PhpM3u8\Line\Line;
use Chrisyue\PhpM3u8\Line\Lines;
use Chrisyue\PhpM3u8\Stream\StreamInterface;
use PHPUnit\Framework\TestCase;

class LinesTest extends TestCase
{
    public function testValid(): void
    {
        $stream = new class([false], [null]) implements StreamInterface {
            private array $validSequence;
            private array $currentSequence;
            private int $position = 0;

            public function __construct(array $validSequence, array $currentSequence)
            {
                $this->validSequence = $validSequence;
                $this->currentSequence = $currentSequence;
            }

            public function current(): mixed
            {
                return $this->currentSequence[$this->position] ?? null;
            }

            public function next(): void
            {
                ++$this->position;
            }

            public function key(): mixed
            {
                return $this->position;
            }

            public function valid(): bool
            {
                return $this->validSequence[$this->position] ?? false;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function add($line): void
            {
                $this->currentSequence[] = $line;
            }
        };
        $lines = new Lines($stream);

        $this->assertFalse($lines->valid());

        $tag = 'EXT-X-FOO:1';
        $stream = new class([true], [$tag]) implements StreamInterface {
            private array $validSequence;
            private array $currentSequence;
            private int $position = 0;

            public function __construct(array $validSequence, array $currentSequence)
            {
                $this->validSequence = $validSequence;
                $this->currentSequence = $currentSequence;
            }

            public function current(): mixed
            {
                return $this->currentSequence[$this->position] ?? null;
            }

            public function next(): void
            {
                ++$this->position;
            }

            public function key(): mixed
            {
                return $this->position;
            }

            public function valid(): bool
            {
                return $this->validSequence[$this->position] ?? false;
            }

            public function rewind(): void
            {
                $this->position = 0;
            }

            public function add($line): void
            {
                $this->currentSequence[] = $line;
            }
        };
        $lines = new Lines($stream);

        $this->assertTrue($lines->valid());
        $this->assertEquals(Line::fromString($tag), $lines->current());
    }
}

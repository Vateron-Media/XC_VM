<?php

declare(strict_types=1);

namespace M3uParser\Tests;

use M3uParser\Exception;
use M3uParser\Tag\ExtInf;
use M3uParser\TagsManagerTrait;
use PHPUnit\Framework\TestCase;

class TagsManagerTraitTest extends TestCase
{
    use TagsManagerTrait;

    public function testTags(): void
    {
        $this->addTag(ExtInf::class);

        $result = $this->getTags();

        self::assertIsArray($result);
        self::assertEquals([ExtInf::class], $result);
    }

    public function testClearTags(): void
    {
        $this->addTag(ExtInf::class);
        $this->clearTags();

        $result = $this->getTags();

        self::assertIsArray($result);
        self::assertEquals([], $result);
    }

    public function testErrorTags(): void
    {
        $this->expectException(Exception::class);
        $this->addTag(self::class); // @phpstan-ignore-line
    }
}

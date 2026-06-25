<?php

declare(strict_types=1);

namespace M3uParser;

use M3uParser\Tag\ExtTagInterface;

class M3uEntry implements \Stringable
{
    protected string $lineDelimiter = "\n";
    /**
     * @var ExtTagInterface[]
     */
    private array $extTags = [];
    private ?string $path = null;

    public function __toString(): string
    {
        $out = '';
        foreach ($this->getExtTags() as $extTag) {
            $out .= $extTag.$this->lineDelimiter;
        }

        $out .= $this->getPath();

        return \rtrim($out);
    }

    /**
     * @return ExtTagInterface[]
     */
    public function getExtTags(): array
    {
        return $this->extTags;
    }

    public function addExtTag(ExtTagInterface $extTag): self
    {
        $this->extTags[] = $extTag;

        return $this;
    }

    /**
     * Remove all previously defined tags.
     */
    public function clearExtTags(): self
    {
        $this->extTags = [];

        return $this;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;

        return $this;
    }
}

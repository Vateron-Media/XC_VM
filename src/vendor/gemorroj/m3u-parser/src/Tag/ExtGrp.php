<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * The directive allows for named grouping of media files within the playlist, facilitating organization and categorization based on different criteria.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/28
 */
class ExtGrp implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTGRP:music.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTGRP:'.$this->getValue();
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTGRP:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTGRP format:
#EXTGRP:<value>
example:
#EXTGRP:Rock
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTGRP:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

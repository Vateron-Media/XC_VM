<?php

declare(strict_types=1);

namespace M3uParser;

/** @extends \ArrayIterator<int, M3uEntry> */
class M3uData extends \ArrayIterator implements \Stringable
{
    use TagAttributesTrait;

    public function __toString(): string
    {
        $out = \rtrim('#EXTM3U '.$this->getAttributesString())."\n";

        foreach ($this as $entry) {
            $out .= $entry."\n";
        }

        return \rtrim($out);
    }
}

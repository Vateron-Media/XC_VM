<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Cover, logo or other image.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/35
 */
class ExtImg implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTIMG:http://cdn1.siol.tv/logo/93x78/slo2.png.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->makeData($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTIMG:'.$this->getValue();
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTIMG:');
    }

    protected function makeData(string $lineStr): void
    {
        /*
EXTIMG format:
#EXTIMG:logo
example:
#EXTIMG:http://cdn1.siol.tv/logo/93x78/slo2.png
         */

        $tmp = \substr($lineStr, \strlen('#EXTIMG:'));
        $value = \trim($tmp);

        $this->setValue($value);
    }
}

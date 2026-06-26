<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * @see https://github.com/Gemorroj/M3uParser/issues/20
 */
class ExtLogo implements ExtTagInterface
{
    private string $logo;

    /**
     * #EXTLOGO:http://cdn1.siol.tv/logo/93x78/slo2.png.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->makeData($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTLOGO:'.$this->getValue();
    }

    public function getValue(): string
    {
        return $this->logo;
    }

    public function setValue(string $logo): self
    {
        $this->logo = $logo;

        return $this;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTLOGO:');
    }

    protected function makeData(string $lineStr): void
    {
        /*
EXTLOGO format:
#EXTLOGO:logo
example:
#EXTLOGO:http://cdn1.siol.tv/logo/93x78/slo2.png
         */

        $tmp = \substr($lineStr, \strlen('#EXTLOGO:'));
        $logo = \trim($tmp);

        $this->setValue($logo);
    }
}

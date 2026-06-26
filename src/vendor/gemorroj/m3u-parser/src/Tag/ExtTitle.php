<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Title for the tracks included in the playlist.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/33
 */
class ExtTitle implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTTITLE:super-song.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTTITLE:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#EXTTITLE:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTTITLE format:
#EXTTITLE:<value>
example:
#EXTTITLE:name
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTTITLE:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

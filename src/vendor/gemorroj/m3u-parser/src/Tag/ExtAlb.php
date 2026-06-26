<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * This directive provides album information, which can be valuable for organizing music playlists according to albums or compilation releases.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/30
 */
class ExtAlb implements ExtTagInterface
{
    private string $value;

    /**
     * #ExtAlb:some album.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTALB:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#EXTALB:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTALB format:
#EXTALB:<value>
example:
#EXTALB:soma album
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTALB:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

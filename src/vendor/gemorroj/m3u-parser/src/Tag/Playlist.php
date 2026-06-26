<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Playlist display title.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/29
 */
class Playlist implements ExtTagInterface
{
    private string $value;

    /**
     * #PLAYLIST:My favorite music.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#PLAYLIST:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#PLAYLIST:');
    }

    protected function make(string $lineStr): void
    {
        /*
PLAYLIST format:
#PLAYLIST:<value>
example:
#PLAYLIST:My favorite music
         */
        $dataLineStr = \substr($lineStr, \strlen('#PLAYLIST:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

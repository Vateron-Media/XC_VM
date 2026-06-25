<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Genre information can be included in an M3U IPTV playlist using this directive, allowing for genre-based sorting and filtering of multimedia content.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/32
 */
class ExtGenre implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTGENRE:Rock.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTGENRE:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#EXTGENRE:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTGENRE format:
#EXTGENRE:<value>
example:
#EXTGENRE:Rock
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTGENRE:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

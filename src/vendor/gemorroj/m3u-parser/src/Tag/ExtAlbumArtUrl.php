<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * URL that can be used to fetch an album art image for the tracks. An album art URL can be specified either as a single URL for every track (assuming the tracks are all from the same album), or as a separate URL for each track in the list (assuming the tracks are from multiple albums).
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/34
 */
class ExtAlbumArtUrl implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTALBUMARTURL:https://store.example.com/download/A32X5yz-1.jpg.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTALBUMARTURL:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#EXTALBUMARTURL:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTALBUMARTURL format:
#EXTALBUMARTURL:<url>
example:
#EXTALBUMARTURL:https://store.example.com/download/A32X5yz-1.jpg
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTALBUMARTURL:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

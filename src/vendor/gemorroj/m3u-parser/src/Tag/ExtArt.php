<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Used to specify the artist or creator associated with a particular media file, the #EXTART directive contributes to enhanced metadata management within the playlist.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/31
 */
class ExtArt implements ExtTagInterface
{
    private string $value;

    /**
     * #EXTART:Гражданская оборона.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTART:'.$this->getValue();
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
        return 0 === \stripos($lineStr, '#EXTART:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTART format:
#EXTART:<value>
example:
#EXTART:Гражданская оборона
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTART:'));
        $dataLineStr = \trim($dataLineStr);

        $this->setValue($dataLineStr);
    }
}

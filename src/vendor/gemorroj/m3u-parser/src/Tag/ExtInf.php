<?php

declare(strict_types=1);

namespace M3uParser\Tag;

use M3uParser\TagAttributesTrait;

/**
 * This directive is used to define specific track information such as the duration and title of a media file. It plays a crucial role in providing details about each entry in the playlist.
 */
class ExtInf implements ExtTagInterface
{
    use TagAttributesTrait;

    private string $title;
    private float $duration;

    /**
     * #EXTINF:-1 tvg-name=Первый_HD tvg-logo="Первый канал" deinterlace=4 group-title="Эфирные каналы",Первый канал HD.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        $attributesString = $this->getAttributesString();

        return '#EXTINF:'.$this->getDuration().('' === $attributesString ? '' : ' '.$attributesString).','.$this->getTitle();
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setDuration(float|int $duration): self
    {
        $this->duration = (float) $duration;

        return $this;
    }

    public function getDuration(): float
    {
        return $this->duration;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTINF:');
    }

    /**
     * @see http://l189-238-14.cn.ru/api-doc/m3u-extending.html
     */
    protected function make(string $lineStr): void
    {
        /*
EXTINF format:
#EXTINF:<duration> [<attributes-list>], <title>
example:
#EXTINF:-1 tvg-name=Первый_HD tvg-logo="Первый канал" deinterlace=4 group-title="Эфирные каналы",Первый канал HD
         */
        [$meta, $title] = $this->splitTitle(\trim(\substr($lineStr, \strlen('#EXTINF:'))));
        $meta = \ltrim($meta);

        if (\preg_match('/^(-?[\d.]+)/', $meta, $matches)) {
            $this->setDuration((float) $matches[1]);
            $meta = \ltrim(\substr($meta, \strlen($matches[1])));
        } else {
            $this->setDuration(0);
        }

        $this->setTitle(\trim($title));

        if ('' !== $meta) {
            $this->initAttributes($meta);
        }
    }

    /**
     * Split the line into the "<duration> <attributes-list>" part and the "<title>" part.
     *
     * The title starts right after the first comma that is not inside double quotes,
     * so commas inside quoted attribute values (e.g. tvg-name="Test, comma") are ignored.
     *
     * @return array{string, string}
     */
    private function splitTitle(string $str): array
    {
        // [^",]                 - a regular char (stops at the separator comma)
        // "(?:\\.|[^"\\])*"     - a quoted value, supporting the escaped \" sequence
        $meta = \preg_match('/^(?:[^",]|"(?:\\\\.|[^"\\\\])*")*/', $str, $matches) ? $matches[0] : '';

        return [$meta, \substr($str, \strlen($meta) + 1)];
    }
}

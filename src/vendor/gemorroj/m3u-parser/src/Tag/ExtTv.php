<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * Defines language and groups TV programs are in.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/5
 */
class ExtTv implements ExtTagInterface
{
    /**
     * @var string[]
     */
    private array $tags = [];
    private string $language;
    private string $xmlTvId;
    private ?string $iconUrl = null;

    /**
     * #EXTTV:nacionalni,hd;slovenski;SLO1;http://cdn1.siol.tv/logo/93x78/slo2.png.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->makeData($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTTV:'.\implode(',', $this->getTags()).';'.$this->getLanguage().';'.$this->getXmlTvId().($this->getIconUrl() ? ';'.$this->getIconUrl() : '');
    }

    /**
     * @return string[]
     */
    public function getTags(): array
    {
        return $this->tags;
    }

    /**
     * @param string[] $tags
     */
    public function setTags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage(string $language): self
    {
        $this->language = $language;

        return $this;
    }

    public function getXmlTvId(): string
    {
        return $this->xmlTvId;
    }

    public function setXmlTvId(string $xmlTvId): self
    {
        $this->xmlTvId = $xmlTvId;

        return $this;
    }

    public function getIconUrl(): ?string
    {
        return $this->iconUrl;
    }

    public function setIconUrl(?string $iconUrl): self
    {
        $this->iconUrl = $iconUrl;

        return $this;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTTV:');
    }

    protected function makeData(string $lineStr): void
    {
        /*
EXTTV format:
#EXTTV:tag[,tag,tag...];language;XMLTV id[;icon URL]
example:
#EXTTV:nacionalni,hd;slovenski;SLO1;http://cdn1.siol.tv/logo/93x78/slo2.png
         */

        $tmp = \substr($lineStr, \strlen('#EXTTV:'));
        $split = \explode(';', $tmp, 4);

        $this->setTags(\array_map('trim', \explode(',', $split[0])));
        $this->setLanguage(\trim($split[1]));
        $this->setXmlTvId(\trim($split[2]));
        if (isset($split[3])) {
            $this->setIconUrl(\trim($split[3]));
        }
    }
}

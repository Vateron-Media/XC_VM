<?php

declare(strict_types=1);

namespace M3uParser\Tests;

use M3uParser\Tag\ExtTagInterface;

class ExtCustomTag implements ExtTagInterface
{
    private string $data = '';

    /**
     * #EXTCUSTOMTAG:data.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->makeData($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTCUSTOMTAG: '.$this->getData();
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;

        return $this;
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTCUSTOMTAG:');
    }

    protected function makeData(string $lineStr): void
    {
        /*
EXTCUSTOMTAG format:
#EXTCUSTOMTAG:data
example:
#EXTCUSTOMTAG:123
         */

        $data = \substr($lineStr, \strlen('#EXTCUSTOMTAG:'));

        $this->setData(\trim($data));
    }
}

<?php

declare(strict_types=1);

namespace M3uParser\Tag;

/**
 * VLC specific attribute.
 *
 * @see https://github.com/Gemorroj/M3uParser/issues/27
 */
class ExtVlcOpt implements ExtTagInterface
{
    private string $key;
    private string $value;

    /**
     * #EXTVLCOPT:http-user-agent=Mozilla/5.0 (Windows NT 6.1; rv:61.0) Gecko/20100101 Firefox/61.0.
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->make($lineStr);
        }
    }

    public function __toString(): string
    {
        return '#EXTVLCOPT:'.$this->getKey().'='.$this->getValue();
    }

    public function setKey(string $key): self
    {
        $this->key = $key;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
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
        return 0 === \stripos($lineStr, '#EXTVLCOPT:');
    }

    protected function make(string $lineStr): void
    {
        /*
EXTVLCOPT format:
#EXTVLCOPT:<key> = <value>
example:
#EXTVLCOPT:http-user-agent=Mozilla/5.0 (Windows NT 6.1; rv:61.0) Gecko/20100101 Firefox/61.0
         */
        $dataLineStr = \substr($lineStr, \strlen('#EXTVLCOPT:'));
        $dataLineStr = \trim($dataLineStr);

        [$key, $value] = \explode('=', $dataLineStr, 2);

        $this->setKey($key);
        $this->setValue($value);
    }
}

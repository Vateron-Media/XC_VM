<?php

declare(strict_types=1);

namespace M3uParser;

/**
 * @see http://l189-238-14.cn.ru/api-doc/m3u-extending.html
 */
trait TagAttributesTrait
{
    /**
     * @var array<string, string>
     */
    private array $attributes = [];

    /**
     * example string: tvg-ID="" tvg-name="MEDI 1 SAT" tvg-logo="" group-title="ARABIC".
     */
    public function initAttributes(string $attrString): void
    {
        $this->parseAttributes($attrString);
    }

    /**
     * @return array<string, string>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name): ?string
    {
        return $this->attributes[$name] ?? null;
    }

    public function hasAttribute(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    /**
     * @param array<string, string> $attributes
     */
    public function setAttributes(array $attributes): self
    {
        $this->attributes = $attributes;

        return $this;
    }

    public function setAttribute(string $name, string $value): self
    {
        $this->attributes[$name] = $value;

        return $this;
    }

    protected function getAttributesString(): string
    {
        $out = '';

        foreach ($this->getAttributes() as $name => $value) {
            $escapedValue = \addcslashes($value, '"');
            $out .= "$name=\"$escapedValue\" ";
        }

        return \rtrim($out);
    }

    private function parseAttributes(string $attrString): void
    {
        \preg_match_all('/([^=" ]+)=("(?:\\\"|[^"])*"|(?:\\\"|[^=" ])+)/', $attrString, $matches, \PREG_SET_ORDER);

        foreach ($matches as $matchPair) {
            $key = $matchPair[1];
            $value = \stripslashes(\trim($matchPair[2], '"'));

            $this->setAttribute($key, $value);
        }
    }
}

<?php

trait TagAttributesTrait {
	/**
	 * @var array
	 */
	private $attributes = array();

	/**
	 * example string: tvg-ID="" tvg-name="MEDI 1 SAT" tvg-logo="" group-title="ARABIC".
	 */
	public function initAttributes(string $attrString): void {
		$this->parseQuotedAttributes($attrString);
		$this->parseNotQuotedAttributes($attrString);
	}

	public function getAttributes(): array {
		return $this->attributes;
	}

	public function getAttribute(string $name): ?string {
		return $this->attributes[$name] ?? null;
	}

	public function hasAttribute(string $name): bool {
		return isset($this->attributes[$name]);
	}

	/**
	 * @return $this
	 */
	public function setAttributes(array $attributes): TagAttributesTrait {
		$this->attributes = $attributes;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function setAttribute(string $name, string $value): TagAttributesTrait {
		$this->attributes[$name] = $value;

		return $this;
	}

	protected function getAttributesString(): string {
		$out = '';

		foreach ($this->getAttributes() as $name => $value) {
			$out .= $name . '="' . $value . '" ';
		}

		return rtrim($out);
	}

	private function parseQuotedAttributes(string $attrString): void {
		preg_match_all('/([a-zA-Z0-9\\-]+)="([^"]*)"/', $attrString, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			$this->setAttribute($match[1], $match[2]);
		}
	}

	private function parseNotQuotedAttributes(string $attrString): void {
		preg_match_all('/([a-zA-Z0-9\\-]+)=([^ "]+)/', $attrString, $matches, PREG_SET_ORDER);

		foreach ($matches as $match) {
			$this->setAttribute($match[1], $match[2]);
		}
	}
}

trait TagsManagerTrait {
	/**
	 * @var array
	 */
	private $tags = array();

	/**
	 * Add tag.
	 *
	 * @param string $tag class name must be implement ExtTagInterface interface
	 *
	 * @return $this
	 */
	public function addTag(string $tag): TagsManagerTrait {
		if (!in_array('ExtTagInterface', class_implements($tag), true)) {
			throw new Exception(sprintf('The class %s must be implement interface %s', $tag, 'ExtTagInterface'));
		}

		$this->tags[] = $tag;

		return $this;
	}

	/**
	 * Add default tags (EXTINF, EXTTV, EXTLOGO).
	 *
	 * @return $this
	 */
	public function addDefaultTags(): TagsManagerTrait {
		$this->addTag('ExtInf');
		$this->addTag('ExtTv');
		$this->addTag('ExtLogo');

		return $this;
	}

	/**
	 * Remove all previously defined tags.
	 *
	 * @return $this
	 */
	public function clearTags(): TagsManagerTrait {
		$this->tags = array();

		return $this;
	}

	/**
	 * Get all active tags.
	 *
	 * @return string[]
	 */
	protected function getTags(): array {
		return $this->tags;
	}
}

class M3uEntry {
	/**
	 * @var string
	 */
	protected $lineDelimiter = "\n";
	/**
	 * @var ExtTagInterface[]
	 */
	private $extTags = array();
	/**
	 * @var null|string
	 */
	private $path;

	public function __toString(): string {
		$out = '';

		foreach ($this->getExtTags() as $extTag) {
			$out .= $extTag . $this->lineDelimiter;
		}

		$out .= $this->getPath();

		return rtrim($out);
	}

	/**
	 * @return ExtTagInterface[]
	 */
	public function getExtTags(): array {
		return $this->extTags;
	}

	/**
	 * @return $this
	 */
	public function addExtTag(ExtTagInterface $extTag): M3uEntry {
		$this->extTags[] = $extTag;

		return $this;
	}

	/**
	 * Remove all previously defined tags.
	 *
	 * @return $this
	 */
	public function clearExtTags(): M3uEntry {
		$this->extTags = array();

		return $this;
	}

	public function getPath(): string {
		return $this->path;
	}

	/**
	 * @return $this
	 */
	public function setPath(string $path): M3uEntry {
		$this->path = $path;

		return $this;
	}
}

interface ExtTagInterface {
	public function __construct(?string $lineStr);

	public function __toString(): string;

	public static function isMatch(string $lineStr): bool;
}

class M3uParser {
	use TagsManagerTrait;

	/**
	 * Parse m3u file.
	 */
	public function parseFile(string $file): M3uData {
		$str = @file_get_contents($file);

		if (false === $str) {
			throw new Exception('Can\'t read file.');
		}

		return $this->parse($str);
	}

	/**
	 * Parse m3u string.
	 */
	public function parse(string $str): M3uData {
		$this->removeBom($str);
		$data = $this->createM3uData();
		$lines = explode("\n", $str);
		$l = count($lines);

		for ($i = 0; $i < $l; ++$i) {
			$lineStr = trim($lines[$i]);

			if (('' === $lineStr) || $this->isComment($lineStr)) {
			} elseif ($this->isExtM3u($lineStr)) {
				$tmp = trim(substr($lineStr, 7));

				if ($tmp) {
					$data->initAttributes($tmp);
				}
			} else {
				$data->append($this->parseLine($i, $lines));
			}
		}

		return $data;
	}

	protected function createM3uEntry(): M3uEntry {
		return new M3uEntry();
	}

	protected function createM3uData(): M3uData {
		return new M3uData();
	}

	/**
	 * Parse one line.
	 *
	 * @param string[] $linesStr
	 */
	protected function parseLine(int &$lineNumber, array $linesStr): M3uEntry {
		$entry = $this->createM3uEntry();
		$l = count($linesStr);

		while ($lineNumber < $l) {
			$nextLineStr = $linesStr[$lineNumber];
			$nextLineStr = trim($nextLineStr);

			if (('' === $nextLineStr) || $this->isComment($nextLineStr) || $this->isExtM3u($nextLineStr)) {
			} else {
				$matched = false;

				foreach ($this->getTags() as $availableTag) {
					if ($availableTag::isMatch($nextLineStr)) {
						$matched = true;
						$entry->addExtTag(new $availableTag($nextLineStr));

						break;
					}
				}

				if (!$matched) {
					$entry->setPath($nextLineStr);

					break;
				}
			}

			++$lineNumber;
		}

		return $entry;
	}

	protected function removeBom(string &$str): void {
		if (0 === strpos($str, '﻿')) {
			$str = substr($str, 3);
		}
	}

	protected function isExtM3u(string $lineStr): bool {
		return 0 === stripos($lineStr, '#EXTM3U');
	}

	protected function isComment(string $lineStr): bool {
		$matched = false;

		foreach ($this->getTags() as $availableTag) {
			if ($availableTag::isMatch($lineStr)) {
				$matched = true;

				break;
			}
		}

		return !$matched && (0 === strpos($lineStr, '#')) && !$this->isExtM3u($lineStr);
	}
}

class M3uData extends ArrayIterator {
	use TagAttributesTrait;

	public function __toString(): string {
		$out = rtrim('#EXTM3U ' . $this->getAttributesString()) . "\n";

		foreach ($this as $entry) {
			$out .= $entry . "\n";
		}

		return rtrim($out);
	}
}

class ExtInf implements ExtTagInterface {
	use TagAttributesTrait;

	/**
	 * @var string
	 */
	private $title;
	/**
	 * @var int
	 */
	private $duration;

	/**
	 * #EXTINF:-1 tvg-name=Первый_HD tvg-logo="Первый канал" deinterlace=4 group-title="Эфирные каналы",Первый канал HD.
	 */
	public function __construct(?string $lineStr = null) {
		if (null !== $lineStr) {
			$this->make($lineStr);
		}
	}

	public function __toString(): string {
		return '#EXTINF: ' . $this->getDuration() . ' ' . $this->getAttributesString() . ', ' . $this->getTitle();
	}

	/**
	 * @return $this
	 */
	public function setTitle(string $title): ExtInf {
		$this->title = $title;

		return $this;
	}

	public function getTitle(): string {
		return $this->title;
	}

	/**
	 * @return $this
	 */
	public function setDuration(int $duration): ExtInf {
		$this->duration = $duration;

		return $this;
	}

	public function getDuration(): int {
		return $this->duration;
	}

	public static function isMatch(string $lineStr): bool {
		return 0 === stripos($lineStr, '#EXTINF:');
	}

	/**
	 * @see http://l189-238-14.cn.ru/api-doc/m3u-extending.html
	 */
	protected function make(string $lineStr): void {
		$dataLineStr = substr($lineStr, strlen('#EXTINF:'));
		$dataLineStr = trim($dataLineStr);
		preg_match('/^(-?\\d+)\\s*(?:(?:[^=]+=["\'][^"\']*["\'])|(?:[^=]+=[^ ]*))*,(.*)$/', $dataLineStr, $matches);
		$this->setDuration((int) $matches[1]);
		$this->setTitle(trim($matches[2]));
		$attributes = preg_replace('/^' . preg_quote($matches[1], '/') . '(.*)' . preg_quote($matches[2], '/') . '$/', '$1', $dataLineStr);
		$splitAttributes = explode(' ', $attributes, 2);

		if (isset($splitAttributes[1]) && ($trimmedAttributes = trim($splitAttributes[1]))) {
			$this->initAttributes($trimmedAttributes);
		}
	}
}

class ExtLogo implements ExtTagInterface {
	/**
	 * @var string
	 */
	private $logo;

	/**
	 * #EXTLOGO:http://cdn1.siol.tv/logo/93x78/slo2.png.
	 */
	public function __construct(?string $lineStr = null) {
		if (null !== $lineStr) {
			$this->makeData($lineStr);
		}
	}

	public function __toString(): string {
		return '#EXTLOGO: ' . $this->getLogo();
	}

	public function getLogo(): string {
		return $this->logo;
	}

	/**
	 * @return $this
	 */
	public function setLogo(string $logo): ExtLogo {
		$this->logo = $logo;

		return $this;
	}

	public static function isMatch(string $lineStr): bool {
		return 0 === stripos($lineStr, '#EXTLOGO:');
	}

	protected function makeData(string $lineStr): void {
		$tmp = substr($lineStr, strlen('#EXTLOGO:'));
		$logo = trim($tmp);
		$this->setLogo($logo);
	}
}

class ExtTv implements ExtTagInterface {
	/**
	 * @var string[]
	 */
	private $tags;
	/**
	 * @var string
	 */
	private $language;
	/**
	 * @var string
	 */
	private $xmlTvId;
	/**
	 * @var null|string
	 */
	private $iconUrl;

	/**
	 * #EXTTV:nacionalni,hd;slovenski;SLO1;http://cdn1.siol.tv/logo/93x78/slo2.png.
	 */
	public function __construct(?string $lineStr = null) {
		if (null !== $lineStr) {
			$this->makeData($lineStr);
		}
	}

	public function __toString(): string {
		return '#EXTTV: ' . implode(',', $this->getTags()) . ';' . $this->getLanguage() . ';' . $this->getXmlTvId() . ($this->getIconUrl() ? ';' . $this->getIconUrl() : '');
	}

	/**
	 * @return string[]
	 */
	public function getTags(): array {
		return $this->tags;
	}

	/**
	 * @param string[] $tags
	 *
	 * @return $this
	 */
	public function setTags(array $tags): ExtTv {
		$this->tags = $tags;

		return $this;
	}

	public function getLanguage(): string {
		return $this->language;
	}

	/**
	 * @return $this
	 */
	public function setLanguage(string $language): ExtTv {
		$this->language = $language;

		return $this;
	}

	public function getXmlTvId(): string {
		return $this->xmlTvId;
	}

	/**
	 * @return $this
	 */
	public function setXmlTvId(string $xmlTvId): ExtTv {
		$this->xmlTvId = $xmlTvId;

		return $this;
	}

	public function getIconUrl(): ?string {
		return $this->iconUrl;
	}

	/**
	 * @return $this
	 */
	public function setIconUrl(?string $iconUrl): ExtTv {
		$this->iconUrl = $iconUrl;

		return $this;
	}

	public static function isMatch(string $lineStr): bool {
		return 0 === stripos($lineStr, '#EXTTV:');
	}

	protected function makeData(string $lineStr): void {
		$tmp = substr($lineStr, strlen('#EXTTV:'));
		$split = explode(';', $tmp, 4);
		$this->setTags(array_map('trim', explode(',', $split[0])));
		$this->setLanguage(trim($split[1]));
		$this->setXmlTvId(trim($split[2]));

		if (isset($split[3])) {
			$this->setIconUrl(trim($split[3]));
		}
	}
}

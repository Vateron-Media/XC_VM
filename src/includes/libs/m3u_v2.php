<?php

class Config
{
	private $data;

	public function __construct(array $data)
	{
		$this->data = $data;
	}

	public function get($key, $default = NULL)
	{
		if (!is_string($key)) {
			throw new InvalidArgumentException(sprintf('$key can only be string, got %s', var_export($key, true)));
		}

		if (array_key_exists($key, $this->data)) {
			return $this->data[$key];
		}

		if (NULL === $default) {
			throw new OutOfBoundsException(sprintf('Unknown config "%s"', $key));
		}

		return $default;
	}

	protected function getData()
	{
		return $this->data;
	}
}

class AttributeListParser
{
	private $valueParsers;
	private $attributeStringToArray;

	public function __construct(Config $valueParsers, AttributeStringToArray $attributeStringToArray)
	{
		$this->valueParsers = $valueParsers;
		$this->attributeStringToArray = $attributeStringToArray;
	}

	public function parse($string, array $types)
	{
		$attributeParse = $this->attributeStringToArray;
		$attributes = $attributeParse($string);
		$result = new ArrayObject();

		foreach ($attributes as $key => $value) {
			if (!isset($types[$key])) {
				continue;
			}

			$type = $types[$key];
			$parse = $this->valueParsers->get($type);

			if (is_callable($parse)) {
				$result[$key] = $parse($value);
			}
		}

		return $result;
	}
}

class DataBuilder
{
	private $currentMediaSegment;
	private $result;
	private $lastAddedTag;

	public function addUri($uri)
	{
		if (NULL !== $this->currentMediaSegment) {
			$this->currentMediaSegment['uri'] = $uri;
			$this->currentMediaSegment = NULL;
			return NULL;
		}

		if ($this->lastAddedTag['definition']->isUriAware()) {
			$this->lastAddedTag['value']['uri'] = $uri;
			return NULL;
		}

		throw new DataBuildingException('uri found, but doesn\'t know how to handle it');
	}

	public function addTag(TagDefinition $definition, $data)
	{
		$parent = $this->result;

		if ('media-segment' === $definition->getCategory()) {
			if (NULL === $this->currentMediaSegment) {
				$this->currentMediaSegment = new ArrayObject();
				$this->result['mediaSegments'][] = $this->currentMediaSegment;
			}

			$parent = $this->currentMediaSegment;
		}

		$this->lastAddedTag = ['definition' => $definition, 'value' => $data];

		if ($definition->isMultiple()) {
			$parent[$definition->getTag()][] = $data;
			return NULL;
		}

		$parent[$definition->getTag()] = $data;
	}

	public function getResult()
	{
		return $this->result;
	}

	public function reset()
	{
		$this->currentMediaSegment = NULL;
		$this->result = new ArrayObject();
		$this->lastAddedTag = NULL;
	}
}

class DataBuildingException
{
}

class Parser
{
	private $tagDefinitions;
	private $valueParsers;
	private $dataBuilder;

	public function __construct(TagDefinitions $tagDefinitions, Config $valueParsers, DataBuilder $dataBuilder)
	{
		$this->tagDefinitions = $tagDefinitions;
		$this->valueParsers = $valueParsers;
		$this->dataBuilder = $dataBuilder;
	}

	public function parse(Lines $lines)
	{
		$this->dataBuilder->reset();

		foreach ($lines as $line) {
			if ($line->isType(Line::TYPE_URI)) {
				$this->dataBuilder->addUri($line->getValue());
				continue;
			}

			$tag = $line->getTag();
			$definition = $this->tagDefinitions->get($tag);

			if (NULL === $definition) {
				continue;
			}

			$valueType = $definition->getValueType();
			$value = $line->getValue();
			$parse = $this->valueParsers->get($valueType);

			if (is_callable($parse)) {
				$value = ('attribute-list' === $valueType ? $parse($value, $definition->getAttributeTypes()) : $parse($value));
			}

			$this->dataBuilder->addTag($definition, $value);
		}

		return $this->dataBuilder->getResult();
	}
}

class Line
{
	public const TYPE_URI = 'uri';
	public const TYPE_TAG = 'tag';

	private $tag;
	private $value;

	public function __construct($tag = NULL, $value = NULL)
	{
		if ((NULL === $tag) && (NULL === $value)) {
			throw new InvalidArgumentException('$tag and $value can not both be null');
		}

		$this->tag = $tag;
		$this->value = $value;
	}

	static public function fromString($line)
	{
		$line = trim($line);

		if (empty($line)) {
			return NULL;
		}

		if ('#' !== $line[0]) {
			return new self(NULL, $line);
		}

		if ('#EXT' !== substr($line, 0, 4)) {
			return NULL;
		}

		$line = ltrim($line, '#');

		if (empty($line)) {
			return NULL;
		}

		list($tag, $value) = array_pad(explode(':', $line, 2), 2, true);
		return new self($tag, $value);
	}

	public function getTag()
	{
		return $this->tag;
	}

	public function getValue()
	{
		return $this->value;
	}

	public function isType($type)
	{
		return $this->getType() === $type;
	}

	public function __toString()
	{
		if ($this->isType('uri')) {
			return $this->value;
		}

		if (true === $this->value) {
			return sprintf('#%s', $this->tag);
		}

		if (false === $this->value) {
			return '';
		}

		return sprintf('#%s:%s', $this->tag, $this->value);
	}

	private function getType()
	{
		if (NULL !== $this->tag) {
			return 'tag';
		}

		return 'uri';
	}
}

class AttributeListDumper
{
	private $valueDumper;

	public function __construct(Config $valueDumper)
	{
		$this->valueDumper = $valueDumper;
	}

	public function dump(ArrayAccess $data, array $types)
	{
		$result = [];

		foreach ($data as $key => $value) {
			if (!isset($types[$key])) {
				continue;
			}

			$type = $types[$key];
			$dump = $this->valueDumper->get($type);
			$result[] = sprintf('%s=%s', $key, $dump($value));
		}

		if (!empty($result)) {
			return implode(',', $result);
		}
	}
}

class Dumper
{
	private $tagDefinitions;
	private $valueDumpers;

	public function __construct(TagDefinitions $tagDefinitions, Config $valueDumpers)
	{
		$this->tagDefinitions = $tagDefinitions;
		$this->valueDumpers = $valueDumpers;
	}

	public function dumpToLines(ArrayAccess $data, Lines $lines)
	{
		$lines->add(new Line('EXTM3U', true));
		$this->iterateTags($this->tagDefinitions->getHeadTags(), $data, $lines);

		if (!isset($data['mediaSegments'])) {
			return NULL;
		}

		foreach ($data['mediaSegments'] as $mediaSegment) {
			$this->iterateTags($this->tagDefinitions->getMediaSegmentTags(), $mediaSegment, $lines);
			$lines->add(new Line(NULL, $mediaSegment['uri']));
		}

		$this->iterateTags($this->tagDefinitions->getFootTags(), $data, $lines);
	}

	private function iterateTags(array $tags, ArrayAccess $data, Lines $lines)
	{
		foreach ($tags as $tag) {
			if (!isset($data[$tag])) {
				continue;
			}

			$definition = $this->tagDefinitions->get($tag);
			$value = $data[$tag];

			if (!$definition->isMultiple()) {
				$this->dumpAndAddToLines($definition, $value, $lines);
				continue;
			}

			foreach ($value as $element) {
				$this->dumpAndAddToLines($definition, $element, $lines);
			}
		}
	}

	private function dumpValue(TagDefinition $definition, $value)
	{
		$valueType = $definition->getValueType();
		$dump = $this->valueDumpers->get($valueType);

		if (!is_callable($dump)) {
			return $value;
		}

		if ('attribute-list' === $valueType) {
			return $dump($value, $definition->getAttributeTypes());
		}

		return $dump($value);
	}

	private function dumpAndAddToLines(TagDefinition $definition, $value, Lines $lines)
	{
		$lines->add(new Line($definition->getTag(), $this->dumpValue($definition, $value)));

		if ($definition->isUriAware()) {
			$lines->add(new Line(NULL, $value['uri']));
		}
	}
}

class DumpingException
{
}

class DefinitionException
{
}

class TagDefinition
{
	private $tag;
	private $config;
	private $attributeTypes;

	public function __construct($tag, Config $config)
	{
		if (!is_string($tag)) {
			throw new InvalidArgumentException('$tag can only be string, got %s', gettype($tag));
		}

		$this->tag = $tag;
		$this->config = $config;
	}

	public function getTag()
	{
		return $this->tag;
	}

	public function getValueType()
	{
		$type = $this->config->get('type');

		if (is_array($type)) {
			$this->attributeTypes = $type;
			return 'attribute-list';
		}

		return $type;
	}

	public function isMultiple()
	{
		return $this->config->get('multiple', false);
	}

	public function getCategory()
	{
		return $this->config->get('category');
	}

	public function isUriAware()
	{
		return $this->config->get('uriAware', false);
	}

	public function getAttributeTypes()
	{
		return $this->attributeTypes;
	}
}

class TagDefinitions
{
	private $definitions;
	private $headTags;
	private $mediaSegmentTags;
	private $footTags;

	public function __construct(array $definitions)
	{
		foreach ($definitions as $tag => $definition) {
			$position = $definition['position'];

			if ('media-segment' === $definition['category']) {
				$this->mediaSegmentTags[$definition['position']] = $tag;
				continue;
			}

			if ($position < 0) {
				$this->headTags[$position] = $tag;
				continue;
			}

			$this->footTags[$position] = $tag;
		}

		$this->definitions = $definitions;
		ksort($this->headTags);
		ksort($this->mediaSegmentTags);
		ksort($this->footTags);
	}

	public function get($tag)
	{
		if (!is_string($tag)) {
			throw new InvalidArgumentException('$tag can only be string, got %s', gettype($tag));
		}

		if (!isset($this->definitions[$tag])) {
			return NULL;
		}

		return new TagDefinition($tag, new Config($this->definitions[$tag]));
	}

	public function getHeadTags()
	{
		return $this->headTags;
	}

	public function getMediaSegmentTags()
	{
		return $this->mediaSegmentTags;
	}

	public function getFootTags()
	{
		return $this->footTags;
	}
}

class AttributeStringToArray
{
	public function __invoke($string)
	{
		if (!is_string($string)) {
			throw new InvalidArgumentException(sprintf('$string can only be string, got %s', gettype($string)));
		}

		preg_match_all('/(?<=^|,)[A-Z0-9-]+=("?).+?\\1(?=,|$)/', $string, $matches);
		$attrs = [];

		foreach ($matches[0] as $attr) {
			list($key, $value) = explode('=', $attr, 2);
			$attrs[$key] = $value;
		}

		return $attrs;
	}
}

class Iso8601Transformer
{
	static public function fromString($string)
	{
		return new DateTime($string);
	}

	static public function toString(DateTime $datetime)
	{
		$timezone = $datetime->format('P');
		return sprintf('%s%s', substr($datetime->format('Y-m-d\\TH:i:s.u'), 0, -3), $timezone);
	}
}

class Resolution
{
	private $width;
	private $height;

	public function __construct($width, $height)
	{
		$this->width = (int) $width;
		$this->height = (int) $height;
		if (($this->width < 1) || ($this->height < 1)) {
			throw new InvalidArgumentException('$width or $height should be an integer greater than 0');
		}
	}

	static public function fromString($string)
	{
		list($width, $height) = explode('x', $string);
		return new self($width, $height);
	}

	public function getWidth()
	{
		return $this->width;
	}

	public function getHeight()
	{
		return $this->height;
	}

	public function __toString()
	{
		return sprintf('%dx%d', $this->width, $this->height);
	}
}

class Byterange
{
	private $length;
	private $offset;

	public function __construct($length, $offset = NULL)
	{
		$this->length = (int) $length;

		if ($this->length < 1) {
			throw new InvalidArgumentException('$length should be an integer greater than 0');
		}

		if (NULL === $offset) {
			return NULL;
		}

		$this->offset = (int) $offset;

		if ($this->offset < 1) {
			throw new InvalidArgumentException('$offset should be an integer greater than 0');
		}
	}

	static public function fromString($string)
	{
		list($length, $offset) = array_pad(explode('@', $string), 2, NULL);
		return new self($length, $offset);
	}

	public function getLength()
	{
		return $this->length;
	}

	public function getOffset()
	{
		return $this->offset;
	}

	public function __toString()
	{
		if (NULL === $this->offset) {
			return (string) $this->length;
		}

		return sprintf('%d@%d', $this->length, $this->offset);
	}
}

class Inf
{
	private $duration;
	private $title;
	private $version;

	public function __construct($duration, $title = NULL, $version = 6)
	{
		$this->duration = 1 * $duration;

		if ($this->duration < 0) {
			throw new InvalidArgumentException('$duration should not be less than 0');
		}

		$this->version = (int) $version;
		if (($this->version < 2) || (7 < $this->version)) {
			throw new InvalidArgumentException(sprintf('$version should be an integer greater than 1 and less than 8'));
		}

		if (NULL === $title) {
			return NULL;
		}

		$this->title = (string) $title;
	}

	static public function fromString($string)
	{
		list($duration, $title) = explode(',', $string);
		return new self($duration, $title);
	}

	public function getDuration()
	{
		return $this->duration;
	}

	public function setTitle($title)
	{
		$this->title = $title;
	}

	public function getTitle()
	{
		return $this->title;
	}

	public function __toString()
	{
		if ($this->version < 3) {
			return sprintf('%d,%s', round($this->duration), $this->title);
		}

		return sprintf('%.3f,%s', $this->duration, $this->title);
	}
}

class ParserFacade
{
	private $parser;

	public function parse(StreamInterface $stream)
	{
		if (NULL === $this->parser) {
			$tagDefinitions = new TagDefinitions(require '/home/xc_vm/includes/libs/resources/tags.php');
			$this->parser = new Parser($tagDefinitions, new Config(require '/home/xc_vm/includes/libs/resources/tagValueParsers.php'), new DataBuilder());
		}

		return $this->parser->parse(new Lines($stream));
	}
}

class DumperFacade
{
	private $dumper;

	public function dump(ArrayAccess $data, StreamInterface $stream)
	{
		if (NULL === $this->dumper) {
			$tagDefinitions = new TagDefinitions(require '/home/xc_vm/includes/libs/resources/tags.php');
			$this->dumper = new Dumper($tagDefinitions, new Config(require '/home/xc_vm/includes/libs/resources/tagValueDumpers.php'));
		}

		$this->dumper->dumpToLines($data, new Lines($stream));
	}
}

interface StreamInterface implements Iterator
{
	public function add($line);
}

class FileStream extends SplFileObject implements StreamInterface
{
	public function add($line)
	{
		$this->fwrite($line . "\n");
	}
}

class TextStream extends ArrayIterator implements StreamInterface
{
	public function __construct($text = NULL)
	{
		$lines = [];

		if (NULL !== $text) {
			$lines = explode("\n", trim($text));
		}

		parent::__construct($lines);
	}

	public function add($line)
	{
		$this->append($line);
	}

	public function __toString()
	{
		return implode("\n", $this->getArrayCopy()) . "\n";
	}
}

class Lines implements Iterator
{
	private $stream;
	private $current;

	public function __construct(StreamInterface $stream)
	{
		$this->stream = $stream;
	}

	public function current()
	{
		return $this->current;
	}

	public function add(Line $line)
	{
		$this->stream->add((string) $line);
	}

	public function next()
	{
		$this->stream->next();
	}

	public function valid()
	{
		$this->current = NULL;

		while ((NULL === $this->current) && $this->stream->valid()) {
			if ($this->stream->current()) {
				$line = Line::fromString($this->stream->current());

				if (NULL !== $line) {
					$this->current = $line;
					return true;
				}
			}

			$this->stream->next();
		}

		return false;
	}

	public function rewind()
	{
		$this->stream->rewind();
	}

	public function key()
	{
		return $this->stream->key();
	}
}

?>
<?php

/**
 * The base class for the xml-string-streamer
 */
class XmlStringStreamer {
	/**
	 * The current parser
	 * @var ParserInterface
	 */
	protected $parser;
	/**
	 * The current stream
	 * @var StreamInterface
	 */
	protected $stream;

	/**
	 * Constructs the XML streamer
	 * @param ParserInterface $parser A parser with options set
	 * @param StreamInterface $stream A stream for the parser to use
	 */
	public function __construct(ParserInterface $parser, StreamInterface $stream) {
		$this->parser = $parser;
		$this->stream = $stream;
	}

	/**
	 * Convenience method for creating a StringWalker parser with a File stream
	 * @param  string|resource $file    File path or handle
	 * @param  array           $options Parser configuration
	 * @return XmlStringStreamer        A streamer ready for use
	 */
	public static function createStringWalkerParser($file, $options = array()) {
		$stream = new File($file, 16384);
		$parser = new StringWalker($options);

		return new XmlStringStreamer($parser, $stream);
	}

	/**
	 * Convenience method for creating a UniqueNode parser with a File stream
	 * @param  string|resource $file    File path or handle
	 * @param  array           $options Parser configuration
	 * @return XmlStringStreamer        A streamer ready for use
	 */
	public static function createUniqueNodeParser($file, $options = array()) {
		$stream = new File($file, 16384);
		$parser = new UniqueNode($options);

		return new XmlStringStreamer($parser, $stream);
	}

	/**
	 * Gets the next node from the parser
	 * @return bool|string The xml string or false
	 */
	public function getNode() {
		return $this->parser->getNodeFrom($this->stream);
	}
}

/**
 * Interface describing a parser
 */
interface ParserInterface {
	/**
	 * Parser contructor
	 * @param array $options An options array decided by the parser implementation
	 */
	public function __construct(array $options);

	/**
	 * Tries to retrieve the next node or returns false
	 * @param  StreamInterface $stream The stream to use
	 * @return string|bool             The next xml node or false if one could not be retrieved
	 */
	public function getNodeFrom(StreamInterface $stream);

	/**
	 * Get the extracted container XML, if called before the whole stream is parsed, the XML returned can be invalid due to missing closing tags
	 * @return string XML string
	 */
	public function getExtractedContainer();
}

/**
 * Interface describing a stream provider
 */
interface StreamInterface {
	/**
	 * Gets the next chunk form the stream if one is available
	 * @return bool|string The next chunk if available, or false if not available
	 */
	public function getChunk();

	/**
	 * Is the stream seekable?
	 * @return bool
	 */
	public function isSeekable();

	/**
	 * Rewind the stream
	 * @return void
	 * @throws Exception if the stream isn't seekable
	 */
	public function rewind();
}

/**
 * The string walker parser builds the XML nodes by fetching one element at a time until a certain depth is re-reached
 */
class StringWalker implements ParserInterface {
	/**
	 * Holds the parser configuration
	 * @var array
	 */
	protected $options;
	/**
	 * Is this the first run?
	 * @var boolean
	 */
	protected $firstRun = true;
	/**
	 * What depth are we currently at?
	 * @var integer
	 */
	protected $depth = 0;
	/**
	 * The latest chunk from the stream
	 * @var string
	 */
	protected $chunk;
	/**
	 * Last XML node in the making, used for anti-freeze detection
	 * @var null|string
	 */
	protected $lastChunk;
	/**
	 * XML node in the making
	 * @var null|string
	 */
	protected $shaved;
	/**
	 * Whether to capture or not
	 * @var boolean
	 */
	protected $capture = false;
	/**
	 * If extractContainer is true, this will grow with the XML captured before and after the specified capture depth
	 * @var string
	 */
	protected $containerXml = '';

	/**
	 * Parser contructor
	 * @param array $options An options array
	 */
	public function __construct(array $options = array()) {
		$this->options = array_merge(array(
			'captureDepth' => 2,
			'expectGT' => false,
			'tags' => array(
				array('<?', '?>', 0),
				array('<!--', '-->', 0),
				array('<![CDATA[', ']]>', 0),
				array('<!', '>', 0),
				array('</', '>', -1),
				array('<', '/>', 0),
				array('<', '>', 1)
			),
			'tagsWithAllowedGT' => array(
				array('<!--', '-->'),
				array('<![CDATA[', ']]>')
			),
			'extractContainer' => false
		), $options);
	}

	/**
	 * Shaves off the next element from the chunk
	 * @return string[]|bool Either a shaved off element array(0 => Captured element, 1 => Data from last shaving point up to and including captured element) or false if one could not be obtained
	 */
	protected function shave() {
		preg_match('/<[^>]+>/', $this->chunk, $matches, PREG_OFFSET_CAPTURE);

		if (isset($matches[0]) && isset($matches[0][0]) && isset($matches[0][1])) {
			list($captured, $offset) = $matches[0];

			if ($this->options['expectGT']) {
				foreach ($this->options['tagsWithAllowedGT'] as $tag) {
					list($opening, $closing) = $tag;

					if (substr($captured, 0, strlen($opening)) === $opening) {
						if (substr($captured, -1 * strlen($closing)) !== $closing) {
							$position = strpos($this->chunk, $closing);

							if ($position === false) {
								return false;
							}

							$captured = substr($this->chunk, $offset, ($position + strlen($closing)) - $offset);
						}
					}
				}
			}

			$data = substr($this->chunk, 0, $offset);
			$this->chunk = substr($this->chunk, $offset + strlen($captured));

			return array($captured, $data . $captured);
		}

		return false;
	}

	/**
	 * Extract XML compatible tag head and tail
	 * @param  string $element XML element
	 * @return string[] 0 => Opening tag, 1 => Closing tag
	 */
	protected function getEdges($element) {
		foreach ($this->options['tags'] as $tag) {
			list($opening, $closing, $depth) = $tag;

			if ((substr($element, 0, strlen($opening)) === $opening) && (substr($element, -1 * strlen($closing)) === $closing)) {
				return $tag;
			}
		}
	}

	/**
	 * The shave method must be able to request more data even though there isn't any more to fetch from the stream, this method wraps the getChunk call so that it returns true as long as there is XML data left
	 * @param  StreamInterface $stream The stream to read from
	 * @return bool Returns whether there is more XML data or not
	 */
	protected function prepareChunk(StreamInterface $stream) {
		if (!$this->firstRun && is_null($this->shaved)) {
			$this->shaved = '';

			return true;
		} elseif (is_null($this->shaved)) {
			$this->shaved = '';
		}

		$newChunk = $stream->getChunk();

		if ($newChunk !== false) {
			$this->chunk .= $newChunk;

			return true;
		} elseif ((trim($this->chunk) !== '') && ($this->chunk !== $this->lastChunk)) {
			$this->lastChunk = $this->chunk;

			return true;
		}

		return false;
	}

	/**
	 * Get the extracted container XML, if called before the whole stream is parsed, the XML returned will most likely be invalid due to missing closing tags
	 * @return string XML string
	 * @throws Exception if the extractContainer option isn't true
	 */
	public function getExtractedContainer() {
		if (!$this->options['extractContainer']) {
			throw new Exception('This method requires the \'extractContainer\' option to be true');
		}

		return $this->containerXml;
	}

	/**
	 * Tries to retrieve the next node or returns false
	 * @param  StreamInterface $stream The stream to use
	 * @return string|bool             The next xml node or false if one could not be retrieved
	 */
	public function getNodeFrom(StreamInterface $stream) {
		while ($this->prepareChunk($stream)) {
			$this->firstRun = false;

			while ($shaved = $this->shave()) {
				list($element, $data) = $shaved;
				list($opening, $closing, $depth) = $this->getEdges($element);
				$this->depth += $depth;
				$flush = false;
				$captureOnce = false;

				if (($this->depth === $this->options['captureDepth']) && (0 < $depth)) {
					$this->capture = true;
				} elseif (($this->depth === $this->options['captureDepth'] - 1) && ($depth < 0)) {
					$flush = true;
					$this->capture = false;
					$this->shaved .= $data;
				} elseif ($this->options['extractContainer'] && ($this->depth < $this->options['captureDepth'])) {
					$this->containerXml .= $element;
				} elseif (($depth === 0) && (($this->depth + 1) === $this->options['captureDepth'])) {
					$captureOnce = true;
					$flush = true;
				}

				if ($this->capture || $captureOnce) {
					$this->shaved .= $data;
				}

				if ($flush) {
					$flush = $this->shaved;
					$this->shaved = null;

					return $flush;
				}
			}
		}

		return false;
	}
}

/**
 * The unique node parser starts at a given element name and flushes when its corresponding closing tag is found
 */
class UniqueNode implements ParserInterface {
	public const FIND_OPENING_TAG_ACTION = 0;
	public const FIND_CLOSING_TAG_ACTION = 1;

	/**
	 * Current working XML blob
	 * @var string
	 */
	private $workingBlob = '';
	/**
	 * The flushed node
	 * @var string
	 */
	private $flushed = '';
	/**
	 * Start position of the given element in the workingBlob
	 * @var integer
	 */
	private $startPos = 0;
	/**
	 * Records how far we've searched in the XML blob so far
	 * @var integer
	 */
	private $hasSearchedUntilPos = -1;
	/**
	 * Next action to perform
	 * @var integer
	 */
	private $nextAction = 0;
	/**
	 * Indicates short closing tag
	 * @var bool
	 */
	private $shortClosedTagNow = false;
	/**
	 * If extractContainer is true, this will grow with the XML captured before and after the specified capture depth
	 * @var string
	 */
	protected $containerXml = '';
	/**
	 * Whether we're found our first capture target or not
	 * @var bool
	 */
	protected $preCapture = true;

	/**
	 * Parser constructor
	 * @param array $options An options array
	 * @throws Exception if the required option uniqueNode isn't set
	 */
	public function __construct(array $options = array()) {
		$this->options = array_merge(array('extractContainer' => false), $options);

		if (!isset($this->options['uniqueNode'])) {
			throw new Exception('Required option \'uniqueNode\' not set');
		}
	}

	/**
	 * Search the blob for our unique node's opening tag
	 * @return bool|int Either returns the char position of the opening tag or false
	 */
	protected function getOpeningTagPos() {
		$startPositionInBlob = false;

		if (preg_match('/<' . preg_quote($this->options['uniqueNode']) . '(>| )/', $this->workingBlob, $matches, PREG_OFFSET_CAPTURE) === 1) {
			$startPositionInBlob = $matches[0][1];
		}

		if ($startPositionInBlob === false) {
			$this->hasSearchedUntilPos = strlen($this->workingBlob) - 1;
		}

		return $startPositionInBlob;
	}

	/**
	 * Search short closing tag in $workingBlob before
	 *
	 * @param string $workingBlob
	 * @param int $len
	 * @return bool|int Either returns the char position of the short closing tag or false
	 */
	private function checkShortClosingTag($workingBlob, $len) {
		$resultEndPositionInBlob = false;

		while ($len = strpos($workingBlob, '/>', $len + 1)) {
			$subBlob = substr($workingBlob, $this->startPos, ($len + strlen('/>')) - $this->startPos);
			$cntOpen = substr_count($subBlob, '<');
			$cntClose = substr_count($subBlob, '/>');

			if (($cntOpen === $cntClose) && ($cntOpen === 1)) {
				$resultEndPositionInBlob = $len + strlen('/>');

				break;
			}
		}

		return $resultEndPositionInBlob;
	}

	/**
	 * Search the blob for our unique node's closing tag
	 * @return bool|int Either returns the char position of the closing tag or false
	 */
	protected function getClosingTagPos() {
		$endPositionInBlob = strpos($this->workingBlob, '</' . $this->options['uniqueNode'] . '>');

		if ($endPositionInBlob === false) {
			if (isset($this->options['checkShortClosing']) && ($this->options['checkShortClosing'] === true)) {
				$endPositionInBlob = $this->checkShortClosingTag($this->workingBlob, $this->startPos);
			}

			if ($endPositionInBlob === false) {
				$this->hasSearchedUntilPos = strlen($this->workingBlob) - 1;
			} else {
				$this->shortClosedTagNow = true;
			}
		} elseif (isset($this->options['checkShortClosing']) && ($this->options['checkShortClosing'] === true)) {
			$tmpEndPositionInBlob = $this->checkShortClosingTag(substr($this->workingBlob, 0, $endPositionInBlob), $this->startPos);

			if ($tmpEndPositionInBlob !== false) {
				$this->shortClosedTagNow = true;
				$endPositionInBlob = $tmpEndPositionInBlob;
			}
		}

		return $endPositionInBlob;
	}

	/**
	 * Set the start position in the workingBlob from where we should start reading when the closing tag is found
	 * @param  int $startPositionInBlob Position of starting tag
	 */
	protected function startSalvaging($startPositionInBlob) {
		$this->startPos = $startPositionInBlob;
	}

	/**
	 * Cut everything from the start position to the end position in the workingBlob (+ tag length) and flush it out for later return in getNodeFrom
	 * @param  int $endPositionInBlob Position of the closing tag
	 */
	protected function flush($endPositionInBlob) {
		$endTagLen = ($this->shortClosedTagNow ? 0 : strlen('</' . $this->options['uniqueNode'] . '>'));
		$realEndPosition = $endPositionInBlob + $endTagLen;
		$this->flushed = substr($this->workingBlob, $this->startPos, $realEndPosition - $this->startPos);
		$this->workingBlob = substr($this->workingBlob, $realEndPosition);
		$this->hasSearchedUntilPos = 0;
		$this->shortClosedTagNow = false;
	}

	/**
	 * Decides whether we're to fetch more chunks from the stream or keep working with what we have.
	 * @param  StreamInterface $stream The stream provider
	 * @return bool                    Keep working?
	 */
	protected function prepareChunk(StreamInterface $stream) {
		if ((-1 < $this->hasSearchedUntilPos) && ($this->hasSearchedUntilPos < (strlen($this->workingBlob) - 1))) {
			return true;
		}

		$chunk = $stream->getChunk();

		if ($chunk === false) {
			if (($this->hasSearchedUntilPos === -1) && (0 < strlen($this->workingBlob))) {
				return true;
			}

			return false;
		} else {
			if (($this->nextAction === 0) && !$this->options['extractContainer']) {
				$this->workingBlob = substr($this->workingBlob, -1 * strlen('<' . $this->options['uniqueNode'] . '>')) . $chunk;
			} else {
				$this->workingBlob .= $chunk;
			}

			return true;
		}
	}

	/**
	 * Tries to retrieve the next node or returns false
	 * @param  StreamInterface $stream The stream to use
	 * @return string|bool             The next xml node or false if one could not be retrieved
	 */
	public function getNodeFrom(StreamInterface $stream) {
		while ($this->prepareChunk($stream)) {
			if ($this->nextAction === 0) {
				$positionInBlob = $this->getOpeningTagPos();

				if ($positionInBlob !== false) {
					if ($this->options['extractContainer'] && $this->preCapture) {
						$this->containerXml .= substr($this->workingBlob, 0, $positionInBlob);
						$this->preCapture = false;
					}

					$this->startSalvaging($positionInBlob);
					$this->nextAction = 1;
				}
			}

			if ($this->nextAction === 1) {
				$positionInBlob = $this->getClosingTagPos();

				if ($positionInBlob !== false) {
					$this->flush($positionInBlob);
					$this->nextAction = 0;
					$flushed = $this->flushed;
					$this->flushed = '';

					return $flushed;
				}
			}
		}

		if ($this->options['extractContainer']) {
			$this->containerXml .= $this->workingBlob;
		}

		return false;
	}

	/**
	 * Get the extracted container XML, if called before the whole stream is parsed, the XML returned can be invalid due to missing closing tags
	 * @return string XML string
	 * @throws Exception if the extractContainer option isn't true
	 */
	public function getExtractedContainer() {
		if (!$this->options['extractContainer']) {
			throw new Exception('This method requires the \'extractContainer\' option to be true');
		}

		return $this->containerXml;
	}
}

class File implements StreamInterface {
	private $handle;
	private $readBytes = 0;
	private $chunkSize;
	private $chunkCallback;

	public function __construct($mixed, $chunkSize = 16384, $chunkCallback = null) {
		if (is_string($mixed)) {
			if (!file_exists($mixed)) {
				throw new Exception('File \'' . $mixed . '\' doesn\'t exist');
			}

			$this->handle = fopen($mixed, 'rb');
			$this->handle;
		} elseif (get_resource_type($mixed) == 'stream') {
			$this->handle = $mixed;
		} else {
			throw new Exception('First argument must be either a filename or a file handle');
		}

		if ($this->handle === false) {
			throw new Exception('Couldn\'t create file handle');
		}

		$this->chunkSize = $chunkSize;
		$this->chunkCallback = $chunkCallback;
	}

	public function __destruct() {
		if (is_resource($this->handle)) {
			fclose($this->handle);
		}
	}

	public function getChunk() {
		if (is_resource($this->handle) && !feof($this->handle)) {
			$buffer = fread($this->handle, $this->chunkSize);
			$this->readBytes += strlen($buffer);

			if (is_callable($this->chunkCallback)) {
				call_user_func_array($this->chunkCallback, array($buffer, $this->readBytes));
			}

			return $buffer;
		}

		return false;
	}

	public function isSeekable() {
		$meta = stream_get_meta_data($this->handle);

		return $meta['seekable'];
	}

	public function rewind() {
		if (!$this->isSeekable()) {
			throw new Exception('Attempted to rewind an unseekable stream');
		}

		$this->readBytes = 0;
		rewind($this->handle);
	}
}

class Stdin extends File {
	public function __construct($chunkSize = 1024, $chunkCallback = null) {
		parent::__construct(fopen('php://stdin', 'r'), $chunkSize, $chunkCallback);
	}
}

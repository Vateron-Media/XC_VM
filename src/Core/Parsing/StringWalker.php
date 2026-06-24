<?php

namespace XcVm\Core\Parsing;

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
	 * @throws \Exception if the extractContainer option isn't true
	 */
	public function getExtractedContainer() {
		if (!$this->options['extractContainer']) {
			throw new \Exception('This method requires the \'extractContainer\' option to be true');
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

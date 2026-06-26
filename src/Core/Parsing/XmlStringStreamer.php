<?php

namespace XcVm\Core\Parsing;

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

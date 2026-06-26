<?php

namespace XcVm\Core\Parsing;

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

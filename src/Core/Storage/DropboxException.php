<?php

namespace XcVm\Core\Storage;

class DropboxException extends \Exception {
	private $tag;

	/**
	 * Build an exception from a Dropbox error response, the last PHP error, or a message.
	 *
	 * @param object|string|null $resp    Error response object, message string, or null (use last PHP error).
	 * @param string|null        $context Optional context appended to the message.
	 */
	public function __construct($resp = null, $context = null) {
		if (is_null($resp)) {
			$el = error_get_last();
			$this->message = $el['message'];
			$this->file = $el['file'];
			$this->line = $el['line'];
		} elseif (is_object($resp) && isset($resp->error)) {
			$this->message = (empty($resp->error_description) ? json_encode($resp) . ($context ? ', in ' . $context : '') : $resp->error_description);
			$this->tag = (is_object($resp->error) ? $resp->error->{'.tag'} : $resp->error);
		} else {
			$this->message = $resp . ($context ? ', in ' . $context : '');
		}
	}

	/**
	 * Return the Dropbox error tag, when present.
	 *
	 * @return string|null Error tag, or null if none was set.
	 */
	public function getTag() {
		return $this->tag;
	}
}

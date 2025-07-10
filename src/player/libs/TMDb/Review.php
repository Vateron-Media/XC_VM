<?php

class Review {
	private $_data;

	public function __construct($data) {
		$this->_data = $data;
	}

	public function getID() {
		return $this->_data['id'];
	}

	public function getAuthor() {
		return $this->_data['author'];
	}

	public function getContent() {
		return $this->_data['content'];
	}

	public function getURL() {
		return $this->_data['url'];
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

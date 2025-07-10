<?php

class Genre {
	private $_data;

	public function __construct($data) {
		$this->_data = $data;
	}

	public function getName() {
		return $this->_data['name'];
	}

	public function getID() {
		return $this->_data['id'];
	}
}

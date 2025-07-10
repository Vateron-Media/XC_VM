<?php

class TVShowRole extends Role {
	private $_data;

	public function __construct($data, $configOptions) {
		$this->_data = $data;
		parent::__construct($data, $configOptions);
	}

	public function getTVShowName() {
		return $this->_data['name'];
	}

	public function getTVShowID() {
		return $this->_data['id'];
	}

	public function getTVShowOriginalTitle() {
		return $this->_data['original_name'];
	}

	public function getTVShowFirstAirDate() {
		return $this->_data['first_air_date'];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

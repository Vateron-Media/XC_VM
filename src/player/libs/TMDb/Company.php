<?php

class Company {
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

	public function getDescription() {
		return $this->_data['description'];
	}

	public function getHeadquarters() {
		return $this->_data['headquarters'];
	}

	public function getHomepage() {
		return $this->_data['homepage'];
	}

	public function getLogo() {
		return $this->_data['logo_path'];
	}

	public function getParentCompanyID() {
		return $this->_data['parent_company'];
	}

	public function getMovies() {
		$movies = array();

		foreach ($this->_data['movies']['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

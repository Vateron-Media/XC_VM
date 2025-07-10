<?php
















class MovieRole extends Role {
	private $_data;

	public function __construct($data, $configOptions) {
		$this->_data = $data;
		parent::__construct($data, $configOptions);
	}

	public function getMovieTitle() {
		return $this->_data['title'];
	}

	public function getMovieID() {
		return $this->_data['id'];
	}

	public function getMovieOriginalTitle() {
		return $this->_data['original_title'];
	}

	public function getMovieReleaseDate() {
		return $this->_data['release_date'];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

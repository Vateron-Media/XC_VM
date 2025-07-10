<?php
















class Person {
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

	public function getProfile() {
		return $this->_data['profile_path'];
	}

	public function getBirthday() {
		return $this->_data['birthday'];
	}

	public function getPlaceOfBirth() {
		return $this->_data['place_of_birth'];
	}

	public function getImbdID() {
		return $this->_data['imdb_id'];
	}

	public function getPopularity() {
		return $this->_data['popularity'];
	}

	public function getMovieRoles() {
		$movieRolesList = array();

		foreach ($this->_data['movie_credits']['cast'] as $data) {
			$movieRolesList[] = new MovieRole($data, $this->getID());
		}

		return $movieRolesList;
	}

	public function getTVShowRoles() {
		$tvShowRolesList = array();

		foreach ($this->_data['tv_credits']['cast'] as $data) {
			$tvShowRolesList[] = new TVShowRole($data, $this->getID());
		}

		return $tvShowRolesList;
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

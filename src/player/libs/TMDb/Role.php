<?php
















class Role {
	private $_data;

	protected function __construct($data, $personId) {
		$this->_data = $data;
		$this->_data['person_id'] = $personId;
	}

	public function getCharacter() {
		return $this->_data['character'];
	}

	public function getPoster() {
		return $this->_data['poster_path'];
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}
}

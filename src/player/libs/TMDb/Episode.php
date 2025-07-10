<?php
















class Episode {
	private $_data;

	public function __construct($data, $tvShowId = 0) {
		$this->_data = $data;
		$this->_data['tvshow_id'] = $tvShowId;
	}

	public function getID() {
		return $this->_data['id'];
	}

	public function getName() {
		return $this->_data['name'];
	}

	public function getTVShowID() {
		return $this->_data['tvshow_id'];
	}

	public function getSeasonNumber() {
		return $this->_data['season_number'];
	}

	public function getEpisodeNumber() {
		return $this->_data['episode_number'];
	}

	public function getOverview() {
		return $this->_data['overview'];
	}

	public function getStill() {
		return $this->_data['still_path'];
	}

	public function getAirDate() {
		return $this->_data['air_date'];
	}

	public function getVoteAverage() {
		return $this->_data['vote_average'];
	}

	public function getVoteCount() {
		return $this->_data['vote_count'];
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

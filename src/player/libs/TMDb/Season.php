<?php
















class Season {
	private $_data;
	private $_idTVShow;

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

	public function getNumEpisodes() {
		return count($this->_data['episodes']);
	}

	public function getEpisode($episodeId) {
		return new Episode($this->_data['episodes'][$episodeId]);
	}

	public function getEpisodes() {
		$episodesList = array();

		foreach ($this->_data['episodes'] as $data) {
			$episodesList[] = new Episode($data, $this->getTVShowID());
		}

		return $episodesList;
	}

	public function getPoster() {
		return $this->_data['poster_path'];
	}

	public function getAirDate() {
		return $this->_data['air_date'];
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

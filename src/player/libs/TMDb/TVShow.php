<?php
















class TVShow {
	private $_data = null;

	public function __construct($data) {
		$this->_data = $data;
	}

	public function getID() {
		return $this->_data['id'];
	}

	public function getName() {
		return $this->_data['name'];
	}

	public function getOriginalName() {
		return $this->_data['original_name'];
	}

	public function getNumSeasons() {
		return $this->_data['number_of_seasons'];
	}

	public function getNumEpisodes() {
		return $this->_data['number_of_episodes'];
	}

	public function getSeason($seasonNumberToFind) {
		foreach ($this->_data['seasons'] as $currentSeason) {
			if ($currentSeason['season_number'] != $seasonNumberToFind) {
			} else {
				$data = $currentSeason;

				break;
			}
		}

		return new Season($data);
	}

	public function getSeasons() {
		$seasonsArray = array();

		foreach ($this->_data['seasons'] as $data) {
			$seasonsArray[] = new Season($data, $this->getID());
		}

		return $seasonsArray;
	}

	public function getPoster() {
		return $this->_data['poster_path'];
	}

	public function getBackdrop() {
		return $this->_data['backdrop_path'];
	}

	public function getOverview() {
		return $this->_data['overview'];
	}

	public function getVoteAverage() {
		return $this->_data['vote_average'];
	}

	public function getVoteCount() {
		return $this->_data['vote_count'];
	}

	public function getInProduction() {
		return $this->_data['in_production'];
	}

	public function get($item = '') {
		return (empty($item) ? $this->_data : $this->_data[$item]);
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

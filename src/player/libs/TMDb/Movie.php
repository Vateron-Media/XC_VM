<?php
















class Movie {
	public $_data;
	public $_tmdb;

	public function __construct($data) {
		$this->_data = $data;
	}

	public function getID() {
		return $this->_data['id'];
	}

	public function getTitle() {
		return $this->_data['title'];
	}

	public function getTagline() {
		return $this->_data['tagline'];
	}

	public function getPoster() {
		return $this->_data['poster_path'];
	}

	public function getVoteAverage() {
		return $this->_data['vote_average'];
	}

	public function getVoteCount() {
		return $this->_data['vote_count'];
	}

	public function getTrailers() {
		return $this->_data['trailers'];
	}

	public function getTrailer() {
		$trailersData = $this->getTrailers();

		return $trailersData['youtube'][0]['source'];
	}

	public function getGenres() {
		$genresList = array();

		foreach ($this->_data['genres'] as $data) {
			$genresList[] = new Genre($data);
		}

		return $genresList;
	}

	public function getReviews() {
		$getReviews = array();

		foreach ($this->_data['review']['result'] as $data) {
			$getReviews[] = new Review($data);
		}

		return $getReviews;
	}

	public function getCompanies() {
		$productionCompaniesList = array();

		foreach ($this->_data['production_companies'] as $data) {
			$productionCompaniesList[] = new Company($data);
		}

		return $productionCompaniesList;
	}

	public function get($item = '') {
		return empty($item) ? $this->_data : $this->_data[$item];
	}

	public function setAPI($tmdbApiKey) {
		$this->_tmdb = $tmdbApiKey;
	}

	public function getJSON() {
		return json_encode($this->_data, JSON_PRETTY_PRINT);
	}
}

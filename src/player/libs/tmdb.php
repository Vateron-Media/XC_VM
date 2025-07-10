<?php
include 'TMDb/Movie.php';
include 'TMDb/TVShow.php';
include 'TMDb/Season.php';
include 'TMDb/Episode.php';
include 'TMDb/Person.php';
include 'TMDb/Role.php';
include 'TMDb/roles/MovieRole.php';
include 'TMDb/roles/TVShowRole.php';
include 'TMDb/Collection.php';
include 'TMDb/Company.php';
include 'TMDb/Genre.php';
include 'TMDb/config/APIConfiguration.php';

class TMDB {
	public const API_BASE_URL = 'http://api.themoviedb.org/3/';
	public const VERSION = '0.0.3.0';

	private $_config = null;
	private $_apikey = null;
	private $_lang = null;
	private $_adult = null;
	private $_apiconfiguration = null;
	private $_debug = null;

	public function __construct($apiKeyValue = null, $languageCode = null, $authenticationKey = null, $enableDebug = null) {
		require_once 'TMDb/config/config.php';
		$this->setConfig($cnf);
		$this->setApikey((isset($apiKeyValue) ? $apiKeyValue : $cnf['apikey']));
		$this->setLang((isset($languageCode) ? $languageCode : $cnf['lang']));
		$this->setAdult((isset($authenticationKey) ? $authenticationKey : $cnf['adult']));
		$this->setDebug((isset($enableDebug) ? $enableDebug : $cnf['debug']));

		if ($this->_loadConfig()) {
		} else {
			echo _('Unable to read configuration, verify that the API key is valid');

			exit();
		}
	}

	private function setConfig($configValues) {
		$this->_config = $configValues;
	}

	private function getConfig() {
		return $this->_config;
	}

	private function setApikey($apiKeyValue) {
		$this->_apikey = (string) $apiKeyValue;
	}

	private function getApikey() {
		return $this->_apikey;
	}

	public function setLang($languageCode = 'en') {
		$this->_lang = (string) $languageCode;
	}

	public function getLang() {
		return $this->_lang;
	}

	public function setAdult($authenticationKey = false) {
		$this->_adult = $authenticationKey;
	}

	public function getAdult() {
		return ($this->_adult ? 'true' : 'false');
	}

	public function setDebug($enableDebug = false) {
		$this->_debug = $enableDebug;
	}

	public function getDebug() {
		return $this->_debug;
	}

	private function _loadConfig() {
		$this->_apiconfiguration = new APIConfiguration($this->_call('configuration'));

		return !empty($this->_apiconfiguration);
	}

	public function getAPIConfig() {
		return $this->_apiconfiguration;
	}

	public function getImageURL($size = 'original') {
		return $this->_apiconfiguration->getImageBaseURL() . $size;
	}

	public function getDiscoverMovies($page = 1) {
		$movies = array();
		$result = $this->_call('discover/movie', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getDiscoverTVShows($page = 1) {
		$tvShowsList = array();
		$result = $this->_call('discover/tv', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function getDiscoverMovie($page = 1) {
		$movies = array();
		$result = $this->_call('discover/movie', 'page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getLatestMovie() {
		return new Movie($this->_call('movie/latest'));
	}

	public function getNowPlayingMovies($page = 1) {
		$movies = array();
		$result = $this->_call('movie/now_playing', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getPopularMovies($page = 1) {
		$movies = array();
		$result = $this->_call('movie/popular', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getTopRatedMovies($page = 1) {
		$movies = array();
		$result = $this->_call('movie/top_rated', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getUpcomingMovies($page = 1) {
		$movies = array();
		$result = $this->_call('movie/upcoming', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getSimilarMovies($id, $page = 1) {
		$movies = array();
		$result = $this->_call('movie/' . $id . '/similar', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function getLatestTVShow() {
		return new TVShow($this->_call('tv/latest'));
	}

	public function getOnTheAirTVShows($page = 1) {
		$tvShowsList = array();
		$result = $this->_call('tv/on_the_air', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function getSimilarSeries($id, $page = 1) {
		$result = $this->_call('tv/' . $id . '/similar', '&page=' . $page);

		return $result['results'];
	}

	public function getAiringTodayTVShows($page = 1, $timeZoneId = 'Europe/Madrid') {
		$tvShowsList = array();
		$result = $this->_call('tv/airing_today', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function getTopRatedTVShows($page = 1) {
		$tvShowsList = array();
		$result = $this->_call('tv/top_rated', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function getPopularTVShows($page = 1) {
		$tvShowsList = array();
		$result = $this->_call('tv/popular', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function getLatestPerson() {
		return new Person($this->_call('person/latest'));
	}

	public function getPopularPersons($page = 1) {
		$personsList = array();
		$result = $this->_call('person/popular', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$personsList[] = new Person($data);
		}

		return $personsList;
	}

	private function _call($endpoint, $parameters = '') {
		$url = 'http://api.themoviedb.org/3/' . $endpoint . '?api_key=' . $this->getApikey() . '&language=' . $this->getLang() . '&append_to_response=' . implode(',', (array) $parameters) . '&include_adult=' . $this->getAdult();

		if (!$this->_debug) {
		} else {
			echo '<pre><a href="' . $url . '">check request</a></pre>';
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, 0);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_FAILONERROR, 1);
		$response = curl_exec($ch);
		curl_close($ch);

		return (array) json_decode($response, true);
	}

	public function getMovie($movieId, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['movie']['default']);

		return new Movie($this->_call('movie/' . $movieId, $parameters));
	}

	public function getTVShow($tvShowId, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['tvshow']['default']);

		return new TVShow($this->_call('tv/' . $tvShowId, $parameters));
	}

	public function getSeason($tvShowId, $seasonNumberToFind, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['season']['default']);

		return new Season($this->_call('tv/' . $tvShowId . '/season/' . $seasonNumberToFind, $parameters), $tvShowId);
	}

	public function getEpisode($tvShowId, $seasonNumberToFind, $episodeId, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['episode']['default']);

		return new Episode($this->_call('tv/' . $tvShowId . '/season/' . $seasonNumberToFind . '/episode/' . $episodeId, $parameters), $tvShowId);
	}

	public function getPerson($configOptions, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['person']['default']);

		return new Person($this->_call('person/' . $configOptions, $parameters));
	}

	public function getCollection($collectionId, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['collection']['default']);

		return new Collection($this->_call('collection/' . $collectionId, $parameters));
	}

	public function getCompany($companyId, $parameters = null) {
		$parameters = (isset($parameters) ? $parameters : $this->getConfig()['appender']['company']['default']);

		return new Company($this->_call('company/' . $companyId, $parameters));
	}

	public function searchMovie($query_mov) {
		$movies = array();
		$result = $this->_call('search/movie', '&query=' . urlencode($query_mov));

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}

	public function searchTVShow($query_tv_show) {
		$tvShowsList = array();
		$result = $this->_call('search/tv', '&query=' . urlencode($query_tv_show));

		foreach ($result['results'] as $data) {
			$tvShowsList[] = new TVShow($data);
		}

		return $tvShowsList;
	}

	public function searchPerson($query_person) {
		$personsList = array();
		$result = $this->_call('search/person', '&query=' . urlencode($query_person));

		foreach ($result['results'] as $data) {
			$personsList[] = new Person($data);
		}

		return $personsList;
	}

	public function searchCollection($query_collection) {
		$collectionResults = array();
		$result = $this->_call('search/collection', '&query=' . urlencode($query_collection));

		foreach ($result['results'] as $data) {
			$collectionResults[] = new Collection($data);
		}

		return $collectionResults;
	}

	public function searchCompany($query_comp) {
		$productionCompaniesList = array();
		$result = $this->_call('search/company', '&query=' . urlencode($query_comp));

		foreach ($result['results'] as $data) {
			$productionCompaniesList[] = new Company($data);
		}

		return $productionCompaniesList;
	}

	public function find($id, $externalSource = 'imdb_id') {
		$foundItems = array();
		$result = $this->_call('find/' . $id, '&external_source=' . urlencode($externalSource));

		foreach ($result['movie_results'] as $data) {
			$foundItems['movies'][] = new Movie($data);
		}

		foreach ($result['person_results'] as $data) {
			$foundItems['persons'][] = new Person($data);
		}

		foreach ($result['tv_results'] as $data) {
			$foundItems['tvshows'][] = new TVShow($data);
		}

		foreach ($result['tv_season_results'] as $data) {
			$foundItems['seasons'][] = new Season($data);
		}

		foreach ($result['tv_episode_results'] as $data) {
			$foundItems['episodes'][] = new Episode($data);
		}

		return $foundItems;
	}

	public function getTimezones() {
		return $this->_call('timezones/list');
	}

	public function getJobs() {
		return $this->_call('job/list');
	}

	public function getMovieGenres() {
		$genresList = array();
		$result = $this->_call('genre/movie/list');

		foreach ($result['genres'] as $data) {
			$genresList[] = new Genre($data);
		}

		return $genresList;
	}

	public function getTVGenres() {
		$genresList = array();
		$result = $this->_call('genre/tv/list');

		foreach ($result['genres'] as $data) {
			$genresList[] = new Genre($data);
		}

		return $genresList;
	}

	public function getMoviesByGenre($genreId, $page = 1) {
		$movies = array();
		$result = $this->_call('genre/' . $genreId . '/movies', '&page=' . $page);

		foreach ($result['results'] as $data) {
			$movies[] = new Movie($data);
		}

		return $movies;
	}
}

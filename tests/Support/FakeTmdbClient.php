<?php

/**
 * FakeTmdbClient — test double for the global \TMDB client.
 *
 * The real \TMDB::__construct() makes a live curl_exec() to
 * api.themoviedb.org and exit()s if that call fails, so it cannot be
 * constructed at all in a test process without network access and a real
 * API key. This double duck-types only the methods VodItemImporter actually calls
 * (searchMovie/searchTVShow/getMovieTitles/getSeriesTitles/getMovie/
 * getTVShow/getSeason) and is driven by plain closures, so each test wires
 * up exactly the canned response it needs. Movie/TVShow/Season are the real
 * TMDB entity classes (see TmdbClient.php's trailing include_once list) —
 * they are plain array wrappers with no network dependency of their own.
 */
final class FakeTmdbClient {
    private $rSearchMovie;
    private $rSearchTVShow;
    private $rGetMovieTitles;
    private $rGetSeriesTitles;
    private $rGetMovie;
    private $rGetTVShow;
    private $rGetSeason;

    public function __construct(array $rHandlers = array()) {
        $rNoResults = function () {
            return array();
        };
        $rNoTitles = function () {
            return array('titles' => array());
        };
        $rNull = function () {
            return null;
        };
        $this->rSearchMovie = $rHandlers['searchMovie'] ?? $rNoResults;
        $this->rSearchTVShow = $rHandlers['searchTVShow'] ?? $rNoResults;
        $this->rGetMovieTitles = $rHandlers['getMovieTitles'] ?? $rNoTitles;
        $this->rGetSeriesTitles = $rHandlers['getSeriesTitles'] ?? $rNoTitles;
        $this->rGetMovie = $rHandlers['getMovie'] ?? $rNull;
        $this->rGetTVShow = $rHandlers['getTVShow'] ?? $rNull;
        $this->rGetSeason = $rHandlers['getSeason'] ?? $rNull;
    }

    public function searchMovie($rTitle, $rYear = null) {
        return ($this->rSearchMovie)($rTitle, $rYear);
    }

    public function searchTVShow($rTitle, $rYear = null) {
        return ($this->rSearchTVShow)($rTitle, $rYear);
    }

    public function getMovieTitles($rID) {
        return ($this->rGetMovieTitles)($rID);
    }

    public function getSeriesTitles($rID) {
        return ($this->rGetSeriesTitles)($rID);
    }

    public function getMovie($rID) {
        return ($this->rGetMovie)($rID);
    }

    public function getTVShow($rID) {
        return ($this->rGetTVShow)($rID);
    }

    public function getSeason($rShowID, $rSeason) {
        return ($this->rGetSeason)($rShowID, $rSeason);
    }
}

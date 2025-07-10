<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        require str_replace('\\', '/', dirname($argv[0])) . '/../includes/admin.php';
        require INCLUDES_PATH . 'libs/tmdb.php';
        require INCLUDES_PATH . 'libs/tmdb_release.php';
        cli_set_process_title('XC_VM[TMDB]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rCategories = getCategories();
        $rTimeout = 3600;
        set_time_limit($rTimeout);
        ini_set('max_execution_time', $rTimeout);
        if (strlen(CoreUtilities::$rSettings['tmdb_api_key']) != 0) {
            $rUpdateSeries = array();
            $db->query('SELECT `id`, `type`, `stream_id` FROM `watch_refresh` WHERE `status` = 0 ORDER BY `stream_id` ASC;');
            foreach ($db->get_rows() as $rRow) {
                if ($rRow['type'] == 1) {
                    $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rRow['stream_id']);
                    if ($db->num_rows() == 1) {
                        $rStream = $db->get_row();
                        if (0 < strlen($rStream['tmdb_language'])) {
                            $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $rStream['tmdb_language']);
                        } else {
                            if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
                                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
                            } else {
                                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
                            }
                        }
                        if ($rStream['tmdb_id']) {
                            $rTMDBID = $rStream['tmdb_id'];
                        } else {
                            if (0 < strlen($rStream['movie_properties'])) {
                                $rTMDBID = intval(json_decode($rStream['movie_properties'], true)['tmdb_id']);
                            } else {
                                $rTMDBID = 0;
                            }
                        }
                        if ($rTMDBID != 0) {
                        } else {
                            $rFilename = pathinfo(json_decode($rStream['stream_source'], true)[0])['filename'];
                            foreach (array($rFilename, $rStream['stream_display_name']) as $rStreamTitle) {
                                $rRelease = parserelease($rStreamTitle);
                                $rTitle = $rRelease['title'];
                                if (!isset($rRelease['excess'])) {
                                } else {
                                    $rTitle = trim($rTitle, (is_array($rRelease['excess']) ? $rRelease['excess'][0] : $rRelease['excess']));
                                }
                                if (isset($rRelease['group'])) {
                                    $rAltTitle = $rTitle . '-' . $rRelease['group'];
                                } else {
                                    if (!isset($rRelease['alternative_title'])) {
                                    } else {
                                        $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                                    }
                                }
                                if (!isset($rRelease['season'])) {
                                } else {
                                    $rTitle .= $rRelease['season'];
                                }
                                $rYear = $rRelease['year'];
                                if ($rTitle) {
                                } else {
                                    $rTitle = $rStreamTitle;
                                }
                                $rMatch = null;
                                $rMatches = array();
                                foreach (range(0, 1) as $rIgnoreYear) {
                                    if (!$rIgnoreYear) {
                                    } else {
                                        if ($rYear) {
                                            $rYear = null;
                                        } else {
                                            break;
                                        }
                                    }
                                    $rResults = $rTMDB->searchMovie($rTitle, $rYear);
                                    foreach ($rResults as $rResultArr) {
                                        similar_text(strtoupper($rTitle), strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentage);
                                        if (!$rAltTitle) {
                                        } else {
                                            similar_text(strtoupper($rAltTitle), strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentageAlt);
                                        }
                                        if (!(CoreUtilities::$rSettings['percentage_match'] <= $rPercentage || CoreUtilities::$rSettings['percentage_match'] <= $rPercentageAlt)) {
                                        } else {
                                            if ($rYear && !in_array(intval(substr(($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date')), 0, 4)), range(intval($rYear) - 1, intval($rYear) + 1))) {
                                            } else {
                                                if ($rAltTitle && strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($rAltTitle)) {
                                                    $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                    break;
                                                }
                                                if (strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($rTitle) && !$rAltTitle) {
                                                    $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                    break;
                                                }
                                                $rMatches[] = array('percentage' => $rPercentage, 'data' => $rResultArr);
                                            }
                                        }
                                    }
                                    if (0 >= count($rMatches)) {
                                    } else {
                                        break;
                                    }
                                }
                                if (0 >= count($rMatches)) {
                                } else {
                                    $rMax = max(array_column($rMatches, 'percentage'));
                                    $rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
                                        return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
                                    }, $rMatches));
                                    list($rMatch) = array_values($rKeys);
                                }
                                if (!$rMatch) {
                                } else {
                                    $rTMDBID = $rMatch->get('id');
                                    break;
                                }
                            }
                        }
                        if (0 < $rTMDBID) {
                            $rMovie = $rTMDB->getMovie($rTMDBID);
                            $rMovieData = json_decode($rMovie->getJSON(), true);
                            $rMovieData['trailer'] = $rMovie->getTrailer();
                            $rThumb = ($rMovieData['poster_path'] ? 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rMovieData['poster_path'] : '');
                            $rBG = ($rMovieData['backdrop_path'] ? 'https://image.tmdb.org/t/p/w1280' . $rMovieData['backdrop_path'] : '');
                            if (!CoreUtilities::$rSettings['download_images']) {
                            } else {
                                if (empty($rThumb)) {
                                } else {
                                    $rThumb = CoreUtilities::downloadImage($rThumb, 2);
                                }
                                if (empty($rBG)) {
                                } else {
                                    $rBG = CoreUtilities::downloadImage($rBG);
                                }
                            }
                            if (!$rBG) {
                            } else {
                                $rBG = array($rBG);
                            }
                            $rCast = array();
                            foreach ($rMovieData['credits']['cast'] as $rMember) {
                                if (count($rCast) >= 5) {
                                } else {
                                    $rCast[] = $rMember['name'];
                                }
                            }
                            $rDirectors = array();
                            foreach ($rMovieData['credits']['crew'] as $rMember) {
                                if (!(count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing')) || in_array($rMember['name'], $rDirectors)) {
                                } else {
                                    $rDirectors[] = $rMember['name'];
                                }
                            }
                            $rCountry = '';
                            if (!isset($rMovieData['production_countries'][0]['name'])) {
                            } else {
                                $rCountry = $rMovieData['production_countries'][0]['name'];
                            }
                            $rGenres = array();
                            foreach ($rMovieData['genres'] as $rGenre) {
                                if (count($rGenres) >= 3) {
                                } else {
                                    $rGenres[] = $rGenre['name'];
                                }
                            }
                            $rSeconds = intval($rMovieData['runtime']) * 60;
                            $rProperties = array('kinopoisk_url' => 'https://www.themoviedb.org/movie/' . $rMovieData['id'], 'tmdb_id' => $rMovieData['id'], 'name' => $rMovieData['title'], 'o_name' => $rMovieData['original_title'], 'cover_big' => $rThumb, 'movie_image' => $rThumb, 'release_date' => $rMovieData['release_date'], 'episode_run_time' => $rMovieData['runtime'], 'youtube_trailer' => $rMovieData['trailer'], 'director' => implode(', ', $rDirectors), 'actors' => implode(', ', $rCast), 'cast' => implode(', ', $rCast), 'description' => $rMovieData['overview'], 'plot' => $rMovieData['overview'], 'age' => '', 'mpaa_rating' => '', 'rating_count_kinopoisk' => 0, 'country' => $rCountry, 'genre' => implode(', ', $rGenres), 'backdrop_path' => $rBG, 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rMovieData['vote_average']);
                            $rTitle = $rMovieData['title'];
                            $rYear = null;
                            $rRating = ($rMovieData['vote_average'] ?: 0);
                            if (0 >= strlen($rMovieData['release_date'])) {
                            } else {
                                $rYear = intval(substr($rMovieData['release_date'], 0, 4));
                            }
                            $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $rRow['id']);
                            $db->query('UPDATE `streams` SET `stream_display_name` = ?, `year` = ?, `movie_properties` = ?, `rating` = ? WHERE `id` = ?;', $rTitle, $rYear, json_encode($rProperties, JSON_UNESCAPED_UNICODE), $rRating, $rRow['stream_id']);
                        } else {
                            $db->query('UPDATE `watch_refresh` SET `status` = -1 WHERE `id` = ?;', $rRow['id']);
                        }
                    } else {
                        $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $rRow['id']);
                    }
                } else {
                    if ($rRow['type'] == 2) {
                        $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rRow['stream_id']);
                        if ($db->num_rows() == 1) {
                            $rStream = $db->get_row();
                            if (0 < strlen($rStream['tmdb_language'])) {
                                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $rStream['tmdb_language']);
                            } else {
                                if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
                                    $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
                                } else {
                                    $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
                                }
                            }
                            $rTMDBID = intval($rStream['tmdb_id']);
                            if ($rTMDBID != 0) {
                            } else {
                                $rFilename = $rStream['title'];
                                $rRelease = parserelease($rFilename);
                                $rTitle = $rRelease['title'];
                                if (!isset($rRelease['excess'])) {
                                } else {
                                    $rTitle = trim($rTitle, (is_array($rRelease['excess']) ? $rRelease['excess'][0] : $rRelease['excess']));
                                }
                                if (isset($rRelease['group'])) {
                                    $rAltTitle = $rTitle . '-' . $rRelease['group'];
                                } else {
                                    if (!isset($rRelease['alternative_title'])) {
                                    } else {
                                        $rAltTitle = $rTitle . ' - ' . $rRelease['alternative_title'];
                                    }
                                }
                                $rYear = $rRelease['year'];
                                if ($rTitle) {
                                } else {
                                    $rTitle = $rFilename;
                                }
                                $rMatch = null;
                                $rMatches = array();
                                foreach (range(0, 1) as $rIgnoreYear) {
                                    if (!$rIgnoreYear) {
                                    } else {
                                        if ($rYear) {
                                            $rYear = null;
                                        } else {
                                            break;
                                        }
                                    }
                                    $rResults = $rTMDB->searchTVShow($rTitle, $rYear);
                                    foreach ($rResults as $rResultArr) {
                                        similar_text(strtoupper($rTitle), strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentage);
                                        if (!$rAltTitle) {
                                        } else {
                                            similar_text(strtoupper($rAltTitle), strtoupper(($rResultArr->get('title') ?: $rResultArr->get('name'))), $rPercentageAlt);
                                        }
                                        if (!(CoreUtilities::$rSettings['percentage_match'] <= $rPercentage || CoreUtilities::$rSettings['percentage_match'] <= $rPercentageAlt)) {
                                        } else {
                                            if ($rYear && !in_array(intval(substr(($rResultArr->get('release_date') ?: $rResultArr->get('first_air_date')), 0, 4)), range(intval($rYear) - 1, intval($rYear) + 1))) {
                                            } else {
                                                if ($rAltTitle && strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($rAltTitle)) {
                                                    $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                    break;
                                                }
                                                if (strtolower(($rResultArr->get('title') ?: $rResultArr->get('name'))) == strtolower($rTitle) && !$rAltTitle) {
                                                    $rMatches = array(array('percentage' => 100, 'data' => $rResultArr));
                                                    break;
                                                }
                                                $rMatches[] = array('percentage' => $rPercentage, 'data' => $rResultArr);
                                            }
                                        }
                                    }
                                    if (0 >= count($rMatches)) {
                                    } else {
                                        break;
                                    }
                                }
                                if (0 >= count($rMatches)) {
                                } else {
                                    $rMax = max(array_column($rMatches, 'percentage'));
                                    $rKeys = array_filter(array_map(function ($rMatches) use ($rMax) {
                                        return ($rMatches['percentage'] == $rMax ? $rMatches['data'] : null);
                                    }, $rMatches));
                                    list($rMatch) = array_values($rKeys);
                                }
                            }
                            if (0 < $rTMDBID) {
                                $rShow = $rTMDB->getTVShow($rTMDBID);
                                $rShowData = json_decode($rShow->getJSON(), true);
                                $rSeriesArray = $rStream;
                                $rSeriesArray['title'] = $rShowData['name'];
                                $rSeriesArray['tmdb_id'] = $rShowData['id'];
                                $rSeriesArray['plot'] = $rShowData['overview'];
                                $rSeriesArray['rating'] = $rShowData['vote_average'];
                                $rSeriesArray['release_date'] = $rShowData['first_air_date'];
                                $rSeriesArray['youtube_trailer'] = getSeriesTrailer($rShowData['id']);
                                $rSeriesArray['cover'] = ($rShowData['poster_path'] ? 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rShowData['poster_path'] : '');
                                $rSeriesArray['cover_big'] = $rSeriesArray['cover'];
                                $rSeriesArray['backdrop_path'] = array();
                                $rBG = ($rShowData['backdrop_path'] ? 'https://image.tmdb.org/t/p/w1280' . $rShowData['backdrop_path'] : '');
                                if (!CoreUtilities::$rSettings['download_images']) {
                                } else {
                                    if (empty($rSeriesArray['cover'])) {
                                    } else {
                                        $rSeriesArray['cover'] = CoreUtilities::downloadImage($rSeriesArray['cover'], 2);
                                    }
                                    if (empty($rBG)) {
                                    } else {
                                        $rBG = CoreUtilities::downloadImage($rBG);
                                    }
                                }
                                if (empty($rBG)) {
                                } else {
                                    $rSeriesArray['backdrop_path'][] = $rBG;
                                }
                                $rCast = array();
                                foreach ($rShowData['credits']['cast'] as $rMember) {
                                    if (count($rCast) >= 5) {
                                    } else {
                                        $rCast[] = $rMember['name'];
                                    }
                                }
                                $rSeriesArray['cast'] = implode(', ', $rCast);
                                $rDirectors = array();
                                foreach ($rShowData['credits']['crew'] as $rMember) {
                                    if (!(count($rDirectors) < 5 && ($rMember['department'] == 'Directing' || $rMember['known_for_department'] == 'Directing')) || in_array($rMember['name'], $rDirectors)) {
                                    } else {
                                        $rDirectors[] = $rMember['name'];
                                    }
                                }
                                $rSeriesArray['director'] = implode(', ', $rDirectors);
                                $rGenres = array();
                                foreach ($rShowData['genres'] as $rGenre) {
                                    if (count($rGenres) >= 3) {
                                    } else {
                                        $rGenres[] = $rGenre['name'];
                                    }
                                }
                                $rSeriesArray['genre'] = implode(', ', $rGenres);
                                $rSeriesArray['episode_run_time'] = intval($rShowData['episode_run_time'][0]);
                                $rPrepare = prepareArray($rSeriesArray);
                                $rQuery = 'REPLACE INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                                if ($db->query($rQuery, ...$rPrepare['data'])) {
                                    $rInsertID = $db->last_insert_id();
                                    updateSeries(intval($rInsertID));
                                    $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $rRow['id']);
                                } else {
                                    $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $rRow['id']);
                                }
                            } else {
                                $db->query('UPDATE `watch_refresh` SET `status` = -1 WHERE `id` = ?;', $rRow['id']);
                            }
                        } else {
                            $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $rRow['id']);
                        }
                    } else {
                        if ($rRow['type'] == 3) {
                            $db->query('SELECT * FROM `streams` WHERE `id` = ?;', $rRow['stream_id']);
                            if ($db->num_rows() == 1) {
                                $rStream = $db->get_row();
                                $db->query('SELECT * FROM `streams_episodes` WHERE `stream_id` = ?;', $rRow['stream_id']);
                                if ($db->num_rows() == 1) {
                                    $rSeriesEpisode = $db->get_row();
                                    $db->query('SELECT * FROM `streams_series` WHERE `id` = ?;', $rSeriesEpisode['series_id']);
                                    if ($db->num_rows() == 1) {
                                        $rSeries = $db->get_row();
                                        if (0 < strlen($rSeries['tmdb_language'])) {
                                            $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], $rSeries['tmdb_language']);
                                        } else {
                                            if (0 < strlen(CoreUtilities::$rSettings['tmdb_language'])) {
                                                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key'], CoreUtilities::$rSettings['tmdb_language']);
                                            } else {
                                                $rTMDB = new TMDB(CoreUtilities::$rSettings['tmdb_api_key']);
                                            }
                                        }
                                        if (0 < strlen($rSeries['tmdb_id'])) {
                                            $rShow = $rTMDB->getTVShow($rSeries['tmdb_id']);
                                            $rShowData = json_decode($rShow->getJSON(), true);
                                            if (!isset($rShowData['name'])) {
                                            } else {
                                                $rFilename = pathinfo(json_decode($rStream['stream_source'], true)[0])['filename'];
                                                $rRelease = parserelease($rFilename);
                                                $rReleaseSeason = $rRelease['season'];
                                                if (is_array($rRelease['episode'])) {
                                                    $rReleaseEpisode = $rRelease['episode'][0];
                                                } else {
                                                    $rReleaseEpisode = $rRelease['episode'];
                                                }
                                                if ($rReleaseSeason && $rReleaseEpisode) {
                                                } else {
                                                    $rReleaseSeason = $rSeriesEpisode['season_num'];
                                                    $rReleaseEpisode = $rSeriesEpisode['episode_num'];
                                                }
                                                if (is_array($rRelease['episode']) && count($rRelease['episode']) == 2) {
                                                    $rTitle = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rRelease['episode'][0]) . '-' . sprintf('%02d', $rRelease['episode'][1]);
                                                } else {
                                                    $rTitle = $rShowData['name'] . ' - S' . sprintf('%02d', intval($rReleaseSeason)) . 'E' . sprintf('%02d', $rReleaseEpisode);
                                                }
                                                $rEpisodes = json_decode($rTMDB->getSeason($rShowData['id'], intval($rReleaseSeason))->getJSON(), true);
                                                $rProperties = array();
                                                foreach ($rEpisodes['episodes'] as $rEpisode) {
                                                    if (intval($rEpisode['episode_number']) != $rReleaseEpisode) {
                                                    } else {
                                                        if (0 >= strlen($rEpisode['still_path'])) {
                                                        } else {
                                                            $rImage = 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'];
                                                            if (!CoreUtilities::$rSettings['download_images']) {
                                                            } else {
                                                                $rImage = CoreUtilities::downloadImage($rImage, 5);
                                                            }
                                                        }
                                                        if (0 >= strlen($rEpisode['name'])) {
                                                        } else {
                                                            $rTitle .= ' - ' . $rEpisode['name'];
                                                        }
                                                        $rSeconds = intval($rShowData['episode_run_time'][0]) * 60;
                                                        $rProperties = array('tmdb_id' => $rEpisode['id'], 'release_date' => $rEpisode['air_date'], 'plot' => $rEpisode['overview'], 'duration_secs' => $rSeconds, 'duration' => sprintf('%02d:%02d:%02d', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60), 'movie_image' => $rImage, 'video' => array(), 'audio' => array(), 'bitrate' => 0, 'rating' => $rEpisode['vote_average'], 'season' => $rReleaseSeason);
                                                        if (strlen($rProperties['movie_image'][0]) != 0) {
                                                        } else {
                                                            unset($rProperties['movie_image']);
                                                        }
                                                        break;
                                                    }
                                                }
                                                $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $rRow['id']);
                                                $db->query('UPDATE `streams` SET `stream_display_name` = ?, `movie_properties` = ? WHERE `id` = ?;', $rTitle, json_encode($rProperties, JSON_UNESCAPED_UNICODE), $rRow['stream_id']);
                                                $db->query('UPDATE `streams_episodes` SET `season_num` = ?, `episode_num` = ? WHERE `stream_id` = ?;', $rReleaseSeason, $rReleaseEpisode, $rRow['stream_id']);
                                                if (in_array($rSeries['id'], $rUpdateSeries)) {
                                                } else {
                                                    $rUpdateSeries[] = $rSeries['id'];
                                                }
                                            }
                                        } else {
                                            $db->query('UPDATE `watch_refresh` SET `status` = -5 WHERE `id` = ?;', $rRow['id']);
                                        }
                                    } else {
                                        $db->query('UPDATE `watch_refresh` SET `status` = -4 WHERE `id` = ?;', $rRow['id']);
                                    }
                                } else {
                                    $db->query('UPDATE `watch_refresh` SET `status` = -3 WHERE `id` = ?;', $rRow['id']);
                                }
                            } else {
                                $db->query('UPDATE `watch_refresh` SET `status` = -2 WHERE `id` = ?;', $rRow['id']);
                            }
                        } else {
                            if (in_array($rRow['stream_id'], $rUpdateSeries)) {
                            } else {
                                $db->query('UPDATE `watch_refresh` SET `status` = 1 WHERE `id` = ?;', $rRow['id']);
                                $rUpdateSeries[] = intval($rRow['stream_id']);
                            }
                        }
                    }
                }
            }
            foreach ($rUpdateSeries as $rSeriesID) {
                updateSeries(intval($rSeriesID));
            }
            @unlink($rIdentifier);
        } else {
            exit('No TMDb API key.');
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}

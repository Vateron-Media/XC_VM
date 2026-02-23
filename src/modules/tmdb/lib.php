<?php

/**
 * XC_VM — TMDB Library Loader
 *
 * Прокси-файл для загрузки TMDB-библиотеки из includes/libs/.
 *
 * Библиотека TMDb (includes/libs/tmdb.php + includes/libs/TMDb/)
 * остаётся на месте до полного перехода на автозагрузку.
 * Этот файл обеспечивает единую точку входа из модуля.
 *
 * @see includes/libs/tmdb.php
 * @see includes/libs/TMDb/
 * @see includes/libs/tmdb_release.php
 */

require_once INCLUDES_PATH . 'libs/tmdb.php';

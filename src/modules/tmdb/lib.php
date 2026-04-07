<?php

/**
 * TMDB Library Loader
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
 *
 * @package XC_VM_Module_Tmdb
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once INCLUDES_PATH . 'libs/tmdb.php';

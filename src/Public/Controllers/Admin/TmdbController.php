<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Tmdb\TmdbApiService;

/**
 * \TMDB API Controller
 *
 * Обрабатывает API-маршруты \TMDB:
 *   - search()   — поиск фильмов/сериалов/эпизодов (action: tmdb_search)
 *   - details()  — детальная информация по \TMDB ID (action: tmdb)
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TmdbController {

    public function search(): void {
        if (!$this->hasMediaPermission()) {
            echo json_encode(['result' => false]);
            exit();
        }

        $term = RequestManager::get('term') ?? '';
        if (strlen($term) === 0) {
            echo json_encode(['result' => false]);
            exit();
        }

        $type     = RequestManager::get('type')     ?? 'movie';
        $language = RequestManager::get('language')  ?? null;
        $season   = RequestManager::has('season') ? intval(RequestManager::get('season')) : null;

        $response = TmdbApiService::search($term, $type, $language ?: null, $season);
        echo json_encode($response);
        exit();
    }

    public function details(): void {
        if (!$this->hasMediaPermission()) {
            echo json_encode(['result' => false]);
            exit();
        }

        $id       = intval(RequestManager::get('id')       ?? 0);
        $type     = RequestManager::get('type')             ?? '';
        $language = RequestManager::get('language')         ?? null;

        $response = TmdbApiService::getDetails($id, $type, $language ?: null);
        echo json_encode($response);
        exit();
    }

    private function hasMediaPermission(): bool {
        return Authorization::check('adv', 'add_series')
            || Authorization::check('adv', 'edit_series')
            || Authorization::check('adv', 'add_movie')
            || Authorization::check('adv', 'edit_movie')
            || Authorization::check('adv', 'add_episode')
            || Authorization::check('adv', 'edit_episode');
    }
}

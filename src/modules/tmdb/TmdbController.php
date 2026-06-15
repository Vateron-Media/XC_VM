<?php

/**
 * TMDB Module Controller
 *
 * Обрабатывает API-маршруты модуля TMDB:
 *   - search()   — поиск фильмов/сериалов/эпизодов (action: tmdb_search)
 *   - details()  — детальная информация по TMDB ID (action: tmdb)
 *
 * @see TmdbService
 * @see TmdbModule
 *
 * @package XC_VM_Module_Tmdb
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

        $term = RequestManager::getAll()['term'] ?? '';
        if (strlen($term) === 0) {
            echo json_encode(['result' => false]);
            exit();
        }

        $type     = RequestManager::getAll()['type']     ?? 'movie';
        $language = RequestManager::getAll()['language']  ?? null;
        $season   = isset(RequestManager::getAll()['season']) ? intval(RequestManager::getAll()['season']) : null;

        $response = TmdbService::search($term, $type, $language ?: null, $season);
        echo json_encode($response);
        exit();
    }

    public function details(): void {
        if (!$this->hasMediaPermission()) {
            echo json_encode(['result' => false]);
            exit();
        }

        $id       = intval(RequestManager::getAll()['id']       ?? 0);
        $type     = RequestManager::getAll()['type']             ?? '';
        $language = RequestManager::getAll()['language']         ?? null;

        $response = TmdbService::getDetails($id, $type, $language ?: null);
        echo json_encode($response);
        exit();
    }

    private function hasMediaPermission(): bool {
        if (!class_exists('Authorization')) {
            return false;
        }

        return Authorization::check('adv', 'add_series')
            || Authorization::check('adv', 'edit_series')
            || Authorization::check('adv', 'add_movie')
            || Authorization::check('adv', 'edit_movie')
            || Authorization::check('adv', 'add_episode')
            || Authorization::check('adv', 'edit_episode');
    }
}

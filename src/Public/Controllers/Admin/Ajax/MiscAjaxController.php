<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Module\Watch\WatchService;

/**
 * Admin-ajax controller for the small admin actions that do not belong to a
 * larger domain group: process, profile, watch_output, reguserlist, userlist,
 * listdir, queue, delete_recording, clear_failures.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class MiscAjaxController extends BaseAjaxController {

    /** action=process — kill a process by pid on a server (process monitor). */
    public function process(): never {
        $this->requireXhr();
        $this->gate('adv', 'process_monitor');

        ApiClient::systemRequest(RequestManager::get('server'), array('action' => 'kill_pid', 'pid' => intval(RequestManager::get('pid'))));

        $this->ok();
    }

    /** action=profile — delete a transcoding profile. */
    public function profile(): never {
        $this->requireXhr();
        $this->gate('adv', 'tprofiles');

        if (RequestManager::get('sub') == 'delete') {
            StreamConfigRepository::deleteProfile(RequestManager::get('profile_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=watch_output — delete a folder-watch log row. */
    public function watchOutput(): never {
        $this->requireXhr();
        $this->gate('adv', 'folder_watch_output');

        global $db;

        if (RequestManager::get('sub') == 'delete') {
            $db->query('DELETE FROM `watch_logs` WHERE `id` = ?;', RequestManager::get('result_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=reguserlist — Select2 registered-user search. */
    public function reguserlist(): never {
        $this->requireXhr();
        $this->gateAny(array(
            array('adv', 'mng_regusers'),
            array('adv', 'manage_mag'),
            array('adv', 'manage_e2'),
            array('adv', 'edit_e2'),
            array('adv', 'add_e2'),
            array('adv', 'add_mag'),
            array('adv', 'edit_mag'),
        ));

        global $db;
        $rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

        if (RequestManager::has('search')) {
            $rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
            $db->query('SELECT COUNT(`id`) AS `id` FROM `users` WHERE `username` LIKE ?;', '%' . RequestManager::get('search') . '%');
            $rReturn['total_count'] = $db->get_row()['id'];
            $db->query('SELECT `id`, `username` FROM `users` WHERE `username` LIKE ? ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

            foreach ($db->get_rows() as $rRow) {
                $rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
            }
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** action=userlist — Select2 line / MAG / Enigma2 search. */
    public function userlist(): never {
        $this->requireXhr();
        $this->gateAny(array(
            array('adv', 'edit_e2'),
            array('adv', 'add_e2'),
            array('adv', 'add_mag'),
            array('adv', 'edit_mag'),
        ));

        global $db;
        $rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

        if (RequestManager::has('search')) {
            $rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
            $db->query('SELECT COUNT(`id`) AS `id` FROM `lines` WHERE `username` LIKE ? AND `is_e2` = 0 AND `is_mag` = 0;', RequestManager::get('search') . '%');
            $rReturn['total_count'] = $db->get_row()['id'];
            $db->query('SELECT COUNT(`device_id`) AS `id` FROM `enigma2_devices` WHERE `mac` LIKE ?;', RequestManager::get('search') . '%');
            $rReturn['total_count'] += $db->get_row()['id'];
            $db->query('SELECT COUNT(`mag_id`) AS `id` FROM `mag_devices` WHERE `mac` LIKE ?;', RequestManager::get('search') . '%');
            $rReturn['total_count'] += $db->get_row()['id'];
            $db->query('SELECT `id`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', RequestManager::get('search') . '%', RequestManager::get('search') . '%', RequestManager::get('search') . '%');

            foreach ($db->get_rows() as $rRow) {
                $rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
            }
        }

        $this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** action=listdir — list media/subtitle files in a directory on a node. */
    public function listdir(): never {
        $this->requireXhr();
        $this->gateAny(array(
            array('adv', 'add_episode'),
            array('adv', 'edit_episode'),
            array('adv', 'add_movie'),
            array('adv', 'edit_movie'),
            array('adv', 'create_channel'),
            array('adv', 'edit_cchannel'),
            array('adv', 'folder_watch_add'),
        ));

        if (RequestManager::get('filter') == 'video') {
            $rFilter = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts');
        } elseif (RequestManager::get('filter') == 'subs') {
            $rFilter = array('srt', 'sub', 'sbv');
        } else {
            $rFilter = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv');
        }

        if (!(RequestManager::has('server') && RequestManager::has('dir'))) {
            $this->fail();
        }

        $this->ok(array('data' => ApiClient::listDir(intval(RequestManager::get('server')), RequestManager::get('dir'), $rFilter)));
    }

    /** action=queue — delete or stop (kill + delete) a queue job. */
    public function queue(): never {
        $this->requireXhr();
        $this->gateAny(array(array('adv', 'streams'), array('adv', 'series'), array('adv', 'episodes')));

        global $db;
        $rSub = RequestManager::get('sub');
        $db->query('SELECT * FROM `queue` WHERE `id` = ?;', RequestManager::get('id'));

        if ($db->num_rows() == 1) {
            $rRow = $db->get_row();

            if ($rSub == 'delete') {
                $db->query('DELETE FROM `queue` WHERE `id` = ?;', RequestManager::get('id'));
                $this->ok();
            }

            if ($rSub == 'stop') {
                if (0 < $rRow['pid']) {
                    ServerRepository::killPID($rRow['server_id'], $rRow['pid']);
                }

                $db->query('DELETE FROM `queue` WHERE `id` = ?;', RequestManager::get('id'));
                $this->ok();
            }
        }

        $this->fail();
    }

    /** action=delete_recording — delete a DVR recording. */
    public function deleteRecording(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_movie');

        if (!(RequestManager::has('id') && 0 < intval(RequestManager::get('id')))) {
            $this->fail();
        }

        if (!class_exists(WatchService::class)) {
            $this->fail();
        }

        WatchService::deleteRecording(RequestManager::get('id'));

        $this->ok();
    }

    /** action=clear_failures — clear a stream's failure log. */
    public function clearFailures(): never {
        $this->requireXhr();
        $this->gate('adv', 'streams');

        global $db;

        if (!(RequestManager::has('id') && 0 < intval(RequestManager::get('id')))) {
            $this->fail();
        }

        $db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', RequestManager::get('id'));

        $this->ok();
    }
}

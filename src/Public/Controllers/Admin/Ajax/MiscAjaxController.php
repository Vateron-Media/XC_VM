<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\RecordingService;

/**
 * Admin-ajax controller for the small admin actions that do not belong to a
 * larger domain group: process, profile, reguserlist, userlist,
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

		NodeRpc::request(RequestManager::get('server'), ['action' => 'kill_pid', 'pid' => intval(RequestManager::get('pid'))]);

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

	/** action=reguserlist — Select2 registered-user search. */
	public function reguserlist(): never {
		$this->requireXhr();
		$this->gateAny([
			['adv', 'mng_regusers'],
			['adv', 'manage_mag'],
			['adv', 'manage_e2'],
			['adv', 'edit_e2'],
			['adv', 'add_e2'],
			['adv', 'add_mag'],
			['adv', 'edit_mag'],
		]);

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;
			$db->query('SELECT COUNT(`id`) AS `id` FROM `users` WHERE `username` LIKE ?;', '%' . RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['id'];
			$db->query('SELECT `id`, `username` FROM `users` WHERE `username` LIKE ? ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

			foreach ($db->get_rows() as $rRow) {
				$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['username']];
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=userlist — Select2 line / MAG / Enigma2 search. */
	public function userlist(): never {
		$this->requireXhr();
		$this->gateAny([
			['adv', 'edit_e2'],
			['adv', 'add_e2'],
			['adv', 'add_mag'],
			['adv', 'edit_mag'],
		]);

		global $db;
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

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
				$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['username']];
			}
		}

		$this->json($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
	}

	/** action=listdir — list media/subtitle files in a directory on a node. */
	public function listdir(): never {
		$this->requireXhr();
		$this->gateAny([
			['adv', 'add_episode'],
			['adv', 'edit_episode'],
			['adv', 'add_movie'],
			['adv', 'edit_movie'],
			['adv', 'create_channel'],
			['adv', 'edit_cchannel'],
			['adv', 'folder_watch_add'],
		]);

		if (RequestManager::get('filter') == 'video') {
			$rFilter = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'];
		} elseif (RequestManager::get('filter') == 'subs') {
			$rFilter = ['srt', 'sub', 'sbv'];
		} else {
			$rFilter = ['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv'];
		}

		if (!RequestManager::has('server') || !RequestManager::has('dir')) {
			$this->fail();
		}

		$this->ok(['data' => ApiClient::listDir(intval(RequestManager::get('server')), RequestManager::get('dir'), $rFilter)]);
	}

	/** action=queue — delete or stop (kill + delete) a queue job. */
	public function queue(): never {
		$this->requireXhr();
		$this->gateAny([['adv', 'streams'], ['adv', 'series'], ['adv', 'episodes']]);

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

		if (!RequestManager::has('id') || 0 >= intval(RequestManager::get('id'))) {
			$this->fail();
		}

		RecordingService::delete(RequestManager::get('id'));

		$this->ok();
	}

	/** action=save_activation_key — store the panel activation key on disk. */
	public function saveActivationKey(): never {
		$this->requireXhr();
		$this->gate('adv', 'settings');

		$rKey = trim((string) RequestManager::get('activation_key'));

		if (!preg_match('/^XCVM1\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $rKey)) {
			$this->fail(['message' => Translator::get('activation_invalid_key')]);
		}

		$rPath = MAIN_HOME . 'config/activation_key';
		$rOld = is_file($rPath) ? trim((string) @file_get_contents($rPath)) : '';

		if (@file_put_contents($rPath, $rKey . "\n") === false) {
			$this->fail(['message' => Translator::get('activation_save_failed')]);
		}

		@chmod($rPath, 0600);

		// The pattern above only proves the key is shaped like one. Ask the
		// extension whether it actually licenses THIS install before reporting
		// success, so a well-formed key that is not bound to this HWID stops
		// reading as a completed activation.
		//
		// The key stays on disk either way: the extension may have cached its
		// verdict for this request, and discarding a good key over a stale "no"
		// would be worse than leaving a dead one behind. Nothing trusts the
		// file's presence any more — the dashboard banner asks LicenseGate too.
		if (!LicenseGate::licensed()) {
			ClusterAudit::log('cluster.licence_key', null, ['accepted' => false], 'admin');
			$this->fail(['message' => Translator::get('activation_key_rejected')]);
		}

		// The cluster's tokens are minted from the licence, so a key replaced
		// after a revocation is when a fleet starts coming back, and the
		// operator's timeline should say when the key changed. Another key
		// also gives the token chain another base (xcvm_core's `B`): tokens
		// minted under the old one run to their expiry, and `token.rotate_now`
		// moves every active node to the new one now instead.
		$rRotated = $rOld !== $rKey ? ClusterOverview::rotateAll(null) : null;
		ClusterAudit::log('cluster.licence_key', null, ['accepted' => true, 'rotated' => $rRotated['queued'] ?? 0], 'admin');

		$this->ok(['message' => Translator::get('activation_saved')]);
	}

	/** action=clear_failures — clear a stream's failure log. */
	public function clearFailures(): never {
		$this->requireXhr();
		$this->gate('adv', 'streams');

		global $db;

		if (!RequestManager::has('id') || 0 >= intval(RequestManager::get('id'))) {
			$this->fail();
		}

		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', RequestManager::get('id'));

		$this->ok();
	}
}

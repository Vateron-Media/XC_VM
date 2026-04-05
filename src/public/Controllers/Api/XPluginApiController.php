<?php

/**
 * XPluginApiController — x plugin api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class XPluginApiController {
	private $deny = true;

	public function shutdown() {
		global $db;

		if ($this->deny) {
			BruteforceGuard::checkFlood();
		}

		if (is_object($db)) {
			$db->close_mysql();
		}
	}

	public function index() {
		global $db, $_INFO;
		$rSettings = SettingsManager::getAll();
		$rRequest = RequestManager::getAll();

		if ($rSettings['disable_enigma2']) {
			$this->deny = false;
			generateError('E2_DISABLED');
		}

		$rIP = $_SERVER['REMOTE_ADDR'];
		$rUserAgent = trim($_SERVER['HTTP_USER_AGENT']);

		if (!empty($rRequest['action']) && $rRequest['action'] == 'gen_mac' && !empty($rRequest['pversion'])) {
			$this->deny = false;

			if ($rRequest['pversion'] == '0.0.1') {
				echo json_encode(strtoupper(implode(':', str_split(substr(md5(mt_rand()), 0, 12), 2))));
			}

			exit();
		}

		$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
		DatabaseFactory::set($db);

		if (!empty($rRequest['action']) && $rRequest['action'] == 'auth') {
			$this->handleAuth($rRequest, $rIP, $rUserAgent);
		}

		if (empty($rRequest['token'])) {
			generateError('E2_NO_TOKEN');
		}

		$rToken = $rRequest['token'];
		$db->query('SELECT * FROM enigma2_devices WHERE `token` = ? AND `public_ip` = ? AND `key_auth` = ? LIMIT 1;', $rToken, $rIP, $rUserAgent);

		if ($db->num_rows() <= 0) {
			generateError('E2_TOKEN_DOESNT_MATCH');
		}

		$this->deny = false;
		$rDeviceInfo = $db->get_row();

		if ($rDeviceInfo['watchdog_timeout'] + 20 < time() - $rDeviceInfo['last_updated']) {
			generateError('E2_WATCHDOG_TIMEOUT');
		}

		$rPage = isset($rRequest['page']) ? $rRequest['page'] : '';

		if (empty($rPage)) {
			$this->checkCommands($db, $rDeviceInfo, $rRequest);
		}

		if ($rPage == 'file') {
			$this->handleFileUpload($rDeviceInfo, $rRequest);
		}
	}

	private function handleAuth($rRequest, $rIP, $rUserAgent) {
		global $db;

		$rMAC = isset($rRequest['mac']) ? htmlentities($rRequest['mac']) : '';
		$rModemMAC = isset($rRequest['mmac']) ? htmlentities($rRequest['mmac']) : '';
		$rLocalIP = isset($rRequest['ip']) ? htmlentities($rRequest['ip']) : '';
		$rEnigmaVersion = isset($rRequest['version']) ? htmlentities($rRequest['version']) : '';
		$rCPU = isset($rRequest['type']) ? htmlentities($rRequest['type']) : '';
		$rPluginVersion = isset($rRequest['pversion']) ? htmlentities($rRequest['pversion']) : '';
		$rLVersion = isset($rRequest['lversion']) ? base64_decode($rRequest['lversion']) : '';
		$rDNS = !empty($rRequest['dn']) ? htmlentities($rRequest['dn']) : '-';
		$rCMAC = !empty($rRequest['cmac']) ? htmlentities(strtoupper($rRequest['cmac'])) : '';

		$rDevice = UserRepository::getE2Info(array('device_id' => null, 'mac' => strtoupper($rMAC)));

		if (!$rDevice) {
			BruteforceGuard::checkBruteforce(null, strtoupper($rMAC));
			generateError('INVALID_CREDENTIALS');
		}

		$this->deny = false;

		if ($rDevice['enigma2']['lock_device'] == 1) {
			if (!empty($rDevice['enigma2']['modem_mac']) && $rDevice['enigma2']['modem_mac'] !== $rModemMAC) {
				BruteforceGuard::checkBruteforce(null, strtoupper($rMAC));
				generateError('E2_DEVICE_LOCK_FAILED');
			}
		}

		$rToken = strtoupper(md5(uniqid(rand(), true)));
		$rTimeout = mt_rand(60, 70);
		$db->query('UPDATE `enigma2_devices` SET `original_mac` = ?,`dns` = ?,`key_auth` = ?,`lversion` = ?,`watchdog_timeout` = ?,`modem_mac` = ?,`local_ip` = ?,`public_ip` = ?,`enigma_version` = ?,`cpu` = ?,`version` = ?,`token` = ?,`last_updated` = ? WHERE `device_id` = ?', $rCMAC, $rDNS, $rUserAgent, $rLVersion, $rTimeout, $rModemMAC, $rLocalIP, $rIP, $rEnigmaVersion, $rCPU, $rPluginVersion, $rToken, time(), $rDevice['enigma2']['device_id']);
		$rDetails = array();
		$rDetails['details'] = array();
		$rDetails['details']['token'] = $rToken;
		$rDetails['details']['username'] = $rDevice['user_info']['username'];
		$rDetails['details']['password'] = $rDevice['user_info']['password'];
		$rDetails['details']['watchdog_seconds'] = $rTimeout;
		header('Content-Type: application/json');
		echo json_encode($rDetails);

		exit();
	}

	private function checkCommands($db, $rDeviceInfo, $rRequest) {
		$db->query('UPDATE `enigma2_devices` SET `last_updated` = ?,`rc` = ? WHERE `device_id` = ?;', time(), $rRequest['rc'], $rDeviceInfo['device_id']);
		$db->query('SELECT * FROM `enigma2_actions` WHERE `device_id` = ?;', $rDeviceInfo['device_id']);
		$rResult = array();

		if ($db->num_rows() > 0) {
			$rFirst = $db->get_row();

			if ($rFirst['key'] == 'message') {
				$rResult['message'] = array();
				$rResult['message']['title'] = $rFirst['command2'];
				$rResult['message']['message'] = $rFirst['command'];
			} elseif ($rFirst['key'] == 'ssh') {
				$rResult['ssh'] = $rFirst['command'];
			} elseif ($rFirst['key'] == 'screen') {
				$rResult['screen'] = '1';
			} elseif ($rFirst['key'] == 'reboot_gui') {
				$rResult['reboot_gui'] = 1;
			} elseif ($rFirst['key'] == 'reboot') {
				$rResult['reboot'] = 1;
			} elseif ($rFirst['key'] == 'update') {
				$rResult['update'] = $rFirst['command'];
			} elseif ($rFirst['key'] == 'block_ssh') {
				$rResult['block_ssh'] = (int) $rFirst['type'];
			} elseif ($rFirst['key'] == 'block_telnet') {
				$rResult['block_telnet'] = (int) $rFirst['type'];
			} elseif ($rFirst['key'] == 'block_ftp') {
				$rResult['block_ftp'] = (int) $rFirst['type'];
			} elseif ($rFirst['key'] == 'block_all') {
				$rResult['block_all'] = (int) $rFirst['type'];
			} elseif ($rFirst['key'] == 'block_plugin') {
				$rResult['block_plugin'] = (int) $rFirst['type'];
			}

			$db->query('DELETE FROM `enigma2_actions` WHERE `id` = ?;', $rFirst['id']);
		}

		header('Content-Type: application/json');

		exit(json_encode(array('valid' => true, 'data' => $rResult)));
	}

	private function handleFileUpload($rDeviceInfo, $rRequest) {
		if (empty($_FILES['f']['name']) || $_FILES['f']['error'] != 0) {
			return;
		}

		$rType = $rRequest['t'];

		switch ($rType) {
			case 'screen':
				$rInfo = getimagesize($_FILES['f']['tmp_name']);

				if ($rInfo && $rInfo[2] == IMAGETYPE_JPEG) {
					move_uploaded_file($_FILES['f']['tmp_name'], E2_IMAGES_PATH . $rDeviceInfo['device_id'] . '_screen_' . time() . '_' . uniqid() . '.jpg');
				}

				break;
		}
	}
}

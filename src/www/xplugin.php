<?php

register_shutdown_function('shutdown');
require 'init.php';
$rDeny = true;

if (!SettingsManager::getAll()['disable_enigma2']) {
} else {
	$rDeny = false;
	generateError('E2_DISABLED');
}

$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = trim($_SERVER['HTTP_USER_AGENT']);

if (empty(RequestManager::getAll()['action']) || RequestManager::getAll()['action'] != 'gen_mac' || empty(RequestManager::getAll()['pversion'])) {
	$db = new DatabaseHandler($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	DatabaseFactory::set($db);

	if (empty(RequestManager::getAll()['action']) || RequestManager::getAll()['action'] != 'auth') {
	} else {
		$rMAC = (isset(RequestManager::getAll()['mac']) ? htmlentities(RequestManager::getAll()['mac']) : '');
		$rModemMAC = (isset(RequestManager::getAll()['mmac']) ? htmlentities(RequestManager::getAll()['mmac']) : '');
		$rLocalIP = (isset(RequestManager::getAll()['ip']) ? htmlentities(RequestManager::getAll()['ip']) : '');
		$rEnigmaVersion = (isset(RequestManager::getAll()['version']) ? htmlentities(RequestManager::getAll()['version']) : '');
		$rCPU = (isset(RequestManager::getAll()['type']) ? htmlentities(RequestManager::getAll()['type']) : '');
		$rPluginVersion = (isset(RequestManager::getAll()['pversion']) ? htmlentities(RequestManager::getAll()['pversion']) : '');
		$rLVersion = (isset(RequestManager::getAll()['lversion']) ? base64_decode(RequestManager::getAll()['lversion']) : '');
		$rDNS = (!empty(RequestManager::getAll()['dn']) ? htmlentities(RequestManager::getAll()['dn']) : '-');
		$rCMAC = (!empty(RequestManager::getAll()['cmac']) ? htmlentities(strtoupper(RequestManager::getAll()['cmac'])) : '');
		$rDetails = array();

		if ($rDevice = UserRepository::getE2Info(array('device_id' => null, 'mac' => strtoupper($rMAC)))) {
			$rDeny = false;

			if ($rDevice['enigma2']['lock_device'] != 1) {
			} else {
				if (empty($rDevice['enigma2']['modem_mac']) || $rDevice['enigma2']['modem_mac'] === $rModemMAC) {
				} else {
					BruteforceGuard::checkBruteforce(null, strtoupper($rMAC));
					generateError('E2_DEVICE_LOCK_FAILED');
				}
			}

			$rToken = strtoupper(md5(uniqid(rand(), true)));
			$rTimeout = mt_rand(60, 70);
			$db->query('UPDATE `enigma2_devices` SET `original_mac` = ?,`dns` = ?,`key_auth` = ?,`lversion` = ?,`watchdog_timeout` = ?,`modem_mac` = ?,`local_ip` = ?,`public_ip` = ?,`enigma_version` = ?,`cpu` = ?,`version` = ?,`token` = ?,`last_updated` = ? WHERE `device_id` = ?', $rCMAC, $rDNS, $rUserAgent, $rLVersion, $rTimeout, $rModemMAC, $rLocalIP, $rIP, $rEnigmaVersion, $rCPU, $rPluginVersion, $rToken, time(), $rDevice['enigma2']['device_id']);
			$rDetails['details'] = array();
			$rDetails['details']['token'] = $rToken;
			$rDetails['details']['username'] = $rDevice['user_info']['username'];
			$rDetails['details']['password'] = $rDevice['user_info']['password'];
			$rDetails['details']['watchdog_seconds'] = $rTimeout;
			header('Content-Type: application/json');
			echo json_encode($rDetails);

			exit();
		}

		BruteforceGuard::checkBruteforce(null, strtoupper($rMAC));
		generateError('INVALID_CREDENTIALS');
	}

	if (!empty(RequestManager::getAll()['token'])) {
	} else {
		generateError('E2_NO_TOKEN');
	}

	$rToken = RequestManager::getAll()['token'];
	$db->query('SELECT * FROM enigma2_devices WHERE `token` = ? AND `public_ip` = ? AND `key_auth` = ? LIMIT 1;', $rToken, $rIP, $rUserAgent);

	if ($db->num_rows() > 0) {
	} else {
		generateError('E2_TOKEN_DOESNT_MATCH');
	}

	$rDeny = false;
	$rDeviceInfo = $db->get_row();

	if ($rDeviceInfo['watchdog_timeout'] + 20 >= time() - $rDeviceInfo['last_updated']) {
	} else {
		generateError('E2_WATCHDOG_TIMEOUT');
	}

	$rPage = (isset(RequestManager::getAll()['page']) ? RequestManager::getAll()['page'] : '');

	if (empty($rPage)) {
		$db->query('UPDATE `enigma2_devices` SET `last_updated` = ?,`rc` = ? WHERE `device_id` = ?;', time(), RequestManager::getAll()['rc'], $rDeviceInfo['device_id']);
		$db->query('SELECT * FROM `enigma2_actions` WHERE `device_id` = ?;', $rDeviceInfo['device_id']);
		$rResult = array();

		if (0 >= $db->num_rows()) {
		} else {
			$rFirst = $db->get_row();

			if ($rFirst['key'] == 'message') {
				$rResult['message'] = array();
				$rResult['message']['title'] = $rFirst['command2'];
				$rResult['message']['message'] = $rFirst['command'];
			} else {
				if ($rFirst['key'] == 'ssh') {
					$rResult['ssh'] = $rFirst['command'];
				} else {
					if ($rFirst['key'] == 'screen') {
						$rResult['screen'] = '1';
					} else {
						if ($rFirst['key'] == 'reboot_gui') {
							$rResult['reboot_gui'] = 1;
						} else {
							if ($rFirst['key'] == 'reboot') {
								$rResult['reboot'] = 1;
							} else {
								if ($rFirst['key'] == 'update') {
									$rResult['update'] = $rFirst['command'];
								} else {
									if ($rFirst['key'] == 'block_ssh') {
										$rResult['block_ssh'] = (int) $rFirst['type'];
									} else {
										if ($rFirst['key'] == 'block_telnet') {
											$rResult['block_telnet'] = (int) $rFirst['type'];
										} else {
											if ($rFirst['key'] == 'block_ftp') {
												$rResult['block_ftp'] = (int) $rFirst['type'];
											} else {
												if ($rFirst['key'] == 'block_all') {
													$rResult['block_all'] = (int) $rFirst['type'];
												} else {
													if ($rFirst['key'] != 'block_plugin') {
													} else {
														$rResult['block_plugin'] = (int) $rFirst['type'];
													}
												}
											}
										}
									}
								}
							}
						}
					}
				}
			}

			$db->query('DELETE FROM `enigma2_actions` WHERE `id` = ?;', $rFirst['id']);
		}

		header('Content-Type: application/json');

		exit(json_encode(array('valid' => true, 'data' => $rResult)));
	}

	if ($rPage != 'file') {
	} else {
		if (empty($_FILES['f']['name'])) {
		} else {
			if ($_FILES['f']['error'] != 0) {
			} else {
				$rNewFileName = strtolower($_FILES['f']['tmp_name']);
				$rType = RequestManager::getAll()['t'];

				switch ($rType) {
					case 'screen':
						$rInfo = getimagesize($_FILES['f']['tmp_name']);

						if (!($rInfo && $rInfo[2] == 'IMAGETYPE_JPEG')) {
						} else {
							move_uploaded_file($_FILES['f']['tmp_name'], E2_IMAGES_PATH . $rDeviceInfo['device_id'] . '_screen_' . time() . '_' . uniqid() . '.jpg');
						}

						break;
				}
			}
		}
	}
} else {
	$rDeny = false;

	if (RequestManager::getAll()['pversion'] == '0.0.1') {
	} else {
		echo json_encode(strtoupper(implode(':', str_split(substr(md5(mt_rand()), 0, 12), 2))));
	}

	exit();
}

function shutdown() {
	global $db;
	global $rDeny;

	if (!$rDeny) {
	} else {
		BruteforceGuard::checkFlood();
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}

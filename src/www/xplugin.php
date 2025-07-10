<?php

register_shutdown_function('shutdown');
require 'init.php';
$rDeny = true;

if (!CoreUtilities::$rSettings['disable_enigma2']) {
} else {
	$rDeny = false;
	generateError('E2_DISABLED');
}

$rIP = $_SERVER['REMOTE_ADDR'];
$rUserAgent = trim($_SERVER['HTTP_USER_AGENT']);

if (empty(CoreUtilities::$rRequest['action']) || CoreUtilities::$rRequest['action'] != 'gen_mac' || empty(CoreUtilities::$rRequest['pversion'])) {
	$db = new Database($_INFO['username'], $_INFO['password'], $_INFO['database'], $_INFO['hostname'], $_INFO['port']);
	CoreUtilities::$db = &$db;

	if (empty(CoreUtilities::$rRequest['action']) || CoreUtilities::$rRequest['action'] != 'auth') {
	} else {
		$rMAC = (isset(CoreUtilities::$rRequest['mac']) ? htmlentities(CoreUtilities::$rRequest['mac']) : '');
		$rModemMAC = (isset(CoreUtilities::$rRequest['mmac']) ? htmlentities(CoreUtilities::$rRequest['mmac']) : '');
		$rLocalIP = (isset(CoreUtilities::$rRequest['ip']) ? htmlentities(CoreUtilities::$rRequest['ip']) : '');
		$rEnigmaVersion = (isset(CoreUtilities::$rRequest['version']) ? htmlentities(CoreUtilities::$rRequest['version']) : '');
		$rCPU = (isset(CoreUtilities::$rRequest['type']) ? htmlentities(CoreUtilities::$rRequest['type']) : '');
		$rPluginVersion = (isset(CoreUtilities::$rRequest['pversion']) ? htmlentities(CoreUtilities::$rRequest['pversion']) : '');
		$rLVersion = (isset(CoreUtilities::$rRequest['lversion']) ? base64_decode(CoreUtilities::$rRequest['lversion']) : '');
		$rDNS = (!empty(CoreUtilities::$rRequest['dn']) ? htmlentities(CoreUtilities::$rRequest['dn']) : '-');
		$rCMAC = (!empty(CoreUtilities::$rRequest['cmac']) ? htmlentities(strtoupper(CoreUtilities::$rRequest['cmac'])) : '');
		$rDetails = array();

		if ($rDevice = CoreUtilities::getE2Info(array('device_id' => null, 'mac' => strtoupper($rMAC)))) {
			$rDeny = false;

			if ($rDevice['enigma2']['lock_device'] != 1) {
			} else {
				if (empty($rDevice['enigma2']['modem_mac']) || $rDevice['enigma2']['modem_mac'] === $rModemMAC) {
				} else {
					CoreUtilities::checkBruteforce(null, strtoupper($rMAC));
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

		CoreUtilities::checkBruteforce(null, strtoupper($rMAC));
		generateError('INVALID_CREDENTIALS');
	}

	if (!empty(CoreUtilities::$rRequest['token'])) {
	} else {
		generateError('E2_NO_TOKEN');
	}

	$rToken = CoreUtilities::$rRequest['token'];
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

	$rPage = (isset(CoreUtilities::$rRequest['page']) ? CoreUtilities::$rRequest['page'] : '');

	if (empty($rPage)) {
		$db->query('UPDATE `enigma2_devices` SET `last_updated` = ?,`rc` = ? WHERE `device_id` = ?;', time(), CoreUtilities::$rRequest['rc'], $rDeviceInfo['device_id']);
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
				$rType = CoreUtilities::$rRequest['t'];

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

	if (CoreUtilities::$rRequest['pversion'] == '0.0.1') {
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
		CoreUtilities::checkFlood();
	}

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}

<?php

$rICount = count(get_included_files());
include 'session.php';
include 'functions.php';
$_PAGE = getPageName();
$_ERRORS = array();

foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
	if (substr($rKey, 0, 7) != 'STATUS_') {
	} else {
		$_ERRORS[intval($rValue)] = $rKey;
	}
}

if (1 < $rICount) { ?>
	<script>
		var rCurrentPage = "<?= $_PAGE ?>";
		var rReferer = null;
		var rErrors = <?= json_encode($_ERRORS) ?>;

		function submitForm(rType, rData, rReferer = null) {
			$(".wrapper").fadeOut();
			$("#status").fadeIn();
			if (!rReferer) {
				rReferer = "";
			}
			$.ajax({
				type: "POST",
				url: "post.php?action=" + encodeURIComponent(rType) + "&referer=" + encodeURIComponent(rReferer),
				data: rData,
				processData: false,
				contentType: false,
				success: function(rReturn) {
					try {
						var rJSON = $.parseJSON(rReturn);
					} catch (e) {
						var rJSON = {
							"status": 0,
							"result": false
						};
					}
					callbackForm(rJSON);
				}
			});
		}

		function callbackForm(rData) {
			if (rData.location) {
				if (self !== top) {
					parent.closeEditModal();
					parent.showSuccess("Item has been saved.");
				} else if (rData.reload) {
					window.location.href = rData.location;
				} else {
					navigate(rData.location);
				}
			} else {
				$(".wrapper").fadeIn();
				$("#status").fadeOut();
				$(':input[type=\"submit\"]').prop('disabled', false);
				if (window.rErrors[rData.status] == "STATUS_INVALID_INPUT") {
					showError("Required entry fields have not been populated. Please check the form.");
					return;
				}
				switch (window.rCurrentPage) {
					case "record":
						switch (window.rErrors[rData.status]) {
							case "STATUS_NO_TITLE":
								showError("Please enter a title for the recorded event.");
								break;
							case "STATUS_NO_SOURCE":
								showError("Please select a source server to record the event from.");
								break;
							case "STATUS_NO_DESTINATION":
								showError("Please select a destination server to record the event to.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "quick_tools":
						showSuccess("Quick tool was successfully executed.");
						break;
					case "code":
						switch (window.rErrors[rData.status]) {
							case "STATUS_CODE_LENGTH":
								showError("Your access code needs to be at least 8 characters long.");
								break;
							case "STATUS_INVALID_CODE":
								showError("Please enter an access code.");
								break;
							case "STATUS_RESERVED_CODE":
								showError("Sorry, this code is reserved for system functions.");
								break;
							case "STATUS_EXISTS_CODE":
								showError("This access code already exists, please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "hmac":
						switch (window.rErrors[rData.status]) {
							case "STATUS_NO_DESCRIPTION":
								showError("Please enter a description.");
								break;
							case "STATUS_EXISTS_HMAC":
								showError("This HMAC Key already exists, please use another.");
								break;
							case "STATUS_NO_KEY":
								showError("Please generate a key.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "edit_profile":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_EMAIL":
								showError("Please enter a valid email address.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "ip":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_IP":
								showError("Please enter a valid IP address / CIDR.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;

					case "user":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_GROUP":
								showError("Please select a member group.");
								break;
							case "STATUS_EXISTS_USERNAME":
								showError("The username you selected already exists. Please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "provider":
						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_IP":
								showError("This provider is already being tracked on the system.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "created_channel":
						switch (window.rErrors[rData.status]) {
							case "STATUS_NO_SOURCES":
								showError("Please select at least one source for your channel.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "group":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_NAME":
								showError("This group name is already in use. Please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "isp":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_NAME":
								showError("This ISP has already been blocked.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "package":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_NAME":
								showError("This package name is already in use. Please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "mag":
					case "enigma":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_DATE":
								showError("Please enter a valid date.");
								break;
							case "STATUS_INVALID_USER":
								showError("The paired user does not exist! Please unlink it.");
								break;
							case "STATUS_INVALID_MAC":
								showError("Please enter a valid MAC address.");
								break;
							case "STATUS_EXISTS_MAC":
								showError("The MAC address you entered is already in use.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;

					case "serie":

						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_CODE":
								showError("This series already exists in your database.");
								break;
							case "STATUS_NO_SOURCES":
								showError("No new episodes could be found in the playlist or folder.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "movie":
						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_NAME":
								showError("This movie already exists in your database. Please use another name.");
								break;
							case "STATUS_NO_SOURCES":
								showError("Please select at least one source for your movie.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "radio":
						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_SOURCE":
								showError("This station source is already in your database. Please use another URL.");
								break;
							case "STATUS_NO_SOURCES":
								showError("Please select at least one source for your station.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "server_install":
					case "proxy":
					case "server":
					case "rtmp_ip":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_IP":
								showError("Please enter a valid IP address / CIDR.");
								break;
							case "STATUS_EXISTS_IP":
								showError("This IP address is already in the database. Please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "stream":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_FILE":
								showError("Could not process M3U file, please use another.");
								break;
							case "STATUS_EXISTS_SOURCE":
								showError("This stream source is already in your database. Please use another URL.");
								break;
							case "STATUS_NO_SOURCES":
								showError("Please select at least one source for your stream.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "ticket":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_DATA":
								showError("Please ensure you enter both a title and message.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "watch_add":
						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_DIR":
								showError("This directory is already being watched, please use another.");
								break;
							case "STATUS_INVALID_DIR":
								showError("An invalid directory was entered, please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "plex_add":
						switch (window.rErrors[rData.status]) {
							case "STATUS_EXISTS_DIR":
								showError("This library is already being synced, please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					case "line":
						switch (window.rErrors[rData.status]) {
							case "STATUS_INVALID_DATE":
								showError("Please enter a valid date.");
								break;
							case "STATUS_EXISTS_USERNAME":
								showError("The username you selected already exists. Please use another.");
								break;
							default:
								showError("An error occured while processing your request.");
								break;
						}
						break;
					default:
						showError("An error occured while processing your request.");
						break;
				}
			}
		}
	</script>

<?php
} else {
	if (checkPermissions($_PAGE)) {
		if (isset(CoreUtilities::$rRequest['referer'])) {
			$rReferer = CoreUtilities::$rRequest['referer'];
			unset(CoreUtilities::$rRequest['referer']);
		} else {
			$rReferer = null;
		}

		$rAction = CoreUtilities::$rRequest['action'];
		$rData = CoreUtilities::$rRequest;
		unset($rData['action']);

		if (count($rData) == 0) {
			$rData = json_decode(file_get_contents('php://input'), true);

			if (!is_array($rData)) {
				$rData = array(file_get_contents('php://input') => 1);
			}
		}

		if (!$rData) {
			echo json_encode(array('result' => false));
			exit();
		}

		switch ($rAction) {
			case 'quick_tools':
				set_time_limit(0);

				if (isset($rData['cleanup_streams'])) {
					$db->query('DELETE FROM `streams_servers` WHERE (`server_id` NOT IN (SELECT `id` FROM `servers`)) OR (`stream_id` NOT IN (SELECT `id` FROM `streams`));');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_null_lines'])) {
					$db->query('DELETE FROM `lines` WHERE `username` IS NULL AND `password` IS NULL;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired'])) {
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_trial'])) {
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `is_trial` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired_trial'])) {
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 0 AND `is_trial` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_isp'])) {
					$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `is_mag` = 0 AND `is_e2` = 0;");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['enable_isp'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 1 WHERE `is_mag` = 0 AND `is_e2` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['disable_isp'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 0 WHERE `is_mag` = 0 AND `is_e2` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired_mag'])) {
					$db->query('DELETE FROM `mag_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()));');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_trial_mag'])) {
					$db->query('DELETE FROM `mag_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND `is_trial` = 1);');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND `is_trial` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired_trial_mag'])) {
					$db->query('DELETE FROM `mag_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND `is_trial` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()));');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 1 AND `is_e2` = 0 AND `is_trial` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_isp_mag'])) {
					$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `is_mag` = 1 AND `is_e2` = 0;");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['enable_isp_mag'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 1 WHERE `is_mag` = 1 AND `is_e2` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['disable_isp_mag'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 0 WHERE `is_mag` = 1 AND `is_e2` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['enable_mag_lock'])) {
					$db->query('UPDATE `mag_devices` SET `lock_device` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['disable_mag_lock'])) {
					$db->query('UPDATE `mag_devices` SET `lock_device` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_mag_lock'])) {
					$db->query('UPDATE `mag_devices` SET `ip` = NULL, `ver` = NULL, `image_version` = NULL, `stb_type` = NULL, `sn` = NULL, `device_id` = NULL, `device_id2` = NULL, `hw_version` = NULL, `token` = NULL;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired_e2'])) {
					$db->query('DELETE FROM `enigma2_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()));');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_trial_e2'])) {
					$db->query('DELETE FROM `enigma2_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND `is_trial` = 1);');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND `is_trial` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['remove_expired_trial_e2'])) {
					$db->query('DELETE FROM `enigma2_devices` WHERE `user_id` IN (SELECT `id` FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND `is_trial` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()));');
					$db->query('DELETE FROM `lines` WHERE `is_mag` = 0 AND `is_e2` = 1 AND `is_trial` = 1 AND (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP());');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_isp_e2'])) {
					$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `is_mag` = 0 AND `is_e2` = 1;");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['enable_isp_e2'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 1 WHERE `is_mag` = 0 AND `is_e2` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['disable_isp_e2'])) {
					$db->query('UPDATE `lines` SET `is_isplock` = 0 WHERE `is_mag` = 0 AND `is_e2` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_activity_logs'])) {
					$db->query('TRUNCATE `lines_activity`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_client_logs'])) {
					$db->query('TRUNCATE `lines_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_credit_logs'])) {
					$db->query('TRUNCATE `users_credits_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_login_flood'])) {
					$db->query("DELETE FROM `login_logs` WHERE `status` = 'INVALID_LOGIN';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_login_logs'])) {
					$db->query('TRUNCATE `login_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_mag_events'])) {
					$db->query('TRUNCATE `mag_events`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_panel_logs'])) {
					$db->query('TRUNCATE `panel_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_stream_errors'])) {
					$db->query('TRUNCATE `streams_errors`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_stream_logs'])) {
					$db->query('TRUNCATE `streams_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_user_logs'])) {
					$db->query('TRUNCATE `users_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['clear_watch_logs'])) {
					$db->query('TRUNCATE `watch_logs`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['block_trial_lines'])) {
					$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `is_trial` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['unblock_trial_lines'])) {
					$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `is_trial` = 1;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_blocked_asns'])) {
					$db->query('UPDATE `blocked_asns` SET `blocked` = 0;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_blocked_ips'])) {
					flushIPs();
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_blocked_isps'])) {
					$db->query('TRUNCATE `blocked_isps`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_blocked_uas'])) {
					$db->query('TRUNCATE `blocked_uas`;');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['flush_country_lock'])) {
					$db->query("UPDATE `lines` SET `forced_country` = '';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['force_epg_update'])) {
					shell_exec(MAIN_HOME . '/php/bin/php ' . MAIN_HOME . '/crons/epg.php > /dev/null 2>/dev/null &');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['force_update_movies'])) {
					$db->query('DELETE FROM `watch_refresh` WHERE `type` = 1;');
					$db->query('SELECT `id` FROM `streams` WHERE `type` = 2;');

					foreach ($db->get_rows() as $rRow) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(1, ?, 0);', $rRow['id']);
					}
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['force_update_series'])) {
					$db->query('DELETE FROM `watch_refresh` WHERE `type` = 2;');
					$db->query('SELECT `id` FROM `streams_series`;');

					foreach ($db->get_rows() as $rRow) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(2, ?, 0);', $rRow['id']);
					}
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['force_update_episodes'])) {
					$db->query('DELETE FROM `watch_refresh` WHERE `type` = 3;');
					$db->query('SELECT `id` FROM `streams` WHERE `type` = 5;');

					foreach ($db->get_rows() as $rRow) {
						$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES(3, ?, 0);', $rRow['id']);
					}
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['reauthorise_mysql'])) {
					fCf52F24c866C872();
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['restart_all_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1;');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1;');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['restart_online_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`pid` > 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`pid` > 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['restart_down_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['start_offline_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0);');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0);');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['stop_down_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['stop_online_streams'])) {
					$rServerIDs = $rStreamIDs = array();
					$db->query('SELECT DISTINCT(`stream_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`pid` > 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rStreamIDs[] = intval($rRow['stream_id']);
					}
					$db->query('SELECT DISTINCT(`server_id`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `streams`.`type` = 1 AND `streams_servers`.`pid` IS NOT NULL AND `streams_servers`.`pid` > 0 AND `streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0;');

					foreach ($db->get_rows() as $rRow) {
						$rServerIDs[] = intval($rRow['server_id']);
					}

					if (count($rStreamIDs) > 0) {
						$rRet = APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => $rStreamIDs, 'servers' => $rServerIDs));
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['block_all_isps'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = 'isp';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['unblock_all_isps'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = 'isp';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['block_all_servers'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = 'hosting';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['unblock_all_servers'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = 'hosting';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['block_all_education'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = 'education';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['unblock_all_education'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = 'education';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['block_all_businesses'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = 'business';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['unblock_all_businesses'])) {
					$db->query("UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = 'business';");
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['purge_unlinked_lines_mag'])) {
					$rIDs = array();
					$db->query('SELECT `id` FROM `lines` WHERE `is_mag` = 1 AND `id` NOT IN (SELECT `user_id` FROM `mag_devices`);');

					foreach ($db->get_rows() as $rRow) {
						$rIDs[] = $rRow['id'];
					}

					if (0 >= count($rIDs)) {
					} else {
						$db->query('DELETE FROM `lines` WHERE `id` IN (' . implode(',', array_map('intval', $rIDs)) . ');');
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['purge_unlinked_lines_e2'])) {
					$rIDs = array();
					$db->query('SELECT `id` FROM `lines` WHERE `is_e2` = 1 AND `id` NOT IN (SELECT `user_id` FROM `enigma2_devices`);');

					foreach ($db->get_rows() as $rRow) {
						$rIDs[] = $rRow['id'];
					}

					if (0 >= count($rIDs)) {
					} else {
						$db->query('DELETE FROM `lines` WHERE `id` IN (' . implode(',', array_map('intval', $rIDs)) . ');');
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['symlink_all_movies'])) {
					$db->query('SELECT `streams`.`id`, `streams_servers`.`server_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `type` = 2 AND `movie_symlink` = 1;');
					$rStreams = $db->get_rows();
					$rStreamIDs = array();

					foreach ($rStreams as $rStream) {
						$rStreamIDs[] = $rStream['id'];
					}

					if (count($rStreamIDs) > 0) {
						$db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `stream_status` = 0, `monitor_pid` = NULL WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');
					}

					foreach ($rStreams as $rStream) {
						CoreUtilities::queueMovie($rStream['id'], $rStream['server_id']);
					}
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['symlink_all_episodes'])) {
					$db->query('SELECT `streams`.`id`, `streams_servers`.`server_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `type` = 5 AND `movie_symlink` = 1;');
					$rStreams = $db->get_rows();
					$rStreamIDs = array();

					foreach ($rStreams as $rStream) {
						$rStreamIDs[] = $rStream['id'];
					}

					if (count($rStreamIDs) > 0) {
						$db->query('UPDATE `streams_servers` SET `bitrate` = NULL, `current_source` = NULL, `to_analyze` = 0, `pid` = NULL, `stream_started` = NULL, `stream_info` = NULL, `stream_status` = 0, `monitor_pid` = NULL WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');
					}

					foreach ($rStreams as $rStream) {
						CoreUtilities::queueMovie($rStream['id'], $rStream['server_id']);
					}
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['recreate_channels'])) {
					$db->query('SELECT `id` FROM `streams` WHERE `type` = 3;');
					$rStreamIDs = array_map('intval', array_keys($db->get_rows(true, 'id')));

					if (count($rStreamIDs) > 0) {
						$db->query("UPDATE `streams_servers` SET `cchannel_rsources` = '[]', `pids_create_channel` = '[]', `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`stream_status` = 0,`monitor_pid` = NULL WHERE `stream_id` IN (" . implode(',', $rStreamIDs) . ');');
					}

					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['delete_duplicates'])) {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'tools.php "duplicates" > /dev/null 2>/dev/null &');
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['restore_images'])) {
					restoreImages();
					echo json_encode(array('result' => true, 'status' => STATUS_SUCCESS));
					exit();
				}

				if (isset($rData['replace_movie_years'])) {
					$db->query('SELECT `id`, `year`, `movie_properties`, `stream_display_name` FROM `streams` WHERE `type` = 2 ORDER BY `id` DESC;');

					foreach ($db->get_rows() as $rRow) {
						$rOriginalRow = $rRow;

						if (!empty($rRow['year'])) {
						} else {
							$rRow['year'] = substr(json_decode($rRow['movie_properties'], true)['release_date'], 0, 4);
						}

						$rRegex = '/\\(([0-9)]+)\\)/';
						preg_match($rRegex, $rRow['stream_display_name'], $rMatches, PREG_OFFSET_CAPTURE, 0);
						$rTitleYear = null;
						$rMatchType = 0;

						if (count($rMatches) == 2) {
							$rTitleYear = intval($rMatches[1][0]);
							$rMatchType = 1;
						} else {
							$rSplit = explode('-', $rRow['stream_display_name']);

							if (!(1 < count($rSplit) && is_numeric(trim(end($rSplit))))) {
							} else {
								$rTitleYear = intval(trim(end($rSplit)));
								$rMatchType = 2;
							}
						}

						if (0 >= $rMatchType) {
						} else {
							if (!(1900 <= $rTitleYear && $rTitleYear <= intval(date('Y') + 1))) {
							} else {
								if (!empty($rRow['year'])) {
								} else {
									$rRow['year'] = $rTitleYear;
								}

								if ($rMatchType == 1) {
									$rRow['stream_display_name'] = trim(preg_replace('!\\s+!', ' ', str_replace($rMatches[0][0], '', $rRow['stream_display_name'])));
								} else {
									$rRow['stream_display_name'] = trim(implode('-', array_slice($rSplit, 0, -1)));
								}
							}
						}

						if (!($rRow['year'] != $rOriginalRow['year'] || $rRow['stream_display_name'] != $rOriginalRow['stream_display_name'])) {
						} else {
							$db->query('UPDATE `streams` SET `stream_display_name` = ?, `year` = ? WHERE `id` = ?;', $rRow['stream_display_name'], $rRow['year'], $rRow['id']);
						}
					}
				}

				if (isset($rData['replace_series_years'])) {
					$db->query('SELECT `id`, `year`, `release_date`, `title` FROM `streams_series`;');

					foreach ($db->get_rows() as $rRow) {
						$rOriginalRow = $rRow;

						if (!empty($rRow['year'])) {
						} else {
							$rRow['year'] = substr($rRow['release_date'], 0, 4);
						}

						$rRegex = '/\\(([0-9)]+)\\)/';
						preg_match($rRegex, $rRow['title'], $rMatches, PREG_OFFSET_CAPTURE, 0);
						$rTitleYear = null;
						$rMatchType = 0;

						if (count($rMatches) == 2) {
							$rTitleYear = intval($rMatches[1][0]);
							$rMatchType = 1;
						} else {
							$rSplit = explode('-', $rRow['title']);

							if (!(1 < count($rSplit) && is_numeric(trim(end($rSplit))))) {
							} else {
								$rTitleYear = intval(trim(end($rSplit)));
								$rMatchType = 2;
							}
						}

						if (0 >= $rMatchType) {
						} else {
							if (!(1900 <= $rTitleYear && $rTitleYear <= intval(date('Y') + 1))) {
							} else {
								if (!empty($rRow['year'])) {
								} else {
									$rRow['year'] = $rTitleYear;
								}

								if ($rMatchType == 1) {
									$rRow['title'] = trim(preg_replace('!\\s+!', ' ', str_replace($rMatches[0][0], '', $rRow['title'])));
								} else {
									$rRow['title'] = trim(implode('-', array_slice($rSplit, 0, -1)));
								}
							}
						}

						if (!($rRow['year'] != $rOriginalRow['year'] || $rRow['title'] != $rOriginalRow['title'])) {
						} else {
							$db->query('UPDATE `streams_series` SET `title` = ?, `year` = ? WHERE `id` = ?;', $rRow['title'], $rRow['year'], $rRow['id']);
						}
					}
				}

				if (isset($rData['check_compatibility'])) {
					$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` WHERE `stream_info` IS NOT NULL;');
					$rCount = $db->get_row()['count'];

					if (0 >= $rCount) {
					} else {
						$rSteps = range(0, $rCount, 1000);

						if ($rSteps) {
						} else {
							$rSteps = array(0);
						}

						foreach ($rSteps as $rStep) {
							$db->query('SELECT `server_stream_id`, `stream_info`, `compatible` FROM `streams_servers` WHERE `stream_info` IS NOT NULL LIMIT ' . $rStep . ', 1000;');

							foreach ($db->get_rows() as $rRow) {
								$rCompatible = CoreUtilities::checkCompatibility($rRow['stream_info']);

								if ($rCompatible == $rRow['compatible']) {
								} else {
									$db->query('UPDATE `streams_servers` SET `compatible` = ? WHERE `server_stream_id` = ?;', $rCompatible, $rRow['server_stream_id']);
								}
							}
						}
					}

					$db->query('UPDATE `streams_servers` SET `compatible` = 0 WHERE `stream_info` IS NULL;');
				}

				if (isset($rData['rescan_vod'])) {
					$db->query('UPDATE `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` SET `to_analyze` = 1, `pid` = IF(`pid`, `pid`, 1) WHERE `type` IN (2,5) AND `direct_source` = 0;');
				}

				if (isset($rData['update_ratings'])) {
					$db->query('SELECT `id`, `movie_properties`, `rating` FROM `streams` WHERE `type` = 2;');

					foreach ($db->get_rows() as $rRow) {
						$rProperties = json_decode($rRow['movie_properties'], true);
						$rRating = (floatval($rProperties['rating']) ?: 0);

						if ($rRow['rating'] == $rRating) {
						} else {
							$db->query('UPDATE `streams` SET `rating` = ? WHERE `id` = ?;', $rRating, $rRow['id']);
						}
					}
				}

				if (isset($rData['add_tmdb_ids'])) {
					$db->query('SELECT `id`, `movie_properties`, `tmdb_id` FROM `streams` WHERE `type` = 2 AND `tmdb_id` IS NULL;');

					foreach ($db->get_rows() as $rRow) {
						$rProperties = json_decode($rRow['movie_properties'], true);
						$rTMDBID = ($rProperties['tmdb_id'] ?: null);

						if ($rRow['tmdb_id'] != $rTMDBID) {
							$db->query('UPDATE `streams` SET `tmdb_id` = ? WHERE `id` = ?;', $rTMDBID, $rRow['id']);
						}
					}
				}

				break;

			// no break
			case 'stream_tools':
				if (isset($rData['replace_dns'])) {
					API::replaceDNS($rData);
					echo json_encode(array('result' => true, 'location' => 'stream_tools?status=1', 'status' => 1));
					exit();
				}

				if (!isset($rData['move_streams'])) {
					break;
				}

				API::moveStreams($rData);
				echo json_encode(array('result' => true, 'location' => 'stream_tools?status=2', 'status' => 2));
				exit();


			case 'bouquet':
				$rReturn = API::processBouquet($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'bouquets?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}
				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'stream':
				$rReturn = API::processStream($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'streams') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					if (isset($_FILES['m3u_file'])) {
						echo json_encode(array('result' => true, 'location' => 'streams?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'stream_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'movie':
				if (!empty($rData['import_folder']) || !empty($_FILES['m3u_file']['tmp_name'])) {
					$rReturn = API::importMovies($rData);
					echo json_encode(array('result' => true, 'location' => 'movies?status=2', 'status' => $rReturn['status']));
					exit();
				}

				$rReturn = API::processMovie($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'movies') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					if (isset($_FILES['m3u_file'])) {
						echo json_encode(array('result' => true, 'location' => 'movies?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'stream_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'backups':
				$rReturn = API::editBackupSettings($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'backups?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'cache':
				$rReturn = API::editCacheCron($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'cache?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'bouquet_order':
				$rReturn = API::sortBouquets($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'bouquet_order?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				if ($rReturn['status'] == STATUS_SUCCESS_REPLACE) {
					echo json_encode(array('result' => true, 'location' => 'bouquet_order?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'bouquet_sort':
				$rReturn = API::reorderBouquet($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'bouquet_sort?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'channel_order':
				$rReturn = API::setChannelOrder($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'channel_order?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'code':
				$rReturn = API::processCode($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (getCurrentCode() == $rReturn['data']['orig_code']) {
						echo json_encode(array('result' => true, 'location' => getProtocol() . '://' . $rServers[SERVER_ID]['server_ip'] . ':' . $_SERVER['SERVER_PORT'] . '/' . $rReturn['data']['new_code'] . '/codes?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'codes?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'hmac':
				$rReturn = API::processHMAC($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'hmacs?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'record':
				$rReturn = API::scheduleRecording($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'archive?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'created_channel':
				$rReturn = API::processChannel($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'created_channels') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'stream_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'edit_profile':
				$rReturn = API::editAdminProfile($rData);
				setcookie('hue', $rData['hue'], time() + 315360000);
				setcookie('theme', $rData['theme'], time() + 315360000);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'edit_profile?status=' . intval($rReturn['status']), 'status' => $rReturn['status'], 'reload' => true));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'epg':
				$rReturn = API::processEPG($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'epgs?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'provider':
				$rReturn = API::processProvider($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'providers?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'episode':
				$rReturn = API::processEpisode($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'episodes') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'stream_view?sid=' . intval($rReturn['data']['series_id']) . '&id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status'])));
					exit();
				}

				if ($rReturn['status'] == STATUS_SUCCESS_MULTI) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'episodes') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'episodes?series=' . intval($rReturn['data']['series_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'episodes_mass':
				$rReturn = API::massEditEpisodes($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'episodes_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'line_mass':
				$rReturn = API::massEditLines($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'line_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'user_mass':
				$rReturn = API::massEditUsers($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'user_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mag_mass':
				$rReturn = API::massEditMags($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mag_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'enigma_mass':
				$rReturn = API::massEditEnigmas($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'enigma_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'stream_mass':
				$rReturn = API::massEditStreams($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'stream_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'created_channel_mass':
				$rReturn = API::massEditChannels($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'created_channel_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'movie_mass':
				$rReturn = API::massEditMovies($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'movie_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'radio_mass':
				$rReturn = API::massEditRadios($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'radio_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'series_mass':
				$rReturn = API::massEditSeries($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'series_mass?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'group':
				$rReturn = API::processGroup($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'groups?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'ip':
				$rReturn = API::blockIP($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'ips?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'isp':
				$rReturn = API::processISP($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'isps?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'line':
				$rReturn = API::processLine($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'lines') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'lines?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mag':
				$rReturn = API::processMAG($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'mags') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'mags?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'enigma':
				$rReturn = API::processEnigma($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'enigmas') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'enigmas?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_streams':
				$rReturn = API::massDeleteStreams($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_movies':
				$rReturn = API::massDeleteMovies($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_lines':
				$rReturn = API::massDeleteLines($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_series':
				$rReturn = API::massDeleteSeries($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_episodes':
				$rReturn = API::massDeleteEpisodes($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_radios':
				$rReturn = API::massDeleteStations($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_users':
				$rReturn = API::massDeleteUsers($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_mags':
				$rReturn = API::massDeleteMags($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'mass_delete_enigmas':
				$rReturn = API::massDeleteEnigmas($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'mass_delete?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'package':
				$rReturn = API::processPackage($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'packages?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'profile':
				$rReturn = API::processProfile($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'profiles?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'radio':
				$rReturn = API::processRadio($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'radios') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'stream_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'rtmp_ip':
				$rReturn = API::processRTMPIP($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'rtmp_ips?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'serie':
				if (!empty($rData['import_folder']) || !empty($_FILES['m3u_file']['tmp_name'])) {
					$rReturn = API::importSeries($rData);
					echo json_encode(array('result' => true, 'location' => 'series?status=2', 'status' => $rReturn['status']));
					exit();
				}

				$rReturn = API::processSeries($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'series') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'series?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'proxy':
				$rReturn = API::processProxy($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'server_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'server':
				$rData['server_type'] = 0;
				$rReturn = API::processServer($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if ($rData['regenerate_ssl'] == 1) {
						$db->query('UPDATE `servers` SET `certbot_ssl` = null WHERE `id` = ?;', $rReturn['data']['insert_id']);
						$rCertbot = array('action' => 'certbot_generate', 'domain' => array());

						if (!is_array($rData['domain_name'])) {
							$rData['domain_name'] = explode(',', $rData['domain_name']);
						}

						foreach ($rData['domain_name'] as $rDomain) {
							if (!filter_var($rDomain, FILTER_VALIDATE_IP)) {
								$rCertbot['domain'][] = $rDomain;
							}
						}

						if (0 < count($rCertbot['domain'])) {
							$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rReturn['data']['insert_id'], time(), json_encode($rCertbot));
							echo json_encode(array('result' => true, 'location' => 'server_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . STATUS_CERTBOT, 'status' => STATUS_CERTBOT));

							exit();
						}

						echo json_encode(array('result' => true, 'location' => 'server_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . STATUS_CERTBOT_INVALID, 'status' => STATUS_CERTBOT_INVALID));

						exit();
					} else {
						echo json_encode(array('result' => true, 'location' => 'server_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));

						exit();
					}
				} else {
					echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
					exit();
				}

				// no break
			case 'server_install':
				$rReturn = API::installServer($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'server_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'settings':
				$rReturn = API::editSettings($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'settings?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'settings_plex':
				$rReturn = API::editPlexSettings($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'settings_plex?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'settings_watch':
				$rReturn = API::editWatchSettings($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'settings_watch?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'server_order':
				$rReturn = API::orderServers($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'server_order?status=' . STATUS_SUCCESS, 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'stream_categories':
				$rReturn = API::orderCategories($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'stream_categories?status=' . STATUS_SUCCESS_MULTI, 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'stream_category':
				$rReturn = API::processCategory($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'stream_categories?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'ticket':
				$rReturn = API::submitTicket($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'ticket_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'user':
				$rReturn = API::processUser($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					if (isset($rData['edit']) && getPageFromURL($rReferer) == 'users') {
						echo json_encode(array('result' => true, 'location' => $rReferer, 'status' => $rReturn['status']));

						exit();
					}

					echo json_encode(array('result' => true, 'location' => 'users?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'useragent':
				$rReturn = API::processUA($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'useragents?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'watch_add':
				$rReturn = API::processWatchFolder($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'watch?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();

			case 'plex_add':
				$rReturn = API::processPlexSync($rData);

				if ($rReturn['status'] == STATUS_SUCCESS) {
					echo json_encode(array('result' => true, 'location' => 'plex?status=' . intval($rReturn['status']), 'status' => $rReturn['status']));
					exit();
				}

				echo json_encode(array('result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']));
				exit();
		}
	} else {
		echo json_encode(array('result' => false));

		exit();
	}
}

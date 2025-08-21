<?php

include 'functions.php';
session_write_close();

if (!PHP_ERRORS) {
	if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
		exit();
	}
}

if (isset($_SESSION['hash'])) {
	if (CoreUtilities::$rSettings['redis_handler']) {
		CoreUtilities::connectRedis();
	}

	if (isset(CoreUtilities::$rRequest['action'])) {
		if (CoreUtilities::$rRequest['action'] == 'stream') {
			if (hasPermissions('adv', 'edit_stream')) {
				$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);
				$rServerID = intval(CoreUtilities::$rRequest['server_id']);
				$rSub = CoreUtilities::$rRequest['sub'];

				if (in_array($rSub, array('start', 'stop', 'restart'))) {
					if ($rSub == 'restart') {
						$rSub = 'start';
					}

					if ($rServerID == -1) {
						$rServerIDs = array();
						$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);

						foreach ($db->get_rows() as $rRow) {
							$rServerIDs[] = intval($rRow['server_id']);
						}

						if (count($rServerIDs) > 0) {
							echo APIRequest(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => $rServerIDs));

							exit();
						}
					} else {
						echo APIRequest(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => array($rServerID)));

						exit();
					}
				} else {
					if ($rSub == 'force') {
						$rForceID = intval(CoreUtilities::$rRequest['force_id']);
						$rServerIDs = array_keys(getStreamSys($rStreamID));

						if (0 >= count($rServerIDs)) {
							echo json_encode(array('result' => false));

							exit();
						}

						$rCommand = array('action' => 'force_stream', 'stream_id' => $rStreamID, 'force_id' => $rForceID);
						echo json_encode(AsyncAPIRequest($rServerIDs, $rCommand));

						exit();
					}

					if ($rSub == 'delete') {
						deleteStream($rStreamID, $rServerID, false);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'kill') {
						CoreUtilities::closeConnection(CoreUtilities::$rRequest['stream_id']);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'purge') {
						if (CoreUtilities::$rSettings['redis_handler']) {
							foreach (CoreUtilities::getRedisConnections(null, ($rServerID == -1 ? null : $rServerID), $rStreamID, true, false, false) as $rConnection) {
								CoreUtilities::closeConnection($rConnection);
							}
						} else {
							if ($rServerID == -1) {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ?;', $rStreamID);
							} else {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
							}

							foreach ($db->get_rows() as $rRow) {
								CoreUtilities::closeConnection($rRow);
							}
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'movie') {
			if (hasPermissions('adv', 'edit_movie')) {
				$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);
				$rServerID = intval(CoreUtilities::$rRequest['server_id']);
				$rSub = CoreUtilities::$rRequest['sub'];

				if (in_array($rSub, array('start', 'stop'))) {
					if ($rServerID == -1) {
						$rServerIDs = array();
						$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);

						foreach ($db->get_rows() as $rRow) {
							$rServerIDs[] = intval($rRow['server_id']);
						}

						if (0 >= count($rServerIDs)) {
						} else {
							echo APIRequest(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => $rServerIDs, 'force' => true));

							exit();
						}
					} else {
						echo APIRequest(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => array($rServerID), 'force' => true));

						exit();
					}
				} else {
					if ($rSub == 'delete') {
						deleteStream($rStreamID, $rServerID, true);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'kill') {
						CoreUtilities::closeConnection(CoreUtilities::$rRequest['stream_id']);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'purge') {
						if (CoreUtilities::$rSettings['redis_handler']) {
							foreach (CoreUtilities::getRedisConnections(null, ($rServerID == -1 ? null : $rServerID), $rStreamID, true, false, false) as $rConnection) {
								CoreUtilities::closeConnection($rConnection);
							}
						} else {
							if ($rServerID == -1) {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ?;', $rStreamID);
							} else {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
							}

							foreach ($db->get_rows() as $rRow) {
								CoreUtilities::closeConnection($rRow);
							}
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'episode') {
			if (hasPermissions('adv', 'edit_episode')) {
				$rStreamID = intval(CoreUtilities::$rRequest['stream_id']);
				$rServerID = intval(CoreUtilities::$rRequest['server_id']);
				$rSub = CoreUtilities::$rRequest['sub'];

				if (in_array($rSub, array('start', 'stop'))) {
					if ($rServerID == -1) {
						$rServerIDs = array();
						$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rStreamID);

						foreach ($db->get_rows() as $rRow) {
							$rServerIDs[] = intval($rRow['server_id']);
						}

						if (0 >= count($rServerIDs)) {
						} else {
							echo APIRequest(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => $rServerIDs, 'force' => true));

							exit();
						}
					} else {
						echo APIRequest(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => array($rStreamID), 'servers' => array($rServerID), 'force' => true));

						exit();
					}
				} else {
					if ($rSub == 'delete') {
						deleteStream($rStreamID, $rServerID, true);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'kill') {
						CoreUtilities::closeConnection(CoreUtilities::$rRequest['stream_id']);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub == 'purge') {
						if (CoreUtilities::$rSettings['redis_handler']) {
							foreach (CoreUtilities::getRedisConnections(null, ($rServerID == -1 ? null : $rServerID), $rStreamID, true, false, false) as $rConnection) {
								CoreUtilities::closeConnection($rConnection);
							}
						} else {
							if ($rServerID == -1) {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ?;', $rStreamID);
							} else {
								$db->query('SELECT * FROM `lines_live` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
							}

							foreach ($db->get_rows() as $rRow) {
								CoreUtilities::closeConnection($rRow);
							}
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'line') {
			if (hasPermissions('adv', 'edit_user')) {
				$rUserID = intval(CoreUtilities::$rRequest['user_id']);
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteLine($rUserID);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rUserID);
					CoreUtilities::updateLine($rUserID);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rUserID);
					CoreUtilities::updateLine($rUserID);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'ban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rUserID);
					CoreUtilities::updateLine($rUserID);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'unban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rUserID);
					CoreUtilities::updateLine($rUserID);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'kill') {
					if (CoreUtilities::$rSettings['redis_handler']) {
						foreach (CoreUtilities::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
							CoreUtilities::closeConnection($rConnection);
						}
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rUserID);

						foreach ($db->get_rows() as $rRow) {
							CoreUtilities::closeConnection($rRow);
						}
					}

					echo json_encode(array('result' => true));

					exit();
				} else {
					echo json_encode(array('result' => false));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'line_activity') {
			if (hasPermissions('adv', 'connection_logs')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub != 'kill') {
					echo json_encode(array('result' => false));

					exit();
				}

				CoreUtilities::closeConnection(CoreUtilities::$rRequest['pid']);
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'process') {
			if (hasPermissions('adv', 'process_monitor')) {
				systemapirequest(CoreUtilities::$rRequest['server'], array('action' => 'kill_pid', 'pid' => intval(CoreUtilities::$rRequest['pid'])));
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'adjust_credits') {
			if (hasPermissions('adv', 'edit_reguser')) {
				$rUser = getRegisteredUser(CoreUtilities::$rRequest['id']);

				if ($rUser && is_numeric(CoreUtilities::$rRequest['credits'])) {
					$rCredits = intval($rUser['credits']) + intval(CoreUtilities::$rRequest['credits']);

					if (0 <= $rCredits) {
						$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rCredits, $rUser['id']);
						$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['id'], $rUserInfo['id'], CoreUtilities::$rRequest['credits'], time(), CoreUtilities::$rRequest['reason']);
						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'reg_user') {
			if (hasPermissions('adv', 'edit_reguser')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteUser(CoreUtilities::$rRequest['user_id'], false, false, $rUserInfo['id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'ticket') {
			if (hasPermissions('adv', 'ticket')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteTicket(CoreUtilities::$rRequest['ticket_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'close') {
					$db->query('UPDATE `tickets` SET `status` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['ticket_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'reopen') {
					$db->query('UPDATE `tickets` SET `status` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['ticket_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'mag') {
			if (hasPermissions('adv', 'edit_mag')) {
				$rSub = CoreUtilities::$rRequest['sub'];
				$rMagDetails = getMag(intval(CoreUtilities::$rRequest['mag_id']));

				if ($rSub == 'delete') {
					deleteMAG(CoreUtilities::$rRequest['mag_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rMagDetails['user_id']);
					CoreUtilities::updateLine($rMagDetails['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rMagDetails['user_id']);
					CoreUtilities::updateLine($rMagDetails['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'ban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rMagDetails['user_id']);
					CoreUtilities::updateLine($rMagDetails['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'unban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rMagDetails['user_id']);
					CoreUtilities::updateLine($rMagDetails['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'convert') {
					deleteMAG(CoreUtilities::$rRequest['mag_id'], false, false, true);
					echo json_encode(array('result' => true, 'line_id' => $rMagDetails['user']['id']));

					exit();
				}

				if ($rSub == 'kill') {
					if (CoreUtilities::$rSettings['redis_handler']) {
						foreach (CoreUtilities::getRedisConnections($rMagDetails['user_id'], null, null, true, false, false) as $rConnection) {
							CoreUtilities::closeConnection($rConnection);
						}
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rMagDetails['user_id']);

						foreach ($db->get_rows() as $rRow) {
							CoreUtilities::closeConnection($rRow);
						}
					}

					echo json_encode(array('result' => true));

					exit();
				} else {
					echo json_encode(array('result' => false));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'enigma') {
			if (hasPermissions('adv', 'edit_e2')) {
				$rSub = CoreUtilities::$rRequest['sub'];
				$rE2Details = getEnigma(intval(CoreUtilities::$rRequest['e2_id']));

				if ($rSub == 'delete') {
					deleteEnigma(CoreUtilities::$rRequest['e2_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rE2Details['user_id']);
					CoreUtilities::updateLine($rE2Details['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rE2Details['user_id']);
					CoreUtilities::updateLine($rE2Details['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'ban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rE2Details['user_id']);
					CoreUtilities::updateLine($rE2Details['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'unban') {
					$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rE2Details['user_id']);
					CoreUtilities::updateLine($rE2Details['user_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'convert') {
					deleteEnigma(CoreUtilities::$rRequest['e2_id'], false, false, true);
					echo json_encode(array('result' => true, 'line_id' => $rE2Details['user']['id']));

					exit();
				}

				if ($rSub == 'kill') {
					if (CoreUtilities::$rSettings['redis_handler']) {
						foreach (CoreUtilities::getRedisConnections($rE2Details['user_id'], null, null, true, false, false) as $rConnection) {
							CoreUtilities::closeConnection($rConnection);
						}
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rE2Details['user_id']);

						foreach ($db->get_rows() as $rRow) {
							CoreUtilities::closeConnection($rRow);
						}
					}

					echo json_encode(array('result' => true));

					exit();
				} else {
					echo json_encode(array('result' => false));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'mag_event') {
			if (hasPermissions('adv', 'manage_events')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					$db->query('DELETE FROM `mag_events` WHERE `id` = ?;', CoreUtilities::$rRequest['mag_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'regenerate_cache') {
			if (hasPermissions('adv', 'backups')) {
				shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php "force"');
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'enable_cache') {
			if (hasPermissions('adv', 'backups')) {
				$db->query('UPDATE `settings` SET `enable_cache` = 1;');

				if (!file_exists(CACHE_TMP_PATH . 'settings')) {
				} else {
					unlink(CACHE_TMP_PATH . 'settings');
				}

				shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache_engine.php');
				$rCache = intval(trim(shell_exec('pgrep -U xc_vm | xargs ps -f -p | grep cache_handler | grep -v grep | grep -v pgrep | wc -l')));

				if ($rCache != 0) {
				} else {
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'cache_handler.php > /dev/null 2>/dev/null &');
				}

				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'disable_cache') {
			if (hasPermissions('adv', 'backups')) {
				$db->query('UPDATE `settings` SET `enable_cache` = 0;');

				if (!file_exists(CACHE_TMP_PATH . 'settings')) {
				} else {
					unlink(CACHE_TMP_PATH . 'settings');
				}

				shell_exec(PHP_BIN . ' ' . CRON_PATH . 'cache.php');
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'epg') {
			if (hasPermissions('adv', 'epg_edit')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteEPG(CoreUtilities::$rRequest['epg_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'reload') {
					shell_exec(PHP_BIN . ' ' . CRON_PATH . 'epg.php "' . intval(CoreUtilities::$rRequest['epg_id']) . '" > /dev/null 2>/dev/null &');
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'provider') {
			if (hasPermissions('adv', 'streams')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteProvider(CoreUtilities::$rRequest['id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'reload') {
					shell_exec(PHP_BIN . ' ' . CRON_PATH . 'providers.php "' . intval(CoreUtilities::$rRequest['id']) . '" > /dev/null 2>/dev/null &');
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'profile') {
			if (hasPermissions('adv', 'tprofiles')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteProfile(CoreUtilities::$rRequest['profile_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'series') {
			if (hasPermissions('adv', 'edit_series')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteSeries(CoreUtilities::$rRequest['series_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'kill_watch') {
			if (hasPermissions('adv', 'folder_watch')) {
				killWatchFolder();
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'kill_plex') {
			if (hasPermissions('adv', 'folder_watch')) {
				killPlexSync();
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'folder') {
			if (hasPermissions('adv', 'folder_watch')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteWatchFolder(CoreUtilities::$rRequest['folder_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'force') {
					$rFolder = getWatchFolder(CoreUtilities::$rRequest['folder_id']);

					if ($rFolder) {
						forceWatch($rFolder['server_id'], $rFolder['id']);
						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'library') {
			if (hasPermissions('adv', 'folder_watch')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteWatchFolder(CoreUtilities::$rRequest['folder_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'force') {
					$rFolder = getWatchFolder(CoreUtilities::$rRequest['folder_id']);

					if ($rFolder) {
						forcePlex($rFolder['server_id'], $rFolder['id']);
						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'useragent') {
			if (hasPermissions('adv', 'block_uas')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					rdeleteBlockedUA(CoreUtilities::$rRequest['ua_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'isp') {
			if (hasPermissions('adv', 'block_isps')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					rdeleteBlockedISP(CoreUtilities::$rRequest['isp_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'mysql_syslog') {
			if (hasPermissions('adv', 'block_ips')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'block' && filter_var(CoreUtilities::$rRequest['ip'], FILTER_VALIDATE_IP)) {
					$db->query("INSERT INTO `blocked_ips`(`ip`, `notes`, `date`) VALUES(?, 'MySQL Bruteforce', ?);", CoreUtilities::$rRequest['ip'], time());
					touch(FLOOD_TMP_PATH . 'block_' . CoreUtilities::$rRequest['ip']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'ip') {
			if (hasPermissions('adv', 'block_ips')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					rdeleteBlockedIP(CoreUtilities::$rRequest['ip']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'rtmp_ip') {
			if (hasPermissions('adv', 'add_rtmp')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteRTMPIP(CoreUtilities::$rRequest['ip']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'watch_output') {
			if (hasPermissions('adv', 'folder_watch_output')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					$db->query('DELETE FROM `watch_logs` WHERE `id` = ?;', CoreUtilities::$rRequest['result_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'server') {
			if (hasPermissions('adv', 'edit_server')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					if ($rServers[CoreUtilities::$rRequest['server_id']]['is_main'] == 0) {
						deleteServer(CoreUtilities::$rRequest['server_id']);
						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();
				}

				if ($rSub == 'update') {
					if (!is_numeric(CoreUtilities::$rRequest['server_id'])) {
						$rIDs = json_decode(CoreUtilities::$rRequest['server_id'], true);
					} else {
						$rIDs = array(intval(CoreUtilities::$rRequest['server_id']));
					}

					foreach ($rIDs as $rID) {
						$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rID, time(), json_encode(array('action' => 'update')));
					}
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ? AND `is_main` = 0;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable_proxy') {
					$db->query('UPDATE `servers` SET `enable_proxy` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable_proxy') {
					$db->query('UPDATE `servers` SET `enable_proxy` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'kill') {
					if (CoreUtilities::$rSettings['redis_handler']) {
						foreach (CoreUtilities::getRedisConnections(null, CoreUtilities::$rRequest['server_id'], null, true, false, false) as $rConnection) {
							CoreUtilities::closeConnection($rConnection);
						}
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ?;', CoreUtilities::$rRequest['server_id']);

						foreach ($db->get_rows() as $rRow) {
							CoreUtilities::closeConnection($rRow);
						}
					}

					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'restart') {
					$rStreamIDs = array();
					$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `on_demand` = 0 AND `monitor_pid` > 0 AND `pid` > 0 AND `stream_status` = 0;', CoreUtilities::$rRequest['server_id']);

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rStreamIDs[] = intval($rRow['stream_id']);
						}
					}

					if (0 >= count($rStreamIDs)) {
					} else {
						$rResult = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs), 'servers' => array(intval(CoreUtilities::$rRequest['server_id']))));
					}

					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'start') {
					$rStreamIDs = array();
					$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `on_demand` = 0;', CoreUtilities::$rRequest['server_id']);

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rStreamIDs[] = intval($rRow['stream_id']);
						}
					}

					if (0 >= count($rStreamIDs)) {
					} else {
						$rResult = APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs), 'servers' => array(intval(CoreUtilities::$rRequest['server_id']))));
					}

					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'stop') {
					$rStreamIDs = array();
					$db->query('SELECT `stream_id` FROM `streams_servers` WHERE `server_id` = ? AND `on_demand` = 0;', CoreUtilities::$rRequest['server_id']);

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rStreamIDs[] = intval($rRow['stream_id']);
						}
					}

					if (0 >= count($rStreamIDs)) {
					} else {
						$rResult = APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => array_values($rStreamIDs), 'servers' => array(intval(CoreUtilities::$rRequest['server_id']))));
					}

					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));
				exit();
			} else {
				echo json_encode(array('result' => false));
				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'proxy') {
			if (hasPermissions('adv', 'edit_server')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteServer(CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `servers` SET `enabled` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['server_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'update') {
					$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', CoreUtilities::$rRequest['server_id'], time(), json_encode(array('action' => 'update')));
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'kill') {
					if (CoreUtilities::$rSettings['redis_handler']) {
						foreach (CoreUtilities::$rServers[$rServerID]['parent_id'] as $rParentID) {
							foreach (CoreUtilities::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
								if ($rConnection['proxy_id'] != CoreUtilities::$rRequest['server_id']) {
								} else {
									CoreUtilities::closeConnection($rConnection);
								}
							}
						}
					} else {
						$db->query('SELECT * FROM `lines_live` WHERE `proxy_id` = ?;', CoreUtilities::$rRequest['server_id']);

						foreach ($db->get_rows() as $rRow) {
							CoreUtilities::closeConnection($rRow);
						}
					}

					echo json_encode(array('result' => true));

					exit();
				} else {
					echo json_encode(array('result' => false));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'package') {
			if (hasPermissions('adv', 'edit_package')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deletePackage(CoreUtilities::$rRequest['package_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if (in_array($rSub, array('is_trial', 'is_official', 'can_gen_mag', 'can_gen_e2', 'only_mag', 'only_e2'))) {
					$db->query('UPDATE `users_packages` SET ? = ? WHERE `id` = ?;', $rSub, CoreUtilities::$rRequest['value'], CoreUtilities::$rRequest['package_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'code') {
			if (hasPermissions('adv', 'add_code')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					removeAccessEntry(CoreUtilities::$rRequest['code_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'hmac') {
			if (hasPermissions('adv', 'add_hmac')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					validateHMAC(CoreUtilities::$rRequest['hmac_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'group') {
			if (hasPermissions('adv', 'edit_group')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteGroup(CoreUtilities::$rRequest['group_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				if (in_array($rSub, array('is_admin', 'is_reseller'))) {
					$db->query('UPDATE `users_groups` SET ? = ? WHERE `group_id` = ?;', $rSub, CoreUtilities::$rRequest['value'], CoreUtilities::$rRequest['group_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'bouquet') {
			if (hasPermissions('adv', 'edit_bouquet')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteBouquet(CoreUtilities::$rRequest['bouquet_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'category') {
			if (hasPermissions('adv', 'edit_cat')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					deleteCategory(CoreUtilities::$rRequest['category_id']);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'get_package') {
			$rReturn = array();
			$rOverride = json_decode($rUserInfo['override_packages'], true);
			$db->query('SELECT `id`, `bouquets`, `official_credits` AS `cost_credits`, `official_duration`, `official_duration_in`, `max_connections`, `can_gen_mag`, `can_gen_e2`, `only_mag`, `only_e2` FROM `users_packages` WHERE `id` = ?;', CoreUtilities::$rRequest['package_id']);

			if ($db->num_rows() == 1) {
				$rData = $db->get_row();

				if (!(isset($rOverride[$rData['id']]['official_credits']) && 0 < strlen($rOverride[$rData['id']]['official_credits']))) {
				} else {
					$rData['cost_credits'] = $rOverride[$rData['id']]['official_credits'];
				}

				$rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));

				if (!isset(CoreUtilities::$rRequest['user_id'])) {
				} else {
					if (!($rUser = getUser(CoreUtilities::$rRequest['user_id']))) {
					} else {
						if (time() < $rUser['exp_date']) {
							$rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in'], $rUser['exp_date']));
						} else {
							$rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));
						}
					}
				}

				foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
					$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);

					if ($db->num_rows() != 1) {
					} else {
						$rRow = $db->get_row();
						$rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
					}
				}
				echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
			} else {
				echo json_encode(array('result' => false));
			}

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'get_package_trial') {
			$rReturn = array();
			$db->query('SELECT `bouquets`, `trial_credits` AS `cost_credits`, `trial_duration`, `trial_duration_in`, `max_connections`, `can_gen_mag`, `can_gen_e2`, `only_mag`, `only_e2` FROM `users_packages` WHERE `id` = ?;', CoreUtilities::$rRequest['package_id']);

			if ($db->num_rows() == 1) {
				$rData = $db->get_row();
				$rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['trial_duration']) . ' ' . $rData['trial_duration_in']));

				foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
					$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);

					if ($db->num_rows() != 1) {
					} else {
						$rRow = $db->get_row();
						$rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
					}
				}
				echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
			} else {
				echo json_encode(array('result' => false));
			}

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'graph_stats') {
			$rLimit = 3600;
			$rTime = roundUpToAny(time(), 10);
			$rNearestRange = $rTime - $rLimit;
			$rPeriod = 60;
			$rStatsRange = array();

			foreach (range($rNearestRange, $rTime, $rPeriod) as $i) {
				$rStatsRange[] = $i;
			}
			$rServerStats = array();

			if (isset(CoreUtilities::$rRequest['server_id'])) {
				$db->query('SELECT `server_id`, `time`, `cpu`, `iostat_info`, `total_mem_used_percent`, `connections`, `streams`, `users`, `total_users`, `bytes_received`, `bytes_sent` FROM `servers_stats` WHERE `time` >= ? AND `server_id` = ? ORDER BY `time` DESC;', $rNearestRange, CoreUtilities::$rRequest['server_id']);
			} else {
				$db->query('SELECT `server_id`, `time`, `cpu`, `iostat_info`, `total_mem_used_percent`, `connections`, `streams`, `users`, `total_users`, `bytes_received`, `bytes_sent` FROM `servers_stats` WHERE `server_id` IN (SELECT `id` FROM `servers` WHERE `server_type` = 0) AND `time` >= ? ORDER BY `time` DESC;', $rNearestRange);
			}

			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					if (!$rServers[$rRow['server_id']]['server_online']) {
					} else {
						$rNearest = getNearest($rStatsRange, intval($rRow['time']));

						if (isset($rStatsRange[$rNearest][intval($rRow['server_id'])])) {
						} else {
							$rServerStats[$rNearest][intval($rRow['server_id'])] = $rRow;
						}
					}
				}
			}

			$rStats = array('cpu' => array(), 'memory' => array(), 'users' => array(), 'io' => array(), 'input' => array(), 'output' => array(), 'dates' => array(null, null));

			foreach (array_keys($rServerStats) as $rTime) {
				$rTotalCPU = 0;
				$rCPUCount = 0;
				$rTotalMem = 0;
				$rMemCount = 0;
				$rTotalIO = 0;
				$rIOCount = 0;
				$rTotalInput = 0;
				$rTotalOutput = 0;
				$rTotalConnections = 0;
				$rTotalStreams = 0;
				$rTotalUsers = 0;

				if (isset(CoreUtilities::$rRequest['server_id'])) {
					$rTotalUsers = $rServerStats[$rTime][CoreUtilities::$rRequest['server_id']]['users'];
				} else {
					$rTotalUsers = $rServerStats[$rTime][SERVER_ID]['total_users'];
				}

				foreach ($rServerStats[$rTime] as $rServerID => $rData) {
					$rTotalCPU += $rData['cpu'];
					$rCPUCount++;
					$rIOStat = json_decode($rData['iostat_info'], true);

					if ($rIOStat) {
						$rTotalIO += $rIOStat['avg-cpu']['iowait'];
						$rIOCount++;
					}

					$rTotalMem += $rData['total_mem_used_percent'];
					$rMemCount++;
					$rTotalConnections += $rData['connections'];
					$rTotalStreams += $rData['streams'];
					$rTotalInput += $rData['bytes_received'];
					$rTotalOutput += $rData['bytes_sent'];
				}

				if ($rStats['dates'][0] && $rTime * 1000 >= $rStats['dates'][0]) {
				} else {
					$rStats['dates'][0] = $rTime * 1000;
				}

				if ($rStats['dates'][1] && $rStats['dates'][1] >= $rTime * 1000) {
				} else {
					$rStats['dates'][1] = $rTime * 1000;
				}

				$rStats['cpu'][] = array($rTime * 1000, round($rTotalCPU / $rCPUCount, 2));
				$rStats['memory'][] = array($rTime * 1000, round($rTotalMem / $rMemCount, 2));
				$rStats['io'][] = array($rTime * 1000, round($rTotalIO / $rIOCount, 2));
				$rStats['connections'][] = array($rTime * 1000, $rTotalConnections);
				$rStats['streams'][] = array($rTime * 1000, $rTotalStreams);
				$rStats['users'][] = array($rTime * 1000, $rTotalUsers);
				$rStats['input'][] = array($rTime * 1000, round($rTotalInput / 125000, 0));
				$rStats['output'][] = array($rTime * 1000, round($rTotalOutput / 125000, 0));
			}
			echo json_encode($rStats, JSON_PARTIAL_OUTPUT_ON_ERROR);

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'stats') {
			if (hasPermissions('adv', 'index')) {
				$rServers = CoreUtilities::getServers(true);
				$rReturn = array('cpu' => 0, 'mem' => 0, 'io' => 0, 'fs' => 0, 'uptime' => '--', 'bytes_sent' => 0, 'bytes_received' => 0, 'open_connections' => 0, 'total_connections' => 0, 'online_users' => 0, 'total_users' => 0, 'total_streams' => 0, 'total_running_streams' => 0, 'offline_streams' => 0, 'requests_per_second' => 0, 'servers' => array());

				if (CoreUtilities::$rSettings['redis_handler']) {
					$rReturn['total_users'] = CoreUtilities::$rSettings['total_users'];
				} else {
					$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['total_users'] = $db->num_rows();
					}
				}

				if (isset(CoreUtilities::$rRequest['server_id'])) {
					$rServerID = intval(CoreUtilities::$rRequest['server_id']);
					$rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

					if (!is_array($rWatchDog)) {
					} else {
						$rReturn['uptime'] = $rWatchDog['uptime'];
						$rReturn['mem'] = round($rWatchDog['total_mem_used_percent'], 0);
						$rReturn['cpu'] = round($rWatchDog['cpu'], 0);

						if (!isset($rWatchDog['iostat_info'])) {
						} else {
							$rReturn['io'] = round($rWatchDog['iostat_info']['avg-cpu']['iowait'], 0);
						}

						if (!isset($rWatchDog['total_disk_space'])) {
						} else {
							$rReturn['fs'] = intval(($rWatchDog['total_disk_space'] - $rWatchDog['free_disk_space']) / $rWatchDog['total_disk_space'] * 100);
						}

						$rReturn['bytes_received'] = intval($rWatchDog['bytes_received']);
						$rReturn['bytes_sent'] = intval($rWatchDog['bytes_sent']);
					}

					$rReturn['requests_per_second'] = $rServers[$rServerID]['requests_per_second'];

					if (CoreUtilities::$rSettings['redis_handler']) {
						$rReturn['open_connections'] = $rServers[$rServerID]['connections'];
						$rReturn['online_users'] = $rServers[$rServerID]['users'];

						foreach (array_keys($rServers) as $rSID) {
							if (!$rServers[$rSID]['server_online']) {
							} else {
								$rReturn['total_connections'] += $rServers[$rSID]['connections'];
							}
						}
					} else {
						$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);

						if (0 >= $db->num_rows()) {
						} else {
							$rReturn['open_connections'] = $db->get_row()['count'];
						}

						$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');

						if (0 >= $db->num_rows()) {
						} else {
							$rReturn['total_connections'] = $db->get_row()['count'];
						}

						$db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', $rServerID);

						if (0 >= $db->num_rows()) {
						} else {
							$rReturn['online_users'] = $db->num_rows();
						}
					}

					$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `stream_status` <> 2 AND `type` = 1;', $rServerID);

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['total_streams'] = $db->get_row()['count'];
					}

					$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['total_running_streams'] = $db->get_row()['count'];
					}

					$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServerID);

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['offline_streams'] = $db->get_row()['count'];
					}

					$rReturn['network_guaranteed_speed'] = $rServers[$rServerID]['network_guaranteed_speed'];
				} else {
					$rUptime = 0;

					if (CoreUtilities::$rSettings['redis_handler']) {
					} else {
						$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');

						if (0 < $db->num_rows()) {
							$rTotalConnections = $db->get_row()['count'];
						} else {
							$rTotalConnections = 0;
						}

						$db->query('SELECT `activity_id` AS `count` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');

						if (0 < $db->num_rows()) {
							$rTotalUsers = $db->num_rows();
						} else {
							$rTotalUsers = 0;
						}

						$db->query('SELECT `user_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
						$rReturn['online_users'] = $db->num_rows();
						$rReturn['open_connections'] = $rTotalConnections;
					}

					$rTotalStreams = $rOnlineStreams = $rOfflineStreams = $rOnlineUsers = $rOpenConnections = array();
					$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `server_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rOpenConnections[intval($rRow['server_id'])] = intval($rRow['count']);
					}
					$db->query('SELECT `server_id`, COUNT(DISTINCT(`user_id`)) AS `count` FROM `lines_live` GROUP BY `server_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rOnlineUsers[intval($rRow['server_id'])] = intval($rRow['count']);
					}
					$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `stream_status` <> 2 AND `type` = 1 GROUP BY `server_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rTotalStreams[intval($rRow['server_id'])] = intval($rRow['count']);
					}
					$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0) GROUP BY `server_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rOfflineStreams[intval($rRow['server_id'])] = intval($rRow['count']);
					}
					$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `pid` > 0 AND `type` = 1 GROUP BY `server_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rOnlineStreams[intval($rRow['server_id'])] = intval($rRow['count']);
					}

					foreach (array_keys($rServers) as $rServerID) {
						if ($rServers[$rServerID]['server_online']) {
							$rArray = array();

							if (CoreUtilities::$rSettings['redis_handler']) {
								$rArray['open_connections'] = $rServers[$rServerID]['connections'];
								$rReturn['open_connections'] += $rServers[$rServerID]['connections'];
								$rReturn['total_connections'] += $rServers[$rServerID]['connections'];
								$rArray['online_users'] = $rServers[$rServerID]['users'];
								$rReturn['online_users'] += $rServers[$rServerID]['users'];
								$rReturn['total_users'] += $rServers[$rServerID]['users'];
							} else {
								$rArray['open_connections'] = ($rOpenConnections[$rServerID] ?: 0);
								$rArray['online_users'] = ($rOnlineUsers[$rServerID] ?: 0);
							}

							$rArray['requests_per_second'] = $rServers[$rServerID]['requests_per_second'];
							$rArray['total_streams'] = ($rTotalStreams[$rServerID] ?: 0);
							$rArray['total_running_streams'] = ($rOnlineStreams[$rServerID] ?: 0);
							$rArray['offline_streams'] = ($rOfflineStreams[$rServerID] ?: 0);
							$rArray['network_guaranteed_speed'] = $rServers[$rServerID]['network_guaranteed_speed'];
							$rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

							if (!is_array($rWatchDog)) {
							} else {
								$rArray['uptime'] = $rWatchDog['uptime'];
								$rArray['mem'] = round($rWatchDog['total_mem_used_percent'], 0);
								$rArray['cpu'] = round($rWatchDog['cpu'], 0);

								if (!isset($rWatchDog['iostat_info'])) {
								} else {
									$rArray['io'] = round($rWatchDog['iostat_info']['avg-cpu']['iowait'], 0);
								}

								if (!isset($rWatchDog['total_disk_space'])) {
								} else {
									$rArray['fs'] = intval(($rWatchDog['total_disk_space'] - $rWatchDog['free_disk_space']) / $rWatchDog['total_disk_space'] * 100);
								}

								$rArray['bytes_received'] = intval($rWatchDog['bytes_received']);
								$rArray['bytes_sent'] = intval($rWatchDog['bytes_sent']);
								$rReturn['bytes_received'] += intval($rWatchDog['bytes_received']);
								$rReturn['bytes_sent'] += intval($rWatchDog['bytes_sent']);
							}

							$rArray['total_connections'] = $rTotalConnections;
							$rArray['server_id'] = $rServerID;
							$rArray['server_type'] = $rServers[$rServerID]['server_type'];
							$rReturn['servers'][] = $rArray;
						}
					}

					foreach ($rReturn['servers'] as $rServerArray) {
						$rReturn['total_streams'] += $rServerArray['total_streams'];
						$rReturn['total_running_streams'] += $rServerArray['total_running_streams'];
						$rReturn['offline_streams'] += $rServerArray['offline_streams'];
					}
					$rReturn['online_users'] = CoreUtilities::$rSettings['total_users'];
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'header_stats') {
			if (hasPermissions('adv', 'index')) {
				$rReturn = array('bytes_sent' => 0, 'bytes_received' => 0, 'total_connections' => 0, 'total_users' => 0, 'total_running_streams' => 0, 'offline_streams' => 0);

				if (!CoreUtilities::$rSettings['redis_handler']) {
					$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['total_connections'] = $db->get_row()['count'];
					}

					$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');

					if (0 >= $db->num_rows()) {
					} else {
						$rReturn['total_users'] = $db->num_rows();
					}
				} else {
					$rReturn['total_users'] = CoreUtilities::$rSettings['total_users'];
				}

				$rOnlineCount = $rOfflineCount = array();
				$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `pid` > 0 AND `type` = 1 GROUP BY `server_id`;');

				foreach ($db->get_rows() as $rRow) {
					$rOnlineCount[intval($rRow['server_id'])] = intval($rRow['count']);
				}
				$db->query('SELECT `server_id`, COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0) GROUP BY `server_id`;');

				foreach ($db->get_rows() as $rRow) {
					$rOfflineCount[intval($rRow['server_id'])] = intval($rRow['count']);
				}

				foreach (array_keys($rServers) as $rServerID) {
					if (!$rServers[$rServerID]['server_online']) {
					} else {
						if (!CoreUtilities::$rSettings['redis_handler']) {
						} else {
							$rReturn['total_connections'] += $rServers[$rServerID]['connections'];
						}

						$rReturn['total_running_streams'] += ($rOnlineCount[$rServerID] ?: 0);
						$rReturn['offline_streams'] += ($rOfflineCount[$rServerID] ?: 0);
						$rWatchDog = json_decode($rServers[$rServerID]['watchdog_data'], true);

						if (!is_array($rWatchDog)) {
						} else {
							$rReturn['bytes_received'] += intval($rWatchDog['bytes_received']);
							$rReturn['bytes_sent'] += intval($rWatchDog['bytes_sent']);
						}
					}
				}
				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'review_selection') {
			if (hasPermissions('adv', 'edit_cchannel') || hasPermissions('adv', 'create_channel')) {
				$rReturn = array('streams' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['data'])) {
				} else {
					foreach (CoreUtilities::$rRequest['data'] as $rStreamID) {
						$db->query('SELECT `id`, `stream_display_name`, `stream_source` FROM `streams` WHERE `id` = ?;', $rStreamID);

						if ($db->num_rows() != 1) {
						} else {
							$rReturn['streams'][] = $db->get_row();
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'review_bouquet') {
			if (hasPermissions('adv', 'edit_bouquet') || hasPermissions('adv', 'add_bouquet')) {
				$rReturn = array('streams' => array(), 'movies' => array(), 'series' => array(), 'radios' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['data']['stream'])) {
				} else {
					foreach (CoreUtilities::$rRequest['data']['stream'] as $rStreamID) {
						$db->query('SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` IN (1,3);', $rStreamID);

						if ($db->num_rows() != 1) {
						} else {
							$rData = $db->get_row();
							$rReturn['streams'][] = $rData;
						}
					}
				}

				if (!isset(CoreUtilities::$rRequest['data']['movies'])) {
				} else {
					foreach (CoreUtilities::$rRequest['data']['movies'] as $rStreamID) {
						$db->query('SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` = 2;', $rStreamID);

						if ($db->num_rows() != 1) {
						} else {
							$rData = $db->get_row();
							$rReturn['movies'][] = $rData;
						}
					}
				}

				if (!isset(CoreUtilities::$rRequest['data']['radios'])) {
				} else {
					foreach (CoreUtilities::$rRequest['data']['radios'] as $rStreamID) {
						$db->query('SELECT `id`, `stream_display_name`, `type` FROM `streams` WHERE `id` = ? AND `type` = 4;', $rStreamID);

						if ($db->num_rows() != 1) {
						} else {
							$rData = $db->get_row();
							$rReturn['radios'][] = $rData;
						}
					}
				}

				if (!isset(CoreUtilities::$rRequest['data']['series'])) {
				} else {
					foreach (CoreUtilities::$rRequest['data']['series'] as $rSeriesID) {
						$db->query('SELECT `id`, `title` FROM `streams_series` WHERE `id` = ?;', $rSeriesID);

						if ($db->num_rows() != 1) {
						} else {
							$rData = $db->get_row();
							$rReturn['series'][] = $rData;
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'epglist') {
			if (hasPermissions('adv', 'import_streams')) {
				$rGroups = array();
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (isset(CoreUtilities::$rRequest['search'])) {
					$rEPGNames = $rEPGMap = array();
					$db->query('SELECT `epg_channels`.`epg_id`, `epg_channels`.`channel_id`, `epg_channels`.`name`, `epg_channels`.`langs`, `epg`.`epg_name` FROM `epg_channels` LEFT JOIN `epg` ON `epg_channels`.`epg_id` = `epg`.`id` WHERE (LOWER(`epg_channels`.`channel_id`) LIKE ? OR LOWER(`epg_channels`.`name`) LIKE ?) ORDER BY `epg_channels`.`name` ASC LIMIT 50;', strtolower(CoreUtilities::$rRequest['search']) . '%', strtolower(CoreUtilities::$rRequest['search']) . '%');

					foreach ($db->get_rows() as $rRow) {
						if (isset($rEPGNames[$rRow['epg_id']])) {
						} else {
							$rEPGNames[$rRow['epg_id']] = $rRow['epg_name'];
						}

						$rLangs = json_decode($rRow['langs'], true);
						$rEPGMap[$rRow['epg_id']][] = array('id' => $rRow['channel_id'], 'text' => $rRow['name'], 'icon' => null, 'lang' => (isset($rLangs[0]) ? $rLangs[0] : ''), 'epg_id' => $rRow['epg_id'], 'type' => 0);
					}

					foreach ($rEPGMap as $rEPGID => $rResults) {
						$rReturn['items'][] = array('text' => $rEPGNames[$rEPGID], 'children' => $rResults);
						$rReturn['total_count'] += count($rResults);
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'serieslist') {
			if (hasPermissions('adv', 'episodes')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query('SELECT COUNT(`id`) AS `count` FROM `streams_series` WHERE `title` LIKE ?;', '%' . CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['count'];
					$db->query('SELECT `id`, `title` FROM `streams_series` WHERE `title` LIKE ? ORDER BY `title` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%');

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['title']);
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'reguserlist') {
			if (hasPermissions('adv', 'mng_regusers') || hasPermissions('adv', 'manage_mag') || hasPermissions('adv', 'manage_e2') || hasPermissions('adv', 'edit_e2') || hasPermissions('adv', 'add_e2') || hasPermissions('adv', 'add_mag') || hasPermissions('adv', 'edit_mag')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query('SELECT COUNT(`id`) AS `id` FROM `users` WHERE `username` LIKE ?;', '%' . CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['id'];
					$db->query('SELECT `id`, `username` FROM `users` WHERE `username` LIKE ? ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%');

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'userlist') {
			if (hasPermissions('adv', 'edit_e2') || hasPermissions('adv', 'add_e2') || hasPermissions('adv', 'add_mag') || hasPermissions('adv', 'edit_mag')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query('SELECT COUNT(`id`) AS `id` FROM `lines` WHERE `username` LIKE ? AND `is_e2` = 0 AND `is_mag` = 0;', CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['id'];
					$db->query('SELECT COUNT(`device_id`) AS `id` FROM `enigma2_devices` WHERE `mac` LIKE ?;', CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] += $db->get_row()['id'];
					$db->query('SELECT COUNT(`mag_id`) AS `id` FROM `mag_devices` WHERE `mac` LIKE ?;', CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] += $db->get_row()['id'];
					$db->query('SELECT `id`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%');

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'streamlist') {
			if (hasPermissions('adv', 'manage_mag')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE `stream_display_name` LIKE ?;', '%' . CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['id'];
					$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `stream_display_name` LIKE ? ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%');

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['stream_display_name']);
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'adaptivelist') {
			if (hasPermissions('adv', 'edit_stream')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE (`stream_display_name` LIKE ? OR `id` LIKE ?) AND `type` = 1;', '%' . CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['id'];
					$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE (`stream_display_name` LIKE ? OR `id` LIKE ?) AND `type` = 1 ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%');

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rReturn['items'][] = array('id' => $rRow['id'], 'text' => '[' . $rRow['id'] . '] ' . $rRow['stream_display_name']);
						}
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'titlesync') {
			if (hasPermissions('adv', 'edit_stream')) {
				$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

				if (!isset(CoreUtilities::$rRequest['search'])) {
				} else {
					if (isset(CoreUtilities::$rRequest['page'])) {
						$rPage = intval(CoreUtilities::$rRequest['page']);
					} else {
						$rPage = 1;
					}

					$db->query("SELECT COUNT(`stream_id`) AS `stream_id` FROM `providers_streams` WHERE `type` = 'live' AND (`stream_display_name` LIKE ? OR `stream_id` LIKE ?);", '%' . CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%');
					$rReturn['total_count'] = $db->get_row()['stream_id'];
					$db->query("SELECT `providers`.`name`, `providers_streams`.`provider_id`, `providers_streams`.`stream_id`, `providers_streams`.`stream_display_name` FROM `providers_streams` LEFT JOIN `providers` ON `providers`.`id` = `providers_streams`.`provider_id` WHERE `providers_streams`.`type` = 'live' AND (`stream_display_name` LIKE ? OR `stream_id` LIKE ?) ORDER BY `stream_display_name` ASC LIMIT " . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%', CoreUtilities::$rRequest['search'] . '%');
					$rGroups = array();

					if (0 >= $db->num_rows()) {
					} else {
						foreach ($db->get_rows() as $rRow) {
							$rGroups[$rRow['provider_id']][] = $rRow;
						}
					}

					foreach ($rGroups as $rGroupID => $rRows) {
						$CacheFlushInterval = array('text' => $rRows[0]['name'], 'children' => array());

						foreach ($rRows as $rRow) {
							$CacheFlushInterval['children'][] = array('id' => $rRow['provider_id'] . '_' . $rRow['stream_id'], 'text' => '[' . $rRow['stream_id'] . '] ' . $rRow['stream_display_name']);
						}
						$rReturn['items'][] = $CacheFlushInterval;
					}
				}

				echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'force_epg') {
			if (hasPermissions('adv', 'epg')) {
				shell_exec(PHP_BIN . ' ' . CRON_PATH . 'epg.php > /dev/null 2>/dev/null &');
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'tmdb_search') {
			if (hasPermissions('adv', 'add_series') || hasPermissions('adv', 'edit_series') || hasPermissions('adv', 'add_movie') || hasPermissions('adv', 'edit_movie') || hasPermissions('adv', 'add_episode') || hasPermissions('adv', 'edit_episode')) {
				$rTerm = CoreUtilities::$rRequest['term'];

				if (0 >= strlen($rTerm)) {
				} else {
					include MAIN_HOME . 'includes/libs/tmdb.php';

					if (0 < strlen(CoreUtilities::$rRequest['language'])) {
						$rTMDB = new TMDB($rSettings['tmdb_api_key'], CoreUtilities::$rRequest['language']);
					} else {
						if (0 < strlen($rSettings['tmdb_language'])) {
							$rTMDB = new TMDB($rSettings['tmdb_api_key'], $rSettings['tmdb_language']);
						} else {
							$rTMDB = new TMDB($rSettings['tmdb_api_key']);
						}
					}

					if (is_numeric($rTerm) && in_array(CoreUtilities::$rRequest['type'], array('movie', 'series', 'episode'))) {
						if (CoreUtilities::$rRequest['type'] == 'movie') {
							$rResult = array(json_decode($rTMDB->getMovie($rTerm)->getJSON(), true));
						} else {
							if (CoreUtilities::$rRequest['type'] == 'series') {
								$rResult = array(json_decode($rTMDB->getTVShow($rTerm)->getJSON(), true));
							} else {
								$rResult = json_decode($rTMDB->getSeason($rTerm, intval(CoreUtilities::$rRequest['season']))->getJSON(), true);

								if (isset($rResult['tvshow_id']) && $rResult['tvshow_id'] == 0) {
									$rResult = null;
								}
							}
						}

						if (is_array($rResult)) {
							echo json_encode(array('result' => true, 'data' => $rResult));

							exit();
						}
					}

					$rRelease = parserelease($rTerm);
					$rTerm = $rRelease['title'];
					$rJSON = array();

					if (CoreUtilities::$rRequest['type'] == 'movie') {
						$rResults = $rTMDB->searchMovie($rTerm);

						foreach ($rResults as $rResult) {
							$rJSON[] = json_decode($rResult->getJSON(), true);
						}
					} else {
						if (CoreUtilities::$rRequest['type'] == 'series') {
							$rResults = $rTMDB->searchTVShow($rTerm);

							foreach ($rResults as $rResult) {
								$rJSON[] = json_decode($rResult->getJSON(), true);
							}
						}
					}

					if (0 >= count($rJSON)) {
					} else {
						echo json_encode(array('result' => true, 'data' => $rJSON));

						exit();
					}
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'tmdb') {
			if (hasPermissions('adv', 'add_series') || hasPermissions('adv', 'edit_series') || hasPermissions('adv', 'add_movie') || hasPermissions('adv', 'edit_movie') || hasPermissions('adv', 'add_episode') || hasPermissions('adv', 'edit_episode')) {
				include MAIN_HOME . 'includes/libs/tmdb.php';

				if (0 < strlen(CoreUtilities::$rRequest['language'])) {
					$rTMDB = new TMDB($rSettings['tmdb_api_key'], CoreUtilities::$rRequest['language']);
				} else {
					if (0 < strlen($rSettings['tmdb_language'])) {
						$rTMDB = new TMDB($rSettings['tmdb_api_key'], $rSettings['tmdb_language']);
					} else {
						$rTMDB = new TMDB($rSettings['tmdb_api_key']);
					}
				}

				$rID = CoreUtilities::$rRequest['id'];

				if (CoreUtilities::$rRequest['type'] == 'movie') {
					$rMovie = $rTMDB->getMovie($rID);
					$rResult = json_decode($rMovie->getJSON(), true);
					$rResult['trailer'] = $rMovie->getTrailer();
				} else {
					if (CoreUtilities::$rRequest['type'] != 'series') {
					} else {
						$rSeries = $rTMDB->getTVShow($rID);
						$rResult = json_decode($rSeries->getJSON(), true);
						$rResult['trailer'] = getSeriesTrailer($rID, (!empty(CoreUtilities::$rRequest['language']) ? CoreUtilities::$rRequest['language'] : $rSettings['tmdb_language']));
					}
				}

				if (!$rResult) {
					echo json_encode(array('result' => false));

					exit();
				}

				echo json_encode(array('result' => true, 'data' => $rResult));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'listdir') {
			if (hasPermissions('adv', 'add_episode') || hasPermissions('adv', 'edit_episode') || hasPermissions('adv', 'add_movie') || hasPermissions('adv', 'edit_movie') || hasPermissions('adv', 'create_channel') || hasPermissions('adv', 'edit_cchannel') || hasPermissions('adv', 'folder_watch_add')) {
				if (CoreUtilities::$rRequest['filter'] == 'video') {
					$rFilter = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts');
				} else {
					if (CoreUtilities::$rRequest['filter'] == 'subs') {
						$rFilter = array('srt', 'sub', 'sbv');
					} else {
						$rFilter = array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts', 'srt', 'sub', 'sbv');
					}
				}

				if (!(isset(CoreUtilities::$rRequest['server']) && isset(CoreUtilities::$rRequest['dir']))) {
					echo json_encode(array('result' => false));

					exit();
				}

				echo json_encode(array('result' => true, 'data' => listDir(intval(CoreUtilities::$rRequest['server']), CoreUtilities::$rRequest['dir'], $rFilter)));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'fingerprint') {
			if (hasPermissions('adv', 'fingerprint')) {
				$rData = json_decode(CoreUtilities::$rRequest['data'], true);
				$rActiveServers = array();

				foreach ($rServers as $rServer) {
					if ((360 < time() - $rServer['last_check_ago'] || $rServer['status'] == 2) && $rServer['is_main'] == 0 && $rServer['status'] != 3) {
						$rServerError = true;
					} else {
						$rServerError = false;
					}

					if ($rServer['status'] != 1 || $rServerError) {
					} else {
						$rActiveServers[] = $rServer['id'];
					}
				}

				if (!(0 < $rData['id'] && 0 < $rData['font_size'] && 0 < strlen($rData['font_color']) && 0 < strlen($rData['xy_offset']) && (0 < strlen($rData['message']) || $rData['type'] < 3))) {
				} else {
					if (CoreUtilities::$rSettings['redis_handler']) {
						if (isset($rData['user'])) {
							$rRows = CoreUtilities::getRedisConnections($rData['id'], null, null, true, false, false);
						} else {
							$rRows = CoreUtilities::getRedisConnections(null, null, $rData['id'], true, false, false);
						}

						$rUserMap = $rUserIDs = array();

						foreach ($rRows as $rRow) {
							if (in_array($rRow['user_id'], $rUserIDs)) {
							} else {
								$rUserIDs[] = intval($rRow['user_id']);
							}
						}

						if (0 >= count($rUserIDs)) {
						} else {
							$db->query('SELECT `id`, `username` FROM `lines` WHERE `id` IN (' . implode(',', $rUserIDs) . ');');

							foreach ($db->get_rows() as $rRow) {
								$rUserMap[$rRow['id']] = $rRow['username'];
							}
						}
					} else {
						if (isset($rData['user'])) {
							$db->query('SELECT `lines_live`.`activity_id`, `lines_live`.`uuid`, `lines_live`.`user_id`, `lines_live`.`server_id`, `lines`.`username` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `user_id` = ?;', $rData['id']);
						} else {
							$db->query('SELECT `lines_live`.`activity_id`, `lines_live`.`uuid`, `lines_live`.`user_id`, `lines_live`.`server_id`, `lines`.`username` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `stream_id` = ?;', $rData['id']);
						}

						$rRows = $db->get_rows();
					}

					if (0 >= count($rRows)) {
					} else {
						set_time_limit(360);
						ini_set('max_execution_time', 360);
						ini_set('default_socket_timeout', 15);

						foreach ($rRows as $rRow) {
							if (!in_array($rRow['server_id'], $rActiveServers)) {
							} else {
								$rArray = array('font_size' => $rData['font_size'], 'font_color' => $rData['font_color'], 'xy_offset' => $rData['xy_offset'], 'message' => '', 'uuid' => $rRow['uuid']);

								if ($rData['type'] == 1) {
									$rArray['message'] = $rRow['uuid'];
								} else {
									if ($rData['type'] == 2) {
										$rArray['message'] = (CoreUtilities::$rSettings['redis_handler'] ? $rUserMap[$rRow['user_id']] : $rRow['username']);
									} else {
										if ($rData['type'] != 3) {
										} else {
											$rArray['message'] = $rData['message'];
										}
									}
								}

								$rArray['action'] = 'signal_send';
								$rSuccess = systemapirequest(intval($rRow['server_id']), $rArray);
							}
						}
					}
				}

				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'restart_all_services') {
			if (hasPermissions('adv', 'servers')) {
				foreach ($rServers as $rServer) {
					if (!$rServer['server_online']) {
					} else {
						$db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"restart_services\"}', ?);", $rServer['id'], time());
					}
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'restart_services') {
			if (hasPermissions('adv', 'edit_server')) {
				if (!is_numeric(CoreUtilities::$rRequest['server_id'])) {
					$rIDs = json_decode(CoreUtilities::$rRequest['server_id'], true);
				} else {
					$rIDs = array(intval(CoreUtilities::$rRequest['server_id']));
				}

				foreach ($rIDs as $rID) {
					$db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"restart_services\"}', ?);", $rID, time());
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'reboot_server') {
			if (hasPermissions('adv', 'edit_server')) {
				if (!is_numeric(CoreUtilities::$rRequest['server_id'])) {
					$rIDs = json_decode(CoreUtilities::$rRequest['server_id'], true);
				} else {
					$rIDs = array(intval(CoreUtilities::$rRequest['server_id']));
				}

				foreach ($rIDs as $rID) {
					$db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"reboot\"}', ?);", $rID, time());
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'update_binaries') {
			if (hasPermissions('adv', 'edit_server')) {
				if (!is_numeric(CoreUtilities::$rRequest['server_id'])) {
					$rIDs = json_decode(CoreUtilities::$rRequest['server_id'], true);
				} else {
					$rIDs = array(intval(CoreUtilities::$rRequest['server_id']));
				}

				foreach ($rIDs as $rID) {
					$db->query("INSERT INTO `signals`(`server_id`, `custom_data`, `time`) VALUES(?, '{\"action\": \"update_binaries\"}', ?);", $rID, time());
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'probe_stream') {
			if (hasPermissions('adv', 'add_stream') || hasPermissions('adv', 'edit_stream')) {
				$rAnalyseDuration = abs(intval(CoreUtilities::$rSettings['stream_max_analyze']));
				$rTimeout = intval($rAnalyseDuration / 1000000) + CoreUtilities::$rSettings['probe_extra_wait'];
				set_time_limit(intval($rTimeout));
				ini_set('max_execution_time', intval($rTimeout));
				ini_set('default_socket_timeout', intval($rTimeout));
				$rServerID = SERVER_ID;

				if (empty(CoreUtilities::$rRequest['server']) || !$rServers[intval(CoreUtilities::$rRequest['server'])]['server_online']) {
				} else {
					$rServerID = intval(CoreUtilities::$rRequest['server']);
				}

				$rStreamInfoText = "<table style='width: 380px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Stream probe failed!</td></tr></tbody></table>";
				$rStreamInfo = null;

				if (!empty(CoreUtilities::$rRequest['url'])) {
					$rURL = CoreUtilities::parseStreamURL(CoreUtilities::$rRequest['url']);

					if (CoreUtilities::detectXC_VM($rURL) && CoreUtilities::$rSettings['api_probe']) {
						$rURLInfo = parse_url($rURL);
						$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . (($rURLInfo['port'] ? ':' . $rURLInfo['port'] : '')) . '/probe/' . base64_encode($rURLInfo['path']);


						if ($rAPIInfo = json_decode(CoreUtilities::getURL($rProbeURL), true)) {
							$rStreamInfo = array();

							foreach ($rAPIInfo['codecs'] as $rType => $rCodec) {
								$rStreamInfo['streams'][] = $rCodec;
							}
							$rStreamInfo['container'] = $rAPIInfo['container'];
						}
					}

					if (!$rStreamInfo) {
						$rStreamInfo = probeSource($rServerID, CoreUtilities::$rRequest['url'], (CoreUtilities::$rRequest['user_agent'] ?: null), (CoreUtilities::$rRequest['http_proxy'] ?: null), (CoreUtilities::$rRequest['cookies'] ?: null), (CoreUtilities::$rRequest['headers'] ?: null))['data'];
						$rStreamInfo['container'] = $rStreamInfo['format']['format_name'];
					}
				}

				if (isset(CoreUtilities::$rRequest['map'])) {
					echo json_encode($rStreamInfo);

					exit();
				}

				if (0 >= count($rStreamInfo['streams'])) {
				} else {
					$rInfo = array();

					foreach ($rStreamInfo['streams'] as $rCodec) {
						if ($rCodec['codec_type'] == 'video') {
							$rInfo['width'] = intval($rCodec['width']);
							$rInfo['height'] = intval($rCodec['height']);
							$rInfo['vbitrate'] = intval($rCodec['bit_rate']);
							$rInfo['vcodec'] = $rCodec['codec_name'];
							$rInfo['fps'] = intval(explode('/', $rCodec['r_frame_rate'])[0]);

							if ($rInfo['fps']) {
							} else {
								$rInfo['fps'] = intval(explode('/', $rCodec['avg_frame_rate'])[0]);
							}
						} else {
							if ($rCodec['codec_type'] != 'audio') {
							} else {
								$rInfo['abitrate'] = intval($rCodec['bit_rate']);
								$rInfo['acodec'] = $rCodec['codec_name'];
							}
						}
					}

					if (0 < $rInfo['fps']) {
						if (1000 > $rInfo['fps']) {
						} else {
							$rInfo['fps'] = intval($rInfo['fps'] / 1000);
						}

						$rFPS = $rInfo['fps'] . '&nbsp;FPS';
					} else {
						$rFPS = '--';
					}

					if (0 < $rInfo['abitrate'] && 0 < $rInfo['vbitrate']) {
						$rBitrate = intval(($rInfo['abitrate'] + $rInfo['vbitrate']) / 1024);
					} else {
						$rBitrate = 'N/A';
					}

					$rStreamInfoText = "<table class='table-data' style='width: 380px;' align='center'><tbody><tr><td class='nowrap' style='color: #20a009;width: 25%;'><i class='mdi mdi-image-size-select-large' data-name='mdi-image-size-select-large'></i></td><td class='nowrap' style='color: #20a009;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td class='nowrap' style='color: #20a009;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td><td class='nowrap' style='color: #20a009;width: 20%;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td><td class='nowrap' style='color: #" . ((strtolower($rStreamInfo['container']) == 'mpegts' ? '20a009' : 'd65656')) . ";width: 18%;'><i class='mdi " . ((strtolower($rStreamInfo['container']) == 'mpegts' ? 'mdi-check' : 'mdi-close')) . "' data-name='" . ((strtolower($rStreamInfo['container']) == 'mpegts' ? 'mdi-check' : 'mdi-close')) . "'></i></td></tr><tr><td class='nowrap'>" . $rInfo['width'] . '&nbsp;x&nbsp;' . $rInfo['height'] . "</td><td class='nowrap'>" . $rInfo['vcodec'] . "</td><td class='nowrap'>" . $rInfo['acodec'] . "</td><td class='nowrap'>" . $rFPS . "</td><td class='nowrap'>LLOD&nbsp;v3</td></tr></tbody></table>";
				}

				echo $rStreamInfoText;

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'check_stream') {
			if (hasPermissions('adv', 'add_stream') || hasPermissions('adv', 'edit_stream')) {
				$rAnalyseDuration = abs(intval(CoreUtilities::$rSettings['stream_max_analyze']));
				$rTimeout = intval($rAnalyseDuration / 1000000) + CoreUtilities::$rSettings['probe_extra_wait'];
				set_time_limit(intval($rTimeout));
				ini_set('max_execution_time', intval($rTimeout));
				ini_set('default_socket_timeout', intval($rTimeout));

				if (isset(CoreUtilities::$rRequest['url'])) {
					$rURL = CoreUtilities::parseStreamURL(CoreUtilities::$rRequest['url']);

					if (isset(CoreUtilities::$rRequest['ua'])) {
						$rUA = ' -user_agent ' . escapeshellarg(CoreUtilities::$rRequest['ua']);
					} else {
						$rUA = '';
					}

					if (isset(CoreUtilities::$rRequest['cookie'])) {
						$rCookie = ' -cookies ' . escapeshellarg(CoreUtilities::fixCookie(CoreUtilities::$rRequest['cookie']));
					} else {
						$rCookie = '';
					}
				} else {
					$rStream = getStream(CoreUtilities::$rRequest['stream']);
					$rStreamOptions = getStreamOptions(CoreUtilities::$rRequest['stream']);

					if (0 < strlen($rStreamOptions[1]['value'])) {
						$rUA = ' -user_agent ' . escapeshellarg($rStreamOptions[1]['value']);
					} else {
						$rUA = '';
					}

					if (isset(CoreUtilities::$rRequest['cookie'])) {
						$rCookie = ' -cookies ' . escapeshellarg(CoreUtilities::fixCookie($rStreamOptions[17]['value']));
					} else {
						$rCookie = '';
					}

					$rURL = CoreUtilities::parseStreamURL(json_decode($rStream['stream_source'], true)[intval(CoreUtilities::$rRequest['id'])]);
				}

				if (0 >= strlen($rURL)) {
				} else {
					$rStreamInfoText = "<table style='width: 300px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Stream probe failed!</td></tr></tbody></table>";
					$rStreamInfo = null;

					if (!(CoreUtilities::detectXC_VM($rURL) && CoreUtilities::$rSettings['api_probe'])) {
					} else {
						$rURLInfo = parse_url($rURL);
						$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . (($rURLInfo['port'] ? ':' . $rURLInfo['port'] : '')) . '/probe/' . base64_encode($rURLInfo['path']);

						if (!($rAPIInfo = json_decode(CoreUtilities::getURL($rProbeURL), true))) {
						} else {
							$rStreamInfo = array();

							foreach ($rAPIInfo['codecs'] as $rType => $rCodec) {
								$rStreamInfo['streams'][] = $rCodec;
							}
						}
					}

					if ($rStreamInfo) {
					} else {
						$rStreamInfo = json_decode(shell_exec('timeout ' . intval($rTimeout) . ' ' . CoreUtilities::$rFFPROBE . $rUA . $rCookie . ' -v quiet -probesize 5000000 -print_format json -show_format -show_streams ' . escapeshellarg($rURL)), true);
					}

					if (0 >= count($rStreamInfo['streams'])) {
					} else {
						$rInfo = array();

						foreach ($rStreamInfo['streams'] as $rCodec) {
							if ($rCodec['codec_type'] == 'video') {
								$rInfo['width'] = intval($rCodec['width']);
								$rInfo['height'] = intval($rCodec['height']);
								$rInfo['vbitrate'] = intval($rCodec['bit_rate']);
								$rInfo['vcodec'] = $rCodec['codec_name'];
								$rInfo['fps'] = intval(explode('/', $rCodec['r_frame_rate'])[0]);

								if ($rInfo['fps']) {
								} else {
									$rInfo['fps'] = intval(explode('/', $rCodec['avg_frame_rate'])[0]);
								}
							} else {
								if ($rCodec['codec_type'] != 'audio') {
								} else {
									$rInfo['abitrate'] = intval($rCodec['bit_rate']);
									$rInfo['acodec'] = $rCodec['codec_name'];
								}
							}
						}

						if (0 < $rInfo['fps']) {
							if (1000 > $rInfo['fps']) {
							} else {
								$rInfo['fps'] = intval($rInfo['fps'] / 1000);
							}

							$rFPS = $rInfo['fps'] . '&nbsp;FPS';
						} else {
							$rFPS = '--';
						}

						if (0 < $rInfo['abitrate'] && 0 < $rInfo['vbitrate']) {
							$rBitrate = intval(($rInfo['abitrate'] + $rInfo['vbitrate']) / 1024);
						} else {
							$rBitrate = 'N/A';
						}

						$rStreamInfoText = "<table class='table-data' style='width: 300px;' align='center'><tbody><tr><td style='color: #20a009;width: 34%;'><i class='mdi mdi-image-size-select-large' data-name='mdi-image-size-select-large'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td><td style='color: #20a009;width: 23%;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td></tr><tr><td class='double'>" . $rInfo['width'] . '&nbsp;x&nbsp;' . $rInfo['height'] . '</td><td>' . $rInfo['vcodec'] . '</td><td>' . $rInfo['acodec'] . '</td><td>' . $rFPS . '</td></tr></tbody></table>';
					}

					echo $rStreamInfoText;
				}

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'clear_logs') {
			if (hasPermissions('adv', 'reg_userlog') || hasPermissions('adv', 'client_request_log') || hasPermissions('adv', 'connection_logs') || hasPermissions('adv', 'stream_errors') || hasPermissions('adv', 'credits_log') || hasPermissions('adv', 'folder_watch_settings')) {
				if (strlen(CoreUtilities::$rRequest['from']) == 0) {
					$rStartTime = null;
				} else {
					if ($rStartTime = strtotime(CoreUtilities::$rRequest['from'] . ' 00:00:00')) {
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}

				if (strlen(CoreUtilities::$rRequest['to']) == 0) {
					$rEndTime = null;
				} else {
					if ($rEndTime = strtotime(CoreUtilities::$rRequest['to'] . ' 23:59:59')) {
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}

				if (in_array(CoreUtilities::$rRequest['type'], array('lines_logs', 'streams_errors', 'lines_activity', 'users_credits_logs', 'users_logs'))) {
					if (CoreUtilities::$rRequest['type'] == 'lines_activity') {
						$rColumn = 'date_start';
					} else {
						$rColumn = 'date';
					}

					if ($rStartTime && $rEndTime) {
						$db->query('DELETE FROM ' . preparecolumn(CoreUtilities::$rRequest['type']) . ' WHERE `' . $rColumn . '` >= ? AND `' . $rColumn . '` <= ?;', $rStartTime, $rEndTime);
					} else {
						if ($rStartTime) {
							$db->query('DELETE FROM ' . preparecolumn(CoreUtilities::$rRequest['type']) . ' WHERE `' . $rColumn . '` >= ?;', $rStartTime);
						} else {
							if ($rEndTime) {
								$db->query('DELETE FROM ' . preparecolumn(CoreUtilities::$rRequest['type']) . ' WHERE `' . $rColumn . '` <= ?;', $rEndTime);
							} else {
								$db->query('TRUNCATE ' . preparecolumn(CoreUtilities::$rRequest['type']) . ';');
							}
						}
					}
				} else {
					if (CoreUtilities::$rRequest['type'] != 'watch_logs') {
					} else {
						if ($rStartTime && $rEndTime) {
							$db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ? AND UNIX_TIMESTAMP(`dateadded`) <= ?;', $rStartTime, $rEndTime);
						} else {
							if ($rStartTime) {
								$db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ?;', $rStartTime);
							} else {
								if ($rEndTime) {
									$db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) <= ?;', $rEndTime);
								} else {
									$db->query('TRUNCATE `watch_logs`;');
								}
							}
						}
					}
				}

				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'backup') {
			if (hasPermissions('adv', 'database')) {
				$rSub = CoreUtilities::$rRequest['sub'];

				if ($rSub == 'delete') {
					$rBackup = pathinfo(CoreUtilities::$rRequest['filename'])['filename'];

					if (!file_exists(MAIN_HOME . 'backups/' . $rBackup . '.sql')) {
					} else {
						unlink(MAIN_HOME . 'backups/' . $rBackup . '.sql');
					}

					if (0 >= strlen($rSettings['dropbox_token'])) {
					} else {
						deleteRemoteBackup('/' . $rBackup . '.sql');
					}

					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'restore') {
					$rBackup = pathinfo(CoreUtilities::$rRequest['filename'])['filename'];
					$rFilename = MAIN_HOME . 'backups/' . $rBackup . '.sql';

					if (file_exists($rFilename)) {
					} else {
						$rFilename = MAIN_HOME . 'tmp/restore.sql';

						if (0 < strlen($rSettings['dropbox_token'])) {
							if (downloadRemoteBackup('/' . $rBackup . '.sql', $rFilename)) {
							} else {
								echo json_encode(array('result' => false));

								exit();
							}
						} else {
							echo json_encode(array('result' => false));

							exit();
						}
					}

					CoreUtilities::restoreBackup($rFilename);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub != 'backup') {
					echo json_encode(array('result' => false));

					exit();
				}

				$rCommand = PHP_BIN . ' ' . CRON_PATH . 'backups.php 1 > /dev/null 2>/dev/null &';
				$rRet = shell_exec($rCommand);
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'send_event') {
			if (hasPermissions('adv', 'manage_events')) {
				$rData = json_decode(CoreUtilities::$rRequest['data'], true);

				if (!is_numeric($rData['id'])) {
					$rIDs = json_decode($rData['id'], true);
				} else {
					$rIDs = array(intval($rData['id']));
				}

				foreach ($rIDs as $rID) {
					if ($rData['type'] == 'send_msg') {
						$rData['need_confirm'] = 1;
					} else {
						if ($rData['type'] == 'play_channel') {
							$rData['need_confirm'] = 0;
							$rData['reboot_portal'] = 0;
							$rData['message'] = intval($rData['channel']);
						} else {
							if ($rData['type'] == 'reset_stb_lock') {
								resetSTB($rData['id']);
							} else {
								$rData['need_confirm'] = 0;
								$rData['reboot_portal'] = 0;
								$rData['message'] = '';
							}
						}
					}

					$db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rID, $rData['type'], $rData['need_confirm'], $rData['message'], $rData['reboot_portal'], time());
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'ip_whois') {
			$rIP = CoreUtilities::$rRequest['ip'];
			$rReader = new MaxMind\Db\Reader(GEOLITE2C_BIN);
			$rResponse = $rReader->get($rIP);

			if (!isset($rResponse['location']['time_zone'])) {
			} else {
				$rDate = new DateTime('now', new DateTimeZone($rResponse['location']['time_zone']));
				$rResponse['location']['time'] = $rDate->format('Y-m-d H:i:s');
			}

			$rReader->close();

			if (!isset(CoreUtilities::$rRequest['isp'])) {
			} else {
				$rReader = new MaxMind\Db\Reader(GEOISP_BIN);
				$rResponse['isp'] = $rReader->get($rIP);
				$rReader->close();
			}

			$rResponse['type'] = null;

			if (!$rResponse['isp']['autonomous_system_number']) {
			} else {
				$rASN = $rResponse['isp']['autonomous_system_number'];
				$db->query('SELECT `type` FROM `blocked_asns` WHERE `asn` = ?;', $rASN);

				if (0 >= $db->num_rows()) {
				} else {
					$rResponse['type'] = $db->get_row()['type'];
				}

				if (!file_exists(CIDR_TMP_PATH . $rASN)) {
				} else {
					$rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);

					foreach ($rCIDRs as $rCIDR => $rData) {
						if (!(ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2]))) {
						} else {
							$rTypes = array();

							if (!$rData[3]) {
							} else {
								$rTypes[] = 'HOSTING';
							}

							if (!$rData[4]) {
							} else {
								$rTypes[] = 'PROXY';
							}

							$rResponse['type'] = implode(', ', $rTypes);

							break;
						}
					}
				}
			}

			echo json_encode(array('result' => true, 'data' => $rResponse));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'asn') {
			if (hasPermissions('adv', 'block_isps')) {
				$rSub = CoreUtilities::$rRequest['sub'];
				$rASN = CoreUtilities::$rRequest['id'];

				if ($rSub == 'allow') {
					$db->query('UPDATE `blocked_asns` SET `blocked` = 0 WHERE `id` = ?;', $rASN);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'block') {
					$db->query('UPDATE `blocked_asns` SET `blocked` = 1 WHERE `id` = ?;', $rASN);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub == 'allow_all') {
					$db->query('UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = ?;', $rASN);
					echo json_encode(array('result' => true));

					exit();
				}

				if ($rSub != 'block_all') {
				} else {
					$db->query('UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = ?;', $rASN);
					echo json_encode(array('result' => true));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'server_view') {
			if (hasPermissions('adv', 'add_server') || hasPermissions('adv', 'edit_server')) {
				if (isset($rServers[CoreUtilities::$rRequest['server_id']])) {
					$rServer = $rServers[CoreUtilities::$rRequest['server_id']];
				} else {
					if (isset($rProxyServers[CoreUtilities::$rRequest['server_id']])) {
						$rServer = $rProxyServers[CoreUtilities::$rRequest['server_id']];
					} else {
						echo json_encode(array('result' => false));

						exit();
					}
				}

				$rStats = array('open_connections' => 0, 'total_running_streams' => 0, 'online_users' => 0, 'offline_streams' => 0, 'gpu_info' => json_decode($rServer['gpu_info'], true), 'watchdog' => json_decode($rServer['watchdog_data'], true));
				$rStats['open_connections'] = ($rServer['connections'] ?: 0);
				$rStats['online_users'] = ($rServer['users'] ?: 0);
				$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServer['id']);
				$rStats['total_running_streams'] = $db->get_row()['count'];
				$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND ((`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServer['id']);
				$rStats['offline_streams'] = $db->get_row()['count'];
				echo json_encode(array('result' => true, 'data' => $rStats, 'netspeed' => (intval($rServer['network_guaranteed_speed']) ?: 1000)));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'server_stats') {
			if (hasPermissions('adv', 'add_server') || hasPermissions('adv', 'edit_server')) {
				$rID = intval(CoreUtilities::$rRequest['id']);

				if (isset($rServers[$rID])) {
					$rWatchdog = getWatchdog($rID);
					$rReturn = array();

					foreach ($rWatchdog as $rData) {
						$rReturn[] = array('cpu' => $rData['cpu'], 'memory' => $rData['total_mem_used_percent'], 'input' => $rData['bytes_received'], 'output' => $rData['bytes_sent'], 'date' => $rData['time']);
					}
					echo json_encode(array('result' => true, 'data' => $rReturn));

					exit();
				} else {
					echo json_encode(array('result' => false));

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'rtmp_kill') {
			if (hasPermissions('adv', 'rtmp')) {
				echo systemapirequest(intval(CoreUtilities::$rRequest['server']), array('action' => 'rtmp_kill', 'name' => CoreUtilities::$rRequest['name']));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'install_status') {
			if (hasPermissions('adv', 'add_server') || hasPermissions('adv', 'edit_server')) {
				CoreUtilities::$rServers = CoreUtilities::getServers(true);
				$rServerID = intval(CoreUtilities::$rRequest['server_id']);
				$rFilename = BIN_PATH . 'install/' . $rServerID . '.install';

				if (file_exists($rFilename)) {
					echo json_encode(array('result' => true, 'data' => trim(file_get_contents($rFilename)), 'status' => intval(CoreUtilities::$rServers[$rServerID]['status'])));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'reinstall_server') {
			if (hasPermissions('adv', 'add_server') || hasPermissions('adv', 'edit_server')) {
				$rServerID = intval(CoreUtilities::$rRequest['server_id']);

				if ($rServers[$rServerID]['server_type'] == 0) {
					$rType = 2;
				} else {
					$rType = 1;
				}

				$rFilename = BIN_PATH . 'install/' . $rServerID . '.json';

				if (file_exists($rFilename)) {
					$rParams = json_decode(file_get_contents($rFilename), true);
					$db->query('UPDATE `servers` SET `status` = 3 WHERE `id` = ?;', $rServerID);

					if (isset($rParams['http_broadcast_port'])) {
						$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . $rType . ' ' . intval($rServerID) . ' ' . intval($rParams['ssh_port']) . ' ' . escapeshellarg($rParams['root_username']) . ' ' . escapeshellarg($rParams['root_password']) . ' ' . intval($rParams['http_broadcast_port']) . ' ' . intval($rParams['https_broadcast_port']) . ' > "' . BIN_PATH . 'install/' . intval($rServerID) . '.install" 2>/dev/null &';
					} else {
						$rCommand = PHP_BIN . ' ' . CLI_PATH . 'balancer.php ' . $rType . ' ' . intval($rServerID) . ' ' . intval($rParams['ssh_port']) . ' ' . escapeshellarg($rParams['root_username']) . ' ' . escapeshellarg($rParams['root_password']) . ' > "' . BIN_PATH . 'install/' . intval($rServerID) . '.install" 2>/dev/null &';
					}

					shell_exec($rCommand);
					echo json_encode(array('result' => true));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'fpm_status') {
			if (hasPermissions('adv', 'add_server') || hasPermissions('adv', 'edit_server')) {
				$rData = str_replace("\n", '<br/>', getFPMStatus(CoreUtilities::$rRequest['server_id']));

				if (empty($rData)) {
					$rData = '<strong>No response from status page.</strong>';
				} else {
					$rInstances = intval($rServers[CoreUtilities::$rRequest['server_id']]['total_services']);

					if (!$rInstances) {
					} else {
						$rData .= '<br/><br/><strong>Results from 1 of ' . $rInstances . ' PHP-FPM instances</strong>';
					}
				}

				echo json_encode(array('result' => true, 'data' => $rData));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'update_all_servers') {
			if (hasPermissions('adv', 'servers')) {
				foreach ($rServers as $rServer) {
					if ($rServer['server_online']) {
						$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update')));
					}
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'update_all_binaries') {
			if (hasPermissions('adv', 'servers')) {
				foreach ($rServers as $rServer) {
					if (!$rServer['server_online']) {
					} else {
						$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);', $rServer['id'], time(), json_encode(array('action' => 'update_binaries')));
					}
				}
				echo json_encode(array('result' => true));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'disable_watch') {
			if (hasPermissions('adv', 'folder_watch_settings')) {
				$db->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` <> 'plex';");
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'enable_watch') {
			if (hasPermissions('adv', 'folder_watch_settings')) {
				$db->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` <> 'plex';");
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'disable_plex') {
			if (hasPermissions('adv', 'folder_watch_settings')) {
				$db->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` = 'plex';");
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'enable_plex') {
			if (hasPermissions('adv', 'folder_watch_settings')) {
				$db->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` = 'plex';");
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'plex_sections') {
			if (hasPermissions('adv', 'folder_watch_settings')) {
				$rData = getPlexLogin(CoreUtilities::$rRequest['username'], CoreUtilities::$rRequest['password']);

				if (!isset($rData['user']['authToken'])) {
				} else {
					$rToken = $rData['user']['authToken'];
					$rSections = getPlexSections(CoreUtilities::$rRequest['ip'], CoreUtilities::$rRequest['port'], $rToken);

					if (!($rSections && 0 < count($rSections))) {
					} else {
						echo json_encode(array('result' => true, 'data' => $rSections));

						exit();
					}
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'enable_handler') {
			if (hasPermissions('adv', 'backups')) {
				$db->query('UPDATE `settings` SET `redis_handler` = 1;');

				if (!file_exists(CACHE_TMP_PATH . 'settings')) {
				} else {
					unlink(CACHE_TMP_PATH . 'settings');
				}

				exec('pgrep -u xc_vm redis-server', $rRedis);

				if (!(0 < count($rRedis) && is_numeric($rRedis[0]))) {
				} else {
					$rPID = intval($rRedis[0]);
					shell_exec('kill -9 ' . $rPID);
				}

				shell_exec(MAIN_HOME . 'bin/redis/redis-server ' . MAIN_HOME . '/bin/redis/redis.conf > /dev/null 2>/dev/null &');
				sleep(1);
				exec("pgrep -U xc_vm | xargs ps | grep signals | awk '{print \$1}'", $rPID);

				if (!(0 < count($rPID) && is_numeric($rPID[0]))) {
				} else {
					$rPID = intval($rPID[0]);
					shell_exec('kill -9 ' . $rPID);
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'signals.php > /dev/null 2>/dev/null &');
				}

				exec("pgrep -U xc_vm | xargs ps | grep watchdog | awk '{print \$1}'", $rPID);

				if (!(0 < count($rPID) && is_numeric($rPID[0]))) {
				} else {
					$rPID = intval($rPID[0]);
					shell_exec('kill -9 ' . $rPID);
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'watchdog.php > /dev/null 2>/dev/null &');
				}

				shell_exec(PHP_BIN . ' ' . CRON_PATH . 'users.php 1 > /dev/null 2>/dev/null &');
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'disable_handler') {
			if (hasPermissions('adv', 'backups')) {
				$db->query('UPDATE `settings` SET `redis_handler` = 0;');

				if (!file_exists(CACHE_TMP_PATH . 'settings')) {
				} else {
					unlink(CACHE_TMP_PATH . 'settings');
				}

				exec('pgrep -u xc_vm redis-server', $rRedis);

				if (!(0 < count($rRedis) && is_numeric($rRedis[0]))) {
				} else {
					$rPID = intval($rRedis[0]);
					shell_exec('kill -9 ' . $rPID);
				}

				exec("pgrep -U xc_vm | xargs ps | grep signals | awk '{print \$1}'", $rPID);

				if (!(0 < count($rPID) && is_numeric($rPID[0]))) {
				} else {
					$rPID = intval($rPID[0]);
					shell_exec('kill -9 ' . $rPID);
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'signals.php > /dev/null 2>/dev/null &');
				}

				exec("pgrep -U xc_vm | xargs ps | grep watchdog | awk '{print \$1}'", $rPID);

				if (!(0 < count($rPID) && is_numeric($rPID[0]))) {
				} else {
					$rPID = intval($rPID[0]);
					shell_exec('kill -9 ' . $rPID);
					shell_exec(PHP_BIN . ' ' . CLI_PATH . 'watchdog.php > /dev/null 2>/dev/null &');
				}

				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'clear_redis') {
			if (hasPermissions('adv', 'backups')) {
				CoreUtilities::$redis->flushAll();
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'report') {
			if (hasPermissions('adv', 'backups')) {
				$rURL = pathinfo('http://127.0.0.1:' . $rServers[SERVER_ID]['http_broadcast_port'] . $_SERVER['REQUEST_URI'])['dirname'] . '/table';
				set_time_limit(60);
				ini_set('memory_limit', '-1');
				$rParams = json_decode(CoreUtilities::$rRequest['params'], true);

				foreach (array() as $rKey) {
					unset($rParams[$rKey]);
				}
				$rParams['api_user_id'] = $rUserInfo['id'];
				$rParams['report'] = true;
				$rParams['start'] = 0;
				$rParams['length'] = 100000;
				$rData = json_decode(generateReport($rURL, $rParams), true);
				header('Content-Type: text/csv; charset=utf-8');
				header('Content-Disposition: attachment; filename=report_' . preg_replace('/[^A-Za-z0-9 ]/', '', $rParams['id']) . '_' . date('YmdHis') . '.csv');

				if (0 >= count(($rData['data'] ?: array()))) {
				} else {
					echo file_get_contents(convertToCSV($rData['data']));
				}

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'decrypt_text') {
			if (hasPermissions('adv', 'stream_tools')) {
				$rDecryptedArray = array();
				$rText = (CoreUtilities::$rRequest['text'] ?: null);

				if (!$rText) {
				} else {
					$rLines = explode("\n", $rText);

					foreach ($rLines as $rLine) {
						$rSplit = explode('/', $rLine);

						foreach ($rSplit as $rPiece) {
							if (stripos($rPiece, 'token=') === false) {
							} else {
								list(, $rPiece) = explode('token=', $rPiece);
							}

							$rDecoded = base64_decode(strtr($rPiece, '-_', '+/'));

							if (!empty($rDecoded)) {
								try {
									$rDecrypted = CoreUtilities::decryptData($rPiece, CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);
								} catch (Exception $e) {
									$rDecrypted = null;
								}

								if ($rDecrypted) {
									$rDecryptedArray[] = utf8_decode($rDecrypted);
								}
							}
						}
					}
				}

				if (0 < count($rDecryptedArray)) {
					echo json_encode(array('result' => true, 'data' => $rDecryptedArray));

					exit();
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'get_episode_ids') {
			if (hasPermissions('adv', 'add_episode')) {
				$rReturn = array();
				$rData = json_decode(CoreUtilities::$rRequest['data'], true);

				if (!is_array($rData)) {
					echo json_encode(array('result' => false));

					exit();
				}

				$rInput = array();

				if (CoreUtilities::$rSettings['parse_type'] == 'guessit') {
					foreach ($rData as $rEpisodeID => $rName) {
						$rInput[$rEpisodeID] = pathinfo($rName)['filename'];
					}
					$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg(json_encode($rInput));
				} else {
					foreach ($rData as $rEpisodeID => $rName) {
						$rInput[$rEpisodeID] = pathinfo(str_replace('-', '_', $rName))['filename'];
					}
					$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'includes/python/release.py ' . escapeshellarg(json_encode($rInput));
				}

				$rEpisodes = json_decode(shell_exec($rCommand), true);

				foreach ($rEpisodes as $rEpisodeID => $rEpisode) {
					if (!isset($rEpisode['episode'])) {
					} else {
						if (is_array($rEpisode['episode'])) {
							$rReturn[] = array($rEpisodeID, intval($rEpisode['episode'][0]));
						} else {
							$rReturn[] = array($rEpisodeID, intval($rEpisode['episode']));
						}
					}
				}
				echo json_encode(array('result' => true, 'data' => $rReturn));

				exit();
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'send_panel_logs') {
			CoreUtilities::submitPanelLogs();
			echo json_encode(array('result' => true));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'get_epg') {
			if (hasPermissions('adv', 'manage_streams')) {
				$rTimezone = (CoreUtilities::$rRequest['timezone'] ?: 'Europe/London');
				date_default_timezone_set($rTimezone);
				$rReturn = array('Channels' => array());
				$rChannels = array_map('intval', explode(',', CoreUtilities::$rRequest['channels']));

				if (count($rChannels) != 0) {
					$rHours = (intval(CoreUtilities::$rRequest['hours']) ?: 3);
					$rStartDate = (intval(strtotime(CoreUtilities::$rRequest['startdate'])) ?: time());
					$rFinishDate = $rStartDate + $rHours * 3600;
					$rPerUnit = floatval(100 / ($rHours * 60));
					$rChannelsSort = $rChannels;
					sort($rChannelsSort);
					$rListings = array();

					if (0 >= count($rChannels)) {
					} else {
						$rArchiveInfo = array();
						$db->query('SELECT `id`, `tv_archive_server_id`, `tv_archive_duration` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');

						if (0 >= $db->num_rows()) {
						} else {
							foreach ($db->get_rows() as $rRow) {
								$rArchiveInfo[$rRow['id']] = $rRow;
							}
						}

						$rEPG = CoreUtilities::getEPGs($rChannels, $rStartDate, $rFinishDate);

						foreach ($rEPG as $rChannelID => $rEPGData) {
							$rFullSize = 0;

							foreach ($rEPGData as $rEPGItem) {
								$rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
								$rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
								$rDuration = ($rCapEnd - $rCapStart) / 60;
								$rArchive = null;

								if (!isset($rArchiveInfo[$rChannelID])) {
								} else {
									if (!(0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id'] && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration'])) {
									} else {
										if (time() - $rArchiveInfo[$rChannelID]['tv_archive_duration'] * 86400 > $rEPGItem['start']) {
										} else {
											$rArchive = array($rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60));
										}
									}
								}

								$rRelativeSize = round($rDuration * $rPerUnit, 2);
								$rFullSize += $rRelativeSize;

								if (100 >= $rFullSize) {
								} else {
									$rRelativeSize -= $rFullSize - 100;
								}

								$rListings[$rChannelID][] = array('ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:iA', $rCapStart), 'EndTime' => date('h:iA', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive);
							}
						}
					}

					$rDefaultEPG = array('ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'Not Available', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null);
					$db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

					foreach ($db->get_rows() as $rStream) {
						if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
							$rArchive = $rStream['tv_archive_duration'];
						} else {
							$rArchive = 0;
						}

						$rDefaultArray = $rDefaultEPG;
						$rDefaultArray['ChannelId'] = $rStream['id'];
						$rCategoryIDs = json_decode($rStream['category_id'], true);
						$rCategories = getCategories('live');

						if (0 < strlen(CoreUtilities::$rRequest['category'])) {
							$rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
						} else {
							$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
						}

						if (1 >= count($rCategoryIDs)) {
						} else {
							$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
						}

						$rReturn['Channels'][] = array('Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (CoreUtilities::validateImage($rStream['stream_icon']) ?: ''), 'TvListings' => ($rListings[$rStream['id']] ?: array($rDefaultArray)));
					}
					echo json_encode($rReturn);

					exit();
				} else {
					echo json_encode($rReturn);

					exit();
				}
			} else {
				echo json_encode(array('result' => false));

				exit();
			}
		}
		if (CoreUtilities::$rRequest['action'] == 'get_programme') {
			if (hasPermissions('adv', 'manage_streams')) {
				$rTimezone = (CoreUtilities::$rRequest['timezone'] ?: 'Europe/London');
				date_default_timezone_set($rTimezone);

				if (!isset(CoreUtilities::$rRequest['id'])) {
				} else {
					$rRow = CoreUtilities::getProgramme(CoreUtilities::$rRequest['stream_id'], CoreUtilities::$rRequest['id']);

					if (!$rRow) {
					} else {
						$rArchive = $rAvailable = false;

						if (time() >= $rRow['end']) {
						} else {
							$db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', CoreUtilities::$rRequest['stream_id']);

							if (0 >= $db->num_rows()) {
							} else {
								foreach ($db->get_rows() as $rStreamRow) {
									if (!$rStreamRow['server_id'] || $rStreamRow['direct_source']) {
									} else {
										$rAvailable = true;

										break;
									}
								}
							}
						}

						$rRow['date'] = date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']);
						echo json_encode(array('result' => true, 'data' => $rRow, 'available' => $rAvailable, 'archive' => $rArchive));

						exit();
					}
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'queue') {
			if (hasPermissions('adv', 'streams') || hasPermissions('adv', 'series') || hasPermissions('adv', 'episodes')) {
				$rSub = CoreUtilities::$rRequest['sub'];
				$db->query('SELECT * FROM `queue` WHERE `id` = ?;', CoreUtilities::$rRequest['id']);

				if ($db->num_rows() != 1) {
				} else {
					$rRow = $db->get_row();

					if ($rSub == 'delete') {
						$db->query('DELETE FROM `queue` WHERE `id` = ?;', CoreUtilities::$rRequest['id']);
						echo json_encode(array('result' => true));

						exit();
					}

					if ($rSub != 'stop') {
					} else {
						if (0 >= $rRow['pid']) {
						} else {
							killPID($rRow['server_id'], $rRow['pid']);
						}

						$db->query('DELETE FROM `queue` WHERE `id` = ?;', CoreUtilities::$rRequest['id']);
						echo json_encode(array('result' => true));

						exit();
					}
				}

				echo json_encode(array('result' => false));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'search') {
			$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);
			$rTables = array('lines' => array('Lines', 'line?id=', '`username`, `admin_notes`, `reseller_notes`, `last_ip`, `contact`', 'id', 'username'), 'mag_devices' => array('MAG Devices', 'mag?id=', '`mac_filter`, `ip`', 'mag_id', 'mac'), 'enigma2_devices' => array('Enigma2 Devices', 'enigma?id=', '`mac_filter`, `public_ip`', 'device_id', 'mac'), 'users' => array('Users', 'user?id=', '`username`, `email`, `ip`, `notes`, `reseller_dns`', 'id', 'username'), 'streams' => array('Streams, Movies & Episodes', 'stream_view?id=', '`stream_display_name`, `stream_source`, `notes`, `channel_id`', 'id', 'stream_display_name'), 'streams_series' => array('TV Series', 'serie?id=', '`title`, `plot`, `cast`, `director`', 'id', 'title'));
			$rLimit = 100;
			$rTerm = strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', CoreUtilities::$rRequest['search']));
			$rTermSP = strtolower(preg_replace('/[^[:alnum:][:space:]]/u', ' ', CoreUtilities::$rRequest['search']));

			if (empty($rTermSP)) {
			} else {
				$rItems = array();

				foreach ($rTables as $rTable => $rTableInfo) {
					if ($rTable == 'streams') {
						$db->query('SELECT `' . $rTable . '`.*, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score1`, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score2` FROM `' . $rTable . '` WHERE MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) OR `id` = ? ORDER BY `score1` + `score2` DESC LIMIT ' . $rLimit . ';', $rTermSP, $rTermSP . '*', $rTermSP . '*', intval($rTerm));
					} else {
						$db->query('SELECT `' . $rTable . '`.*, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score1`, MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) AS `score2` FROM `' . $rTable . '` WHERE MATCH(' . $rTableInfo[2] . ') AGAINST (? IN BOOLEAN MODE) ORDER BY `score1` + `score2` DESC LIMIT ' . $rLimit . ';', $rTermSP, $rTermSP . '*', $rTermSP . '*');
					}

					foreach ($db->get_rows() as $rRow) {
						similar_text($rTerm, strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', $rRow[$rTableInfo[4]])), $rPerc);

						if (!($rTable == 'streams' && $rRow['id'] == intval($rTerm))) {
						} else {
							$rPerc = 1000;
						}

						if ($rTerm != strtolower(preg_replace('/[^[:alnum:][:space:]]/u', '', $rRow[$rTableInfo[4]]))) {
						} else {
							$rPerc = 1000;
						}

						$rRow['score'] = $rRow['score1'] + $rRow['score2'] + $rPerc;
						$rRow['table'] = $rTable;
						$rItems[] = $rRow;
					}
				}
				array_multisort(array_column($rItems, 'score'), SORT_DESC, $rItems);
				$rItems = array_slice($rItems, 0, (intval(CoreUtilities::$rSettings['search_items']) ?: 50));
				$rStreamNameIDs = $rDeviceIDs = $rLineIDs = $rOwnerIDs = $rUserIDs = $rSeriesIDs = $rStreamIDs = array();

				foreach ($rItems as $rItem) {
					if ($rItem['table'] == 'streams') {
						if (0 >= intval($rItem['id'])) {
						} else {
							$rStreamIDs[] = intval($rItem['id']);
						}
					} else {
						if ($rItem['table'] == 'streams_series') {
							if (0 >= intval($rItem['id'])) {
							} else {
								$rSeriesIDs[] = intval($rItem['id']);
							}
						} else {
							if ($rItem['table'] == 'users') {
								if (0 >= intval($rItem['id'])) {
								} else {
									$rUserIDs[] = intval($rItem['id']);
								}

								if (0 >= intval($rItem['owner_id'])) {
								} else {
									$rOwnerIDs[] = intval($rItem['owner_id']);
								}
							} else {
								if ($rItem['table'] == 'lines') {
									if (0 >= intval($rItem['id'])) {
									} else {
										$rLineIDs[] = intval($rItem['id']);
									}

									if (0 >= intval($rItem['member_id'])) {
									} else {
										$rOwnerIDs[] = intval($rItem['member_id']);
									}

									$rActivityArray = json_decode($rItem['last_activity_array'], true);

									if (!(is_array($rActivityArray) && 0 < intval($rActivityArray['stream_id']))) {
									} else {
										$rStreamNameIDs[] = intval($rActivityArray['stream_id']);
									}
								} else {
									if (!($rItem['table'] == 'mag_devices' || $rItem['table'] == 'enigma2_devices')) {
									} else {
										if (0 >= intval($rItem['user_id'])) {
										} else {
											$rDeviceIDs[] = intval($rItem['user_id']);
											$rLineIDs[] = intval($rItem['user_id']);
										}
									}
								}
							}
						}
					}
				}
				$rDeviceLines = $rStreamNames = $rLinesInfo = $rOwnerNames = $rUsersCount = $rLinesCount = $rSeriesInfo = $rSeriesTitles = $rServerItems = $rServerCount = $rConnectionCount = $rLineConnectionCount = array();
				$rDeviceIDs = confirmIDs($rDeviceIDs);

				if (0 >= count($rDeviceIDs)) {
				} else {
					$db->query('SELECT * FROM `lines` WHERE `id` IN (' . implode(',', $rDeviceIDs) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rDeviceLines[$rRow['id']] = $rRow;

						if (0 >= intval($rRow['member_id'])) {
						} else {
							$rOwnerIDs[] = $rRow['member_id'];
						}
					}
				}

				$rStreamIDs = confirmIDs($rStreamIDs);

				if (0 >= count($rStreamIDs)) {
				} else {
					$db->query('SELECT `streams_episodes`.`stream_id`, `streams_series`.`id`, `streams_series`.`title` FROM `streams_episodes` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` WHERE `streams_episodes`.`stream_id` IN (' . implode(',', $rStreamIDs) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rSeriesTitles[$rRow['stream_id']] = $rRow['title'];
					}
					$db->query('SELECT * FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rServerCount[$rRow['stream_id']]++;

						if ($rServers[$rRow['server_id']]['server_online']) {
							$rRow['priority'] = (0 < $rRow['pid'] ? 1 : 0);
						} else {
							$rRow['priority'] = 0;
						}

						$rServerItems[$rRow['stream_id']][] = $rRow;
					}

					foreach (array_keys($rServerItems) as $rStreamID) {
						array_multisort(array_column($rServerItem[$rStreamID], 'priority'), SORT_DESC, $rServerItem[$rStreamID]);
					}

					if (CoreUtilities::$rSettings['redis_handler']) {
						$rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
					} else {
						$db->query('SELECT `stream_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ') AND `hls_end` = 0;');

						foreach ($db->get_rows() as $rRow) {
							$rConnectionCount[$rRow['stream_id']] = $rRow['count'];
						}
					}
				}

				$rSeriesIDs = confirmIDs($rSeriesIDs);

				if (0 >= count($rSeriesIDs)) {
				} else {
					$db->query('SELECT `series_id`, MAX(`season_num`) AS `latest_season`, COUNT(*) AS `episodes` FROM `streams_episodes` WHERE `series_id` IN (' . implode(',', $rSeriesIDs) . ') GROUP BY `series_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rSeriesInfo[$rRow['series_id']] = array($rRow['latest_season'], $rRow['episodes']);
					}
				}

				$rUserIDs = confirmIDs($rUserIDs);

				if (0 >= count($rUserIDs)) {
				} else {
					$db->query('SELECT `owner_id`, COUNT(*) AS `count` FROM `users` WHERE `owner_id` IN (' . implode(',', $rUserIDs) . ') GROUP BY `owner_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rUsersCount[$rRow['owner_id']] = $rRow['count'];
					}
					$db->query('SELECT `member_id`, COUNT(*) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserIDs) . ') GROUP BY `member_id`;');

					foreach ($db->get_rows() as $rRow) {
						$rLinesCount[$rRow['member_id']] = $rRow['count'];
					}
				}

				$rOwnerIDs = confirmIDs($rOwnerIDs);

				if (0 >= count($rOwnerIDs)) {
				} else {
					$db->query('SELECT `id`, `username` FROM `users` WHERE `id` IN (' . implode(',', $rOwnerIDs) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rOwnerNames[$rRow['id']] = $rRow['username'];
					}
				}

				$rLineIDs = confirmIDs($rLineIDs);

				if (0 >= count($rLineIDs)) {
				} else {
					if (CoreUtilities::$rSettings['redis_handler']) {
						$rLineConnectionCount = CoreUtilities::getUserConnections($rLineIDs, true);
						$rConnectionMap = CoreUtilities::getFirstConnection($rLineIDs);
						$rLStreamIDs = array();

						foreach ($rConnectionMap as $rUserID => $rConnection) {
							if (in_array($rConnection['stream_id'], $rStreamIDs)) {
							} else {
								$rLStreamIDs[] = intval($rConnection['stream_id']);
							}
						}
						$rStreamMap = array();

						if (0 >= count($rLStreamIDs)) {
						} else {
							$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rLStreamIDs) . ');');

							foreach ($db->get_rows() as $rRow) {
								$rStreamMap[$rRow['id']] = $rRow['stream_display_name'];
							}
						}

						foreach ($rConnectionMap as $rUserID => $rConnection) {
							$rLinesInfo[$rUserID]['stream_id'] = $rConnection['stream_id'];
							$rLinesInfo[$rUserID]['last_active'] = $rConnection['date_start'];
							$rLinesInfo[$rUserID]['online'] = true;
							$rStreamNameIDs[] = intval($rConnection['stream_id']);
						}
						unset($rConnectionMap);
					} else {
						$db->query('SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`hls_end` = 0 AND `lines_live`.`user_id` IN (' . implode(',', $rLineIDs) . ');');

						foreach ($db->get_rows() as $rRow) {
							$rLinesInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
							$rLinesInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
							$rLinesInfo[$rRow['user_id']]['online'] = true;
							$rStreamNameIDs[] = intval($rRow['stream_id']);
						}
						$db->query('SELECT `user_id`, COUNT(*) AS `count` FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rLineIDs)) . ') AND `hls_end` = 0;');

						foreach ($db->get_rows() as $rRow) {
							$rLineConnectionCount[$rRow['user_id']] = $rRow['count'];
						}
					}
				}

				$rStreamNameIDs = confirmIDs($rStreamNameIDs);

				if (0 >= count($rStreamNameIDs)) {
				} else {
					$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_unique($rStreamNameIDs)) . ');');

					foreach ($db->get_rows() as $rRow) {
						$rStreamNames[$rRow['id']] = $rRow['stream_display_name'];
					}
				}

				$rCategories = getCategories(null);
				$rGroups = getMemberGroups();

				foreach ($rItems as $rItem) {
					$rTableInfo = $rTables[$rItem['table']];

					switch ($rItem['table']) {
						case 'streams':
							$rServerItem = ($rServerItems[$rItem['id']][0] ?: null);
							$rCategoryIDs = json_decode($rItem['category_id'], true);
							$rProperties = json_decode($rItem['movie_properties'], true);

							if ($rItem['type'] != 5) {
								if (hasPermissions('adv', 'manage_streams')) {
									$rTitle = "<span style='cursor: pointer;' onClick=\"navigate('stream_view?id=" . intval($rItem['id']) . "');\">" . $rItem['stream_display_name'] . '</span>';
								} else {
									$rTitle = $rItem['stream_display_name'];
								}

								$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');

								if (1 >= count($rCategoryIDs)) {
								} else {
									$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
								}
							} else {
								if (hasPermissions('adv', 'manage_streams')) {
									$rTitle = ($rSeriesTitles[$rItem['id']] ? "<span style='cursor: pointer;' onClick=\"navigate('stream_view?id=" . intval($rItem['id']) . "');\">" . $rSeriesTitles[$rItem['id']] . '</span>' : 'No Series');
								} else {
									$rTitle = ($rSeriesTitles[$rItem['id']] ?: 'No Series');
								}

								if (stripos($rItem['stream_display_name'], $rTitle) !== 0) {
								} else {
									$rCategory = ltrim(substr($rItem['stream_display_name'], strlen($rTitle), strlen($rItem['stream_display_name']) - strlen($rTitle)));

									if (substr($rCategory, 0, 1) != '-') {
									} else {
										$rCategory = trim(ltrim($rCategory, '-'));
									}
								}
							}

							if ($rItem['type'] != 2) {
							} else {
								$rRatingText = '';

								if (!$rProperties['rating']) {
								} else {
									$rStarRating = round($rProperties['rating']) / 2;
									$rFullStars = floor($rStarRating);
									$rHalfStar = 0 < $rStarRating - $rFullStars;
									$rEmpty = 5 - ($rFullStars + (($rHalfStar ? 1 : 0)));

									if (0 >= $rFullStars) {
									} else {
										foreach (range(1, $rFullStars) as $i) {
											$rRatingText .= "<i class='mdi mdi-star'></i>";
										}
									}

									if (!$rHalfStar) {
									} else {
										$rRatingText .= "<i class='mdi mdi-star-half'></i>";
									}

									if (0 >= $rEmpty) {
									} else {
										foreach (range(1, $rEmpty) as $i) {
											$rRatingText .= "<i class='mdi mdi-star-outline'></i>";
										}
									}
								}

								$rYear = ($rItem['year'] ? '<strong>' . $rItem['year'] . '</strong> &nbsp;' : '');
								$rTitle .= "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . '</span></a>';
							}

							$rItem['server_id'] = ($rServerItem['server_id'] ?: null);

							if ($rItem['server_id']) {
								$rServerName = $rServers[$rItem['server_id']]['server_name'];

								if (1 >= $rServerCount[$rItem['id']]) {
								} else {
									$rServerName .= ' (+' . ($rServerCount[$rItem['id']] - 1) . ')';
								}
							} else {
								$rServerName = '';
							}

							if ($rServerItem) {
								$rUptime = time() - intval($rServerItem['stream_started']);

								if ($rItem['type'] == 1 || $rItem['type'] == 4) {
									if (intval($rItem['direct_source']) == 1) {
										$rActualStatus = 5;
									} else {
										if ($rServerItem['monitor_pid']) {
											if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
												if (intval($rServerItem['stream_status']) == 2) {
													$rActualStatus = 2;
												} else {
													$rActualStatus = 1;
												}
											} else {
												if ($rServerItem['stream_status'] == 0) {
													$rActualStatus = 2;
												} else {
													$rActualStatus = 3;
												}
											}
										} else {
											if (intval($rServerItem['on_demand']) == 1) {
												$rActualStatus = 4;
											} else {
												$rActualStatus = 0;
											}
										}
									}
								} else {
									if ($rItem['type'] == 2 || $rItem['type'] == 5) {
										if (intval($rItem['direct_source']) == 1) {
											$rActualStatus = 5;
										} else {
											if (!is_null($rServerItem['pid']) && 0 < $rServerItem['pid']) {
												if ($rServerItem['to_analyze'] == 1) {
													$rActualStatus = 7;
												} else {
													if ($rServerItem['stream_status'] == 1) {
														$rActualStatus = 10;
													} else {
														$rActualStatus = 9;
													}
												}
											} else {
												$rActualStatus = 8;
											}
										}
									} else {
										if ($rItem['type'] != 3) {
										} else {
											if ($rServerItem['monitor_pid']) {
												if ($rServerItem['pid'] && 0 < $rServerItem['pid']) {
													if (intval($rServerItem['stream_status']) == 2) {
														$rActualStatus = 2;
													} else {
														$rActualStatus = 1;
													}
												} else {
													if ($rServerItem['stream_status'] == 0) {
														$rActualStatus = 2;
													} else {
														$rActualStatus = 3;
													}
												}
											} else {
												$rActualStatus = 0;
											}

											if (count(json_decode($rServerItem['cchannel_rsources'], true)) == count(json_decode($rItem['stream_source'], true)) || $rServerItem['parent_id']) {
											} else {
												$rActualStatus = 6;
											}
										}
									}
								}
							} else {
								if (intval($rItem['direct_source']) == 1) {
									$rActualStatus = 5;
								} else {
									$rActualStatus = -1;
								}
							}

							if ($rActualStatus == 1) {
								if (86400 <= $rUptime) {
									$rUptime = sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
								} else {
									$rUptime = sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
								}

								$rUptime = "<button type='button' class='btn bg-animate-info btn-xs waves-effect waves-light no-border btn-fixed-xl'>" . $rUptime . '</button>';
							} else {
								if ($rActualStatus == 6) {
									$rSources = json_decode($rItem['stream_source'], true);
									$rLeft = count(array_diff($rSources, json_decode($rServerItem['cchannel_rsources'], true)));
									$rPercent = intval((count($rSources) - $rLeft) / count($rSources) * 100);
									$rUptime = "<button type='button' class='btn bg-animate-primary btn-xs waves-effect waves-light no-border btn-fixed-xl'>" . $rPercent . '% DONE</button>';
								} else {
									$rUptime = $rSearchStatusArray[$rActualStatus];
								}
							}

							if ($rItem['type'] == 1) {
								$rPageText = $rPage = 'stream';
							} else {
								if ($rItem['type'] == 2) {
									$rPageText = $rPage = 'movie';
								} else {
									if ($rItem['type'] == 3) {
										$rPageText = 'channel';
										$rPage = 'created_channel';
									} else {
										if ($rItem['type'] == 4) {
											$rPageText = $rPage = 'radio';
										} else {
											if ($rItem['type'] != 5) {
											} else {
												$rPageText = $rPage = 'episode';
											}
										}
									}
								}
							}

							$rHasButtons = false;
							$rButtons = '<div class="btn-group bg-animate-info">';

							if (in_array($rItem['type'], array(1, 3, 4))) {
								if (!hasPermissions('adv', 'edit_stream')) {
								} else {
									$rHasButtons = true;
									$rButtons .= "<button title=\"Edit\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"navigate('" . $rPage . '?id=' . intval($rItem['id']) . "');\"><i class=\"mdi mdi-pencil\"></i></button>";

									if (intval($rActualStatus) == 1 || intval($rActualStatus) == 2 || intval($rActualStatus) == 3 || $rItem['on_demand'] == 1 || $rActualStatus == 5 || $rActualStatus == 7) {
										$rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
										$rStatus = '';
									} else {
										$rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
										$rStatus = ' disabled';
									}

									$rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'restart');\"" . $rStatus . '><i class="mdi mdi-refresh"></i></button>';
									$rButtons .= "<button title=\"Purge\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('stream', " . intval($rItem['id']) . ", 'purge');\"" . $rStatus . '><i class="mdi mdi-hammer"></i></button>';

									if ($rItem['type'] != 1) {
									} else {
										if (($rConnectionCount[$rItem['id']] ?: false)) {
											$rButtons .= '<button title="Fingerprint" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rItem['id'] . ", 'stream');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
										} else {
											$rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
										}
									}
								}
							} else {
								if (!hasPermissions('adv', 'edit_' . $rPage)) {
								} else {
									$rHasButtons = true;
									$rButtons .= "<button title=\"Edit\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"navigate('" . $rPage . '?id=' . intval($rItem['id']) . "');\"><i class=\"mdi mdi-pencil\"></i></button>";

									if (intval($rActualStatus) == 9) {
										$rButtons .= "<button title=\"Re-Encode\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
									} else {
										if (intval($rActualStatus) == 5) {
											$rButtons .= '<button disabled type="button" class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-stop"></i></button>';
										} else {
											if (intval($rActualStatus) == 7) {
												$rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
											} else {
												$rButtons .= "<button title=\"Start Encoding\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
											}
										}
									}

									$rButtons .= "<button title=\"Purge\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onclick=\"searchAPI('" . $rPage . "', " . intval($rItem['id']) . ", 'purge');\"" . $rStatus . '><i class="mdi mdi-hammer"></i></button>';
								}
							}

							$rButtons .= '</div>';

							if (in_array($rItem['type'], array(1, 3, 4))) {
								$rIcon = urlencode($rItem['stream_icon']);
								$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<img src="resize?maxw=96&maxh=96&url=' . $rIcon . '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-success btn-xs waves-effect waves-light no-border">' . strtoupper($rPageText) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<i class=\"fe-zap text-white\"></i> &nbsp; <button onClick=\"navigate('live_connections?stream_id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn bg-animate-info btn-xs waves-effect waves-light no-border\">" . number_format($rConnectionCount[$rItem['id']], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-clock text-white"></i> &nbsp; ' . $rUptime . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';

								if (!$rHasButtons) {
								} else {
									$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';
								}

								$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>';
							} else {
								$rIcon = urlencode($rProperties['movie_image']);
								$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t\t" . '<div class="search-fade">' . "\n\t\t\t\t\t\t\t\t\t" . "<div class=\"search-image\" style=\"background: url('resize?maxw=512&maxh=512&url=" . $rIcon . "');\"></div>" . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-primary btn-xs waves-effect waves-light no-border">' . strtoupper($rPage) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<i class=\"fe-zap text-white\"></i> &nbsp; <button onClick=\"navigate('live_connections?stream_id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn bg-animate-info btn-xs waves-effect waves-light no-border\">" . number_format($rConnectionCount[$rItem['id']], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-clock text-white"></i> &nbsp; ' . $rUptime . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';

								if (!$rHasButtons) {
								} else {
									$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>';
								}

								$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>';
							}

							break;

						case 'streams_series':
							$rSeriesItem = ($rSeriesInfo[$rItem['id']] ?: array());
							$rCategoryIDs = json_decode($rItem['category_id'], true);
							$rTitle = $rItem['title'];
							$rRatingText = '';

							if (!$rItem['rating']) {
							} else {
								$rStarRating = round($rItem['rating']) / 2;
								$rFullStars = floor($rStarRating);
								$rHalfStar = 0 < $rStarRating - $rFullStars;
								$rEmpty = 5 - ($rFullStars + (($rHalfStar ? 1 : 0)));

								if (0 >= $rFullStars) {
								} else {
									foreach (range(1, $rFullStars) as $i) {
										$rRatingText .= "<i class='mdi mdi-star'></i>";
									}
								}

								if (!$rHalfStar) {
								} else {
									$rRatingText .= "<i class='mdi mdi-star-half'></i>";
								}

								if (0 >= $rEmpty) {
								} else {
									foreach (range(1, $rEmpty) as $i) {
										$rRatingText .= "<i class='mdi mdi-star-outline'></i>";
									}
								}
							}

							$rYear = ($rItem['year'] ? '<strong>' . $rItem['year'] . '</strong> &nbsp;' : '');
							$rTitle .= "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . '</span></a>';
							$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');

							if (1 >= count($rCategoryIDs)) {
							} else {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ')';
							}

							$rHasButtons = false;
							$rButtons = '<div class="btn-group bg-animate-info">';

							if (!hasPermissions('adv', 'add_episode')) {
							} else {
								$rHasButtons = true;
								$rButtons .= "<button title=\"Add Episode(s)\" onClick=\"navigate('episode?sid=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-plus-circle-outline\"></i></button>";
							}

							if (!hasPermissions('adv', 'episodes')) {
							} else {
								$rHasButtons = true;
								$rButtons .= "<button title=\"View Episodes\" onClick=\"navigate('episodes?series=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-eye\"></i></button>";
							}

							if (!hasPermissions('adv', 'edit_series')) {
							} else {
								$rHasButtons = true;
								$rButtons .= "<button title=\"Edit\" onClick=\"navigate('serie?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
							}

							$rButtons .= '</div>';
							$rIcon = urlencode($rItem['cover']);
							$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="search-fade">' . "\n\t\t\t\t\t\t\t\t" . "<div class=\"search-image\" style=\"background: url('resize?maxw=512&maxh=512&url=" . $rIcon . "');\"></div>" . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rTitle . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-white mb-1 text-truncate"><small>' . $rCategory . '<br/>' . $rServerName . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-danger btn-xs waves-effect waves-light no-border">TV SERIES</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . 'S &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rSeriesItem[0], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . 'E &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rSeriesItem[1], 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

							if (!$rHasButtons) {
							} else {
								$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
							}

							$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

							break;

						case 'users':
							$rUserCount = ($rUsersCount[$rItem['id']] ?: 0);
							$rLineCount = ($rLinesCount[$rItem['id']] ?: 0);
							$rOwnerName = ($rOwnerNames[$rItem['owner_id']] ?: null);
							$rHasButtons = false;
							$rButtons = '<div class="btn-group bg-animate-info">';

							if (!hasPermissions('adv', 'edit_reguser')) {
							} else {
								$rHasButtons = true;

								if (!$rGroups[$rItem['member_group_id']]['is_reseller']) {
								} else {
									$rButtons .= '<button title="Add Credits" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="addCredits(' . $rItem['id'] . ');"><i class="mdi mdi-coin"></i></button>';
								}

								$rButtons .= "<button title=\"Edit\" onClick=\"navigate('user?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";

								if ($rItem['status'] == 1) {
									$rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('user', " . $rItem['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
								} else {
									$rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('user', " . $rItem['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
								}
							}

							$rButtons .= '</div>';

							if ($rItem['status'] == 1) {
								$rStatus = 'Active';
								$rStatusColour = 'info';
							} else {
								$rStatus = 'Inactive';
								$rStatusColour = 'warning';
							}

							$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">';

							if ($rGroups[$rItem['member_group_id']]['is_reseller']) {
								$rHTML .= '<div class="col-9">';
							} else {
								$rHTML .= '<div class="col-12">';
							}

							$rHTML .= '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['username'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rGroups[$rItem['member_group_id']]['group_name'] ? '<span class="text-white">' . $rGroups[$rItem['member_group_id']]['group_name'] . '</span><br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>';

							if ($rGroups[$rItem['member_group_id']]['is_reseller']) {
								$rHTML .= '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center font-24 search-icon-xl">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-coin text-white"></i><br/>' . number_format($rItem['credits'], 0) . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>';
							}

							$rHTML .= '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-warning btn-xs waves-effect waves-light no-border">USER</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-users text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rUserCount, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-tv text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format($rLineCount, 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

							if ($rHasButtons) {
								$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
							}

							$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

							break;

						case 'lines':
							$rOwnerName = ($rOwnerNames[$rItem['member_id']] ?: null);
							$rHasButtons = false;
							$rButtons = '<div class="btn-group bg-animate-info">';

							if (hasPermissions('adv', 'edit_user')) {
								$rHasButtons = true;
								$rButtons .= "<button title=\"Edit\" onClick=\"navigate('line?id=" . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
								$rButtons .= "<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>";

								if ($rItem['admin_enabled']) {
									$rButtons .= "<button title=\"Ban Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
								} else {
									$rButtons .= "<button title=\"Unban Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
								}

								if ($rItem['enabled']) {
									$rButtons .= "<button title=\"Disable Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
								} else {
									$rButtons .= "<button title=\"Enable Line\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rItem['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
								}

								if (($rLineConnectionCount[$rItem['id']] ?: false)) {
									$rButtons .= '<button title="Fingerprint" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rItem['id'] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
								} else {
									$rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
								}
							}

							$rButtons .= '</div>';

							if (!$rItem['admin_enabled']) {
								$rStatus = 'Banned';
								$rStatusColour = 'danger';
							} else {
								if (!$rItem['enabled']) {
									$rStatus = 'Disabled';
									$rStatusColour = 'warning';
								} else {
									$rStatus = 'Active';
									$rStatusColour = 'info';
								}
							}

							$rLastInfo = (isset($rLinesInfo[$rItem['id']]) ? $rLinesInfo[$rItem['id']] : json_decode($rItem['last_activity_array'], true));

							if (is_array($rLastInfo)) {
								$rLastInfo['stream_display_name'] = $rStreamNames[$rLastInfo['stream_id']];

								if ($rLastInfo['online']) {
									$rLastInfoText = "<a class='text-white' href='javascript:void(0);' onClick=\"navigate('stream_view?id=" . intval($rLastInfo['stream_id']) . "');\">" . $rLastInfo['stream_display_name'] . "</a><br/><small class='text-lighter'>Online: " . CoreUtilities::secondsToTime(time() - $rLastInfo['last_active']) . '</small>';
								} else {
									$rLastInfoText = "Last Active<br/><small class='text-lighter'>" . (($rLastInfo['date_end'] ? date(CoreUtilities::$rSettings['date_format'], $rLastInfo['date_end']) . '<br/>' . date('H:i:s', $rLastInfo['date_end']) : 'Never')) . '</small>';
								}
							} else {
								$rLastInfoText = "Last Active<br/><small class='text-lighter'>Never</small>";
							}

							$rExpires = ($rItem['exp_date'] ? date(CoreUtilities::$rSettings['datetime_format'], $rItem['exp_date']) : null);
							$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['username'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rExpires ? '<span class="text-white">expires:</span> ' . $rExpires . '<br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1">' . $rLastInfoText . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-pink btn-xs waves-effect waves-light no-border">' . (($rItem['is_restreamer'] ? "<i title='Restreamer' class='mdi mdi-swap-horizontal tooltip'></i> " : ($rItem['is_trial'] ? "<i title='Trial' class='mdi mdi-gavel tooltip'></i> " : ''))) . 'LINE</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format(($rLineConnectionCount[$rItem['id']] ?: 0), 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

							if (!$rHasButtons) {
							} else {
								$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
							}

							$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

							break;

						case 'enigma_devices':
						case 'mag_devices':
							$rLineInfo = ($rDeviceLines[$rItem['user_id']] ?: null);

							if ($rLineInfo) {
								$rDeviceType = ($rItem['table'] == 'mag_devices' ? 'mag' : 'enigma');
								$rOwnerName = ($rOwnerNames[$rLineInfo['member_id']] ?: null);
								$rHasButtons = false;
								$rButtons = '<div class="btn-group bg-animate-info">';

								if (!hasPermissions('adv', 'edit_user')) {
								} else {
									$rHasButtons = true;
									$rButtons .= "<button title=\"Edit\" onClick=\"navigate('" . $rDeviceType . '?id=' . $rItem['id'] . "');\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\"><i class=\"mdi mdi-pencil\"></i></button>";
									$rButtons .= "<button title=\"Kill Connection\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>";

									if ($rItem['admin_enabled']) {
										$rButtons .= "<button title=\"Ban Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
									} else {
										$rButtons .= "<button title=\"Unban Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
									}

									if ($rItem['enabled']) {
										$rButtons .= "<button title=\"Disable Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
									} else {
										$rButtons .= "<button title=\"Enable Device\" type=\"button\" class=\"btn btn-xs waves-effect waves-light no-border tooltip\" onClick=\"searchAPI('line', " . $rLineInfo['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
									}

									if (($rLineConnectionCount[$rLineInfo['id']] ?: false)) {
										$rButtons .= '<button title="Fingerprint" type="button" class="btn btn-xs waves-effect waves-light no-border tooltip" onClick="modalFingerprint(' . $rLineInfo['id'] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
									} else {
										$rButtons .= '<button type="button" disabled class="btn btn-xs waves-effect waves-light no-border tooltip"><i class="mdi mdi-fingerprint"></i></button>';
									}
								}

								$rButtons .= '</div>';

								if (!$rLineInfo['admin_enabled']) {
									$rStatus = 'Banned';
									$rStatusColour = 'danger';
								} else {
									if (!$rLineInfo['enabled']) {
										$rStatus = 'Disabled';
										$rStatusColour = 'warning';
									} else {
										$rStatus = 'Active';
										$rStatusColour = 'info';
									}
								}

								$rLastInfo = (isset($rLinesInfo[$rLineInfo['id']]) ? $rLinesInfo[$rLineInfo['id']] : json_decode($rLineInfo['last_activity_array'], true));

								if (is_array($rLastInfo)) {
									$rLastInfo['stream_display_name'] = $rStreamNames[$rLastInfo['stream_id']];

									if ($rLastInfo['online']) {
										$rLastInfoText = "<a class='text-white' href='javascript:void(0);' onClick=\"navigate('stream_view?id=" . intval($rLastInfo['stream_id']) . "');\">" . $rLastInfo['stream_display_name'] . "</a><br/><small class='text-lighter'>Online: " . CoreUtilities::secondsToTime(time() - $rLastInfo['last_active']) . '</small>';
									} else {
										$rLastInfoText = "Last Active<br/><small class='text-lighter'>" . (($rLastInfo['date_end'] ? date(CoreUtilities::$rSettings['date_format'], $rLastInfo['date_end']) . '<br/>' . date('H:i:s', $rLastInfo['date_end']) : 'Never')) . '</small>';
									}
								} else {
									$rLastInfoText = "Last Active<br/><small class='text-lighter'>Never</small>";
								}

								$rExpires = ($rLineInfo['exp_date'] ? date(CoreUtilities::$rSettings['datetime_format'], $rLineInfo['exp_date']) : null);
								$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">' . $rItem['mac'] . '</h3>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<p class="text-lighter mb-1 text-truncate"><small>' . (($rExpires ? '<span class="text-white">expires:</span> ' . $rExpires . '<br/>' : '')) . (($rOwnerName ? '<span class="text-white">owner:</span> ' . $rOwnerName : '')) . '</small></p>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1">' . $rLastInfoText . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="card-body action-block">' . "\n\t\t\t\t\t\t\t\t" . '<div class="media align-items-center align-center">' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%; display: flex;">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn bg-animate-pink btn-xs waves-effect waves-light no-border">' . (($rLineInfo['is_trial'] ? "<i class='mdi mdi-gavel'></i> " : '')) . strtoupper($rDeviceType) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-user-check text-white"></i> &nbsp; <button type="button" class="btn bg-animate-' . $rStatusColour . ' btn-xs waves-effect waves-light no-border">' . $rStatus . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn bg-animate-info btn-xs waves-effect waves-light no-border">' . number_format(($rLineConnectionCount[$rLineInfo['id']] ?: 0), 0) . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';

								if (!$rHasButtons) {
								} else {
									$rHTML .= '<li class="dropdown notification-list">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="mr-0 waves-effect pd-left pd-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="pro-user-name text-white ml-1">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="fe-sliders text-white"></i> &nbsp; ' . $rButtons . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</li>';
								}

								$rHTML .= '</ul>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>';

								break;
							}

							break;
					}
					$rReturn['items'][] = array('id' => $rTable . '#' . $rItem[$rTableInfo[3]], 'url' => $rTableInfo[1] . $rItem[$rTableInfo[3]], 'text' => $rItem[$rTableInfo[4]], 'html' => $rHTML);
				}
			}

			$rReturn['total_count'] = count($rReturn['items']);

			if ($rReturn['total_count'] != 0) {
			} else {
				$rHTML = '<div class="card-search text-white">' . "\n\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t" . '<div class="media align-items-center">' . "\n\t\t\t\t\t\t" . '<div class="col-9">' . "\n\t\t\t\t\t\t\t" . '<div>' . "\n\t\t\t\t\t\t\t\t" . '<h3 class="text-white my-1 text-truncate">No Results Found</h3>' . "\n\t\t\t\t\t\t\t\t" . "<p class=\"text-lighter mb-1\"><small>Try refining your search or manually locating the content you're looking for.</small></p>" . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '<div class="col-3">' . "\n\t\t\t\t\t\t\t" . '<div class="float-right text-center search-icon-xl mt-1" style="font-size: 72px;"><i class="fe-alert-circle"></i></div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>';
				$rReturn['items'][] = array('id' => 'no_results', 'url' => null, 'text' => 'No Results', 'html' => $rHTML);
			}

			echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'delete_recording') {
			if (hasPermissions('adv', 'edit_movie')) {
				if (!(isset(CoreUtilities::$rRequest['id']) && 0 < intval(CoreUtilities::$rRequest['id']))) {
					echo json_encode(array('result' => false));

					exit();
				}

				deleteRecording(CoreUtilities::$rRequest['id']);
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] == 'clear_failures') {
			if (hasPermissions('adv', 'streams')) {
				if (!(isset(CoreUtilities::$rRequest['id']) && 0 < intval(CoreUtilities::$rRequest['id']))) {
					echo json_encode(array('result' => false));

					exit();
				}

				$db->query('DELETE FROM `streams_logs` WHERE `stream_id` = ?;', CoreUtilities::$rRequest['id']);
				echo json_encode(array('result' => true));

				exit();
			}

			echo json_encode(array('result' => false));

			exit();
		}
		if (CoreUtilities::$rRequest['action'] != 'multi') {
		}
		$rType = CoreUtilities::$rRequest['type'];
		$rRequestIDs = json_decode(CoreUtilities::$rRequest['ids'], true);
		$rSub = CoreUtilities::$rRequest['sub'];

		if (count($rRequestIDs) != 0) {
			switch ($rType) {
				case 'line':
					if (hasPermissions('adv', 'edit_line')) {
						if ($rSub == 'delete') {
							deleteLines($rRequestIDs);
						} else {
							if ($rSub == 'enable') {
								$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id`IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
								CoreUtilities::updateLines($rRequestIDs);
							} else {
								if ($rSub == 'disable') {
									$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
									CoreUtilities::updateLines($rRequestIDs);
								} else {
									if ($rSub == 'ban') {
										$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
										CoreUtilities::updateLines($rRequestIDs);
									} else {
										if ($rSub == 'unban') {
											$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
											CoreUtilities::updateLines($rRequestIDs);
										} else {
											if ($rSub != 'purge') {
											} else {
												if (CoreUtilities::$rSettings['redis_handler']) {
													foreach ($rRequestIDs as $rUserID) {
														foreach (CoreUtilities::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
															CoreUtilities::closeConnection($rConnection);
														}
													}
												} else {
													$db->query('SELECT * FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');

													foreach ($db->get_rows() as $rRow) {
														CoreUtilities::closeConnection($rRow);
													}
												}
											}
										}
									}
								}
							}
						}

						echo json_encode(array('result' => true));

						exit();
					}
					echo json_encode(array('result' => false));

					exit();

				case 'mag':
				case 'enigma':
					$rPermission = array('mag' => 'edit_mag', 'enigma2' => 'edit_e2')[$rType];

					if (hasPermissions('adv', $rPermission)) {
						$rUserIDs = array();

						if ($rType == 'mag') {
							$db->query('SELECT `user_id` FROM `mag_devices` WHERE `mag_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
						} else {
							$db->query('SELECT `user_id` FROM `enigma2_devices` WHERE `device_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
						}

						foreach ($db->get_Rows() as $rRow) {
							$rUserIDs[] = $rRow['user_id'];
						}

						if (0 >= count($rUserIDs)) {
						} else {
							if ($rSub == 'delete') {
								if ($rType == 'mag') {
									deleteMAGs($rRequestIDs);
								} else {
									deleteEnigmas($rRequestIDs);
								}
							} else {
								if ($rSub == 'enable') {
									$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');
								} else {
									if ($rSub == 'disable') {
										$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');
									} else {
										if ($rSub == 'ban') {
											$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');
										} else {
											if ($rSub == 'unban') {
												$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');
											} else {
												if ($rSub == 'purge') {
													if (CoreUtilities::$rSettings['redis_handler']) {
														foreach ($rUserIDs as $rUserID) {
															foreach (CoreUtilities::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
																CoreUtilities::closeConnection($rConnection);
															}
														}
													} else {
														$db->query('SELECT * FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');

														foreach ($db->get_rows() as $rRow) {
															CoreUtilities::closeConnection($rRow);
														}
													}
												} else {
													if (!($rSub == 'convert' && in_array($rType, array('mag', 'enigma')))) {
													} else {
														foreach ($rRequestIDs as $rDeviceID) {
															if ($rType == 'mag') {
																deleteMAG($rDeviceID, false, false, true);
															} else {
																deleteEnigma($rDeviceID, false, false, true);
															}
														}
													}
												}
											}
										}
									}
								}
							}

							CoreUtilities::updateLines($rUserIDs);
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}

					// no break
				case 'user':
					if (hasPermissions('adv', 'edit_reguser')) {
						if ($rSub == 'enable') {
							$db->query('UPDATE `users` SET `status` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
						} else {
							if ($rSub == 'disable') {
								$db->query('UPDATE `users` SET `status` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
							} else {
								if ($rSub != 'delete') {
								} else {
									deleteUsers($rRequestIDs);
								}
							}
						}

						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();

				case 'server':
				case 'proxy':
					if (hasPermissions('adv', 'edit_server')) {
						if ($rType == 'server' && in_array($rSub, array('restart', 'start', 'stop'))) {
							$rStreamMap = array();

							if ($rSub == 'start') {
								$db->query('SELECT `server_id`, `stream_id` FROM `streams_servers` WHERE `server_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ') AND `on_demand` = 0;');
							} else {
								$db->query('SELECT `server_id`, `stream_id` FROM `streams_servers` WHERE `server_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ') AND `on_demand` = 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0;');
							}

							if (0 >= $db->num_rows()) {
							} else {
								foreach ($db->get_rows() as $rRow) {
									$rStreamMap[intval($rRow['server_id'])][] = intval($rRow['stream_id']);
								}
							}

							if (0 >= count($rStreamMap)) {
							} else {
								foreach ($rStreamMap as $rServerID => $rStreamIDs) {
									if ($rSub == 'stop') {
										APIRequest(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									} else {
										APIRequest(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									}
								}
							}
						} else {
							if ($rSub == 'purge') {
								foreach ($rRequestIDs as $rServerID) {
									if (CoreUtilities::$rSettings['redis_handler']) {
										if ($rType == 'proxy') {
											foreach (CoreUtilities::$rServers[$rServerID]['parent_id'] as $rParentID) {
												foreach (CoreUtilities::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
													if ($rConnection['proxy_id'] != $rServerID) {
													} else {
														CoreUtilities::closeConnection($rConnection);
													}
												}
											}
										} else {
											foreach (CoreUtilities::getRedisConnections(null, $rServerID, null, true, false, false) as $rConnection) {
												CoreUtilities::closeConnection($rConnection);
											}
										}
									} else {
										if ($rType == 'proxy') {
											$db->query('SELECT * FROM `lines_live` WHERE `proxy_id` = ?;', $rServerID);
										} else {
											$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
										}

										foreach ($db->get_rows() as $rRow) {
											CoreUtilities::closeConnection($rRow);
										}
									}
								}
							} else {
								if ($rSub == 'enable') {
									$db->query('UPDATE `servers` SET `enabled` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
								} else {
									if ($rSub == 'disable') {
										$db->query('UPDATE `servers` SET `enabled` = 0 WHERE `is_main` = 0 AND `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
									} else {
										if ($rSub == 'enable_proxy' && $rType == 'server') {
											$db->query('UPDATE `servers` SET `enable_proxy` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
										} else {
											if ($rSub == 'disable_proxy' && $rType == 'server') {
												$db->query('UPDATE `servers` SET `enable_proxy` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
											} else {
												foreach ($rRequestIDs as $rServerID) {
													if ($rServers[$rServerID]['is_main'] != 0) {
													} else {
														deleteServer($rServerID);
													}
												}
											}
										}
									}
								}
							}
						}

						echo json_encode(array('result' => true));

						exit();
					}

					echo json_encode(array('result' => false));

					exit();

				case 'series':
					if ($rSub != 'delete') {
					} else {
						deleteSeriesMass($rRequestIDs);
					}

					echo json_encode(array('result' => true));

					exit();

				case 'stream':
				case 'movie':
				case 'episode':
				case 'cchannel':
				case 'radio':
					if (hasPermissions('adv', 'edit_' . $rType)) {
						$rNoServer = $rStreamMap = array();

						foreach ($rRequestIDs as $rStream) {
							list($rStreamID, $rServerID) = explode('-', $rStream);

							if (!$rServerID) {
								$rNoServer[] = $rStreamID;
							} else {
								$rStreamMap[$rServerID][] = $rStreamID;
							}
						}
						$rUnallocated = $rAllocated = array();

						if (0 >= count($rNoServer)) {
						} else {
							$db->query('SELECT `stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rNoServer)) . ');');

							foreach ($db->get_rows() as $rRow) {
								$rStreamMap[intval($rRow['server_id'])][] = intval($rRow['stream_id']);

								if (in_array(intval($rRow['stream_id']), $rAllocated)) {
								} else {
									$rAllocated[] = intval($rRow['stream_id']);
								}
							}
						}

						foreach ($rNoServer as $rStreamID) {
							if (in_array($rStreamID, $rAllocated)) {
							} else {
								$rUnallocated[] = $rStreamID;
							}
						}

						if (!(0 < count($rStreamMap) || $rSub == 'delete' && 0 < count($rUnallocated))) {
						} else {
							if (in_array($rSub, array('start', 'stop', 'restart'))) {
								if ($rSub != 'restart') {
								} else {
									$rSub = 'start';
								}

								foreach ($rStreamMap as $rServerID => $rStreamIDs) {
									if (in_array($rType, array('stream', 'radio', 'cchannel'))) {
										APIRequest(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									} else {
										APIRequest(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									}
								}
							} else {
								if ($rSub == 'delete') {
									if (0 >= count($rStreamMap)) {
									} else {
										foreach ($rStreamMap as $rServerID => $rStreamIDs) {
											deleteStreamsByServer($rStreamIDs, $rServerID, $rDeleteFiles = true);
										}
									}

									if (0 >= count($rUnallocated)) {
									} else {
										deleteStreams($rUnallocated, true);
									}
								} else {
									if ($rSub != 'purge') {
									} else {
										foreach ($rStreamMap as $rServerID => $rStreamIDs) {
											if (CoreUtilities::$rSettings['redis_handler']) {
												foreach ($rStreamIDs as $rStreamID) {
													foreach (CoreUtilities::getRedisConnections(null, $rServerID, $rStreamID, true, false, false) as $rConnection) {
														CoreUtilities::closeConnection($rConnection);
													}
												}
											} else {
												$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rServerID);

												foreach ($db->get_rows() as $rRow) {
													CoreUtilities::closeConnection($rRow);
												}
											}
										}
									}
								}
							}
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}

					// no break
				default:
					break;
			}
		} else {
			echo json_encode(array('result' => false));
			exit();
		}
	}

	echo json_encode(array('result' => false));
} else {
	echo json_encode(array('result' => false, 'error' => 'Not logged in'));
	exit();
}

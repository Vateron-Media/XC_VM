<?php

include 'functions.php';
session_write_close();

if (isset($_SESSION['reseller'])) {
	if (PHP_ERRORS) {
	} else {
		if (!(empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest')) {
		} else {
			exit();
		}
	}

	if (!CoreUtilities::$rSettings['redis_handler']) {
	} else {
		CoreUtilities::connectRedis();
	}

	if (!$rUserInfo['id']) {
		echo json_encode(array('result' => false));
	} else {
		if (isset($rUserInfo['reports'])) {
		} else {
			echo json_encode(array('result' => false));
		}
	}

	if (!isset(CoreUtilities::$rRequest['action'])) {
	} else {
		if (CoreUtilities::$rRequest['action'] == 'dashboard') {
			$rReturn = array('open_connections' => 0, 'online_users' => 0, 'active_accounts' => 0, 'credits' => 0, 'credits_assigned' => 0);

			if (CoreUtilities::$rSettings['redis_handler']) {
				$rReports = array();
				$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');

				foreach ($db->get_rows() as $rRow) {
					$rReports[] = $rRow['id'];
				}

				if (0 >= count($rReports)) {
				} else {
					foreach (CoreUtilities::getUserConnections($rReports, true) as $rUserID => $rConnections) {
						$rReturn['open_connections'] += $rConnections;

						if (0 >= $rConnections) {
						} else {
							$rReturn['online_users']++;
						}
					}
				}
			} else {
				$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
				$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
				$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
				$rReturn['online_users'] = $db->num_rows();
			}

			$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['active_accounts'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT SUM(`credits`) AS `credits` FROM `users` WHERE `id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['credits'] = ($db->get_row()['credits'] ?: 0);
			$rReturn['credits_assigned'] = ($rReturn['credits'] - intval($rUserInfo['credits']) ?: 0);
			echo json_encode($rReturn);

			exit();
		} else {
			if (CoreUtilities::$rRequest['action'] == 'connections') {
				if ($rPermissions['reseller_client_connection_logs']) {
					$rStreamID = CoreUtilities::$rRequest['stream_id'];
					$rSub = CoreUtilities::$rRequest['sub'];

					if ($rSub == 'purge') {
						if (CoreUtilities::$rSettings['redis_handler']) {
							$rReports = array();
							$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');

							foreach ($db->get_rows() as $rRow) {
								$rReports[] = $rRow['id'];
							}
							$rConnections = CoreUtilities::getRedisConnections(null, null, $rStreamID, true, false, false, false);

							foreach ($rConnections as $rConnection) {
								if (!in_array($rConnection['user_id'], $rReports)) {
								} else {
									CoreUtilities::closeConnection($rConnection);
								}
							}
						} else {
							$db->query('SELECT `lines_live`.* FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = ? AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');', $rStreamID);

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
					exit();
				}
			} else {
				if (CoreUtilities::$rRequest['action'] == 'line') {
					if ($rPermissions['create_line']) {
						$rSub = CoreUtilities::$rRequest['sub'];
						$rUserID = intval(CoreUtilities::$rRequest['user_id']);
						$rLine = getUser($rUserID);

						if (hasPermissions('line', $rUserID) && $rLine) {
							if ($rSub == 'delete') {
								deleteLine($rUserID);
								$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', CoreUtilities::$rRequest['user_id'], 0, $rUserInfo['credits'], time(), json_encode($rLine));
								echo json_encode(array('result' => true));

								exit();
							}

							if ($rSub == 'enable') {
								$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rUserID);
								$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', CoreUtilities::$rRequest['user_id'], 0, $rUserInfo['credits'], time(), json_encode($rLine));
								echo json_encode(array('result' => true));

								exit();
							}

							if ($rSub == 'disable') {
								$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rUserID);
								$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', CoreUtilities::$rRequest['user_id'], 0, $rUserInfo['credits'], time(), json_encode($rLine));
								echo json_encode(array('result' => true));

								exit();
							}

							if ($rSub == 'reset_isp') {
								$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rUserID);
								echo json_encode(array('result' => true));

								exit();
							}

							if ($rSub == 'kill_line') {
								if ($rPermissions['reseller_client_connection_logs']) {
									if (CoreUtilities::$rSettings['redis_handler']) {
										foreach (CoreUtilities::getUserConnections(array($rUserID), false)[$rUserID] as $rConnection) {
											CoreUtilities::closeConnection($rConnection);
										}
									} else {
										$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rUserID);

										if (0 >= $db->num_rows()) {
										} else {
											foreach ($db->get_rows() as $rRow) {
												CoreUtilities::closeConnection($rRow);
											}
										}
									}

									echo json_encode(array('result' => true));

									exit();
								} else {
									exit();
								}
							} else {
								echo json_encode(array('result' => false));

								exit();
							}
						} else {
							echo json_encode(array('result' => false, 'error' => 'No permissions.'));

							exit();
						}
					} else {
						exit();
					}
				} else {
					if (CoreUtilities::$rRequest['action'] == 'line_activity') {
						if ($rPermissions['reseller_client_connection_logs']) {
							$rSub = CoreUtilities::$rRequest['sub'];

							if ($rSub != 'kill') {
							} else {
								if (CoreUtilities::$rSettings['redis_handler']) {
									if (!($rActivityInfo = igbinary_unserialize(CoreUtilities::$redis->get(CoreUtilities::$rRequest['uuid'])))) {
									} else {
										if (hasPermissions('line', $rActivityInfo['user_id'])) {
											CoreUtilities::closeConnection($rActivityInfo);
											echo json_encode(array('result' => true));

											exit();
										}

										echo json_encode(array('result' => false, 'error' => 'No permissions.'));

										exit();
									}
								} else {
									$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ? LIMIT 1;', CoreUtilities::$rRequest['uuid']);

									if ($db->num_rows() != 1) {
									} else {
										$rRow = $db->get_row();

										if (hasPermissions('line', $rRow['user_id'])) {
											CoreUtilities::closeConnection($rRow);
											echo json_encode(array('result' => true));

											exit();
										}

										echo json_encode(array('result' => false, 'error' => 'No permissions.'));

										exit();
									}
								}
							}

							echo json_encode(array('result' => false));

							exit();
						}

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'adjust_credits') {
						if ($rPermissions['create_sub_resellers']) {
							if (hasPermissions('user', CoreUtilities::$rRequest['id'])) {
								$rUser = getRegisteredUser(CoreUtilities::$rRequest['id']);

								if (!($rUser && is_numeric(CoreUtilities::$rRequest['credits']))) {
								} else {
									$rOwnerCredits = intval($rUserInfo['credits']) - intval(CoreUtilities::$rRequest['credits']);
									$rCredits = intval($rUser['credits']) + intval(CoreUtilities::$rRequest['credits']);

									if (!(0 <= $rCredits && 0 <= $rOwnerCredits)) {
									} else {
										$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rOwnerCredits, $rUserInfo['id']);
										$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rCredits, $rUser['id']);
										$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['id'], $rUserInfo['id'], CoreUtilities::$rRequest['credits'], time(), CoreUtilities::$rRequest['reason']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'adjust_credits', CoreUtilities::$rRequest['id'], intval(CoreUtilities::$rRequest['credits']), $rOwnerCredits, time(), json_encode($rUser));
										echo json_encode(array('result' => true));

										exit();
									}
								}

								echo json_encode(array('result' => false));

								exit();
							}

							echo json_encode(array('result' => false, 'error' => 'No permissions.'));

							exit();
						}

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'reg_user') {
						if ($rPermissions['create_sub_resellers']) {
							if (hasPermissions('user', CoreUtilities::$rRequest['user_id'])) {
								$rSub = CoreUtilities::$rRequest['sub'];
								$rUser = getRegisteredUser(CoreUtilities::$rRequest['user_id']);

								if ($rSub == 'delete') {
									if ($rPermissions['delete_users']) {
										$rOwnerCredits = intval($rUserInfo['credits']) + intval($rUser['credits']);
										$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rOwnerCredits, $rUserInfo['id']);
										deleteUser(CoreUtilities::$rRequest['user_id'], false, false, $rUserInfo['id']);
										$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUserInfo['id'], $rUserInfo['id'], intval($rUser['credits']), time(), 'Deleted user: ' . $rUser['username']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', CoreUtilities::$rRequest['user_id'], intval($rUser['credits']), $rOwnerCredits, time(), json_encode($rUser));
										echo json_encode(array('result' => true));

										exit();
									}

									exit();
								}

								if ($rSub == 'enable') {
									$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['user_id']);
									$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', CoreUtilities::$rRequest['user_id'], 0, $rUserInfo['credits'], time(), json_encode($rUser));
									echo json_encode(array('result' => true));

									exit();
								}

								if ($rSub != 'disable') {
									echo json_encode(array('result' => false));

									exit();
								}

								$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['user_id']);
								$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', CoreUtilities::$rRequest['user_id'], 0, $rUserInfo['credits'], time(), json_encode($rUser));
								echo json_encode(array('result' => true));

								exit();
							}

							echo json_encode(array('result' => false, 'error' => 'No permissions.'));

							exit();
						}

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'ticket') {
						$rTicket = getTicket(CoreUtilities::$rRequest['ticket_id']);

						if (!$rTicket) {
						} else {
							if (hasPermissions('user', $rTicket['member_id'])) {
								$rSub = CoreUtilities::$rRequest['sub'];

								if ($rSub == 'close') {
									$db->query('UPDATE `tickets` SET `status` = 0 WHERE `id` = ?;', CoreUtilities::$rRequest['ticket_id']);
									echo json_encode(array('result' => true));

									exit();
								}

								if ($rSub != 'reopen') {
								} else {
									if ($rTicket['member_id'] != $rUserInfo['id']) {
										$db->query('UPDATE `tickets` SET `status` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['ticket_id']);
										echo json_encode(array('result' => true));

										exit();
									}

									exit();
								}
							} else {
								echo json_encode(array('result' => false, 'error' => 'No permissions.'));

								exit();
							}
						}

						echo json_encode(array('result' => false));

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'mag') {
						if ($rPermissions['create_mag']) {
							$rSub = CoreUtilities::$rRequest['sub'];
							$rMagDetails = getMag(intval(CoreUtilities::$rRequest['mag_id']));

							if (!$rMagDetails) {
							} else {
								if (hasPermissions('line', $rMagDetails['user_id'])) {
									if ($rSub == 'delete') {
										deleteMAG(CoreUtilities::$rRequest['mag_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', CoreUtilities::$rRequest['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'enable') {
										$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rMagDetails['user_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', CoreUtilities::$rRequest['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'disable') {
										$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rMagDetails['user_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', CoreUtilities::$rRequest['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'convert') {
										deleteMAG(CoreUtilities::$rRequest['mag_id'], false, false, true);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rMagDetails['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails['user']));
										echo json_encode(array('result' => true, 'line_id' => $rMagDetails['user']['id']));

										exit();
									}

									if ($rSub == 'reset_isp') {
										$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rMagDetails['user']['id']);
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub != 'kill_line') {
									} else {
										if ($rPermissions['reseller_client_connection_logs']) {
											if (CoreUtilities::$rSettings['redis_handler']) {
												foreach (CoreUtilities::getUserConnections(array($rMagDetails['user_id']), false)[$rMagDetails['user_id']] as $rConnection) {
													CoreUtilities::closeConnection($rConnection);
												}
											} else {
												$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rMagDetails['user_id']);

												if (0 >= $db->num_rows()) {
												} else {
													foreach ($db->get_rows() as $rRow) {
														CoreUtilities::closeConnection($rRow);
													}
												}
											}

											echo json_encode(array('result' => true));

											exit();
										} else {
											exit();
										}
									}
								} else {
									echo json_encode(array('result' => false, 'error' => 'No permissions.'));

									exit();
								}
							}

							echo json_encode(array('result' => false));

							exit();
						}

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'enigma') {
						if ($rPermissions['create_enigma']) {
							$rSub = CoreUtilities::$rRequest['sub'];
							$rE2Details = getEnigma(intval(CoreUtilities::$rRequest['e2_id']));

							if (!$rE2Details) {
							} else {
								if (hasPermissions('line', $rE2Details['user_id'])) {
									if ($rSub == 'delete') {
										deleteEnigma(CoreUtilities::$rRequest['e2_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', CoreUtilities::$rRequest['e2_id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'enable') {
										$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rE2Details['user_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', CoreUtilities::$rRequest['e2_id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'disable') {
										$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rE2Details['user_id']);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', CoreUtilities::$rRequest['e2_id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub == 'convert') {
										deleteEnigma(CoreUtilities::$rRequest['e2_id'], false, false, true);
										$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rE2Details['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details['user']));
										echo json_encode(array('result' => true, 'line_id' => $rE2Details['user']['id']));

										exit();
									}

									if ($rSub == 'reset_isp') {
										$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rE2Details['user']['id']);
										echo json_encode(array('result' => true));

										exit();
									}

									if ($rSub != 'kill_line') {
									} else {
										if ($rPermissions['reseller_client_connection_logs']) {
											if (CoreUtilities::$rSettings['redis_handler']) {
												foreach (CoreUtilities::getUserConnections(array($rMagDetails['user_id']), false)[$rE2Details['user_id']] as $rConnection) {
													CoreUtilities::closeConnection($rConnection);
												}
											} else {
												$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rE2Details['user_id']);

												if (0 >= $db->num_rows()) {
												} else {
													foreach ($db->get_rows() as $rRow) {
														CoreUtilities::closeConnection($rRow);
													}
												}
											}

											echo json_encode(array('result' => true));

											exit();
										} else {
											exit();
										}
									}
								} else {
									echo json_encode(array('result' => false, 'error' => 'No permissions.'));

									exit();
								}
							}

							echo json_encode(array('result' => false));

							exit();
						}

						exit();
					}

					if (CoreUtilities::$rRequest['action'] == 'get_package') {
						$rReturn = array();
						$rOverride = json_decode($rUserInfo['override_packages'], true);
						$db->query('SELECT `id`, `bouquets`, `official_credits` AS `cost_credits`, `official_duration`, `official_duration_in`, `max_connections`, `check_compatible`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', CoreUtilities::$rRequest['package_id']);

						if ($db->num_rows() == 1) {
							$rData = $db->get_row();

							if (!(isset($rOverride[$rData['id']]['official_credits']) && 0 < strlen($rOverride[$rData['id']]['official_credits']))) {
							} else {
								$rData['cost_credits'] = $rOverride[$rData['id']]['official_credits'];
							}

							if (isset(CoreUtilities::$rRequest['orig_id']) && $rData['check_compatible']) {
								$rData['compatible'] = checkCompatible(CoreUtilities::$rRequest['package_id'], CoreUtilities::$rRequest['orig_id']);
							} else {
								$rData['compatible'] = true;
							}

							$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));

							if (!(isset(CoreUtilities::$rRequest['user_id']) && $rData['compatible'])) {
							} else {
								if (!($rUser = getUser(CoreUtilities::$rRequest['user_id']))) {
								} else {
									if (time() < $rUser['exp_date']) {
										$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in'], $rUser['exp_date']));
									} else {
										$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));
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
							$rData['duration'] = $rData['official_duration'] . ' ' . $rData['official_duration_in'];
							echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
						} else {
							echo json_encode(array('result' => false));
						}

						exit();
					} else {
						if (CoreUtilities::$rRequest['action'] == 'get_package_trial') {
							$rReturn = array();
							$db->query('SELECT `bouquets`, `trial_credits` AS `cost_credits`, `trial_duration`, `trial_duration_in`, `max_connections`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', CoreUtilities::$rRequest['package_id']);

							if ($db->num_rows() == 1) {
								$rData = $db->get_row();
								$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['trial_duration']) . ' ' . $rData['trial_duration_in']));

								foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
									$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);

									if ($db->num_rows() != 1) {
									} else {
										$rRow = $db->get_row();
										$rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
									}
								}
								$rData['duration'] = $rData['trial_duration'] . ' ' . $rData['trial_duration_in'];
								$rData['compatible'] = true;
								echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
							} else {
								echo json_encode(array('result' => false));
							}

							exit();
						} else {
							if (CoreUtilities::$rRequest['action'] == 'header_stats') {
								$rReturn = array('total_connections' => 0, 'total_users' => 0);

								if (CoreUtilities::$rSettings['redis_handler']) {
									$rReports = array();
									$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');

									foreach ($db->get_rows() as $rRow) {
										$rReports[] = $rRow['id'];
									}

									if (0 >= count($rReports)) {
									} else {
										foreach (CoreUtilities::getUserConnections($rReports, true) as $rUserID => $rConnections) {
											$rReturn['total_connections'] += $rConnections;

											if (0 >= $rConnections) {
											} else {
												$rReturn['total_users']++;
											}
										}
									}
								} else {
									$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
									$rReturn['total_connections'] = ($db->get_row()['count'] ?: 0);
									$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
									$rReturn['total_users'] = $db->num_rows();
								}

								echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);

								exit();
							} else {
								if (CoreUtilities::$rRequest['action'] == 'stats') {
									$rReturn = array('open_connections' => 0, 'online_users' => 0, 'total_lines' => 0, 'total_users' => 0, 'owner_credits' => 0, 'user_credits' => 0, 'total_credits' => 0);
									$rUptime = 0;

									if (CoreUtilities::$rSettings['redis_handler']) {
										$rReports = array();
										$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');

										foreach ($db->get_rows() as $rRow) {
											$rReports[] = $rRow['id'];
										}

										if (0 >= count($rReports)) {
										} else {
											foreach (CoreUtilities::getUserConnections($rReports, true) as $rUserID => $rConnections) {
												$rReturn['open_connections'] += $rConnections;

												if (0 >= $rConnections) {
												} else {
													$rReturn['online_users']++;
												}
											}
										}
									} else {
										$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
										$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
										$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
										$rReturn['online_users'] = $db->num_rows();
									}

									$db->query('SELECT COUNT(*) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
									$rReturn['total_lines'] = $db->get_row()['count'];
									$db->query('SELECT COUNT(*) AS `count`, SUM(`credits`) AS `credits` FROM `users` WHERE `owner_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
									$rRow = $db->get_row();
									$rReturn['total_users'] = $rRow['count'];
									$rReturn['user_credits'] = $rRow['credits'];
									$rReturn['owner_credits'] = $rUserInfo['credits'];
									$rReturn['total_credits'] = $rReturn['owner_credits'] + $rReturn['user_credits'];
									echo json_encode($rReturn);

									exit();
								} else {
									if (CoreUtilities::$rRequest['action'] == 'userlist') {
										$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

										if (!isset(CoreUtilities::$rRequest['search'])) {
										} else {
											if (isset(CoreUtilities::$rRequest['page'])) {
												$rPage = intval(CoreUtilities::$rRequest['page']);
											} else {
												$rPage = 1;
											}

											$db->query('SELECT COUNT(`id`) AS `id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?);', '%' . CoreUtilities::$rRequest['search'] . '%', '%' . CoreUtilities::$rRequest['search'] . '%', '%' . CoreUtilities::$rRequest['search'] . '%');
											$rReturn['total_count'] = $db->get_row()['id'];
											$db->query('SELECT `id`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?) ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%', '%' . CoreUtilities::$rRequest['search'] . '%', '%' . CoreUtilities::$rRequest['search'] . '%');

											if (0 >= $db->num_rows()) {
											} else {
												foreach ($db->get_rows() as $rRow) {
													$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
												}
											}
										}

										echo json_encode($rReturn);

										exit();
									}

									if (CoreUtilities::$rRequest['action'] == 'send_event') {
										if ($rPermissions['create_mag']) {
											$rData = json_decode(CoreUtilities::$rRequest['data'], true);
											$rMag = getMag($rData['id']);

											if (!$rMag) {
											} else {
												if (hasPermissions('line', $rMag['user_id'])) {
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
																echo json_encode(array('result' => true));

																exit();
															}

															$rData['need_confirm'] = 0;
															$rData['reboot_portal'] = 0;
															$rData['message'] = '';
														}
													}

													if (!$db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rData['id'], $rData['type'], $rData['need_confirm'], $rData['message'], $rData['reboot_portal'], time())) {
													} else {
														$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'send_event', $rMag['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMag));
														echo json_encode(array('result' => true));

														exit();
													}
												} else {
													echo json_encode(array('result' => false, 'error' => 'No permissions.'));

													exit();
												}
											}

											echo json_encode(array('result' => false));

											exit();
										}

										exit();
									}

									if (CoreUtilities::$rRequest['action'] == 'streamlist') {
										if ($rPermissions['create_mag'] || $rPermissions['can_view_vod'] || $rPermissions['reseller_client_connection_logs']) {
											$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

											if (!isset(CoreUtilities::$rRequest['search'])) {
											} else {
												if (isset(CoreUtilities::$rRequest['page'])) {
													$rPage = intval(CoreUtilities::$rRequest['page']);
												} else {
													$rPage = 1;
												}

												$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE `stream_display_name` LIKE ? AND `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ');', '%' . CoreUtilities::$rRequest['search'] . '%');
												$rReturn['total_count'] = $db->get_row()['id'];
												$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ') AND `stream_display_name` LIKE ? ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . CoreUtilities::$rRequest['search'] . '%');

												if (0 >= $db->num_rows()) {
												} else {
													foreach ($db->get_rows() as $rRow) {
														$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['stream_display_name']);
													}
												}
											}

											echo json_encode($rReturn);

											exit();
										}

										exit();
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
											$db->query('SELECT `type` FROM `blocked_asns` WHERE `asn` = ?;', $rResponse['isp']['autonomous_system_number']);

											if (0 >= $db->num_rows()) {
											} else {
												$rResponse['type'] = $db->get_row()['type'];
											}
										}

										echo json_encode(array('result' => true, 'data' => $rResponse));

										exit();
									}

									if (CoreUtilities::$rRequest['action'] == 'get_epg') {
										if ($rPermissions['can_view_vod']) {
											if (count($rPermissions['stream_ids']) != 0) {
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
												exit();
											}
										} else {
											exit();
										}
									} else {
										if (CoreUtilities::$rRequest['action'] != 'get_programme') {
										} else {
											if ($rPermissions['can_view_vod']) {
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

											exit();
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

	echo json_encode(array('result' => false));
} else {
	echo json_encode(array('result' => false, 'error' => 'Not logged in'));

	exit();
}

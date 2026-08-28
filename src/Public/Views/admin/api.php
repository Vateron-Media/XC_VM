<?php

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Backup\BackupService;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\ImageUtils;
use XcVm\Core\Util\StreamUtils;
use XcVm\Core\Util\TimeUtils;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\TicketRepository;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Module\Watch\WatchService;
use XcVm\Public\Controllers\Admin\TableController;
use XcVm\Streaming\Codec\FfmpegPaths;
use XcVm\Streaming\Health\ProcessChecker;

/** @var \XcVm\Core\Database\Database $db */

include 'functions.php';
session_write_close();

if (!PHP_ERRORS) {
	// The report/export download is opened via a full-page navigation (window.location),
	// which never carries the X-Requested-With header. It stays gated by the session check
	// below and the per-action `backups` permission, so exempt it from the XHR guard.
	$rDirectActions = array('report');

	if (!in_array(RequestManager::get('action'), $rDirectActions, true)) {
		if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
			exit();
		}
	}
}

if (isset($_SESSION['hash'])) {
	if (SettingsManager::get('redis_handler')) {
		RedisManager::ensureConnected();
	}

	if (RequestManager::has('action')) {
		if (RequestManager::get('action') != 'multi') {
		}
		$rType = RequestManager::get('type') ?? '';
		$rRequestIDs = json_decode(RequestManager::get('ids') ?? '[]', true) ?: [];
		$rSub = RequestManager::get('sub') ?? '';

		if (count($rRequestIDs) != 0) {
			switch ($rType) {
				case 'line':
					if (Authorization::check('adv', 'edit_line')) {
						if ($rSub == 'delete') {
							LineRepository::deleteMany($rRequestIDs);
						} else {
							if ($rSub == 'enable') {
								$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id`IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
								LineService::updateLinesSignal($rRequestIDs);
							} else {
								if ($rSub == 'disable') {
									$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
									LineService::updateLinesSignal($rRequestIDs);
								} else {
									if ($rSub == 'ban') {
										$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
										LineService::updateLinesSignal($rRequestIDs);
									} else {
										if ($rSub == 'unban') {
											$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
											LineService::updateLinesSignal($rRequestIDs);
										} else {
											if ($rSub != 'purge') {
											} else {
												if (SettingsManager::get('redis_handler')) {
													foreach ($rRequestIDs as $rUserID) {
														foreach (ConnectionTracker::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
															ConnectionTracker::closeConnection($rConnection);
														}
													}
												} else {
													$db->query('SELECT * FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');

													foreach ($db->get_rows() as $rRow) {
														ConnectionTracker::closeConnection($rRow);
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

					if (Authorization::check('adv', $rPermission)) {
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
									MagService::deleteDevices($rRequestIDs);
								} else {
									EnigmaService::deleteDevices($rRequestIDs);
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
													if (SettingsManager::get('redis_handler')) {
														foreach ($rUserIDs as $rUserID) {
															foreach (ConnectionTracker::getRedisConnections($rUserID, null, null, true, false, false) as $rConnection) {
																ConnectionTracker::closeConnection($rConnection);
															}
														}
													} else {
														$db->query('SELECT * FROM `lines_live` WHERE `user_id` IN (' . implode(',', array_map('intval', $rUserIDs)) . ');');

														foreach ($db->get_rows() as $rRow) {
															ConnectionTracker::closeConnection($rRow);
														}
													}
												} else {
													if (!($rSub == 'convert' && in_array($rType, array('mag', 'enigma')))) {
													} else {
														foreach ($rRequestIDs as $rDeviceID) {
															if ($rType == 'mag') {
																MagService::deleteDevice($rDeviceID, false, false, true);
															} else {
																EnigmaService::deleteDevice($rDeviceID, false, false, true);
															}
														}
													}
												}
											}
										}
									}
								}
							}

							LineService::updateLinesSignal($rUserIDs);
						}

						echo json_encode(array('result' => true));

						exit();
					} else {
						echo json_encode(array('result' => false));

						exit();
					}

					// no break
				case 'user':
					if (Authorization::check('adv', 'edit_reguser')) {
						if ($rSub == 'enable') {
							$db->query('UPDATE `users` SET `status` = 1 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
						} else {
							if ($rSub == 'disable') {
								$db->query('UPDATE `users` SET `status` = 0 WHERE `id` IN (' . implode(',', array_map('intval', $rRequestIDs)) . ');');
							} else {
								if ($rSub != 'delete') {
								} else {
									UserService::deleteRegisteredUsers($rRequestIDs);
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
					if (Authorization::check('adv', 'edit_server')) {
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
										ApiClient::request(array('action' => 'stream', 'sub' => 'stop', 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									} else {
										ApiClient::request(array('action' => 'stream', 'sub' => 'start', 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									}
								}
							}
						} else {
							if ($rSub == 'purge') {
								foreach ($rRequestIDs as $rServerID) {
									if (SettingsManager::get('redis_handler')) {
										if ($rType == 'proxy') {
											foreach (ServerRepository::getAll()[$rServerID]['parent_id'] as $rParentID) {
												foreach (ConnectionTracker::getRedisConnections(null, $rParentID, null, true, false, false) as $rConnection) {
													if ($rConnection['proxy_id'] == $rServerID) {
														ConnectionTracker::closeConnection($rConnection);
													}
												}
											}
										} else {
											foreach (ConnectionTracker::getRedisConnections(null, $rServerID, null, true, false, false) as $rConnection) {
												ConnectionTracker::closeConnection($rConnection);
											}
										}
									} else {
										if ($rType == 'proxy') {
											$db->query('SELECT * FROM `lines_live` WHERE `proxy_id` = ?;', $rServerID);
										} else {
											$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ?;', $rServerID);
										}

										foreach ($db->get_rows() as $rRow) {
											ConnectionTracker::closeConnection($rRow);
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
														ServerRepository::deleteById($rServerID);
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
						SeriesService::deleteSeriesByIds($rRequestIDs);
					}

					echo json_encode(array('result' => true));

					exit();

				case 'stream':
				case 'movie':
				case 'episode':
				case 'cchannel':
				case 'radio':
					if (Authorization::check('adv', 'edit_' . $rType)) {
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
										ApiClient::request(array('action' => 'stream', 'sub' => $rSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									} else {
										ApiClient::request(array('action' => 'vod', 'sub' => $rSub, 'stream_ids' => $rStreamIDs, 'servers' => array($rServerID)));
									}
								}
							} else {
								if ($rSub == 'delete') {
									if (0 >= count($rStreamMap)) {
									} else {
										foreach ($rStreamMap as $rServerID => $rStreamIDs) {
											StreamRepository::deleteStreamsByServer($rStreamIDs, $rServerID, $rDeleteFiles = true);
										}
									}

									if (0 >= count($rUnallocated)) {
									} else {
										StreamRepository::deleteStreams($rUnallocated, true);
									}
								} else {
									if ($rSub != 'purge') {
									} else {
										foreach ($rStreamMap as $rServerID => $rStreamIDs) {
											if (SettingsManager::get('redis_handler')) {
												foreach ($rStreamIDs as $rStreamID) {
													foreach (ConnectionTracker::getRedisConnections(null, $rServerID, $rStreamID, true, false, false) as $rConnection) {
														ConnectionTracker::closeConnection($rConnection);
													}
												}
											} else {
												$db->query('SELECT * FROM `lines_live` WHERE `server_id` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');', $rServerID);

												foreach ($db->get_rows() as $rRow) {
													ConnectionTracker::closeConnection($rRow);
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

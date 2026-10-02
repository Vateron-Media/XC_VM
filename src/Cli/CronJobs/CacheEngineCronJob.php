<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Process\Multithread;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamCacheBuilder;

/**
 * CacheEngineCronJob — cache engine cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CacheEngineCronJob implements CommandInterface {
	use CronTrait;

	private $rSplit = 10000;

	private $rThreadCount;

	private $rUpdateIDs = [];

	/**
	 * Set by a worker whose database read failed; the parent run then keeps
	 * the existing cache files instead of sweeping the ones it did not
	 * rewrite (they would be live streams/lines, not deleted ones).
	 */
	private const FAILED_MARKER = 'cache_engine_failed';

	public function getName(): string {
		return 'cron:cache_engine';
	}

	public function getDescription(): string {
		return 'Cron: generate cache for lines, streams, series, groups';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		
		register_shutdown_function([$this, 'shutdown']);

		ini_set('memory_limit', -1);
		ini_set('max_execution_time', 0);

		SettingsManager::set(SettingsRepository::getAll(true));
		$this->rThreadCount = (SettingsManager::get('cache_thread_count') ?: 10);

		$rType = null;
		$rGroupStart = $rGroupMax = null;

		if (!empty($rArgs[0])) {
			$rType = $rArgs[0];
			if ($rType == 'streams_update' || $rType == 'lines_update') {
				$this->rUpdateIDs = array_map('intval', explode(',', $rArgs[1] ?? ''));
			} else {
				if (isset($rArgs[1]) && isset($rArgs[2])) {
					$rGroupStart = intval($rArgs[1]);
					$rGroupMax = intval($rArgs[2]);
				}
			}
			if ($rType == 'force') {
				echo 'Forcing cache regen...' . "\n";
				SettingsManager::update('cache_changes', false);
			}
		} else {
			// Stop older runs and their workers, so two rebuilds never race.
+			// Read from /proc (own PID excluded), not a ps|grep shell pipeline;
+			// the parent is the cron shell that launched this run.
+			foreach (ProcessManager::findProcessPIDs(['cache_engine']) as $rPID) {
+				if ($rPID !== posix_getppid()) {
+					ProcessManager::kill($rPID);
+				}
+			}
		}

		$this->loadCron($rType, $rGroupStart, $rGroupMax);

		return 0;
	}

	private function getChangedStreams(): array {
		global $db;
		$rReturn = ['changes' => [], 'delete' => []];
		$rExisting = [];
		$db->query('SELECT `id`, GREATEST(IFNULL(UNIX_TIMESTAMP(`streams`.`updated`), 0), IFNULL(MAX(UNIX_TIMESTAMP(`streams_servers`.`updated`)), 0)) AS `updated` FROM `streams` LEFT JOIN `streams_servers` ON `streams`.`id` = `streams_servers`.`stream_id` GROUP BY `id`;');
		if ($db->dbh && $db->result) {
			if ($db->result->rowCount() > 0) {
				foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
					if (!file_exists(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) || (filemtime(STREAMS_TMP_PATH . 'stream_' . $rRow['id']) ?: 0) <= $rRow['updated']) {
						$rReturn['changes'][] = $rRow['id'];
					}
					$rExisting[] = $rRow['id'];
				}
			}
		}
		$rExisting = array_flip($rExisting);
		foreach (glob(STREAMS_TMP_PATH . 'stream_*') as $rFile) {
			$rParts = explode('_', $rFile);
			$rStreamID = intval(end($rParts));
			if (!isset($rExisting[$rStreamID])) {
				$rReturn['delete'][] = $rStreamID;
			}
		}
		return $rReturn;
	}

	private function getChangedLines(): array {
		global $db;
		$rReturn = ['changes' => [], 'delete_i' => [], 'delete_c' => [], 'delete_t' => []];
		$cacheMemoryAllocation = glob(LINES_TMP_PATH . 'line_i_*');
		$cacheFailureHandler = glob(LINES_TMP_PATH . 'line_c_*');
		$cacheSuccessIndicator = glob(LINES_TMP_PATH . 'line_t_*');
		$cacheRevalidationCheck = $cacheDataCompression = $cacheDataDecompression = [];
		$db->query('SELECT `id`, `username`, `password`, `access_token`, UNIX_TIMESTAMP(`updated`) AS `updated` FROM `lines`;');
		if ($db->dbh && $db->result) {
			if ($db->result->rowCount() > 0) {
				foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
					if (!file_exists(LINES_TMP_PATH . 'line_i_' . $rRow['id']) || (filemtime(LINES_TMP_PATH . 'line_i_' . $rRow['id']) ?: 0) <= $rRow['updated']) {
						$rReturn['changes'][] = $rRow['id'];
					}
					$cacheRevalidationCheck[] = $rRow['id'];
					$cacheDataCompression[] = (SettingsManager::get('case_sensitive_line') ? $rRow['username'] . '_' . $rRow['password'] : strtolower($rRow['username'] . '_' . $rRow['password']));
					if ($rRow['access_token']) {
						$cacheDataDecompression[] = $rRow['access_token'];
					}
				}
			}
		}
		$cacheRevalidationCheck = array_flip($cacheRevalidationCheck);
		foreach ($cacheMemoryAllocation as $rFile) {
			$rUserID = (intval(explode('line_i_', $rFile, 2)[1]) ?: null);
			if ($rUserID && !isset($cacheRevalidationCheck[$rUserID])) {
				$rReturn['delete_i'][] = $rUserID;
			}
		}
		$cacheDataCompression = array_flip($cacheDataCompression);
		foreach ($cacheFailureHandler as $rFile) {
			$cacheExpirationTime = (explode('line_c_', $rFile, 2)[1] ?: null);
			if ($cacheExpirationTime && !isset($cacheDataCompression[$cacheExpirationTime])) {
				$rReturn['delete_c'][] = $cacheExpirationTime;
			}
		}
		$cacheDataDecompression = array_flip($cacheDataDecompression);
		foreach ($cacheSuccessIndicator as $rFile) {
			$rToken = (explode('line_t_', $rFile, 2)[1] ?: null);
			if ($rToken && !isset($cacheDataDecompression[$rToken])) {
				$rReturn['delete_t'][] = $rToken;
			}
		}
		return $rReturn;
	}

	private function loadCron($rType, $rGroupStart, $rGroupMax): void {
		global $db;
		$rStartTime = time();
		if (ProcessManager::isNginxRunning()) {
			if (SettingsManager::get('enable_cache') || !empty($this->rUpdateIDs)) {
				switch ($rType) {
					case 'lines':
						$this->generateLines($rGroupStart, $rGroupMax);
						break;
					case 'lines_update':
						$this->generateLines(null, null, $this->rUpdateIDs);
						break;
					case 'series':
						$this->generateSeries($rGroupStart, $rGroupMax);
						break;
					case 'streams':
						$this->generateStreams($rGroupStart, $rGroupMax);
						break;
					case 'streams_update':
						$this->generateStreams(null, null, $this->rUpdateIDs);
						break;
					case 'groups':
						$this->generateGroups();
						break;
					case 'lines_per_ip':
						$this->generateLinesPerIP();
						break;
					case 'theft_detection':
						$this->generateTheftDetection();
						break;
					default:
						$cacheInitTime = $rSeriesCategories = [];
						@unlink(CACHE_TMP_PATH . self::FAILED_MARKER);
						$db->query('SELECT `series_id`, MAX(`streams`.`added`) AS `last_modified` FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` GROUP BY `series_id`;');
						foreach ($db->get_rows() as $rRow) {
							$cacheInitTime[$rRow['series_id']] = $rRow['last_modified'];
						}
						if (!$db->query('SELECT * FROM `streams_series`;') || !$db->result) {
							$this->markFailed();
						} else {
							if ($db->result->rowCount() > 0) {
								foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
									if (isset($cacheInitTime[$rRow['id']])) {
										$rRow['last_modified'] = $cacheInitTime[$rRow['id']];
									}
									$rSeriesCategories[$rRow['id']] = json_decode($rRow['category_id'], true);
									$this->write(SERIES_TMP_PATH . 'series_' . $rRow['id'], igbinary_serialize($rRow));
								}
							}
						}
						$this->write(SERIES_TMP_PATH . 'series_categories', igbinary_serialize($rSeriesCategories));
						$rDelete = ['streams' => [], 'lines_i' => [], 'lines_c' => [], 'lines_t' => []];
						$cacheDataKey = [];
						if (SettingsManager::get('cache_changes')) {
							$rChanges = $this->getChangedLines();
							$rDelete['lines_i'] = $rChanges['delete_i'];
							$rDelete['lines_c'] = $rChanges['delete_c'];
							$rDelete['lines_t'] = $rChanges['delete_t'];
							if (count($rChanges['changes']) > 0) {
								foreach (array_chunk($rChanges['changes'], $this->rSplit) as $rChunk) {
									$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines_update" "' . implode(',', $rChunk) . '"';
								}
							}
						} else {
							$db->query('SELECT COUNT(*) AS `count` FROM `lines`;');
							$rLinesCount = $db->get_row()['count'];
							$cacheValidityCheck = [];
							if ($this->rSplit > 0) {
								for ($rStart = 0; $rStart <= $rLinesCount; $rStart += $this->rSplit) {
									$cacheValidityCheck[] = $rStart;
								}
							}
							if ($cacheValidityCheck === []) {
								$cacheValidityCheck = [0];
							}
							foreach ($cacheValidityCheck as $rStart) {
								$rMax = $this->rSplit;
								if ($rLinesCount < $rStart + $rMax) {
									$rMax = $rLinesCount - $rStart;
								}
								$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines" ' . $rStart . ' ' . $rMax;
							}
						}
						$db->query('SELECT COUNT(*) AS `count` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5);');
						$cacheRetrieveMethod = (int) $db->get_row()['count'];
						$cacheStoreMethod = [];
						if ($cacheRetrieveMethod > 0) {
							for ($rStart = 0; $rStart < $cacheRetrieveMethod; $rStart += $this->rSplit) {
								$rMax = min($this->rSplit, $cacheRetrieveMethod - $rStart);
								$cacheStoreMethod[] = $rStart;
								$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "series" ' . $rStart . ' ' . $rMax;
							}
						} else {
							// Still merged below, so the empty run's files are consumed too.
							$cacheStoreMethod[] = 0;
							$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "series" 0 0';
						}
						if (SettingsManager::get('cache_changes')) {
							$rChanges = $this->getChangedStreams();
							$rDelete['streams'] = $rChanges['delete'];
							if (count($rChanges['changes']) > 0) {
								foreach (array_chunk($rChanges['changes'], $this->rSplit) as $rChunk) {
									$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "streams_update" "' . implode(',', $rChunk) . '"';
								}
							}
						} else {
							$db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
							$cacheDeleteMethod = (int) $db->get_row()['count'];
							$cacheCleanupTrigger = [];
							if ($this->rSplit > 0) {
								for ($rStart = 0; $rStart <= $cacheDeleteMethod; $rStart += $this->rSplit) {
									$cacheCleanupTrigger[] = $rStart;
								}
							}
							if ($cacheCleanupTrigger === []) {
								$cacheCleanupTrigger = [0];
							}
							foreach ($cacheCleanupTrigger as $rStart) {
								$rMax = $this->rSplit;
								if ($cacheDeleteMethod < $rStart + $rMax) {
									$rMax = $cacheDeleteMethod - $rStart;
								}
								$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "streams" ' . $rStart . ' ' . $rMax;
							}
						}
						$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "groups"';
						$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "lines_per_ip"';
						$cacheDataKey[] = PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine "theft_detection"';
						$cacheMetadataKey = new Multithread($cacheDataKey, $this->rThreadCount);
						$cacheMetadataKey->run();
						unset($cacheDataKey);
						$rSeriesEpisodes = $rSeriesMap = [];
						foreach ($cacheStoreMethod as $rStart) {
							if (file_exists(SERIES_TMP_PATH . 'series_map_' . $rStart)) {
								foreach (igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_map_' . $rStart)) as $rStreamID => $rSeriesID) {
									$rSeriesMap[$rStreamID] = $rSeriesID;
								}
								unlink(SERIES_TMP_PATH . 'series_map_' . $rStart);
							}
							if (file_exists(SERIES_TMP_PATH . 'series_episodes_' . $rStart)) {
								$rSeasonData = igbinary_unserialize(file_get_contents(SERIES_TMP_PATH . 'series_episodes_' . $rStart));
								foreach (array_keys($rSeasonData) as $rSeriesID) {
									if (!isset($rSeriesEpisodes[$rSeriesID])) {
										$rSeriesEpisodes[$rSeriesID] = [];
									}
									foreach (array_keys($rSeasonData[$rSeriesID]) as $rSeasonNum) {
										foreach ($rSeasonData[$rSeriesID][$rSeasonNum] as $rEpisode) {
											$rSeriesEpisodes[$rSeriesID][$rSeasonNum][] = $rEpisode;
										}
									}
								}
								unlink(SERIES_TMP_PATH . 'series_episodes_' . $rStart);
							}
						}
						$this->write(SERIES_TMP_PATH . 'series_map', igbinary_serialize($rSeriesMap));
						foreach ($rSeriesEpisodes as $rSeriesID => $rSeasons) {
							$this->write(SERIES_TMP_PATH . 'episodes_' . $rSeriesID, igbinary_serialize($rSeasons));
						}
						$rFailed = file_exists(CACHE_TMP_PATH . self::FAILED_MARKER);
						if ($rFailed) {
							echo 'A cache worker could not read the database; keeping the existing cache files.' . "\n";
						}
						foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH, CACHE_TMP_PATH] as $rTmpPath) {
							FileCache::cleanStaleTemps($rTmpPath);
						}
						if (SettingsManager::get('cache_changes')) {
							// Series and episode lists are rebuilt whole every run, but
							// only rewritten for what still exists: drop the files of
							// deleted series and of series left with no episodes.
							if (!$rFailed) {
								$this->removeStaleSeries(array_flip(array_keys($rSeriesCategories)), $rSeriesEpisodes);
							}
							foreach ($rDelete['streams'] as $rStreamID) {
								@unlink(STREAMS_TMP_PATH . 'stream_' . $rStreamID);
							}
							foreach ($rDelete['lines_i'] as $rUserID) {
								@unlink(LINES_TMP_PATH . 'line_i_' . $rUserID);
							}
							foreach ($rDelete['lines_c'] as $cacheExpirationTime) {
								@unlink(LINES_TMP_PATH . 'line_c_' . $cacheExpirationTime);
							}
							foreach ($rDelete['lines_t'] as $rToken) {
								@unlink(LINES_TMP_PATH . 'line_t_' . $rToken);
							}
						} elseif (!$rFailed) {
							foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rTmpPath) {
								foreach (scandir($rTmpPath) as $rFile) {
									if ($rFile === '.' || $rFile === '..') {
										continue;
									}
									$rFilePath = $rTmpPath . $rFile;
									if (is_file($rFilePath) && filemtime($rFilePath) < $rStartTime - 1) {
										unlink($rFilePath);
									}
								}
							}
						}
						echo 'Cache updated!' . "\n";
						$this->write(CACHE_TMP_PATH . 'cache_complete', (string) time());
						$db->query('UPDATE `settings` SET `last_cache` = ?, `last_cache_taken` = ?;', time(), time() - $rStartTime);
						break;
				}
			} else {
				echo 'Cache is disabled.' . "\n";
				echo 'Generating group permissions...' . "\n";
				$this->generateGroups();
				echo 'Generating lines per ip...' . "\n";
				$this->generateLinesPerIP();
				echo 'Detecting theft of VOD...' . "\n";
				$this->generateTheftDetection();
				echo 'Clearing old data...' . "\n";
				foreach ([STREAMS_TMP_PATH, LINES_TMP_PATH, SERIES_TMP_PATH] as $rTmpPath) {
					foreach (scandir($rTmpPath) as $rFile) {
						if ($rFile === '.' || $rFile === '..') {
							continue;
						}
						$rFilePath = $rTmpPath . $rFile;
						if (is_file($rFilePath)) {
							unlink($rFilePath);
						}
					}
				}
				$this->write(CACHE_TMP_PATH . 'cache_complete', (string) time());
				exit();
			}
		} else {
			echo 'XC_VM not running...' . "\n";
			exit();
		}
	}

	private function generateLines($rStart = null, $rCount = null, $cacheLockMechanism = []): void {
		global $db;
		if (is_null($rCount)) {
			$rCount = count($cacheLockMechanism);
		}
		if ($rCount > 0) {
			$rColumns = '`id`, `username`, `password`, `exp_date`, `created_at`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `is_trial`, `is_restreamer`, `is_stalker`, `is_mag`, `is_e2`, `is_isplock`, `allowed_ips`, `allowed_ua`, `pair_id`, `force_server_id`, `isp_desc`, `forced_country`, `bypass_ua`, `last_expiration_video`, `access_token`, `mag_devices`.`token` AS `mag_token`, `admin_notes`, `reseller_notes`, `lines`.`custom_data`';
			$rSteps = [];
			if (!is_null($rStart)) {
				$rEnd = $rStart + $rCount - 1;
				if ($this->rSplit >= ($rEnd - $rStart + 1)) {
					$rSteps = [$rStart];
				}
			} else {
				$rSteps = [null];
			}
			$rExists = [];
			$rRead = true;
			foreach ($rSteps as $rStep) {
				if (!is_null($rStep)) {
					if ($rStart + $rCount < $rStep + $this->rSplit) {
						$rMax = ($rStart + $rCount) - $rStep;
					} else {
						$rMax = $this->rSplit;
					}
					// Page the lines themselves, in id order, before the MAG join:
					// paging the joined rows would skip or repeat lines between
					// pages (no stable order, a line with several devices).
					$rOk = $db->query('SELECT ' . $rColumns . ' FROM (SELECT * FROM `lines` ORDER BY `id` ASC LIMIT ' . intval($rStep) . ', ' . intval($rMax) . ') AS `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id`;');
				} else {
					$rOk = $db->query('SELECT ' . $rColumns . ' FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` WHERE `id` IN (' . implode(',', array_map('intval', $cacheLockMechanism)) . ');');
				}
				if ($rOk && $db->result) {
					if ($db->result->rowCount() > 0) {
						foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rUserInfo) {
							$rExists[intval($rUserInfo['id'])] = true;
							$rOldKeys = $this->lineKeys(intval($rUserInfo['id']));
							$this->write(LINES_TMP_PATH . 'line_i_' . $rUserInfo['id'], igbinary_serialize($rUserInfo));
							$rKey = $this->credentialKey($rUserInfo['username'], $rUserInfo['password']);
							$this->write(LINES_TMP_PATH . 'line_c_' . $rKey, (string) $rUserInfo['id']);
							if (!empty($rUserInfo['access_token'])) {
								$this->write(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token'], (string) $rUserInfo['id']);
							}
							// Credentials or token changed: the old lookup files
							// would keep resolving to this line.
							if ($rOldKeys['c'] !== null && $rOldKeys['c'] !== $rKey) {
								$this->unlinkLookup('line_c_' . $rOldKeys['c'], intval($rUserInfo['id']));
							}
							if ($rOldKeys['t'] !== null && $rOldKeys['t'] !== (string) $rUserInfo['access_token']) {
								$this->unlinkLookup('line_t_' . $rOldKeys['t'], intval($rUserInfo['id']));
							}
						}
					}
					$db->result = null;
				} else {
					$rRead = false;
				}
			}
			if (!$rRead) {
				// A failed read says nothing about which lines exist.
				$this->markFailed();
				return;
			}
			foreach ($cacheLockMechanism as $rForceID) {
				$rForceID = intval($rForceID);
				if (!isset($rExists[$rForceID])) {
					$this->removeLine($rForceID);
				}
			}
		}
	}

	/** The cache key a line's username and password are looked up by (`line_c_<key>`). */
	private function credentialKey($rUsername, $rPassword): string {
		return SettingsManager::get('case_sensitive_line') ? $rUsername . '_' . $rPassword : strtolower($rUsername . '_' . $rPassword);
	}

	/**
	 * The credential and token keys of a line's current cache entry, so the
	 * lookup files can follow a rename or a deletion.
	 *
	 * @return array{c: ?string, t: ?string}
	 */
	private function lineKeys(int $rUserID): array {
		$rKeys = ['c' => null, 't' => null];
		$rRaw = @file_get_contents(LINES_TMP_PATH . 'line_i_' . $rUserID);
		$rOld = $rRaw !== false ? @igbinary_unserialize($rRaw) : null;
		if (is_array($rOld)) {
			if (isset($rOld['username'], $rOld['password'])) {
				$rKeys['c'] = $this->credentialKey($rOld['username'], $rOld['password']);
			}
			if (!empty($rOld['access_token'])) {
				$rKeys['t'] = (string) $rOld['access_token'];
			}
		}
		return $rKeys;
	}

	/** Drop a deleted line's entry and the lookup files that pointed to it. */
	private function removeLine(int $rUserID): void {
		$rKeys = $this->lineKeys($rUserID);
		foreach (['c' => 'line_c_', 't' => 'line_t_'] as $rType => $rPrefix) {
			if ($rKeys[$rType] !== null) {
				$this->unlinkLookup($rPrefix . $rKeys[$rType], $rUserID);
			}
		}
		@unlink(LINES_TMP_PATH . 'line_i_' . $rUserID);
	}

	/** Remove a `line_c_` / `line_t_` lookup file, only while it still names $rUserID (another line may hold the key now). */
	private function unlinkLookup(string $rFile, int $rUserID): void {
		$rPath = LINES_TMP_PATH . $rFile;
		if (intval(@file_get_contents($rPath)) === $rUserID) {
			@unlink($rPath);
		}
	}

	private function generateStreams($rStart = null, $rCount = null, $cacheLockMechanism = []): void {
		global $db;
		if (is_null($rCount)) {
			$rCount = count($cacheLockMechanism);
		}
		if ($rCount > 0) {
			$rBouquetMap = [];
			$rBouquetMapPath = CACHE_TMP_PATH . 'bouquet_map';
			if (file_exists($rBouquetMapPath) && 0 < filesize($rBouquetMapPath)) {
				$rBouquetData = @igbinary_unserialize(file_get_contents($rBouquetMapPath));
				if (is_array($rBouquetData)) {
					$rBouquetMap = $rBouquetData;
				}
			}
			$rSteps = [];
			if (!is_null($rStart)) {
				$rEnd = $rStart + $rCount - 1;
				if ($this->rSplit >= ($rEnd - $rStart + 1)) {
					$rSteps = [$rStart];
				}
			} else {
				$rSteps = [null];
			}
			$rExists = [];
			foreach ($rSteps as $rStep) {
				if (!is_null($rStep)) {
					if ($rStart + $rCount < $rStep + $this->rSplit) {
						$rMax = ($rStart + $rCount) - $rStep;
					} else {
						$rMax = $this->rSplit;
					}
					$rRows = StreamCacheBuilder::streamRows($db, null, $rStep, $rMax);
				} else {
					$rRows = StreamCacheBuilder::streamRows($db, array_map('intval', $cacheLockMechanism));
				}
				if ($rRows === null) {
					// A failed read says nothing about which streams exist.
					$this->markFailed();
					return;
				}
				if ($rRows !== []) {
					$rStreamMap = StreamCacheBuilder::serverMap($db, array_map(static fn($rRow) => intval($rRow['id']), $rRows));
					foreach ($rRows as $rStreamInfo) {
						$rID = intval($rStreamInfo['id']);
						$rExists[$rID] = true;
						StreamCacheBuilder::write($rID, StreamCacheBuilder::entry($rStreamInfo, $rBouquetMap[$rID] ?? [], $rStreamMap[$rID] ?? []));
					}
					unset($rRows, $rStreamMap);
				}
			}
			foreach ($cacheLockMechanism as $rForceID) {
				if (!isset($rExists[intval($rForceID)])) {
					StreamCacheBuilder::remove(intval($rForceID));
				}
			}
		}
	}

	private function generateSeries($rStart, $rCount): void {
		global $db;
		$rSeriesMap = [];
		$rSeriesEpisodes = [];
		if ($rCount > 0) {
			if (is_null($rStart)) {
				$rSteps = [null];
			} else {
				$rEnd = $rStart + $rCount - 1;
				$rangeLength = $rEnd - $rStart + 1;
				if ($this->rSplit >= $rangeLength) {
					$rSteps = [$rStart];
				} else {
					$rSteps = range($rStart, $rEnd, $this->rSplit);
				}
			}
			foreach ($rSteps as $rStep) {
				if ($rStart + $rCount < $rStep + $this->rSplit) {
					$rMax = ($rStart + $rCount) - $rStep;
				} else {
					$rMax = $this->rSplit;
				}
				if (!$db->query('SELECT `stream_id`, `series_id`, `season_num`, `episode_num` FROM `streams_episodes` WHERE `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` = 5) ORDER BY `series_id` ASC, `season_num` ASC, `episode_num` ASC, `stream_id` ASC LIMIT ' . $rStep . ', ' . $rMax . ';')) {
					// The episode lists are merged from every worker; a missing
					// page would empty series that still have episodes.
					$this->markFailed();
					continue;
				}
				foreach ($db->get_rows() as $rRow) {
					if ($rRow['stream_id'] && $rRow['series_id']) {
						$rSeriesMap[intval($rRow['stream_id'])] = intval($rRow['series_id']);
						if (!isset($rSeriesEpisodes[$rRow['series_id']])) {
							$rSeriesEpisodes[$rRow['series_id']] = [];
						}
						$rSeriesEpisodes[$rRow['series_id']][$rRow['season_num']][] = ['episode_num' => $rRow['episode_num'], 'stream_id' => $rRow['stream_id']];
					}
				}
			}
		}
		$this->write(SERIES_TMP_PATH . 'series_episodes_' . $rStart, igbinary_serialize($rSeriesEpisodes));
		$this->write(SERIES_TMP_PATH . 'series_map_' . $rStart, igbinary_serialize($rSeriesMap));
		unset($rSeriesMap);
	}

	private function generateGroups(): void {
		global $db;
		$db->query('SELECT `group_id` FROM `users_groups`;');
		foreach ($db->get_rows() as $rGroup) {
			$rBouquets = $rReturn = [];
			$db->query("SELECT * FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rGroup['group_id']);
			foreach ($db->get_rows() as $rRow) {
				foreach (json_decode($rRow['bouquets'], true) as $rID) {
					if (!in_array($rID, $rBouquets)) {
						$rBouquets[] = $rID;
					}
				}
				if ($rRow['is_line']) {
					$rReturn['create_line'] = true;
				}
				if ($rRow['is_mag']) {
					$rReturn['create_mag'] = true;
				}
				if ($rRow['is_e2']) {
					$rReturn['create_enigma'] = true;
				}
			}
			if (count($rBouquets) > 0) {
				$db->query('SELECT * FROM `bouquets` WHERE `id` IN (' . implode(',', array_map('intval', $rBouquets)) . ');');
				$rSeriesIDs = [];
				$rStreamIDs = [];
				foreach ($db->get_rows() as $rRow) {
					if ($rRow['bouquet_channels']) {
						$rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_channels'], true));
					}
					if ($rRow['bouquet_movies']) {
						$rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_movies'], true));
					}
					if ($rRow['bouquet_radios']) {
						$rStreamIDs = array_merge($rStreamIDs, json_decode($rRow['bouquet_radios'], true));
					}
					foreach (json_decode($rRow['bouquet_series'], true) as $rSeriesID) {
						$rSeriesIDs[] = $rSeriesID;
						$db->query('SELECT `stream_id` FROM `streams_episodes` WHERE `series_id` = ?;', $rSeriesID);
						foreach ($db->get_rows() as $rEpisode) {
							$rStreamIDs[] = $rEpisode['stream_id'];
						}
					}
				}
				$rReturn['stream_ids'] = array_unique($rStreamIDs);
				$rReturn['series_ids'] = array_unique($rSeriesIDs);
				$rCategories = [];
				if (count($rReturn['stream_ids']) > 0) {
					$db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['stream_ids'])) . ');');
					foreach ($db->get_rows() as $rRow) {
						if ($rRow['category_id']) {
							$rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
						}
					}
				}
				if (count($rReturn['series_ids']) > 0) {
					$db->query('SELECT DISTINCT(`category_id`) AS `category_id` FROM `streams_series` WHERE `id` IN (' . implode(',', array_map('intval', $rReturn['series_ids'])) . ');');
					foreach ($db->get_rows() as $rRow) {
						if ($rRow['category_id']) {
							$rCategories = array_merge($rCategories, json_decode($rRow['category_id'], true));
						}
					}
				}
				$rReturn['category_ids'] = array_unique($rCategories);
			}
			$this->write(CACHE_TMP_PATH . 'permissions_' . intval($rGroup['group_id']), igbinary_serialize($rReturn));
		}
	}

	private function generateLinesPerIP(): void {
		global $db;
		$rLinesPerIP = [3600 => [], 86400 => [], 604800 => [], 0 => []];
		foreach (array_keys($rLinesPerIP) as $rTime) {
			if ($rTime > 0) {
				$db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `date_start` >= ? AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;', time() - $rTime);
			} else {
				$db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 GROUP BY `lines_activity`.`user_id` ORDER BY `ip_count` DESC LIMIT 1000;');
			}
			foreach ($db->get_rows() as $rRow) {
				$rLinesPerIP[$rTime][] = $rRow;
			}
		}
		$this->write(CACHE_TMP_PATH . 'lines_per_ip', igbinary_serialize($rLinesPerIP));
	}

	private function generateTheftDetection(): void {
		global $db;
		$rTheftDetection = [3600 => [], 86400 => [], 604800 => [], 0 => []];
		foreach (array_keys($rTheftDetection) as $rTime) {
			if ($rTime > 0) {
				$db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `date_start` >= ? AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5)) GROUP BY `lines_activity`.`user_id` ORDER BY `vod_count` DESC LIMIT 1000;', time() - $rTime);
			} else {
				$db->query('SELECT `lines_activity`.`user_id`, COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`, `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5)) GROUP BY `lines_activity`.`user_id` ORDER BY `vod_count` DESC LIMIT 1000;');
			}
			foreach ($db->get_rows() as $rRow) {
				$rTheftDetection[$rTime][] = $rRow;
			}
		}
		$this->write(CACHE_TMP_PATH . 'theft_detection', igbinary_serialize($rTheftDetection));
	}

	/**
	 * Remove the `series_<id>` / `episodes_<id>` files of series that are gone
	 * or have no episodes left.
	 *
	 * @param array<int|string, mixed> $rSeries       Current series ids (keys).
	 * @param array<int|string, mixed> $rWithEpisodes Series ids with episodes (keys).
	 */
	private function removeStaleSeries(array $rSeries, array $rWithEpisodes): void {
		foreach (scandir(SERIES_TMP_PATH) ?: [] as $rFile) {
			if (preg_match('/^series_(\d+)$/', $rFile, $rMatch) && !isset($rSeries[$rMatch[1]])) {
				@unlink(SERIES_TMP_PATH . $rFile);
			} elseif (preg_match('/^episodes_(\d+)$/', $rFile, $rMatch) && !isset($rWithEpisodes[$rMatch[1]])) {
				@unlink(SERIES_TMP_PATH . $rFile);
			}
		}
	}

	/** Atomic cache write: readers see the old file or the new one, never a partial one. */
	private function write(string $rPath, string $rData): void {
		if (!FileCache::writeAtomic($rPath, $rData)) {
			echo 'Cache write failed: ' . $rPath . "\n";
		}
	}

	/** Tell the parent run a worker's database read failed (FAILED_MARKER). */
	private function markFailed(): void {
		@touch(CACHE_TMP_PATH . self::FAILED_MARKER);
	}

	public function shutdown(): void {
		global $db;
		if (is_object($db)) {
			$db->close_mysql();
		}
	}
}

<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Logging\FileLogger;
use XcVm\Core\Process\Multithread;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamCacheBuilder;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Cache\CacheRunState;

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
			// Read from /proc (own PID excluded), not a ps|grep shell pipeline;
			// the parent is the cron shell that launched this run.
			foreach (ProcessManager::findProcessPIDs(['cache_engine']) as $rPID) {
				if ($rPID !== posix_getppid()) {
					ProcessManager::kill($rPID);
				}
			}
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
		$cacheRevalidationCheck = $cacheDataCompression = $cacheDataDecompression = [];
		$db->query('SELECT `id`, `username`, `password`, `access_token`, UNIX_TIMESTAMP(`updated`) AS `updated` FROM `lines`;');
		if ($db->dbh && $db->result) {
			if ($db->result->rowCount() > 0) {
				foreach ($db->result->fetchAll(\PDO::FETCH_ASSOC) as $rRow) {
					// A line without its lookup is rewritten too: the lookups of a cache
					// built before their names were hashed are made on the first pass
					// (and the old ones, which no line has any more, dropped below).
					$rKey = $this->credentialKey($rRow['username'], $rRow['password']);
					if (!file_exists(LINES_TMP_PATH . 'line_i_' . $rRow['id']) || (filemtime(LINES_TMP_PATH . 'line_i_' . $rRow['id']) ?: 0) <= $rRow['updated'] || !file_exists(LINES_TMP_PATH . 'line_c_' . $rKey)) {
						$rReturn['changes'][] = $rRow['id'];
					}
					$cacheRevalidationCheck[] = $rRow['id'];
					$cacheDataCompression[] = $rKey;
					if ($rRow['access_token']) {
						$cacheDataDecompression[] = $rRow['access_token'];
					}
				}
			}
		}
		$cacheRevalidationCheck = array_flip($cacheRevalidationCheck);
		$cacheDataCompression = array_flip($cacheDataCompression);
		$cacheDataDecompression = array_flip($cacheDataDecompression);
		// The directory read once, a name at a time: three glob()s of it listed
		// and sorted every file's full path (three files a line) before any was
		// looked at, a second and half a gigabyte at 200,000 lines, every run.
		$rDirectory = @opendir(LINES_TMP_PATH);
		while ($rDirectory !== false && ($rFile = readdir($rDirectory)) !== false) {
			$rName = substr($rFile, 7);
			switch (substr($rFile, 0, 7)) {
				case 'line_i_':
					$rUserID = intval($rName);
					if ($rUserID && !isset($cacheRevalidationCheck[$rUserID])) {
						$rReturn['delete_i'][] = $rUserID;
					}
					break;
				case 'line_c_':
					if ($rName && !isset($cacheDataCompression[$rName])) {
						$rReturn['delete_c'][] = $rName;
					}
					break;
				case 'line_t_':
					if ($rName && !isset($cacheDataDecompression[$rName])) {
						$rReturn['delete_t'][] = $rName;
					}
					break;
			}
		}
		if ($rDirectory !== false) {
			closedir($rDirectory);
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
						// A scheduled run (no argument) is judged on the dashboard; a forced one is not.
						if ($rType === null) {
							CacheRunState::started(CACHE_TMP_PATH);
						} else {
							@unlink(CACHE_TMP_PATH . CacheRunState::FAILED);
						}
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
						$rFailed = file_exists(CACHE_TMP_PATH . CacheRunState::FAILED);
						if ($rFailed) {
							echo 'A cache worker could not read the database or write a cache file; keeping the entries it did not rewrite.' . "\n";
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
						$this->finishRun($rFailed, $rStartTime);
						break;
				}
			} else {
				echo 'Cache is disabled.' . "\n";
				CacheRunState::clear(CACHE_TMP_PATH);
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
							$this->write(LINES_TMP_PATH . 'line_c_' . $rKey, (string) $rUserInfo['id'], true);
							if (!empty($rUserInfo['access_token'])) {
								$this->write(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token'], (string) $rUserInfo['id'], true);
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
		return UserRepository::credentialKey((bool) SettingsManager::get('case_sensitive_line'), (string) $rUsername, (string) $rPassword);
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
						if (!StreamCacheBuilder::write($rID, StreamCacheBuilder::entry($rStreamInfo, $rBouquetMap[$rID] ?? [], $rStreamMap[$rID] ?? []))) {
							echo 'Cache write failed: stream_' . $rID . "\n";
							$this->markFailed();
						}
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
		// Every series' episodes in one read, in the order the per-series read gave them.
		if (!$db->query('SELECT `series_id`, `stream_id` FROM `streams_episodes` ORDER BY `series_id` ASC, `id` ASC;')) {
			$this->markFailed();
			return;
		}
		$rEpisodes = [];
		foreach ($db->get_rows() as $rRow) {
			$rEpisodes[intval($rRow['series_id'])][] = $rRow['stream_id'];
		}
		// A bouquet column that is not a JSON list counts as empty.
		$rList = static fn(mixed $rJSON): array => is_array($rDecoded = json_decode((string) $rJSON, true)) ? $rDecoded : [];

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
					$rStreamIDs = array_merge($rStreamIDs, $rList($rRow['bouquet_channels']), $rList($rRow['bouquet_movies']), $rList($rRow['bouquet_radios']));
					foreach ($rList($rRow['bouquet_series']) as $rSeriesID) {
						$rSeriesIDs[] = $rSeriesID;
						foreach ($rEpisodes[intval($rSeriesID)] ?? [] as $rEpisodeID) {
							$rStreamIDs[] = $rEpisodeID;
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
		$rOld = @igbinary_unserialize((string) @file_get_contents(CACHE_TMP_PATH . 'lines_per_ip'));
		$this->write(CACHE_TMP_PATH . 'lines_per_ip', igbinary_serialize(self::linesPerIp($db, is_array($rOld) ? $rOld : null, time(), CACHE_TMP_PATH . 'lines_per_ip_all')));
	}

	private function generateTheftDetection(): void {
		global $db;
		$rOld = @igbinary_unserialize((string) @file_get_contents(CACHE_TMP_PATH . 'theft_detection'));
		$this->write(CACHE_TMP_PATH . 'theft_detection', igbinary_serialize(self::theftDetection($db, is_array($rOld) ? $rOld : null, time(), CACHE_TMP_PATH . 'theft_detection_all')));
	}

	/** How often the All Time range of the two reports reads every closed connection again. */
	private const ALL_TIME_SEC = 3600;

	/** Line IP Usage: per line, the addresses it used in the last hour, day, week and all time. */
	public static function linesPerIp(object $db, ?array $rOld, int $rNow, string $rMarker): array {
		return self::report($db, 'COUNT(DISTINCT(`lines_activity`.`user_ip`)) AS `ip_count`', '', 'ip_count', $rOld, $rNow, $rMarker);
	}

	/** VOD Theft Detection: per line, the movies and episodes it played in the last hour, day, week and all time. */
	public static function theftDetection(object $db, ?array $rOld, int $rNow, string $rMarker): array {
		return self::report($db, 'COUNT(DISTINCT(`lines_activity`.`stream_id`)) AS `vod_count`', ' AND `stream_id` IN (SELECT `id` FROM `streams` WHERE `type` IN (2,5))', 'vod_count', $rOld, $rNow, $rMarker);
	}

	/**
	 * Whether the All Time range is due: its marker is ALL_TIME_SEC old or missing.
	 * The marker records the attempt, so a recompute that is killed is not tried
	 * again by every pass.
	 */
	public static function allTimeDue(string $rMarker, int $rNow, int $rEvery = self::ALL_TIME_SEC): bool {
		clearstatcache(true, $rMarker);
		if (is_file($rMarker) && $rNow - (int) filemtime($rMarker) < $rEvery) {
			return false;
		}
		@touch($rMarker, $rNow);
		return true;
	}

	/**
	 * One of the two reports, by window (seconds back; 0 is all time). The All Time
	 * range reads every closed connection, so it is taken from $rOld unless it is
	 * due, and kept from $rOld when it cannot be read again.
	 */
	private static function report(object $db, string $rCount, string $rWhere, string $rOrder, ?array $rOld, int $rNow, string $rMarker): array {
		$rReport = [3600 => [], 86400 => [], 604800 => [], 0 => []];
		foreach (array_keys($rReport) as $rTime) {
			if ($rTime === 0 && !self::allTimeDue($rMarker, $rNow) && is_array($rOld[0] ?? null)) {
				$rReport[0] = $rOld[0];
				continue;
			}
			$rSince = $rTime > 0 ? '`date_start` >= ? AND ' : '';
			$rOk = $db->query('SELECT `lines_activity`.`user_id`, ' . $rCount . ', `lines`.`username` FROM `lines_activity` LEFT JOIN `lines` ON `lines`.`id` = `lines_activity`.`user_id` WHERE ' . $rSince . '`lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0' . $rWhere . ' GROUP BY `lines_activity`.`user_id` ORDER BY `' . $rOrder . '` DESC LIMIT 1000;', ...($rTime > 0 ? [$rNow - $rTime] : []));
			if (!$rOk && $rTime === 0) {
				@unlink($rMarker);
				$rReport[0] = is_array($rOld[0] ?? null) ? $rOld[0] : [];
				continue;
			}
			foreach (($rOk ? $db->get_rows() : []) ?: [] as $rRow) {
				$rReport[$rTime][] = $rRow;
			}
		}
		return $rReport;
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

	/**
	 * A write that fails marks the run failed. A line's lookup is named after its
	 * credentials ($rLookup), which can hold '/' or be too long for a file name: such
	 * a lookup never exists (the line signs in from the database), and does not count.
	 */
	private function write(string $rPath, string $rData, bool $rLookup = false): void {
		if (!FileCache::writeAtomic($rPath, $rData)) {
			echo 'Cache write failed: ' . $rPath . "\n";
			$rName = substr($rPath, strlen(LINES_TMP_PATH));
			if (!$rLookup || (!str_contains($rName, '/') && strlen('.' . $rName . '.' . getmypid() . '.tmp') <= 255)) {
				$this->markFailed();
			}
		}
	}

	/**
	 * End of a full run. A failed one (a worker could not read the database or write
	 * a file) leaves last_cache where the last good run put it, so the Cache page
	 * does not show a fresh time over a stale cache, and says so in Panel Logs.
	 */
	private function finishRun(bool $rFailed, int $rStartTime): void {
		global $db;
		if ($rFailed) {
			FileLogger::log('cron', 'A cache engine run failed: a worker could not read the database or write a cache file', 'cron:cache_engine');
		} else {
			echo 'Cache updated!' . "\n";
		}
		$this->write(CACHE_TMP_PATH . 'cache_complete', (string) time());
		CacheRunState::finished(CACHE_TMP_PATH, $rFailed);
		if (!$rFailed) {
			$db->query('UPDATE `settings` SET `last_cache` = ?, `last_cache_taken` = ?;', time(), time() - $rStartTime);
		}
	}

	/** Tell the parent run a worker's database read or file write failed (CacheRunState::FAILED). */
	private function markFailed(): void {
		@touch(CACHE_TMP_PATH . CacheRunState::FAILED);
	}

	public function shutdown(): void {
		global $db;
		if (is_object($db)) {
			$db->close_mysql();
		}
	}
}

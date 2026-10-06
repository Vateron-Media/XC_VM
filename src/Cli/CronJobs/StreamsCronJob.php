<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\AgentConnections;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSorter;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Streaming\Codec\FFprobeRunner;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * StreamsCronJob — streams cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:streams';
	}

	public function getDescription(): string {
		return 'Cron: check live streams, monitors, on-demand, rogue PIDs';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Live Checker]');
		$this->loadCron();

		return 0;
	}

	/**
	 * Whether handing a stream (whose watchdog is gone) to the fanout supervisor
	 * must restart its producer rather than adopt it.
	 *
	 * An ffmpeg producer's feed into the daemon is a tee slave that, once broken
	 * by a daemon restart, stays broken for the life of the process — adopting it
	 * would leave the daemon's viewers on dead air until a stall restart. The
	 * native remuxer redials the ingest socket by itself, so it is adopted and
	 * the channel does not blink.
	 */
	private function handOverNeedsRestart(array $rStream): int {
		$rPID = file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.pid') ? intval(@file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.pid')) : intval($rStream['pid']);
		if (!ProcessManager::isStreamRunning($rPID, $rStream['stream_id'])) {
			return 0; // nothing running: a plain start
		}
		$rExe = basename((string) @readlink('/proc/' . $rPID . '/exe'));
		return (strpos($rExe, 'ffmpeg') === 0 && FanoutClient::daemonStreamMissing(intval($rStream['stream_id']))) ? 1 : 0;
	}

	/**
	 * Fold the producer's CPU, memory and kind into the stream's progress JSON —
	 * what the admin streams list shows per stream.
	 *
	 * Only this node can read its own /proc, so the reading is taken here and
	 * travels to the panel in the row the cron already writes. CPU is the
	 * difference against this pass's predecessor, so it is the average over the
	 * last minute rather than ffmpeg's lifetime average — and where there is no
	 * usable predecessor (the producer's first pass, or a new pid after a
	 * restart) the lifetime average stands in, so the column never waits a pass
	 * to show a figure.
	 *
	 * The previous reading is kept beside the stream's files (`<id>_.usage`, on
	 * the streams tmpfs, removed with the rest of `<id>_*` when it stops) rather
	 * than in the database row: it is this node's bookkeeping, and a value that
	 * has to survive a round trip through a row other code also rewrites is one
	 * that sometimes does not.
	 *
	 * @param string $rProgressJson This pass's progress report.
	 * @param int    $rStreamID     The stream.
	 * @param int    $rPID          The producer's pid.
	 * @return string The JSON to store.
	 */
	private function withResourceUsage(string $rProgressJson, int $rStreamID, int $rPID): string {
		$rProgress = json_decode($rProgressJson, true);
		if (!is_array($rProgress)) {
			$rProgress = [];
		}
		// Keys an earlier version kept in the row for the CPU difference.
		unset($rProgress['cpu_t'], $rProgress['cpu_at']);

		$rSamplePath = STREAMS_PATH . $rStreamID . '_.usage';
		$rSample = ProcessManager::resourceSample($rPID);
		if ($rSample === null) {
			@unlink($rSamplePath);
			unset($rProgress['cpu'], $rProgress['mem'], $rProgress['producer']);
			return json_encode($rProgress);
		}

		$rCPU = null;
		$rPrevious = json_decode((string) @file_get_contents($rSamplePath), true);
		if (is_array($rPrevious) && intval($rPrevious['pid'] ?? 0) === $rPID) {
			$rCPU = ProcessManager::cpuPercent($rSample, $rPrevious);
		}
		if ($rCPU === null) {
			$rCPU = ProcessManager::cpuPercentSinceStart($rSample);
		}
		@file_put_contents($rSamplePath, json_encode(['pid' => $rPID, 'ticks' => $rSample['ticks'], 'at' => $rSample['at']]));

		$rProgress['cpu'] = $rCPU;
		$rProgress['mem'] = $rSample['rss'];
		$rProgress['producer'] = ProcessManager::producerKind($rPID);

		return json_encode($rProgress);
	}

	private function loadCron(): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		global $db;

		if (!ProcessManager::isNginxRunning()) {
			echo 'XC_VM not running...' . "\n";
		}

		// What the node's store kept while the agent took no event (mode 2),
		// first: a seed waits for it (a no-op without STREAMS or the agent).
		StreamStateWriter::resend();
		// This node's streams from its replica and its own store (mode 1 or 2
		// with STREAMS on): an on-demand stream's viewers then come from the
		// agent's registry with CONNECTIONS on, not MAIN's Redis.
		$rLocal = StreamSource::local();
		$rAgentViewers = $rLocal && AgentConnections::enabled();

		if ($rRedis && !$rAgentViewers) {
			RedisManager::ensureConnected();
		}

		$rActivePIDs = [];
		$rStreamIDs = [];

		// Bring streams_servers in step with the fanout supervisor first, so the
		// pass below reads what is actually running. $rSupervised is the set it
		// is supervising, or null when it cannot be asked — unknown, which the
		// checks below treat as "not supervised" exactly as before this existed.
		$rStates = FanoutClient::monitorStates();
		$rSupervised = StreamProcess::reconcileSupervised($rStates);
		// Not read is not none: the daemon answered, the rows it is compared
		// with did not. The checks below would take each stream it supervises
		// for unwatched and start a monitor for it, so the pass ends here.
		if ($rStates !== null && $rSupervised === null) {
			echo 'The supervised streams could not be read: nothing is checked this pass.' . "\n";
			return;
		}
		$rSupervisedSet = array_flip($rSupervised ?? []);
		// While the daemon takes hand-overs, streams still under a PHP monitor
		// (started before supervision was on, or while the daemon was down) are
		// moved to it — adopting their running encoder, so they do not restart.
		$rMigrate = $rStates !== null && !empty($rStates['accepting']) && StreamProcess::supervisionEnabled();

		// Past a fence's drain the node releases its producers (plan, section 9,
		// FENCED). Their rows keep their state, so the first pass after the fence
		// lifts starts them again; until then startMonitor() starts nothing.
		$rFenced = NodeLease::refusesEverything();

		$rRows = NodeStreams::liveChecks($rRedis, $db);
		// Not read is not none: the checks at the end kill every monitor and
		// producer the lists do not name, so a pass that could not read them
		// ends here and does nothing by them.
		if ($rRows === null) {
			echo 'The streams could not be read: nothing is checked this pass.' . "\n";
			return;
		}
		if (count($rRows) > 0) {
			foreach ($rRows as $rStream) {
				echo 'Stream ID: ' . $rStream['stream_id'] . "\n";
				$rStreamIDs[] = $rStream['stream_id'];

				$rIsSupervised = isset($rSupervisedSet[intval($rStream['stream_id'])]);
				if ($rFenced) {
					if ($rIsSupervised || ProcessManager::isMonitorAlive($rStream['monitor_pid'], $rStream['stream_id']) || ProcessManager::isStreamRunning(intval($rStream['pid']), $rStream['stream_id'])) {
						echo 'Fenced: releasing the producer...' . "\n\n";
						StreamProcess::stopStream(intval($rStream['stream_id']));
					}
					// Its thumbnail and TV archive workers read the stream: they go with
					// it, and the first pass after the fence lifts starts them again.
					foreach (['Thumbnail' => 'vframes_pid', 'TVArchive' => 'tv_archive_pid'] as $rWorker => $rColumn) {
						if (ProcessManager::isNamedProcessRunning(intval($rStream[$rColumn] ?? 0), $rWorker, $rStream['stream_id'])) {
							echo 'Fenced: releasing its ' . $rWorker . ' worker...' . "\n";
							posix_kill(intval($rStream[$rColumn]), 9);
						}
					}
					continue;
				}
				// superviseStream, not startMonitor: a stream the daemon will not
				// take (delay, created channels) must keep the PHP monitor it has,
				// not have a second one spawned beside it every pass.
				if ($rMigrate && !$rIsSupervised && ProcessManager::isMonitorAlive($rStream['monitor_pid'], $rStream['stream_id'])) {
					if (StreamProcess::superviseStream(intval($rStream['stream_id']), false)) {
						echo 'Handed over to the fanout supervisor.' . "\n\n";
						continue;
					}
				}
				if ($rIsSupervised || ProcessManager::isMonitorAlive($rStream['monitor_pid'], $rStream['stream_id']) || $rStream['on_demand']) {
					if ($rStream['on_demand'] == 1 && $rStream['attached'] == 0) {
						if ($rAgentViewers) {
							$rStream['online_clients'] = NodeStreams::viewers([intval($rStream['stream_id'])])[intval($rStream['stream_id'])] ?? 0;
						} elseif ($rRedis) {
							// Unknown is never none: a Redis that cannot be asked keeps
							// the stream running, as the agent's registry does.
							$rStream['online_clients'] = self::redisViewers(intval($rStream['stream_id'])) ?? 1;
						}

						$rAdminQueue = $rQueue = 0;
						if (SettingsManager::getBool('on_demand_instant_off') && file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id']))) {
							foreach ((igbinary_unserialize(file_get_contents(SIGNALS_TMP_PATH . 'queue_' . intval($rStream['stream_id']))) ?: []) as $rPID) {
								if (ProcessManager::isRunning($rPID, 'php-fpm')) {
									$rQueue++;
								}
							}
						}
						if (file_exists(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']))) {
							if (time() - filemtime(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id'])) <= 30) {
								$rAdminQueue = 1;
							} else {
								unlink(SIGNALS_TMP_PATH . 'admin_' . intval($rStream['stream_id']));
							}
						}
						if ($rQueue == 0 && $rAdminQueue == 0 && $rStream['online_clients'] == 0 && (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.m3u8') || SettingsManager::getInt('on_demand_wait_time') < time() - intval($rStream['stream_started']) || $rStream['stream_status'] == 1)) {
							echo 'Stop on-demand stream...' . "\n\n";
							StreamProcess::stopStream($rStream['stream_id'], true);
							// Stopped: nothing below applies (it would start a thumbnail
							// and a TV archive worker for the stream just stopped).
							continue;
						}
					}

					if ($rStream['vframes_server_id'] == SERVER_ID && !ProcessManager::isNamedProcessRunning($rStream['vframes_pid'], 'Thumbnail', $rStream['stream_id'])) {
						echo 'Start Thumbnail...' . "\n";
						StreamProcess::startThumbnail($rStream['stream_id']);
					}
					if ($rStream['tv_archive_server_id'] == SERVER_ID && !ProcessManager::isNamedProcessRunning($rStream['tv_archive_pid'], 'TVArchive', $rStream['stream_id'])) {
						echo 'Start TV Archive...' . "\n";
						shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php archive ' . intval($rStream['stream_id']) . ' >/dev/null 2>/dev/null & echo $!');
					}

					foreach (glob(STREAMS_PATH . $rStream['stream_id'] . '_*.ts.enc') as $rFile) {
						if (!file_exists(rtrim($rFile, '.enc'))) {
							unlink($rFile);
						}
					}

					if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.pid')) {
						$rPID = intval(file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.pid'));
					} else {
						$rPID = intval(shell_exec("ps aux | grep -v grep | grep '/" . intval($rStream['stream_id']) . "_.m3u8' | awk '{print \$2}'"));
					}
					$rActivePIDs[] = intval($rPID);

					$rPlaylist = STREAMS_PATH . $rStream['stream_id'] . '_.m3u8';
					if (ProcessManager::isStreamRunning($rPID, $rStream['stream_id']) && file_exists($rPlaylist)) {
						// Re-feed after a daemon restart (ADR 0003, C-ops). This
						// stream's ffmpeg is running and teeing into the daemon,
						// but if the daemon restarted it wiped its in-memory
						// registry and `onfail=ignore` silently dropped the tee
						// slave — the daemon no longer knows the stream
						// (control /streams/<id> → 404) and delivery has quietly
						// fallen back to legacy. Restart it so buildLive
						// re-registers the ingest and the daemon serves it again.
						// Guard: only a reachable-daemon 404 (daemonStreamMissing),
						// so a stopped daemon leaves legacy alone; throttled by a
						// stamp so a stream whose ingest keeps failing is not
						// restart-looped every cron tick. Proxy streams have no
						// local ffmpeg, so they never reach this running branch.
						// Only an ffmpeg producer needs the restart: a broken tee slave
						// stays broken. The PHP relays (LLOD, loopback) and a delayed
						// stream's DelayCommand re-register and redial the daemon by
						// themselves (IngestFeeder), and restarting a delayed stream
						// would throw its buffer away.
						$rSelfFeeding = intval($rStream['delay_minutes'] ?? 0) > 0 || ProcessManager::producerKind($rPID) === 'php';
						if (!$rSelfFeeding && FanoutClient::daemonStreamMissing($rStream['stream_id'])) {
							// The stamp lives outside STREAMS_PATH: the restart below
							// runs `rm -f <id>_*` there, which deleted a `<id>_.refeed`
							// stamp — and with it the 120 s throttle it was meant to be.
							$rRefeedStamp = SIGNALS_TMP_PATH . 'refeed_' . intval($rStream['stream_id']);
							if (!file_exists($rRefeedStamp) || time() - filemtime($rRefeedStamp) > 120) {
								echo 'Daemon lost stream ' . $rStream['stream_id'] . ' (restarted) — re-feeding...' . "\n\n";
								touch($rRefeedStamp);
								StreamProcess::startMonitor($rStream['stream_id'], 1);
								continue;
							}
						}

						echo 'Update Stream Information...' . "\n";
						$rBitrate = StreamUtils::getStreamBitrate('live', STREAMS_PATH . $rStream['stream_id'] . '_.m3u8');
						$rProgressPath = STREAMS_PATH . $rStream['stream_id'] . '_.progress';
						if (file_exists($rProgressPath)) {
							// ffmpeg appends key=value progress reports to this file
							// for the stream's whole life. Read only the tail, keep
							// the last COMPLETE report block (terminated by a
							// "progress=" line) and re-encode it as the JSON the rest
							// of the panel expects. Then truncate so the file stays
							// tiny on disk (ffmpeg keeps its write offset, so the
							// hole left behind is sparse).
							$rTail = '';
							$rFp = fopen($rProgressPath, 'rb');
							if ($rFp !== false) {
								fseek($rFp, 0, SEEK_END);
								if (ftell($rFp) > 16384) {
									fseek($rFp, -16384, SEEK_END);
								} else {
									rewind($rFp);
								}
								$rTail = stream_get_contents($rFp);
								fclose($rFp);
							}
							$rReport = [];
							$rCurrentReport = [];
							foreach (explode("\n", (string) $rTail) as $rLine) {
								$rLine = trim($rLine);
								if ($rLine === '') {
									continue;
								}
								$rKV = explode('=', $rLine, 2);
								if (count($rKV) !== 2) {
									continue;
								}
								$rReportKey = trim($rKV[0]);
								$rCurrentReport[$rReportKey] = trim($rKV[1]);
								if ($rReportKey === 'progress') {
									$rReport = $rCurrentReport;
									$rCurrentReport = [];
								}
							}
							$rProgress = $rReport !== [] ? json_encode($rReport) : ($rStream['progress_info'] ?: json_encode([]));
							file_put_contents($rProgressPath, '');
							if ($rStream['fps_restart']) {
								file_put_contents(STREAMS_PATH . $rStream['stream_id'] . '_.progress_check', $rProgress);
							}
						} else {
							$rProgress = $rStream['progress_info'];
						}
						$rProgress = $this->withResourceUsage((string) $rProgress, intval($rStream['stream_id']), $rPID);
						// A supervised stream's codecs, resolution and bitrate come
						// from the daemon, measured off the bytes (reconcileSupervised
						// wrote them above); recomputing them here from a stream_info
						// that nothing probes any more would blank them. Only the
						// producer's progress report is this pass's to record.
						if ($rIsSupervised) {
							if ($rProgress !== $rStream['progress_info']) {
								StreamStateWriter::updateRow(intval($rStream['server_stream_id']), ['progress_info' => $rProgress], $db);
							}
							echo "\n";
							continue;
						}
						if (file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info')) {
							$rStreamInfo = file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info');
							unlink(STREAMS_PATH . $rStream['stream_id'] . '_.stream_info');
						} else {
							$rStreamInfo = $rStream['stream_info'];
						}
						$rCompatible = 0;
						$rAudioCodec = $rVideoCodec = $rResolution = null;
						if ($rStreamInfo) {
							$rStreamJSON = json_decode($rStreamInfo, true);
							$rCompatible = intval(DiagnosticsService::checkCompatibility($rStreamJSON, SettingsManager::getBool('player_allow_hevc')));
							if (is_array($rStreamJSON) && isset($rStreamJSON['codecs']) && is_array($rStreamJSON['codecs'])) {
								$rAudioCodec = isset($rStreamJSON['codecs']['audio']['codec_name']) ? $rStreamJSON['codecs']['audio']['codec_name'] : null;
								$rVideoCodec = isset($rStreamJSON['codecs']['video']['codec_name']) ? $rStreamJSON['codecs']['video']['codec_name'] : null;
								$rResolution = isset($rStreamJSON['codecs']['video']['height']) ? $rStreamJSON['codecs']['video']['height'] : null;
							}
							if ($rResolution) {
								$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
							}
						}
						if ($rStream['pid'] != $rPID) {
							StreamStateWriter::updateRow(intval($rStream['server_stream_id']), ['pid' => $rPID, 'progress_info' => $rProgress, 'stream_info' => $rStreamInfo, 'compatible' => $rCompatible, 'bitrate' => $rBitrate, 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution], $db);
						} else {
							StreamStateWriter::updateRow(intval($rStream['server_stream_id']), ['progress_info' => $rProgress, 'stream_info' => $rStreamInfo, 'compatible' => $rCompatible, 'bitrate' => $rBitrate, 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution], $db);
						}
					}
					echo "\n";
				} else {
					echo 'Start monitor...' . "\n\n";
					// A producer still running is the stream's own: the new monitor
					// takes it as it is (it reads the same pid), so the last check of
					// this pass does not take it for a leftover.
					$rActivePIDs[] = file_exists(STREAMS_PATH . $rStream['stream_id'] . '_.pid') ? intval(file_get_contents(STREAMS_PATH . $rStream['stream_id'] . '_.pid')) : intval($rStream['pid']);
					if (StreamProcess::startMonitor($rStream['stream_id'], $this->handOverNeedsRestart($rStream)) === StreamProcess::MONITOR_PHP) {
						usleep(50000); // stagger PHP monitor spawns
					}
				}
			}
		}

		$rProxied = NodeStreams::proxied($db);
		if (count($rProxied) > 0) {
			foreach ($rProxied as $rStream) {
				if (file_exists(STREAMS_PATH . $rStream['id'] . '.analyse')) {
					$rFFProbeOutput = FFprobeRunner::probeStream(STREAMS_PATH . $rStream['id'] . '.analyse');
					// Defaults: the UPDATE below runs even when probing fails or
					// the output has no codec info, so these must always be set.
					$rBitrate = $rCompatible = $rAudioCodec = $rVideoCodec = $rResolution = null;
					if ($rFFProbeOutput) {
						$rBitrate = $rFFProbeOutput['bitrate'] / 1024;
						$rCompatible = intval(DiagnosticsService::checkCompatibility($rFFProbeOutput, SettingsManager::getBool('player_allow_hevc')));
						if (is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']) && is_array($rFFProbeOutput['codecs'])) {
							$rAudioCodec = isset($rFFProbeOutput['codecs']['audio']['codec_name']) ? $rFFProbeOutput['codecs']['audio']['codec_name'] : null;
							$rVideoCodec = isset($rFFProbeOutput['codecs']['video']['codec_name']) ? $rFFProbeOutput['codecs']['video']['codec_name'] : null;
							$rResolution = isset($rFFProbeOutput['codecs']['video']['height']) ? $rFFProbeOutput['codecs']['video']['height'] : null;
						}
						if ($rResolution) {
							$rResolution = StreamSorter::getNearest([240, 360, 480, 576, 720, 1080, 1440, 2160], $rResolution);
						}
					}
					echo 'Stream ID: ' . $rStream['id'] . "\n";
					echo 'Update Stream Information...' . "\n";
					StreamStateWriter::update(intval($rStream['id']), intval(SERVER_ID), ['bitrate' => $rBitrate, 'stream_info' => json_encode($rFFProbeOutput), 'audio_codec' => $rAudioCodec, 'video_codec' => $rVideoCodec, 'resolution' => $rResolution, 'compatible' => $rCompatible], $db);
				}

				// With the agent's registry, each viewer's socket is checked there
				// (one it could not answer for stays).
				$rUUIDs = $rAgentViewers ? null : $this->connectionUuidsForStream(ConnectionTracker::getConnections(SERVER_ID, null, $rStream['id']), $rStream['id']);

				$rConDir = CONS_TMP_PATH . $rStream['id'] . '/';
				// The per-stream connection dir only exists once a client connects,
				// so its absence is normal — guard with is_dir() to avoid a bogus
				// opendir() warning being logged every cron tick on idle streams.
				if (is_dir($rConDir) && ($rHandle = opendir($rConDir))) {
					while (false !== ($rFilename = readdir($rHandle))) {
						if ($rFilename != '.' && $rFilename != '..') {
							if ($rUUIDs === null ? AgentConnections::get($rFilename) === false : !in_array($rFilename, $rUUIDs)) {
								unlink(CONS_TMP_PATH . $rStream['id'] . '/' . $rFilename);
							}
						}
					}
					closedir($rHandle);
				}
			}
		}

		$rOnDemandIDs = NodeStreams::onDemandIDs($db);
		if ($rOnDemandIDs === null) {
			echo 'The on-demand streams could not be read: nothing is killed this pass.' . "\n";
			return;
		}
		$rProcesses = shell_exec('ps aux | grep XC_VM');
		if (preg_match_all('/XC_VM\\[(.*)\\]/', $rProcesses, $rMatches)) {
			$rRemove = array_diff($rMatches[1], $rStreamIDs);
			$rRemove = array_diff($rRemove, $rOnDemandIDs);
			foreach ($rRemove as $rStreamID) {
				if (is_numeric($rStreamID)) {
					// A monitor puts its pid in its stream's row, then probes the
					// sources and starts the producer: only from then on do the
					// streams read above name the stream. A monitor its row names is
					// not a leftover while nothing has started, nor once the row says
					// it has and that read would name the stream now (a live stream,
					// not a direct source): it started since, and the next pass has it.
					// The row is read as the monitor and that read take it (nodeRow):
					// in MAIN's database until the node's own store is seeded.
					$rRow = StreamSource::nodeRow(intval($rStreamID), $db);
					if ($rRow !== null && ProcessManager::isMonitorAlive(intval($rRow['monitor_pid'] ?? 0), intval($rStreamID)) && ((($rRow['pid'] ?? null) === null && intval($rRow['stream_status'] ?? 0) === 0) || StreamSource::streamRow(intval($rStreamID), true, $db) !== null)) {
						continue;
					}
					echo 'Kill Stream ID: ' . $rStreamID . "\n";
					shell_exec("kill -9 `ps -ef | grep '/" . intval($rStreamID) . '_.m3u8\\|XC_VM\\[' . intval($rStreamID) . "\\]' | grep -v grep | awk '{print \$2}'`;");
					shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');
				}
			}
		}

		if (SettingsManager::getBool('kill_rogue_ffmpeg')) {
			// The supervisor restarts producers on its own schedule: a pid read from
			// _.pid at the top of this pass may already have been replaced, and the
			// replacement is not rogue. Ask the daemon for what it runs NOW.
			$rStates = FanoutClient::monitorStates();
			foreach (($rStates['streams'] ?? []) as $rState) {
				if (intval($rState['pid'] ?? 0) > 0) {
					$rActivePIDs[] = intval($rState['pid']);
				}
			}
			// A producer that started after this pass did (an on-demand stream a
			// viewer has just opened, a restart by its PHP monitor) was not there
			// when the streams above were read: the next pass judges it. Both
			// start times are /proc's own, in ticks after boot.
			$rPassStart = ProcessManager::resourceSample(intval(getmypid()))['start'] ?? null;
			exec("ps aux | grep -v grep | grep '/*_.m3u8' | awk '{print \$2}'", $rRoguePIDs);
			foreach ($rRoguePIDs as $rPID) {
				if (is_numeric($rPID) && intval($rPID) > 0 && !in_array($rPID, $rActivePIDs)) {
					$rStart = ProcessManager::resourceSample(intval($rPID))['start'] ?? null;
					if ($rPassStart === null || $rStart === null || $rStart >= $rPassStart) {
						continue;
					}
					echo 'Kill Roque PID: ' . $rPID . "\n";
					shell_exec('kill -9 ' . $rPID . ';');
				}
			}
		}
	}

	/**
	 * The connection UUIDs for a stream, filtered to that stream. Tolerates the
	 * differing shapes ConnectionTracker::getConnections() returns — the Redis
	 * [keys, data] pair, the MySQL map grouped by user id, and a single
	 * connection row — which the old flat double-loop assumed away (TypeError).
	 *
	 * @param mixed $rConnections Raw getConnections() result.
	 * @param mixed $rStreamID    Stream id to keep (rows without one are kept).
	 * @return list<string>
	 */
	private function connectionUuidsForStream($rConnections, $rStreamID): array {
		$rUUIDs = [];
		foreach (self::collectUuidRows($rConnections) as $rRow) {
			if (empty($rRow['stream_id']) || $rRow['stream_id'] == $rStreamID) {
				$rUUIDs[] = $rRow['uuid'];
			}
		}
		return $rUUIDs;
	}

	/**
	 * Flatten an arbitrarily nested connection structure to the list of rows
	 * that carry a `uuid`, ignoring non-arrays (e.g. Redis key strings). Pure.
	 *
	 * @param mixed $rNode
	 * @return list<array>
	 */
	private static function collectUuidRows($rNode): array {
		if (!is_array($rNode)) {
			return [];
		}
		if (isset($rNode['uuid'])) {
			return [$rNode];
		}
		$rRows = [];
		foreach ($rNode as $rChild) {
			$rRows = array_merge($rRows, self::collectUuidRows($rChild));
		}
		return $rRows;
	}

	/**
	 * An on-demand stream's viewers on this server in MAIN's Redis, or null
	 * when Redis cannot be asked (not connected, or a call that fails).
	 */
	private static function redisViewers(int $rStreamID): ?int {
		$rRedis = RedisManager::instance();
		if (!$rRedis instanceof \Redis) {
			return null;
		}
		try {
			$rKeys = $rRedis->zRangeByScore('STREAM#' . $rStreamID, '-inf', '+inf');
			if (!is_array($rKeys)) {
				return null;
			}
			if ($rKeys === []) {
				return 0;
			}
			$rRecords = $rRedis->mGet($rKeys);
			if (!is_array($rRecords)) {
				return null;
			}
			$rCount = 0;
			foreach ($rRecords as $rRaw) {
				$rConnection = is_string($rRaw) ? igbinary_unserialize($rRaw) : null;
				if (is_array($rConnection) && ($rConnection['server_id'] ?? null) == SERVER_ID) {
					$rCount++;
				}
			}
			return $rCount;
		} catch (\RedisException) {
			return null;
		}
	}
}

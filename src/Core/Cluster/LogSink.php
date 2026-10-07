<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Logging\FileLogger;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Where a node's log records go: client request logs, stream logs, stream and
 * panel errors, restream detections, and what root did (the system log).
 *
 * The node-side crons (cron:lines_logs, cron:streams_logs, cron:errors) and
 * the cache handler collect records locally, then hand them here by type. The
 * legacy backend writes them into MAIN's database, one multi-row INSERT per
 * batch, which is what the callers used to build by hand. On a node whose
 * LOGS flow is on, they become `log.<type>` events for the agent instead
 * ({@see EventSpool}), redacted first ({@see Redactor}); MAIN's ingest writes
 * them with insert(). The SQL backend does not redact, as before.
 *
 * Root's system log lines (`mysql_syslog`) come through syslog(), which
 * leaves the legacy row to its caller.
 */
final class LogSink {
	/** Rows per INSERT: well under MySQL's placeholder limit for every type. */
	public const CHUNK = 1000;

	/** Values' bytes per `log.*` event: well under MAIN's ingest body, whatever a row carries. */
	public const SPOOL_BYTES = 1048576;

	/**
	 * type => [table, columns, INSERT IGNORE].
	 *
	 * @var array<string, array{0: string, 1: list<string>, 2: bool}>
	 */
	public const TYPES = [
		'client'       => ['lines_logs', ['stream_id', 'user_id', 'client_status', 'query_string', 'user_agent', 'ip', 'extra_data', 'date'], false],
		'stream'       => ['streams_logs', ['stream_id', 'server_id', 'action', 'source', 'date'], false],
		'stream_error' => ['streams_errors', ['stream_id', 'server_id', 'date', 'error'], false],
		'panel_error'  => ['panel_logs', ['server_id', 'type', 'log_message', 'log_extra', 'line', 'date', 'file', 'env', 'version', 'unique'], true],
		'restream'     => ['detect_restream_logs', ['user_id', 'stream_id', 'ip', 'time'], false],
		'syslog'       => ['mysql_syslog', ['server_id', 'type', 'error', 'username', 'ip', 'database', 'date'], false],
		'ondemand_check' => ['ondemand_check', ['stream_id', 'server_id', 'status', 'source_id', 'source_url', 'fps', 'video_codec', 'audio_codec', 'resolution', 'response', 'errors', 'date'], false],
		'activity'     => ['lines_activity', ['server_id', 'proxy_id', 'user_id', 'isp', 'external_device', 'stream_id', 'date_start', 'user_agent', 'user_ip', 'date_end', 'container', 'geoip_country_code', 'divergence', 'hmac_id', 'hmac_identifier'], false],
	];

	/**
	 * `syslog`: the types a node's root side writes (RootSignalsCronJob, with
	 * CONFIG for its credential actions, CLUSTER for the guided cutover's
	 * seed_connections, and ARTEFACT for an artefact it refused,
	 * ArtefactStage), and the only ones
	 * MAIN takes from a node. Not `AUTH`: no node writes it, and old rows of
	 * that type on MAIN are MariaDB's own refused sign-ins.
	 */
	public const SYSLOG_TYPES = ['FLUSH', 'REBOOT', 'OPENSSL_EXTRA', 'RESTART', 'STOP', 'RELOAD', 'CERTBOT', 'BINARIES', 'MODULE', 'UPDATE', 'PHP-FPM', 'ARTEFACT', 'CONFIG', 'CLUSTER'];

	/** @var (callable(string, list<array<string, mixed>>, ?object): bool)|null */
	private static $rSink;

	/**
	 * Write records of one type. Each row maps the type's columns to values; a
	 * missing column is written as NULL.
	 *
	 * @param list<array<string, mixed>> $rRows
	 * @return bool True when every row was written.
	 */
	public static function write(string $rType, array $rRows, ?object $rDb = null): bool {
		if (!isset(self::TYPES[$rType])) {
			throw new \InvalidArgumentException('Unknown log type: ' . $rType);
		}
		if ($rRows === []) {
			return true;
		}
		if (self::$rSink !== null) {
			return (bool) (self::$rSink)($rType, array_values($rRows), $rDb);
		}
		if (NodeFlows::on(NodeFlows::LOGS) && self::spool($rType, array_values($rRows))) {
			return true;
		}
		return self::insert($rType, $rRows, $rDb);
	}

	/**
	 * A line for MAIN's system log (`mysql_syslog`, the System Logs page)
	 * about what this node's root side did: a root action, a PHP-FPM
	 * restart. With the LOGS flow on it becomes a redacted `log.syslog`
	 * event on P1, as root on this node, and MAIN writes the row for it
	 * (EventIngest). A node in mode 2 never writes MAIN's database (its
	 * connect is refused): a line the spool does not take (the agent
	 * stopped, or LOGS off) goes to the panel's error log only (dropped()).
	 * Either way the action it records runs after this, whatever became of
	 * the line.
	 *
	 * @return bool False when the caller writes the row itself, as before:
	 *              MAIN, a node in mode 0, and one in mode 1 whose LOGS flow
	 *              is off or whose agent stopped.
	 */
	public static function syslog(string $rType, string $rError, ?int $rTime = null): bool {
		$rRow = ['server_id' => defined('SERVER_ID') ? (int) SERVER_ID : 0, 'type' => $rType, 'error' => $rError, 'username' => 'root', 'ip' => 'localhost', 'database' => null, 'date' => $rTime ?? time()];
		if (NodeFlows::on(NodeFlows::LOGS) && self::spool('syslog', [$rRow])) {
			return true;
		}
		if (!NodeRole::refusesConnects()) {
			return false;
		}
		self::dropped($rType, $rError);
		return true;
	}

	/**
	 * A system log line a node in mode 2 could not hand to its agent, kept
	 * in the panel's error log (FileLogger: `LOGS_TMP_PATH/error_log.log`),
	 * which cron:errors sends on as a `log.panel_error` once the agent takes
	 * events again. Redacted. Root writes it as the owner of the agent's
	 * directory (SettingsAudit::asAgentUser): the logs directory is xc_vm's,
	 * where root neither creates a file of its own nor follows a link. PHP's
	 * error_log (a cron's stderr) only when root cannot switch.
	 */
	private static function dropped(string $rType, string $rError): void {
		$rLine = 'Not in MAIN\'s system log (mode 2, and the agent took no event): ' . $rType . ': ' . Redactor::redact($rError);
		$rKept = SettingsAudit::asAgentUser(static function () use ($rLine): bool {
			FileLogger::log('syslog', $rLine);
			return true;
		}, dirname(rtrim(EventSpool::dir(), '/')));
		if (!$rKept) {
			error_log('XC_VM ' . $rLine);
		}
	}

	/**
	 * The SQL backend: MAIN's own writes, a legacy node's, and MAIN's ingest of
	 * a node's `log.*` events.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	public static function insert(string $rType, array $rRows, ?object $rDb = null): bool {
		[$rTable, $rColumns, $rIgnore] = self::TYPES[$rType];
		$rDb ??= DatabaseFactory::get();
		$rOK = true;
		foreach (array_chunk(array_values($rRows), self::CHUNK) as $rChunk) {
			$rTuple = '(' . implode(',', array_fill(0, count($rColumns), '?')) . ')';
			$rParams = [];
			foreach ($rChunk as $rRow) {
				foreach ($rColumns as $rColumn) {
					$rParams[] = $rRow[$rColumn] ?? null;
				}
			}
			$rSql = 'INSERT ' . ($rIgnore ? 'IGNORE ' : '') . 'INTO `' . $rTable . '` (`' . implode('`,`', $rColumns) . '`) VALUES ' . implode(',', array_fill(0, count($rChunk), $rTuple)) . ';';
			$rWritten = (bool) $rDb->query($rSql, ...$rParams);
			// Viewer activity is also each line's "last seen": the rows and that
			// update belong together, wherever they are written from, and an
			// update that failed fails the write (inside a node's event batch
			// a deadlock on `lines` rolled back the batch's rows with it).
			if ($rWritten && $rType === 'activity') {
				$rWritten = self::lastActivity($rChunk, (int) $rDb->last_insert_id(), $rDb);
			}
			$rOK = $rWritten && $rOK;
		}
		return $rOK;
	}

	/**
	 * Point each line at its newest activity row, as cron:activity did when it
	 * built the INSERT itself. A multi-row INSERT reports its first id and the
	 * rest follow consecutively. Lines are updated in id order so concurrent
	 * nodes lock alike, and `updated` is kept so the line cache is not
	 * rebuilt for it (it holds none of these columns).
	 *
	 * @param list<array<string, mixed>> $rRows One chunk, as it was inserted.
	 * @return bool False when the update failed.
	 */
	private static function lastActivity(array $rRows, int $rFirstID, object $rDb): bool {
		if ($rFirstID <= 0) {
			return true;
		}
		$rLast = [];
		foreach (array_values($rRows) as $i => $rRow) {
			$rUserID = (int) ($rRow['user_id'] ?? 0);
			if ($rUserID > 0) {
				$rLast[$rUserID] = [$rFirstID + $i, $rRow];
			}
		}
		if ($rLast === []) {
			return true;
		}
		ksort($rLast);

		$rIPs = $rIDs = $rArrays = '';
		$rIPParams = $rArrayParams = [];
		foreach ($rLast as $rUserID => [$rActivityID, $rRow]) {
			$rIPs .= ' WHEN ' . $rUserID . ' THEN ?';
			$rIPParams[] = (string) ($rRow['user_ip'] ?? '');
			$rIDs .= ' WHEN ' . $rUserID . ' THEN ' . $rActivityID;
			$rArrays .= ' WHEN ' . $rUserID . ' THEN ?';
			$rArrayParams[] = (string) json_encode(['date_end' => $rRow['date_end'] ?? null, 'stream_id' => $rRow['stream_id'] ?? null]);
		}
		return (bool) $rDb->query(
			'UPDATE `lines` SET `last_ip` = CASE `id`' . $rIPs . ' END, `last_activity` = CASE `id`' . $rIDs
			. ' END, `last_activity_array` = CASE `id`' . $rArrays . ' END, `updated` = `updated` WHERE `id` IN ('
			. implode(',', array_keys($rLast)) . ');',
			...[...$rIPParams, ...$rArrayParams]
		);
	}

	/**
	 * The cluster API backend (LOGS flow on): redacted `log.<type>` events on
	 * the agent's P1 lane, in chunks of CHUNK rows and SPOOL_BYTES of values.
	 *
	 * The byte budget is what keeps one event a request MAIN will take: a
	 * caller's batch is bounded in rows, not in size (a user agent, a query
	 * string or an ffprobe error is as long as the source made it), and a
	 * thousand oversized rows in one event would be tens of megabytes to
	 * redact, serialise and post. A single row over the budget is still an
	 * event of its own: it is the row or nothing.
	 *
	 * @param list<array<string, mixed>> $rRows
	 */
	private static function spool(string $rType, array $rRows): bool {
		$rColumns = array_flip(self::TYPES[$rType][1]);
		$rEvents = [];
		$rRedacted = [];
		$rBytes = 0;

		foreach ($rRows as $rRow) {
			$rRow = Redactor::redactRow(array_intersect_key($rRow, $rColumns));
			$rSize = 0;
			foreach ($rRow as $rValue) {
				$rSize += is_string($rValue) ? strlen($rValue) : 8;
			}
			if ($rRedacted !== [] && (count($rRedacted) >= self::CHUNK || $rBytes + $rSize > self::SPOOL_BYTES)) {
				$rEvents[] = ['type' => 'log.' . $rType, 'd' => ['rows' => $rRedacted]];
				$rRedacted = [];
				$rBytes = 0;
			}
			$rRedacted[] = $rRow;
			$rBytes += $rSize;
		}
		if ($rRedacted !== []) {
			$rEvents[] = ['type' => 'log.' . $rType, 'd' => ['rows' => $rRedacted]];
		}

		return EventSpool::append('p1', $rEvents);
	}

	/** Replace the backend (tests). Null restores the default: events when LOGS is on, else SQL. */
	public static function useSink(?callable $rSink): void {
		self::$rSink = $rSink;
	}
}

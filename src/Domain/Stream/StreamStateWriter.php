<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Cluster\StreamStateFields;

/**
 * Stream State Writer
 *
 * The one way a node records the runtime state of a stream it runs: process
 * ids, status, probe results, progress. Everything else in `streams_servers`
 * (what should run where, parents, on-demand, …) is desired state and belongs
 * to MAIN. Callers used to UPDATE the row directly from ~30 places; they now
 * pass the fields to update() or updateRow(), which refuse any column outside
 * STATE_FIELDS, and the writer applies them through a sink.
 *
 * On a legacy node the writer merges into the row in MAIN's database through
 * StreamRowMerge, as before. On a node whose STREAMS flow is on it sends a
 * `stream.state` event through the agent instead ({@see EventSpool}), which
 * MAIN merges into that node's own row (EventIngest), and keeps the fields
 * in the node's own store ({@see StreamRuntime}), under one lock, so the
 * node's readers take them from there. When the agent takes no event (it
 * stopped), a node that may reach MAIN's database writes its row as before
 * (and its store lapses: MAIN's row alone is then whole); a node in mode 2
 * keeps them in its store alone, and resend() sends them once the agent is
 * back.
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class StreamStateWriter {
	/** Columns of `streams_servers` a node may write about its own streams. */
	public const STATE_FIELDS = [
		'pid', 'monitor_pid', 'delay_pid',
		'stream_status', 'stream_started', 'to_analyze', 'delay_available_at',
		'stream_info', 'progress_info', 'current_source',
		'bitrate', 'audio_codec', 'video_codec', 'resolution', 'compatible',
		'cc_info', 'cchannel_rsources', 'ondemand_check',
	];

	/** @var (callable(string, array<string, mixed>, list<mixed>, ?object): bool)|null */
	private static $rSink;

	/**
	 * Update this node's row for a stream, keyed by (stream_id, server_id).
	 *
	 * @param array<string, mixed> $rFields column => value; null writes NULL.
	 * @param object|null $rDb The caller's DatabaseHandler, if it holds its own.
	 */
	public static function update(int $rStreamID, int $rServerID, array $rFields, ?object $rDb = null): bool {
		return self::write('`stream_id` = ? AND `server_id` = ?', $rFields, [$rStreamID, $rServerID], $rDb, ['stream_id' => $rStreamID, 'server_id' => $rServerID]);
	}

	/**
	 * Update a row by its `server_stream_id`.
	 *
	 * @param array<string, mixed> $rFields column => value; null writes NULL.
	 */
	public static function updateRow(int $rServerStreamID, array $rFields, ?object $rDb = null): bool {
		return self::write('`server_stream_id` = ?', $rFields, [$rServerStreamID], $rDb, ['ssid' => $rServerStreamID]);
	}

	/**
	 * Replace the sink (tests). It receives the WHERE
	 * clause, the fields and the WHERE values. Null restores the default
	 * (events when STREAMS is on, else SQL).
	 *
	 * @param (callable(string, array<string, mixed>, list<mixed>, ?object): bool)|null $rSink
	 */
	public static function useSink(?callable $rSink): void {
		self::$rSink = $rSink;
	}

	/**
	 * Send what the node's store kept but the agent did not take (it was
	 * stopped, in mode 2): a `stream.state` event of each stream's unsent
	 * columns, and a `stream.worker` event per unsent worker pid. cron:streams
	 * runs it every minute, whether or not the readers take the store (a seed
	 * waits for it); nothing without STREAMS or while the agent still takes
	 * no event.
	 *
	 * @return int the streams sent
	 */
	public static function resend(): int {
		if (!NodeFlows::on(NodeFlows::STREAMS) || !EventSpool::agentAlive() || !defined('SERVER_ID')) {
			return 0;
		}
		return StreamRuntime::resend(static function (int $rStreamID, array $rFields): bool {
			$rEvents = [];
			$rState = StreamStateFields::pick($rFields, self::STATE_FIELDS);
			if ($rState !== []) {
				$rEvents[] = ['type' => 'stream.state', 'd' => ['stream_id' => $rStreamID, 'server_id' => (int) SERVER_ID, 'fields' => (object) $rState]];
			}
			foreach (ContentSink::WORKERS as $rWorker) {
				if (array_key_exists($rWorker . '_pid', $rFields)) {
					$rEvents[] = ['type' => 'stream.worker', 'd' => ['stream_id' => $rStreamID, 'worker' => $rWorker, 'pid' => (int) $rFields[$rWorker . '_pid']]];
				}
			}
			return $rEvents === [] || EventSpool::append('p0', $rEvents);
		});
	}

	/**
	 * The cluster API backend (STREAMS flow on): a `stream.state` event on the
	 * agent's P0 lane. MAIN merges it into the sender's own row only.
	 *
	 * @param array<string, int> $rKey
	 * @param array<string, mixed> $rFields
	 */
	private static function spool(array $rKey, array $rFields): bool {
		return EventSpool::append('p0', [['type' => 'stream.state', 'd' => $rKey + ['fields' => (object) StreamStateFields::redact($rFields)]]]);
	}

	/**
	 * @param array<string, mixed> $rFields
	 * @param list<mixed> $rWhereValues
	 * @param array<string, int> $rKey how a `stream.state` event names the row
	 */
	private static function write(string $rWhere, array $rFields, array $rWhereValues, ?object $rDb, array $rKey): bool {
		if ($rFields === []) {
			return true;
		}
		$rUnknown = array_diff(array_keys($rFields), self::STATE_FIELDS);
		if ($rUnknown !== []) {
			throw new \InvalidArgumentException('Not stream runtime state: ' . implode(', ', $rUnknown));
		}
		if (self::$rSink !== null) {
			return (bool) (self::$rSink)($rWhere, $rFields, $rWhereValues, $rDb);
		}
		if (NodeFlows::on(NodeFlows::STREAMS)) {
			// Kept in the node's store and spooled for MAIN, under one lock.
			if (StreamRuntime::keep($rKey, $rFields, static fn (): bool => self::spool($rKey, $rFields))) {
				return true;
			}
			// The agent took no event. Mode 2 has no database: the store keeps it for resend().
			if (NodeRole::refusesConnects()) {
				return false;
			}
		}
		// Legacy backend: merge into the row in MAIN's database directly. MAIN's
		// row alone has it: once it landed, a store this node kept lapses. Not in
		// mode 2: MAIN's database refused the write, and the store cannot be seeded again.
		try {
			return StreamRowMerge::apply($rWhere, $rFields, $rWhereValues, $rDb);
		} finally {
			if (!NodeRole::refusesConnects()) {
				StreamRuntime::lapse();
			}
		}
	}
}

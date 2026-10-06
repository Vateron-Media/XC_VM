<?php

namespace XcVm\Core\Cluster;

/**
 * This node's connection registry, held by its agent (cluster plan, Phase 6),
 * for a node whose CONNECTIONS flow is on. ConnectionTracker's store seam
 * reads and writes a viewer here instead of in MAIN's Redis or `lines_live`;
 * the agent mirrors every change to MAIN as a P0 event, so MAIN's store stays
 * current for the reaper, the limits and the admin.
 *
 * Every call answers null when the agent did not: the caller then uses MAIN's
 * store for that call, and the viewer is never held up by the agent.
 */
final class AgentConnections {
	/** The stream endpoints' hot path: the agent answers in well under this. */
	public const TIMEOUT = 1.0;

	/**
	 * A new viewer's register, which may wait for the agent's conn_admit to
	 * MAIN (1.5 s) and then its offline policy.
	 */
	public const ADMIT_TIMEOUT = 2.5;

	/**
	 * The admission request of a new viewer's register (`PUT /v1/conn/{uuid}`).
	 * A header, so an agent that predates admission ignores it and the record
	 * it stores stays the record.
	 */
	public const ADMISSION_HEADER = 'X-XCVM-Admission';

	/**
	 * A connection's uuid, as a regex fragment: what every store, event and
	 * command naming a connection carries (ADR 0004). CONN_UUID matches it
	 * whole. Never the name of a Redis index: a connection's record is stored
	 * under its bare uuid, so a node naming one `LIVE` would replace that index.
	 */
	public const CONN_UUID_CHARS = '(?!(?:LIVE|ENDED|CONNECTIONS)(?![A-Za-z0-9_-]))[A-Za-z0-9_-]{1,64}';

	/** A whole connection uuid (CONN_UUID_CHARS), for preg_match(). */
	public const CONN_UUID = '/^' . self::CONN_UUID_CHARS . '\z/';

	public static function enabled(): bool {
		return NodeFlows::on(NodeFlows::CONNECTIONS);
	}

	/**
	 * Record (or replace) a connection.
	 *
	 * @param array<string, mixed> $rRecord
	 * @return true|null true when recorded; null when the agent did not answer or refused.
	 */
	public static function put(string $rUUID, array $rRecord): ?bool {
		$rOut = self::call('PUT', $rUUID, $rRecord);
		return $rOut !== null && $rOut[0] === 200 ? true : null;
	}

	/**
	 * What the agent needs to admit a new viewer (cluster plan, Phase 6,
	 * "Global max_connections and kills", steps 4-6), from the stream token
	 * the viewer presented (MAIN-sealed, so its content is MAIN's) and the
	 * record about to be registered:
	 *
	 * - `adm` {exp, sid}: MAIN's admission at mint, passed on only while it
	 *   holds (exp, MAIN's unix seconds, not past) and only for the node that
	 *   records the viewer. With it the agent admits without asking MAIN.
	 * - `line_id`, or `hmac_id` + `identifier`, `stream_id`, `ip`, `ua`: what
	 *   the agent sends MAIN's conn_admit when there is no `adm`.
	 * - `max_connections`: the token's limit, for the `local` offline policy
	 *   only; the agent never sends it to MAIN.
	 * - `mint`: the record's proof that MAIN minted the token
	 *   (ConnectionTracker::openRecord), which an agent from 0.14.4 copies
	 *   into conn_admit; an older one ignores it.
	 *
	 * Null for a viewer with no limit (the token's max_connections is 0), and
	 * on a node MAIN has not left `active` (quarantined): MAIN mints it no
	 * claim and answers its conn_admit NOT_ACTIVE, so its viewers are admitted
	 * without asking. The register is then the plain one, as before.
	 *
	 * @param array<string, mixed> $rToken  The decrypted stream token.
	 * @param array<string, mixed> $rRecord The registry record.
	 * @param int $rMainNow MAIN's clock as this node sees it (time() less servers.time_offset).
	 * @return array<string, mixed>|null
	 */
	public static function admission(array $rToken, array $rRecord, int $rMainNow): ?array {
		$rMax = (int) ((is_array($rToken['user_info'] ?? null) ? $rToken['user_info'] : [])['max_connections'] ?? 0);
		if ($rMax <= 0 || NodeFlows::current()['state'] !== 'active') {
			return null;
		}
		$rOut = [];
		$rAdm = $rToken['adm'] ?? null;
		if (is_array($rAdm) && is_int($rAdm['exp'] ?? null) && is_int($rAdm['sid'] ?? null) && $rAdm['exp'] >= $rMainNow && $rAdm['sid'] === (int) ($rRecord['server_id'] ?? 0)) {
			$rOut['adm'] = ['exp' => $rAdm['exp'], 'sid' => $rAdm['sid']];
		}
		if (is_string($rRecord['mint'] ?? null)) {
			$rOut['mint'] = $rRecord['mint']; // MAIN's proof of the mint, for conn_admit (an agent that knows it)
		}
		if (!empty($rRecord['user_id'])) {
			$rOut['line_id'] = (int) $rRecord['user_id'];
		} else {
			$rOut['hmac_id'] = (int) ($rRecord['hmac_id'] ?? 0);
			$rOut['identifier'] = (string) ($rRecord['hmac_identifier'] ?? '');
		}
		return $rOut + [
			'stream_id' => (int) ($rRecord['stream_id'] ?? 0),
			'max_connections' => $rMax,
			'ip' => (string) ($rRecord['user_ip'] ?? ''),
			'ua' => (string) ($rRecord['user_agent'] ?? ''),
		];
	}

	/**
	 * Register a new viewer, admitted by the agent when $rAdmission is given
	 * (admission()). The agent answers 403 {admit: false, reason} for a viewer
	 * it refuses, and records nothing; any other answer is the plain PUT's,
	 * so an agent that predates admission admits every viewer.
	 *
	 * @param array<string, mixed> $rRecord
	 * @param array<string, mixed>|null $rAdmission
	 * @return true|string|null true when recorded; the refusal reason when the agent refused the viewer; null when the agent did not answer.
	 */
	public static function register(string $rUUID, array $rRecord, ?array $rAdmission): bool|string|null {
		if ($rAdmission === null) {
			return self::put($rUUID, $rRecord);
		}
		if (!preg_match(self::CONN_UUID, $rUUID)) {
			return null;
		}
		$rHeader = (string) json_encode($rAdmission, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
		$rOut = AgentClient::request('PUT', '/v1/conn/' . $rUUID, $rRecord, self::ADMIT_TIMEOUT, [self::ADMISSION_HEADER => $rHeader]);
		if ($rOut === null) {
			return null;
		}
		if ($rOut[0] === 403 && ($rOut[1]['admit'] ?? null) === false) {
			$rReason = $rOut[1]['reason'] ?? null;
			return is_string($rReason) && preg_match('/^[A-Z_]{1,32}\z/', $rReason) ? $rReason : 'REFUSED';
		}
		return $rOut[0] === 200 ? true : null;
	}

	/**
	 * @return array<string, mixed>|false|null the record, false when there is none, null when the agent did not answer
	 */
	public static function get(string $rUUID): array|false|null {
		return self::record(self::call('GET', $rUUID, null));
	}

	/**
	 * The first connection matching every column (a Range request's fallback).
	 *
	 * @param array<string, mixed> $rMatch
	 * @return array<string, mixed>|false|null
	 */
	public static function find(array $rMatch): array|false|null {
		return self::record(AgentClient::request('POST', '/v1/conn/find', ['match' => (object) $rMatch], self::TIMEOUT));
	}

	/** How many streams one counts() call asks the agent for (its MaxCountStreams). */
	public const COUNT_CHUNK = 10000;

	/**
	 * The open viewers of each stream (`POST /v1/conn/counts`): stream id =>
	 * count. Null when the agent does not answer it, an agent from before the
	 * call included (it answers 405).
	 *
	 * @param list<int> $rStreamIDs
	 * @return array<int, int>|null
	 */
	public static function counts(array $rStreamIDs): ?array {
		$rCounts = [];
		foreach (array_chunk(array_values(array_unique($rStreamIDs)), self::COUNT_CHUNK) as $rChunk) {
			$rOut = AgentClient::request('POST', '/v1/conn/counts', ['stream_ids' => $rChunk], self::TIMEOUT);
			if ($rOut === null || $rOut[0] !== 200 || !is_array($rOut[1]['counts'] ?? null)) {
				return null;
			}
			foreach ($rChunk as $rID) {
				$rCounts[$rID] = (int) ($rOut[1]['counts'][(string) $rID] ?? 0);
			}
		}
		return $rCounts;
	}

	/** @return array<string, mixed>|false|null a line's oldest open connection */
	public static function oldest(mixed $rLineID): array|false|null {
		return self::record(AgentClient::request('POST', '/v1/conn/oldest', ['user_id' => $rLineID], self::TIMEOUT));
	}

	/** @return array<string, mixed>|false|null the record after refreshing its hls_last_read */
	public static function touch(string $rUUID, int $rLastRead): array|false|null {
		return self::record(self::call('POST', $rUUID . '/touch', ['hls_last_read' => $rLastRead]));
	}

	/** The keys of a registry record (ConnectionTracker's Redis record shape). */
	public const RECORD_KEYS = ['uuid', 'user_id', 'hmac_id', 'hmac_identifier', 'identity', 'stream_id', 'server_id', 'proxy_id', 'user_agent', 'user_ip', 'container', 'pid', 'date_start', 'geoip_country_code', 'isp', 'external_device', 'hls_last_read', 'hls_end', 'on_demand'];

	/** Records per seed call (the socket takes 1 MB a request). */
	public const SEED_CHUNK = 500;

	/**
	 * Load records into the registry as they are, with no event: MAIN's store
	 * already holds them (cluster:seed-connections). The registry is emptied
	 * first.
	 *
	 * @param list<array<string, mixed>> $rRecords
	 * @return int|null records loaded; null when the agent did not answer
	 */
	public static function seed(array $rRecords): ?int {
		$rSeeded = 0;
		foreach (array_chunk($rRecords, self::SEED_CHUNK) ?: [[]] as $i => $rChunk) {
			$rChunk = array_map(static fn($rRecord) => (object) array_intersect_key($rRecord, array_flip(self::RECORD_KEYS)), $rChunk);
			$rOut = AgentClient::request('POST', '/v1/conn/seed', ['records' => $rChunk, 'reset' => $i === 0], 10.0);
			if ($rOut === null || $rOut[0] !== 200 || !is_int($rOut[1]['seeded'] ?? null)) {
				return null;
			}
			$rSeeded += $rOut[1]['seeded'];
		}
		return $rSeeded;
	}

	/** Remove a connection (the agent tells MAIN). */
	public static function delete(string $rUUID): ?bool {
		$rOut = self::call('DELETE', $rUUID, null);
		return $rOut !== null && $rOut[0] === 204 ? true : null;
	}

	/** Follow a close already made in MAIN's store (no event). */
	public static function closed(string $rUUID, bool $rRemove): ?bool {
		$rOut = self::call('POST', $rUUID . '/close', ['remove' => $rRemove]);
		return $rOut !== null && $rOut[0] === 204 ? true : null;
	}

	/**
	 * @param array<string, mixed>|null $rBody
	 * @return array{0: int, 1: array<string, mixed>|null}|null
	 */
	private static function call(string $rMethod, string $rPath, ?array $rBody): ?array {
		if (!preg_match('#^' . self::CONN_UUID_CHARS . '(/(touch|close))?\z#', $rPath)) {
			return [400, null];
		}
		return AgentClient::request($rMethod, '/v1/conn/' . $rPath, $rBody, self::TIMEOUT);
	}

	/**
	 * @param array{0: int, 1: array<string, mixed>|null}|null $rOut
	 * @return array<string, mixed>|false|null
	 */
	private static function record(?array $rOut): array|false|null {
		if ($rOut === null) {
			return null;
		}
		if ($rOut[0] === 404) {
			return false;
		}
		return $rOut[0] === 200 && is_array($rOut[1]) ? $rOut[1] : null;
	}
}

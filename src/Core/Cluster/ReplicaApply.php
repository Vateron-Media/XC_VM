<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Util\AtomicFile;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * cluster:apply's work (plan, section 9, Phase 7): turn the replica the agent
 * verified into this node's caches. The agent writes
 * `config/cluster/replica/blocklist.json` from the sealed, panel-signed
 * records it holds and runs cluster:apply; PHP opens those records itself
 * only from disk (below), with the agent's keys.
 *
 * ```text
 * cache            from the replica's blocklist
 * blocked_ips      ip   (a list of addresses)
 * blocked_servers  asn  (a list of blocked ASNs)
 * blocked_ua       ua   ([id => {id, exact_match, blocked_ua (lower case)}])
 * blocked_isp      isp  ([{id, isp, blocked}])
 * rtmp_ips         rtmp ([resolved ip => {password, push, pull}]), which
 *                  only MAIN's cron:cache builds; an LB read its database.
 *                  From disk a name is not resolved (caches())
 * ```
 *
 * The shapes are the ones cron:cache writes from MAIN's database, so every
 * reader (LegacyInitializer, BlocklistService) keeps its contract.
 *
 * - CONFIG off (shadow): nothing is written; the report counts, per cache,
 *   entries the database has that the replica lacks (`missing`) and entries
 *   only the replica has (`extra`). Both at 0 is what lets CONFIG go on. A
 *   node in mode 2 may not read MAIN's database: it compares neither the
 *   crontab nor the RTMP publishers, and the report names them under
 *   `unchecked`.
 * - CONFIG on: the replica is authoritative. It writes the caches, and
 *   cron:cache stops writing them from the database.
 *
 * The `settings` section (`replica/settings.json`) becomes the settings cache
 * together with the sealed `secrets` section (`replica/secrets.json`), which
 * carries what the allowlist withholds and the node needs:
 *
 * ```text
 * settings  the settings cache: the section's raw row with the secrets'
 *           live_streaming_pass, decoded as SettingsRepository decodes
 *           MAIN's row, so every reader keeps its contract
 * secrets   OPENSSL_EXTRA in config/openssl_extra, with the value MAIN
 *           replaced while MAIN still accepts it (OpensslExtra::adopt)
 * ```
 *
 * In shadow, and while either is missing or malformed, the report names the
 * settings keys whose decoded value differs from the settings cache, and the
 * secrets whose value differs from what the node uses: never a value.
 *
 * The other whole sections (`replica/<name>.json`, `{etag, data}`, written
 * by the agent from the verified `rep` record; see ReplicaSections):
 *
 * ```text
 * servers + node  the `servers` cache, in ServerRepository::getAll's shape:
 *                 every server's routing fields, this node's own
 *                 configuration, the URLs built here (api_url with this
 *                 node's own live_streaming_pass), liveness unknown (null;
 *                 server_online: enabled)
 * crontab         the jobs this node's crontab runs (CRON_CACHE), read by
 *                 LegacyInitializer::generateCron and cron:root_signals
 * cluster         compared with the agent's own policy (agent.json); no PHP
 *                 reads it, the agent does
 * bouquets        the `bouquets` cache, in BouquetService::getAll's shape
 *                 (BouquetService::fromRows)
 * categories      the `categories` cache, in CategoryService's shape (every
 *                 column, keyed by id)
 * ```
 *
 * In shadow they are only compared with what the node uses today; with
 * CONFIG on they are the caches, and their readers stop reading MAIN's
 * database once an apply has built them (owns()). A section that is missing,
 * names another node or is malformed never writes a cache: its readers go
 * back to MAIN's database, as before the replica.
 *
 * Either way the report goes to `replica/apply.json`. cron:cache applies the
 * replica too, every minute while CONFIG is on (minute()), so the caches
 * follow the copy on disk even when the agent has not run cluster:apply since
 * a change of the flow.
 *
 * The R2 `streams` section (`replica/streams.json`, the agent's cursor, and
 * `replica/streams/<id>.json`, one `stream` record each) becomes the node's
 * stream caches (ReplicaStreamCache): one entry per stream, in the shapes the
 * node's readers took from MAIN's database (StreamSource). Its flow is
 * STREAMS, not CONFIG:
 *
 * - STREAMS on: the entries are written, those of streams the agent removed
 *   deleted, and StreamSource reads them instead of MAIN's database once an
 *   apply built them (ReplicaStreamCache::owned). A record that does not
 *   read (or, from disk, does not verify) writes nothing and deletes
 *   nothing: its stream keeps the entry it had.
 * - STREAMS off (shadow): nothing is written; the report names the streams
 *   MAIN's database says the node holds that the section lacks (`missing`),
 *   the reverse (`extra`), and the parts and fields that differ (`differ`):
 *   ids and names only, never a value (stream sources carry credentials)
 *   and never the tickets. MAIN serves the section only while STREAMS is
 *   on, so this measures how far the files the agent kept lag behind.
 * - Without a whole section (no cursor above 0, no readable `streams/`) or
 *   with a file that names no stream, nothing is written either, and the
 *   readers keep MAIN's database.
 *
 * The whole sections are applied first, then the blocklist, then the streams
 * section: a node may hold tens of thousands of streams, each verified from
 * disk at boot within `service`'s timeout, and neither that nor a shadow
 * comparison that fails may keep the blocklist from being applied.
 *
 * From disk (`cluster:apply --from-disk`, which `service` runs at boot before
 * the daemons, when the agent may not run yet), every section is taken from
 * the sealed, panel-signed record the agent stored beside its `.json`, once
 * it opens and verifies for this node (ReplicaRecords). A section whose record
 * does not verify is treated as unreadable: it writes no cache, and hands
 * back one it owned. The report names the sections under `from_disk`
 * (`verified`, `unverified`), never their content.
 */
final class ReplicaApply {
	use DirSeam;

	/** The crontab section's cache: the jobs the node's crontab runs. */
	public const CRON_CACHE = 'cron_jobs';

	/**
	 * The sections whose cache an authoritative apply built (section => the
	 * ETags applied). Beside the caches in `tmp/cache/`, so it goes with them
	 * at a reboot.
	 */
	public const OWNED_CACHE = 'replica_owned';

	/** Differing fields a shadow report names at most. */
	private const MAX_DIFFER = 100;

	private static ?string $rConfigDir = null;

	/**
	 * While an apply runs from disk: each section as its verified record has
	 * it (ReplicaRecords), false when it does not verify.
	 *
	 * @var array<string, array<string, mixed>|false|null>|null
	 */
	private static ?array $rFromDisk = null;

	/**
	 * While an apply runs from disk: the agent's keys, which the streams
	 * section's records are verified with one by one (ReplicaRecords::stream).
	 *
	 * @var array{node: string, box_sk: string, sign_pub: string}|null
	 */
	private static ?array $rIdentity = null;

	/** Stream ids a report names at most (`missing`, `extra`, `unreadable`). */
	private const MAX_IDS = 100;

	/** Held streams the shadow comparison reads from MAIN's database per step. */
	private const SHADOW_STEP = 1000;

	/** The replica's directory (useDir(): tests' own). */
	private static function defaultDir(): string {
		return self::configDir() . AgentPaths::DIR . 'replica/';
	}

	/** Tests: another config directory (where config/openssl_extra lives); null restores CONFIG_PATH. */
	public static function useConfigDir(?string $rDir): void {
		self::$rConfigDir = $rDir;
	}

	public static function configDir(): string {
		return self::$rConfigDir ?? AgentPaths::configDir();
	}

	/** The agent's state file, beside the replica's directory (so useDir() moves it too). */
	private static function agentState(): string {
		return dirname(self::dir()) . '/' . basename(AgentPaths::STATE);
	}

	/**
	 * Does the replica own this section's cache? Only with its flow on (CONFIG;
	 * STREAMS for `streams`), the section stored by the agent (`servers` needs
	 * `node` too, `settings` needs `secrets`, `streams` is `streams.json`), and
	 * its cache built by an authoritative apply since it was last MAIN's
	 * database's. Until then the readers keep MAIN's database and cron:cache
	 * keeps refreshing it, so no database copy is ever taken for the replica's
	 * and left with nothing to refresh it.
	 */
	public static function owns(string $rSection): bool {
		if (!NodeFlows::on($rSection === ReplicaSections::STREAMS ? NodeFlows::STREAMS : NodeFlows::CONFIG)) {
			return false;
		}
		$rFiles = match ($rSection) {
			ReplicaSections::SERVERS => [ReplicaSections::SERVERS, ReplicaSections::NODE],
			ReplicaSections::SETTINGS => [ReplicaSections::SETTINGS, ReplicaSections::SECRETS],
			default => [$rSection],
		};
		foreach ($rFiles as $rFile) {
			if (!is_file(self::dir() . $rFile . '.json')) {
				return false;
			}
		}
		return self::built($rSection);
	}

	/**
	 * Has an authoritative apply recorded that it built this section's cache
	 * (and nothing handed it back since)? The record alone, which owns() also
	 * needs: SettingsRepository asks this first, since it runs before the
	 * settings are loaded and owns() asks NodeFlows, which on a node with an
	 * agent reads the servers to rule out MAIN.
	 */
	public static function built(string $rSection): bool {
		$rOwned = FileCache::getCache(self::OWNED_CACHE);
		return is_array($rOwned) && isset($rOwned[$rSection]);
	}

	/**
	 * CONFIG is off: the whole sections' caches are MAIN's database's again,
	 * so turning it back on waits for an apply instead of reusing them. The
	 * streams section follows its own flow (disownStreams()).
	 */
	public static function disown(): void {
		$rOwned = FileCache::getCache(self::OWNED_CACHE);
		if (is_array($rOwned) && isset($rOwned[ReplicaSections::STREAMS])) {
			FileCache::setCache(self::OWNED_CACHE, [ReplicaSections::STREAMS => $rOwned[ReplicaSections::STREAMS]]);
		} else {
			FileCache::delCache(self::OWNED_CACHE);
		}
		FileCache::delCache(self::CRON_CACHE);
		if (is_array($rOwned) && array_diff_key($rOwned, [ReplicaSections::STREAMS => true]) !== []) {
			// The node no longer boots from the replica, and says so at once.
			SettingsAudit::republish();
		}
	}

	/**
	 * STREAMS is off, or the section is gone or unusable: the streams'
	 * definitions are MAIN's database's again, so turning it back on waits
	 * for an apply. The entries stay; the next apply checks each again.
	 */
	public static function disownStreams(): void {
		self::own(ReplicaSections::STREAMS, null);
	}

	/** Record that an authoritative apply built a section's cache ($rTag), or that it did not (null). */
	private static function own(string $rSection, ?string $rTag): void {
		$rOwned = FileCache::getCache(self::OWNED_CACHE);
		$rOwned = is_array($rOwned) ? $rOwned : [];
		if (($rOwned[$rSection] ?? null) === $rTag) {
			return;
		}
		$rWas = isset($rOwned[$rSection]);
		if ($rTag === null) {
			unset($rOwned[$rSection]);
		} else {
			$rOwned[$rSection] = $rTag;
		}
		FileCache::setCache(self::OWNED_CACHE, $rOwned);
		if ($rWas !== ($rTag !== null)) {
			// A section became the replica's, or no longer is: what the node says
			// of itself changes with it, either way (a node in mode 2 has no other
			// reason to report for an hour). Not for a new tag alone.
			SettingsAudit::republish();
		}
	}

	/**
	 * The jobs this node's crontab runs: the replica's once it owns the
	 * crontab, else MAIN's `crontab` table as before. Null: leave the crontab
	 * as it is (the replica's jobs are gone, or there is no database).
	 *
	 * @return list<array{filename: string, time: string}>|null
	 */
	public static function cronJobs(?object $rDb): ?array {
		if (self::owns(ReplicaSections::CRONTAB)) {
			$rJobs = FileCache::getCache(self::CRON_CACHE);
			return is_array($rJobs) ? array_values($rJobs) : null;
		}
		if ($rDb === null) {
			return null;
		}
		$rDb->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
		$rOut = [];
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rOut[] = ['filename' => (string) $rRow['filename'], 'time' => (string) $rRow['time']];
		}
		return $rOut;
	}

	/**
	 * The xc_vm crontab those jobs make, one `<time> <php> console.php
	 * cron:<name> # XC_VM` line each, as LegacyInitializer::generateCron
	 * writes it and cron:root_signals checks it. Null: leave the crontab as it
	 * is; "" only when the source really has no job.
	 */
	public static function crontabText(?object $rDb): ?string {
		$rJobs = self::cronJobs($rDb);
		if ($rJobs === null) {
			return null;
		}
		$rLines = [];
		foreach ($rJobs as $rJob) {
			$rLines[] = $rJob['time'] . ' ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:' . $rJob['filename'] . ' # XC_VM';
		}
		return implode("\n", $rLines);
	}

	/**
	 * Apply the materialised replica. Null when there is nothing the agent
	 * wrote to apply.
	 *
	 * @param bool $rAuthoritative the CONFIG flow (the whole sections and the
	 *                             blocklist); the streams section follows
	 *                             STREAMS
	 * @param int|null $rServerID this node (SERVER_ID)
	 * @param bool $rFromDisk take each section from its verified record, not
	 *                        from the `.json` the agent wrote (ReplicaRecords)
	 * @param bool $rMinute cron:cache's minute: a streams section in shadow is
	 *                      not compared with MAIN's database (the agent's
	 *                      cluster:apply does that); the last comparison stays
	 *                      in the report
	 * @return array<string, mixed>|null
	 */
	public static function run(bool $rAuthoritative, ?int $rNow = null, ?int $rServerID = null, bool $rFromDisk = false, bool $rMinute = false): ?array {
		$rServerID ??= defined('SERVER_ID') ? (int) SERVER_ID : 0;
		$rReport = ['at' => $rNow ?? time()];
		if (!$rFromDisk) {
			return self::apply($rAuthoritative, $rServerID, $rReport, $rMinute);
		}
		$rIdentity = ReplicaRecords::identity(self::agentState());
		self::$rIdentity = $rIdentity;
		self::$rFromDisk = ['blocklist' => ReplicaRecords::blocklist(self::dir(), $rIdentity)];
		foreach ([...ReplicaSections::WHOLE, ReplicaSections::SECRETS] as $rName) {
			self::$rFromDisk[$rName] = ReplicaRecords::whole(self::dir(), $rName, $rIdentity);
		}
		$rVerified = array_keys(array_filter(self::$rFromDisk, 'is_array'));
		$rUnverified = array_keys(array_filter(self::$rFromDisk, static fn(mixed $rDoc): bool => $rDoc === false));
		if ($rVerified !== [] || $rUnverified !== []) {
			$rReport['from_disk'] = ['verified' => $rVerified, 'unverified' => $rUnverified];
		}
		try {
			return self::apply($rAuthoritative, $rServerID, $rReport, $rMinute);
		} finally {
			self::$rFromDisk = null;
			self::$rIdentity = null;
		}
	}

	/**
	 * cron:cache's minute. With CONFIG on, the whole replica, authoritative
	 * (run()); a streams section in shadow is only handed back, never
	 * compared with MAIN's database, and the agent's last comparison stays in
	 * the report. With CONFIG off, the whole sections' caches are MAIN's
	 * database's again (disown()) and the streams section alone follows
	 * STREAMS (streamsMinute()), with no report written.
	 *
	 * @return array<string, mixed>|null what was applied: the report, or
	 *                                   with CONFIG off the streams part
	 *                                   alone; null when there is none
	 */
	public static function minute(?int $rServerID = null): ?array {
		if (NodeFlows::on(NodeFlows::CONFIG)) {
			return self::run(true, null, $rServerID, false, true);
		}
		self::disown();
		$rStreams = self::streamsMinute($rServerID);
		return $rStreams === null ? null : [ReplicaSections::STREAMS => $rStreams];
	}

	/**
	 * cron:cache's minute while CONFIG is off: the streams section alone,
	 * authoritative while STREAMS is on, else handed back. Writes no report:
	 * the agent's cluster:apply keeps it.
	 *
	 * @return array<string, mixed>|null the streams part
	 */
	public static function streamsMinute(?int $rServerID = null): ?array {
		if (!NodeFlows::on(NodeFlows::STREAMS)) {
			self::disownStreams();
			return null;
		}
		return self::streams(true, $rServerID ?? (defined('SERVER_ID') ? (int) SERVER_ID : 0));
	}

	/**
	 * @param array<string, mixed> $rReport
	 * @return array<string, mixed>|null
	 */
	private static function apply(bool $rAuthoritative, int $rServerID, array $rReport, bool $rMinute = false): ?array {
		// The whole sections first: they are what a boot from the replica needs
		// (ReplicaBoot::ready), and the blocklist may wait on DNS for its RTMP
		// publishers. The secrets before the settings: they report what differs
		// from what the node used before this apply. The streams last (below).
		$rSecrets = self::secrets($rAuthoritative, $rReport['at']);
		if ($rSecrets !== null) {
			$rReport['secrets'] = $rSecrets;
		}
		$rSettings = self::settings($rAuthoritative);
		if ($rSettings !== null) {
			$rReport['settings'] = $rSettings;
		}
		// The servers' URLs take the settings this apply made, not the ones this process loaded.
		$rNodeSettings = ($rSettings['mode'] ?? null) === 'applied' ? FileCache::getCache('settings') : null;
		foreach ([
			'servers' => self::servers($rAuthoritative, $rServerID, is_array($rNodeSettings) ? $rNodeSettings : null), 'crontab' => self::crontab($rAuthoritative), 'cluster' => self::cluster(),
			ReplicaSections::BOUQUETS => self::catalog(ReplicaSections::BOUQUETS, $rAuthoritative), ReplicaSections::CATEGORIES => self::catalog(ReplicaSections::CATEGORIES, $rAuthoritative),
		] as $rKey => $rPart) {
			if ($rPart !== null) {
				$rReport[$rKey] = $rPart;
			}
		}
		// Mode 2 compares nothing with MAIN's database (crontab()).
		$rUnchecked = !$rAuthoritative && NodeRole::refusesConnects() && ($rReport['crontab']['mode'] ?? null) === 'shadow' ? ['crontab'] : [];
		$rDoc = self::$rFromDisk === null ? json_decode((string) @file_get_contents(self::dir() . 'blocklist.json'), true) : self::$rFromDisk['blocklist'];
		$rCaches = is_array($rDoc) && is_array($rDoc['data'] ?? null) ? self::caches($rDoc['data'], self::$rFromDisk === null) : null;
		if ($rCaches !== null) {
			$rReport += ['seq' => (int) ($rDoc['seq'] ?? 0), 'etag' => (string) ($rDoc['etag'] ?? ''), 'mode' => $rAuthoritative ? 'applied' : 'shadow'];
			if ($rAuthoritative) {
				foreach ($rCaches as $rKey => $rValue) {
					FileCache::setCache($rKey, $rValue);
				}
			} else {
				$rReport['diff'] = [];
				foreach ($rCaches as $rKey => $rValue) {
					// An LB never cached the RTMP publishers (current()), and mode 2 may not read MAIN's.
					if ($rKey === 'rtmp_ips' && NodeRole::refusesConnects()) {
						$rUnchecked[] = $rKey;
						continue;
					}
					$rReport['diff'][$rKey] = self::diff(self::current($rKey), $rValue);
				}
			}
		}
		if ($rUnchecked !== []) {
			$rReport['unchecked'] = $rUnchecked;
		}
		// The streams last: from disk each record is verified on its own (a
		// node may hold tens of thousands, within `service`'s timeout), and
		// neither that nor a failed shadow comparison may keep the sections
		// above from being applied.
		$rStreams = self::streamsPart($rServerID, $rMinute);
		if ($rStreams !== null) {
			$rReport[ReplicaSections::STREAMS] = $rStreams;
			if (self::$rFromDisk !== null && isset($rStreams['unreadable'])) {
				$rReport['from_disk'] ??= ['verified' => [], 'unverified' => []];
				$rReport['from_disk'][$rStreams['unreadable'] === [] ? 'verified' : 'unverified'][] = ReplicaSections::STREAMS;
			}
		}
		if (!$rAuthoritative) {
			self::disown();
		}
		if (count($rReport) === 1) {
			return null;
		}
		AtomicFile::write(self::dir() . 'apply.json', (string) json_encode($rReport));
		return $rReport;
	}

	/**
	 * The `bouquets` or `categories` section: that cache (CONFIG on), in the
	 * shape its reader builds from MAIN's database (BouquetService::getAll,
	 * CategoryService::getFromDatabase), or the bouquets or categories whose
	 * row differs from the cache cron:cache built from MAIN's database: ids
	 * only. A section that is not a list of rows with distinct ids is
	 * `refused` and hands the cache back to MAIN's database.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function catalog(string $rSection, bool $rAuthoritative): ?array {
		$rDoc = self::whole($rSection);
		if ($rDoc === null) {
			return null;
		}
		$rCache = is_array($rDoc) ? self::catalogCacheOf($rSection, $rDoc['data']) : null;
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if ($rAuthoritative) {
			// Refused: the cache is MAIN's database's again (cron:cache).
			$rApplied = $rCache !== null && FileCache::setCache($rSection, $rCache);
			self::own($rSection, $rApplied ? $rReport['etag'] : null);
			if ($rApplied) {
				return $rReport + ['mode' => 'applied', 'rows' => count($rCache)];
			}
		}
		if ($rCache === null) {
			return $rReport + ['mode' => 'refused'];
		}
		if ($rAuthoritative) {
			return $rReport + ['mode' => 'failed', 'rows' => count($rCache)];
		}
		$rCurrent = FileCache::getCache($rSection);
		$rCurrent = is_array($rCurrent) ? $rCurrent : [];
		$rDiffer = [];
		foreach (array_intersect_key($rCache, $rCurrent) as $rID => $rRow) {
			if (json_encode(self::loose($rRow)) !== json_encode(self::loose($rCurrent[$rID]))) {
				$rDiffer[] = (int) $rID;
			}
		}
		return $rReport + [
			'mode' => 'shadow', 'rows' => count($rCache),
			'missing' => array_slice(array_map('intval', array_keys(array_diff_key($rCurrent, $rCache))), 0, self::MAX_IDS),
			'extra' => array_slice(array_map('intval', array_keys(array_diff_key($rCache, $rCurrent))), 0, self::MAX_IDS),
			'differ' => array_slice($rDiffer, 0, self::MAX_IDS),
		];
	}

	/**
	 * The bouquets or categories cache from its section's data, or null when
	 * the data is not a list of rows with distinct ids.
	 *
	 * @param array<mixed> $rData
	 * @return array<int, array<string, mixed>>|null
	 */
	private static function catalogCacheOf(string $rSection, array $rData): ?array {
		$rRows = $rData[$rSection] ?? null;
		if (!ReplicaRecords::listOf($rRows, 'is_array')) {
			return null;
		}
		$rFields = $rSection === ReplicaSections::BOUQUETS ? ReplicaSections::BOUQUET_FIELDS : ReplicaSections::CATEGORY_FIELDS;
		$rOut = [];
		foreach ($rRows as $rRow) {
			$rID = $rRow['id'] ?? null;
			if (!is_int($rID) || $rID <= 0 || isset($rOut[$rID])) {
				return null;
			}
			$rOut[$rID] = ReplicaSections::typed($rRow, $rFields);
		}
		return $rSection === ReplicaSections::BOUQUETS ? BouquetService::fromRows($rOut) : $rOut;
	}

	/**
	 * The bouquets or categories cache the node's readers take instead of
	 * MAIN's database: the replica's once an apply built it (rebuilt from the
	 * section on disk when it is gone), however old; or, in a process booted
	 * from the replica (ReplicaBoot), the cache as it is, [] without one.
	 * Null: read MAIN's database, as before.
	 *
	 * @return array<mixed>|null
	 */
	public static function catalogCache(string $rSection): ?array {
		if (self::built($rSection) && self::owns($rSection)) {
			$rCache = FileCache::getCache($rSection);
			if (!is_array($rCache)) {
				$rCache = (self::catalog($rSection, true)['mode'] ?? null) === 'applied' ? FileCache::getCache($rSection) : null;
			}
			if (is_array($rCache)) {
				return $rCache;
			}
		}
		return ReplicaBoot::active() ? ReplicaBoot::cached($rSection) : null;
	}

	/**
	 * The streams part of an apply: authoritative while STREAMS is on. On
	 * cron:cache's minute a section in shadow is only handed back, and the
	 * last comparison the agent's cluster:apply reported stays.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function streamsPart(int $rServerID, bool $rMinute): ?array {
		$rOn = NodeFlows::on(NodeFlows::STREAMS);
		if ($rOn || !$rMinute) {
			return self::streams($rOn, $rServerID);
		}
		self::disownStreams();
		$rLast = json_decode((string) @file_get_contents(self::dir() . 'apply.json'), true);
		$rLast = is_array($rLast) ? ($rLast[ReplicaSections::STREAMS] ?? null) : null;
		return is_array($rLast) && ($rLast['mode'] ?? null) !== 'applied' ? $rLast : null;
	}

	/**
	 * The R2 `streams` section: the node's stream caches (STREAMS on), or how
	 * the section differs from what MAIN's database says the node holds.
	 * Null when the agent stores no such section.
	 *
	 * ```text
	 * applied     {since, streams, written, removed, unreadable: [ids]}
	 * shadow      {since, streams, missing: [ids], extra: [ids], unreadable: [ids],
	 *              differ: ["<id>.<part>" | "<id>.<part>.<field>"]}
	 * incomplete  no cursor above 0 or no readable streams/: nothing written
	 * refused     a file in streams/ names no stream: nothing written
	 * ```
	 *
	 * Ids and names only: never a value, and never the tickets.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function streams(bool $rAuthoritative, int $rServerID): ?array {
		$rDir = self::dir();
		if (!is_file($rDir . 'streams.json') && !is_dir($rDir . 'streams')) {
			self::disownStreams();
			return null;
		}
		$rReport = ['since' => ReplicaStreams::since()];
		$rIDs = ReplicaStreams::ids();
		if (!is_array($rIDs)) {
			// The readers keep (or take again) MAIN's database; no entry is touched.
			self::disownStreams();
			return $rReport + ['mode' => $rIDs === false ? 'refused' : 'incomplete'];
		}
		$rReport['streams'] = count($rIDs);
		if (!$rAuthoritative) {
			self::disownStreams();
			return $rReport + self::streamsShadow($rIDs, $rServerID);
		}
		$rStore = ReplicaStreamCache::store();
		$rIndex = ReplicaStreamCache::index();
		$rWritten = 0;
		$rUnreadable = [];
		foreach ($rIDs as $rID) {
			$rDoc = self::streamRecord($rID);
			$rEntry = is_array($rDoc) ? ReplicaStreamCache::entry($rID, $rDoc['data'], $rServerID, $rDoc['etag'], $rDoc['ver']) : null;
			if ($rEntry === null) {
				// Never a removal: the stream keeps the entry it had.
				$rUnreadable[] = $rID;
				continue;
			}
			// Unchanged since the last apply: not written again. From disk every
			// entry is written from its verified record.
			if (self::$rFromDisk === null && ($rIndex[$rID]['etag'] ?? null) === $rEntry['etag'] && ($rIndex[$rID]['ver'] ?? null) === $rEntry['ver'] && $rStore->has((string) $rID)) {
				$rIndex[$rID] = ReplicaStreamCache::meta($rEntry);
				continue;
			}
			if (!$rStore->set((string) $rID, $rEntry)) {
				$rUnreadable[] = $rID;
				continue;
			}
			$rIndex[$rID] = ReplicaStreamCache::meta($rEntry);
			$rWritten++;
		}
		// Removals: the streams whose file the agent deleted, never one whose record does not read.
		$rHeld = array_flip($rIDs);
		$rRemoved = 0;
		foreach (array_unique(array_merge(ReplicaStreamCache::cached(), array_keys($rIndex))) as $rID) {
			if (!isset($rHeld[$rID])) {
				$rStore->delete((string) $rID);
				unset($rIndex[$rID]);
				$rRemoved++;
			}
		}
		ReplicaStreamCache::writeIndex($rIndex, $rUnreadable);
		self::own(ReplicaSections::STREAMS, (string) $rReport['since']);
		return $rReport + ['mode' => 'applied', 'written' => $rWritten, 'removed' => $rRemoved, 'unreadable' => array_slice($rUnreadable, 0, self::MAX_IDS)];
	}

	/**
	 * The shadow comparison of the streams section with MAIN's database, in
	 * the record's own shape (StreamRecords, MAIN's reads of a record). It
	 * walks the ids in steps: the next SHADOW_STEP streams MAIN's database
	 * says the node holds, their records' data, and the section's records in
	 * that id range, one at a time. Only the ids and names a report keeps are
	 * held, so a node holding tens of thousands of streams stays within the
	 * CLI's memory.
	 *
	 * @param list<int> $rIDs the streams the section holds, ascending
	 * @return array<string, mixed>
	 */
	private static function streamsShadow(array $rIDs, int $rServerID): array {
		$rOut = ['missing' => [], 'extra' => [], 'unreadable' => [], 'differ' => []];
		$rCaps = ['missing' => self::MAX_IDS, 'extra' => self::MAX_IDS, 'unreadable' => self::MAX_IDS, 'differ' => self::MAX_DIFFER];
		$rNote = static function (string $rList, int|string $rWhat) use (&$rOut, $rCaps): void {
			if (count($rOut[$rList]) < $rCaps[$rList]) {
				$rOut[$rList][] = $rWhat;
			}
		};
		$rCompared = true;
		$rCount = count($rIDs);
		$rPos = 0;
		$rFrom = 0;
		do {
			$rMain = null;
			$rTo = StreamRecords::MAX_ID;
			if ($rCompared) {
				try {
					$rHeld = StreamRecords::held($rServerID, null, $rFrom, StreamRecords::MAX_ID, self::SHADOW_STEP);
					// A full step: this range ends at its last id, the next starts after it.
					if (count($rHeld) >= self::SHADOW_STEP) {
						$rTo = (int) end($rHeld);
					}
					$rMain = StreamRecords::data($rServerID, $rHeld);
				} catch (\Throwable) {
					// MAIN's database did not answer (or mode 2 refused it): nothing
					// to compare with; the records are still read for `unreadable`.
					$rCompared = false;
					$rTo = StreamRecords::MAX_ID;
				}
			}
			for (; $rPos < $rCount && $rIDs[$rPos] <= $rTo; $rPos++) {
				$rID = $rIDs[$rPos];
				$rDoc = self::streamRecord($rID);
				$rHave = $rMain[$rID] ?? null;
				unset($rMain[$rID]);
				if (!is_array($rDoc) || ReplicaStreamCache::entry($rID, $rDoc['data'], $rServerID, $rDoc['etag'], $rDoc['ver']) === null) {
					$rNote('unreadable', $rID);
				} elseif ($rMain !== null && $rHave === null) {
					$rNote('extra', $rID);
				} elseif ($rHave !== null) {
					foreach (self::streamDiffer($rHave, $rDoc['data']) as $rWhat) {
						$rNote('differ', $rID . '.' . $rWhat);
					}
				}
			}
			// What MAIN's database holds in the range that the section lacks.
			foreach (array_keys($rMain ?? []) as $rID) {
				$rNote('missing', (int) $rID);
			}
			$rFrom = $rTo + 1;
		} while ($rTo < StreamRecords::MAX_ID);
		if (!$rCompared) {
			return ['mode' => 'shadow', 'compared' => false, 'unreadable' => $rOut['unreadable']];
		}
		return ['mode' => 'shadow'] + $rOut;
	}

	/**
	 * The parts, and for `stream` and `server` the fields, in which a record's
	 * data differs from MAIN's (never `tickets`): `<part>` or `<part>.<field>`.
	 *
	 * @param array<string, mixed> $rMain the record MAIN's database gives
	 * @param array<mixed> $rData the section's
	 * @return list<string>
	 */
	private static function streamDiffer(array $rMain, array $rData): array {
		$rOut = [];
		foreach (['stream', 'type', 'profile', 'server', 'options', 'children', 'recordings'] as $rPart) {
			$rHave = $rMain[$rPart] ?? null;
			$rWant = $rData[$rPart] ?? null;
			if (in_array($rPart, ['stream', 'server'], true) && is_array($rHave) && is_array($rWant)) {
				foreach (array_unique(array_merge(array_keys($rHave), array_keys($rWant))) as $rField) {
					if (json_encode(self::loose($rHave[$rField] ?? null)) !== json_encode(self::loose($rWant[$rField] ?? null))) {
						$rOut[] = $rPart . '.' . $rField;
					}
				}
			} elseif (json_encode(self::loose($rHave)) !== json_encode(self::loose($rWant))) {
				$rOut[] = $rPart;
			}
		}
		return $rOut;
	}

	/**
	 * One stream's record as the agent stored it: `streams/<id>.json`
	 * (ReplicaRecords::storedStream), or while an apply runs from disk the
	 * verified `streams/<id>.rep` (ReplicaRecords::stream). False when it
	 * does not read or verify.
	 *
	 * @return array{etag: string, ver: int, data: array<mixed>}|false
	 */
	private static function streamRecord(int $rID): array|false {
		if (self::$rFromDisk !== null) {
			return ReplicaRecords::stream(self::dir(), $rID, self::$rIdentity) ?? false;
		}
		return ReplicaRecords::storedStream(self::dir(), $rID) ?? false;
	}

	/**
	 * The `settings` section, with the `secrets` section. With CONFIG on and
	 * both usable, the settings cache: the section's raw row with the secrets'
	 * `live_streaming_pass`, decoded as SettingsRepository decodes MAIN's
	 * row; its readers then stop reading MAIN's database (owns()). Otherwise
	 * the keys whose value differs from the settings cache cron:cache built
	 * from MAIN's database: `shadow` (CONFIG off) or `incomplete` (no usable
	 * secrets section: the section withholds what the node reads, so the
	 * cache stays MAIN's database's). A section that is not a raw row (empty,
	 * without `server_name`, a list, a value that is not a string or null) is
	 * `refused`, and the cache is MAIN's database's too.
	 *
	 * @return array{etag: string, mode: string, keys?: int, differ?: list<string>}|null
	 */
	public static function settings(bool $rAuthoritative = false): ?array {
		$rDoc = self::whole(ReplicaSections::SETTINGS);
		if ($rDoc === null) {
			return null;
		}
		$rData = is_array($rDoc) && self::rawRow($rDoc['data']) ? $rDoc['data'] : null;
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if ($rAuthoritative) {
			$rSecrets = self::secretEntries();
			// Refused or incomplete: the cache is MAIN's database's again (cron:cache).
			// Without the stream secret's value (MAIN sent its hash), none: the node reads with its own key (ViewerKey).
			$rApplied = $rData !== null && $rSecrets !== null && FileCache::setCache('settings', SettingsRepository::decode(['live_streaming_pass' => $rSecrets['live_streaming_pass']['current'] ?? ''] + $rData));
			self::own(ReplicaSections::SETTINGS, $rApplied ? $rReport['etag'] : null);
			if ($rApplied) {
				return $rReport + ['mode' => 'applied', 'keys' => count((array) $rData)];
			}
		}
		if ($rData === null) {
			return $rReport + ['mode' => 'refused'];
		}
		$rReplica = SettingsRepository::decode($rData);
		$rCurrent = FileCache::getCache('settings');
		$rCurrent = is_array($rCurrent) ? $rCurrent : [];
		$rDiffer = [];
		foreach (array_keys($rData) as $rKey) {
			if (json_encode(self::loose($rReplica[$rKey] ?? null)) !== json_encode(self::loose($rCurrent[$rKey] ?? null))) {
				$rDiffer[] = (string) $rKey;
			}
		}
		return $rReport + ['mode' => $rAuthoritative ? 'incomplete' : 'shadow', 'keys' => count($rData), 'differ' => $rDiffer];
	}

	/**
	 * The `secrets` section. With CONFIG on, OPENSSL_EXTRA goes where the node
	 * reads it: config/openssl_extra, keeping the value MAIN replaced while
	 * MAIN still accepts it (OpensslExtra::adopt, 0600). `live_streaming_pass`
	 * goes into the settings cache with the `settings` section (settings()).
	 * In shadow nothing is written. The report names the secrets whose value
	 * differs from what the node used (`differ`), never a value, kid or hash
	 * of one: cluster:apply prints it, and the agent may log that output.
	 *
	 * @return array{mode: string, differ?: list<string>}|null
	 */
	public static function secrets(bool $rAuthoritative, ?int $rNow = null): ?array {
		if (self::whole(ReplicaSections::SECRETS) === null) {
			return null;
		}
		$rEntries = self::secretEntries();
		if ($rEntries === null) {
			return ['mode' => 'refused'];
		}
		$rSettings = FileCache::getCache('settings');
		$rInUse = [
			'live_streaming_pass' => is_array($rSettings) ? (string) ($rSettings['live_streaming_pass'] ?? '') : '',
			'openssl_extra' => OpensslExtra::inUse(self::configDir()),
			ViewerKey::PASS_HASH => ViewerKey::passHash() ?? '',
		];
		$rDiffer = [];
		foreach ($rEntries as $rKey => $rEntry) {
			if (!hash_equals($rEntry['current'], $rInUse[$rKey] ?? '')) {
				$rDiffer[] = $rKey;
			}
		}
		if (!$rAuthoritative) {
			return ['mode' => 'shadow', 'differ' => $rDiffer];
		}
		$rExtra = $rEntries['openssl_extra'];
		try {
			$rSet = OpensslExtra::adopt($rExtra['current'], $rExtra['previous'], $rExtra['previous_valid_until'], self::configDir(), $rNow ?? time());
			// The viewer-token secret MAIN replaced last: the node reads the
			// links minted under it too, for the window MAIN dated
			// (StreamSecret). Its current value arrives with the settings.
			$rLive = $rEntries['live_streaming_pass'] ?? null;
			if ($rLive !== null) {
				StreamSecret::adopt($rLive['previous'], $rLive['previous_valid_until'], $rNow ?? time());
			} else {
				// MAIN sent the hash: no value of the secret is the node's to read with.
				@unlink(StreamSecret::file());
			}
			if (ViewerKey::adoptPassHash($rEntries[ViewerKey::PASS_HASH]['current'] ?? null) === false) {
				$rSet = false;
			}
			// The node's own viewer-token key (ViewerKey): MAIN mints with it once
			// the node reports holding it, so the report follows each change (and,
			// in mode 2, any report the spool did not take).
			$rViewer = ReplicaSections::secret(self::whole(ReplicaSections::SECRETS)['data'][ViewerKey::NAME] ?? null);
			if ($rViewer !== null) {
				$rWrote = ViewerKey::adopt($rViewer);
				if ($rWrote === false) {
					$rSet = false;
				} elseif ($rWrote === true || (NodeRole::refusesConnects() && NodeStateSink::reported(ViewerKey::FP) !== $rViewer['kid'])) {
					$rViewerFp = $rViewer['kid'];
				}
			}
		} catch (\Throwable) {
			// Never an uncaught trace: it would print the value among the arguments.
			$rSet = false;
		}
		if (isset($rViewerFp)) {
			try {
				NodeStateSink::state([ViewerKey::FP => $rViewerFp]);
			} catch (\Throwable) {
				// ponytail: unreported, MAIN keeps minting this node's tokens with the
				// shared secret, which it reads; mode 2 reports again next minute
				// (its kept copy differs), mode 1 at the key's next change.
			}
		}
		return ['mode' => $rSet === false ? 'failed' : 'applied', 'differ' => $rDiffer];
	}

	/**
	 * The `secrets` section's entries (ReplicaSections::SECRET_KEYS, each as
	 * ReplicaSections::secret takes it), or null when there is no usable one.
	 * A key a later MAIN adds is left for the node that knows it. A locked-down
	 * MAIN sends `streaming_pass_hash` (ViewerKey::PASS_HASH) in place of
	 * `live_streaming_pass`; a section with both is refused.
	 *
	 * @return array<string, array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}>|null
	 */
	public static function secretEntries(): ?array {
		$rDoc = self::whole(ReplicaSections::SECRETS);
		if (!is_array($rDoc)) {
			return null;
		}
		$rHashed = array_key_exists(ViewerKey::PASS_HASH, $rDoc['data']);
		if ($rHashed && array_key_exists('live_streaming_pass', $rDoc['data'])) {
			return null;
		}
		$rOut = [];
		foreach ($rHashed ? ['openssl_extra', ViewerKey::PASS_HASH] : ReplicaSections::SECRET_KEYS as $rKey) {
			$rEntry = ReplicaSections::secret($rDoc['data'][$rKey] ?? null);
			if ($rEntry === null) {
				return null;
			}
			$rOut[$rKey] = $rEntry;
		}
		return $rOut;
	}

	/**
	 * The settings section's data: MAIN's raw row, every value a string or
	 * null, and a row indeed (`server_name` among it). An empty or partial
	 * section would become the node's whole settings, every flag it lacks
	 * unset.
	 */
	private static function rawRow(array $rData): bool {
		if (!is_string($rData['server_name'] ?? null)) {
			return false;
		}
		foreach ($rData as $rValue) {
			if (!is_string($rValue) && $rValue !== null) {
				return false;
			}
		}
		return true;
	}

	/**
	 * A whole section the agent stored: `replica/<name>.json`, `{etag, data}`.
	 * Null when there is none; false when it cannot be read. While an apply
	 * runs from disk, the section as its verified record has it (false when
	 * the record does not verify).
	 *
	 * @return array{etag: string, data: array<mixed>}|false|null
	 */
	public static function whole(string $rName): array|false|null {
		if (self::$rFromDisk !== null) {
			$rDoc = self::$rFromDisk[$rName] ?? null;
			return is_array($rDoc) ? ['etag' => (string) $rDoc['etag'], 'data' => (array) $rDoc['data']] : $rDoc;
		}
		$rFile = self::dir() . $rName . '.json';
		if (!is_file($rFile)) {
			return null;
		}
		$rDoc = json_decode((string) @file_get_contents($rFile), true);
		if (!is_array($rDoc) || !is_array($rDoc['data'] ?? null) || !is_string($rDoc['etag'] ?? null)) {
			return false;
		}
		return ['etag' => $rDoc['etag'], 'data' => $rDoc['data']];
	}

	/**
	 * The `servers` and `node` sections: the servers cache (CONFIG on), or
	 * what differs from the one cron:cache built from MAIN's database.
	 *
	 * @param array<string, mixed>|null $rSettings this node's settings (the
	 *                                             ones loaded, or while they
	 *                                             are not, the settings cache)
	 * @return array<string, mixed>|null
	 */
	public static function servers(bool $rAuthoritative, int $rServerID, ?array $rSettings = null): ?array {
		$rServers = self::whole(ReplicaSections::SERVERS);
		$rNode = self::whole(ReplicaSections::NODE);
		if ($rServers === null && $rNode === null) {
			return null;
		}
		$rReport = ['etag' => is_array($rServers) ? $rServers['etag'] : '', 'node_etag' => is_array($rNode) ? $rNode['etag'] : ''];
		$rRows = is_array($rServers) && is_array($rNode) ? self::serverRows($rServers['data'], $rNode['data'], $rServerID, $rSettings ?? SettingsRepository::loaded()) : null;
		if ($rAuthoritative) {
			// Refused or incomplete: the cache is MAIN's database's again (cron:cache).
			if ($rRows !== null && FileCache::setCache('servers', $rRows)) {
				self::own(ReplicaSections::SERVERS, $rReport['etag'] . '/' . $rReport['node_etag']);
				self::allowedDomains($rRows, $rServers['data']['reseller_dns'] ?? null);
			} else {
				self::own(ReplicaSections::SERVERS, null);
			}
		}
		if ($rServers === null || $rNode === null) {
			return $rReport + ['mode' => 'incomplete'];
		}
		if ($rRows === null) {
			return $rReport + ['mode' => 'refused'];
		}
		if ($rAuthoritative) {
			return $rReport + ['mode' => 'applied', 'rows' => count($rRows)];
		}
		$rCurrent = FileCache::getCache('servers');
		$rCurrent = is_array($rCurrent) ? $rCurrent : [];
		$rDiffer = [];
		foreach (array_intersect_key($rRows, $rCurrent) as $rID => $rRow) {
			$rFields = array_keys(ReplicaSections::SERVER_FIELDS + ($rID === $rServerID ? ReplicaSections::NODE_FIELDS : []));
			foreach ($rFields as $rField) {
				if (json_encode(self::loose($rRow[$rField] ?? null)) !== json_encode(self::loose($rCurrent[$rID][$rField] ?? null))) {
					$rDiffer[] = $rID . '.' . $rField;
				}
			}
		}
		return $rReport + [
			'mode' => 'shadow', 'rows' => count($rRows),
			'missing' => array_values(array_map('intval', array_keys(array_diff_key($rCurrent, $rRows)))),
			'extra' => array_values(array_map('intval', array_keys(array_diff_key($rRows, $rCurrent)))),
			'differ' => array_slice($rDiffer, 0, self::MAX_DIFFER),
		];
	}

	/**
	 * verify_host's list (`allowed_domains`) from the servers just applied
	 * and the section's `reseller_dns`, as cron:cache builds it from MAIN's
	 * database. A section without them (a MAIN before they were carried)
	 * leaves none, and hosts unchecked: a list without the resellers would
	 * refuse their viewers.
	 *
	 * @param array<int, array<string, mixed>> $rRows
	 */
	private static function allowedDomains(array $rRows, mixed $rResellerDns): void {
		if (ReplicaRecords::listOf($rResellerDns, 'is_string')) {
			FileCache::setCache('allowed_domains', ServerRepository::allowedDomains($rRows, $rResellerDns));
		} else {
			FileCache::delCache('allowed_domains');
		}
	}

	/**
	 * The servers cache from the `servers` and `node` sections, keyed by id as
	 * ServerRepository::getAll builds it; null unless both are MAIN's for this
	 * node (the node section names it, and the list holds its row).
	 *
	 * @param array<mixed> $rServers the `servers` section's data
	 * @param array<mixed> $rNode the `node` section's data
	 * @param array<string, mixed> $rSettings this node's settings (live_streaming_pass)
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function serverRows(array $rServers, array $rNode, int $rServerID, array $rSettings): ?array {
		if ($rServerID <= 0 || ($rNode['id'] ?? null) !== $rServerID || !ReplicaRecords::listOf($rServers['servers'] ?? null, 'is_array') || !ReplicaRecords::listOf($rServers['nodes'] ?? [], 'is_array')) {
			return null;
		}
		foreach ($rServers['nodes'] ?? [] as $rEntry) {
			if (!is_int($rEntry['sid'] ?? null) || !is_int($rEntry['gen'] ?? null) || !in_array($rEntry['state'] ?? null, ReplicaSections::NODE_STATES, true) || !is_string($rEntry['ed_pub'] ?? null)) {
				return null;
			}
		}
		$rColumns = array_fill_keys(array_keys(ReplicaSections::SERVER_FIELDS + ReplicaSections::NODE_FIELDS), null) + array_fill_keys(ReplicaSections::SERVER_LOCAL, null);
		$rOut = [];
		foreach ($rServers['servers'] as $rServer) {
			$rID = $rServer['id'] ?? null;
			if (!is_int($rID) || $rID <= 0 || isset($rOut[$rID])) {
				return null;
			}
			$rRow = ReplicaSections::typed($rServer, ReplicaSections::SERVER_FIELDS);
			if ($rID === $rServerID) {
				$rRow = ReplicaSections::typed($rNode, ReplicaSections::NODE_FIELDS) + $rRow;
			}
			$rRow = ServerRepository::decorate($rRow + $rColumns, $rSettings);
			// Liveness is MAIN's to judge: this node tries every enabled server.
			$rRow['server_online'] = !empty($rRow['enabled']) || $rID === $rServerID;
			$rRow['cluster_health'] = null;
			if (!isset($rRow['order'])) {
				$rRow['order'] = 0;
			}
			unset($rRow['php_pids']);
			$rOut[$rID] = $rRow;
		}
		return isset($rOut[$rServerID]) ? $rOut : null;
	}

	/**
	 * The `crontab` section: the jobs this node's crontab runs (CONFIG on), or
	 * which of MAIN's rows it leaves out and adds.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function crontab(bool $rAuthoritative): ?array {
		$rDoc = self::whole(ReplicaSections::CRONTAB);
		if ($rDoc === null) {
			return null;
		}
		$rJobs = is_array($rDoc) ? self::jobs($rDoc['data']) : null;
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if ($rAuthoritative) {
			// Refused: the crontab is MAIN's table's again.
			$rApplied = $rJobs !== null && FileCache::setCache(self::CRON_CACHE, $rJobs);
			self::own(ReplicaSections::CRONTAB, $rApplied ? $rReport['etag'] : null);
		}
		if ($rJobs === null) {
			return $rReport + ['mode' => 'refused'];
		}
		if ($rAuthoritative) {
			return $rReport + ['mode' => 'applied', 'jobs' => count($rJobs)];
		}
		// Mode 2 may not read MAIN's table: not compared (apply()'s `unchecked`).
		if (NodeRole::refusesConnects()) {
			return $rReport + ['mode' => 'shadow', 'jobs' => count($rJobs)];
		}
		try {
			$rCurrent = self::cronJobs(DatabaseFactory::get()) ?? [];
		} catch (\Throwable) {
			$rCurrent = [];
		}
		$rLine = static fn(array $rJob): string => $rJob['time'] . ' ' . $rJob['filename'];
		$rHave = array_combine(array_map($rLine, $rCurrent), array_column($rCurrent, 'filename')) ?: [];
		$rWant = array_combine(array_map($rLine, $rJobs), array_column($rJobs, 'filename')) ?: [];
		return $rReport + ['mode' => 'shadow', 'jobs' => count($rJobs), 'missing' => array_values(array_diff_key($rHave, $rWant)), 'extra' => array_values(array_diff_key($rWant, $rHave))];
	}

	/**
	 * @param array<mixed> $rData the `crontab` section's data
	 * @return list<array{filename: string, time: string}>|null
	 */
	private static function jobs(array $rData): ?array {
		if (!ReplicaRecords::listOf($rData['jobs'] ?? null, 'is_array')) {
			return null;
		}
		$rOut = [];
		foreach ($rData['jobs'] as $rJob) {
			$rJob = ReplicaSections::cronJob($rJob);
			if ($rJob === null) {
				return null;
			}
			$rOut[] = $rJob;
		}
		return $rOut;
	}

	/**
	 * The `cluster` section, compared with the policy the agent holds
	 * (`agent.json`, beside the replica): the agent adopts the policy itself,
	 * and no PHP on the node reads it.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function cluster(): ?array {
		$rDoc = self::whole(ReplicaSections::CLUSTER);
		if ($rDoc === null) {
			return null;
		}
		$rData = is_array($rDoc) ? $rDoc['data'] : [];
		$rReport = ['etag' => is_array($rDoc) ? $rDoc['etag'] : ''];
		if (!ReplicaRecords::listOf($rData['main_urls'] ?? null, 'is_string') || !is_int($rData['policy_ver'] ?? null) || !is_string($rData['panel_sign_pub'] ?? null)) {
			return $rReport + ['mode' => 'refused'];
		}
		$rAgent = AgentPaths::readState(self::agentState());
		$rDiffer = [];
		foreach (['main_urls', 'panel_sign_pub', 'policy_ver'] as $rKey) {
			if (($rAgent[$rKey] ?? null) !== $rData[$rKey]) {
				$rDiffer[] = $rKey;
			}
		}
		return $rReport + ['mode' => 'shadow', 'policy_ver' => $rData['policy_ver'], 'differ' => $rDiffer];
	}

	/** Scalars as strings, as a driver reads them, at any depth. */
	private static function loose(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'loose'], $rValue);
		}
		return $rValue === null ? null : (is_bool($rValue) ? (string) (int) $rValue : (string) $rValue);
	}

	/**
	 * The caches, in cron:cache's shapes, from the replica's blocklist data;
	 * null when the data is not what MAIN sends.
	 *
	 * @param array<string, mixed> $rData
	 * @param bool $rResolve resolve RTMP publishers' names (DNS); false from
	 *                       disk at boot, where DNS may not answer during the
	 *                       MAIN outage the boot is for and `service`'s
	 *                       timeout would stop the apply: a name is kept as
	 *                       given, matching no address until the next apply
	 * @return array<string, mixed>|null
	 */
	public static function caches(array $rData, bool $rResolve = true): ?array {
		$rIPs = $rData['ip'] ?? [];
		$rASNs = $rData['asn'] ?? [];
		$rUAs = $rData['ua'] ?? [];
		$rISPs = $rData['isp'] ?? [];
		$rRTMPs = $rData['rtmp'] ?? [];
		if (!ReplicaRecords::listOf($rIPs, 'is_string') || !ReplicaRecords::listOf($rASNs, 'is_int') || !ReplicaRecords::listOf($rUAs, 'is_array') || !ReplicaRecords::listOf($rISPs, 'is_array') || !ReplicaRecords::listOf($rRTMPs, 'is_array')) {
			return null;
		}
		$rUA = [];
		foreach ($rUAs as $rRow) {
			$rID = (int) ($rRow['id'] ?? 0);
			$rUA[$rID] = ['id' => $rID, 'exact_match' => (int) ($rRow['exact_match'] ?? 0), 'blocked_ua' => strtolower((string) ($rRow['user_agent'] ?? ''))];
		}
		$rISP = [];
		foreach ($rISPs as $rRow) {
			$rISP[] = ['id' => (int) ($rRow['id'] ?? 0), 'isp' => (string) ($rRow['isp'] ?? ''), 'blocked' => (int) ($rRow['blocked'] ?? 0)];
		}
		// As cron:cache on MAIN: keyed by the resolved address.
		$rRTMP = [];
		foreach ($rRTMPs as $rRow) {
			$rHost = (string) ($rRow['ip'] ?? '');
			$rRTMP[$rResolve ? gethostbyname($rHost) : $rHost] = ['password' => (string) ($rRow['password'] ?? ''), 'push' => (bool) ($rRow['push'] ?? false), 'pull' => (bool) ($rRow['pull'] ?? false)];
		}
		return ['blocked_ips' => array_values($rIPs), 'blocked_servers' => array_values($rASNs), 'blocked_ua' => $rUA, 'blocked_isp' => $rISP, 'rtmp_ips' => $rRTMP];
	}

	/**
	 * What the node uses today, for the shadow diff: the cache cron:cache built
	 * from MAIN's database, or for RTMP (which an LB never cached) the database.
	 */
	private static function current(string $rKey): mixed {
		if ($rKey === 'rtmp_ips' && class_exists(BlocklistService::class)) {
			try {
				return BlocklistService::getAllowedRTMP();
			} catch (\Throwable) {
				return [];
			}
		}
		return FileCache::getCache($rKey);
	}

	/**
	 * Entries of the current cache the replica lacks, and the reverse, compared
	 * by value (rows by their normalised content; the drivers type them apart),
	 * and by key where the cache is keyed by something outside its rows.
	 *
	 * @return array{missing: int, extra: int}
	 */
	private static function diff(mixed $rCurrent, mixed $rReplica): array {
		$rEntries = static function (mixed $rCache): array {
			$rCache = is_array($rCache) ? $rCache : [];
			$rOut = [];
			foreach ($rCache as $rKey => $rEntry) {
				$rValue = is_array($rEntry) ? (string) json_encode(array_map('strval', $rEntry)) : (string) $rEntry;
				$rOut[] = array_is_list($rCache) ? $rValue : $rKey . '=' . $rValue;
			}
			return array_count_values($rOut);
		};
		$rHave = $rEntries($rCurrent);
		$rWant = $rEntries($rReplica);
		return ['missing' => array_sum(array_diff_key($rHave, $rWant)), 'extra' => array_sum(array_diff_key($rWant, $rHave))];
	}
}

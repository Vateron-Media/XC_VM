<?php

namespace XcVm\Domain\Cluster;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * The firewall allowlist for MAIN's MariaDB (3306) and Redis (6379).
 *
 * Both listen on every interface because legacy and hybrid LBs still reach
 * them. The allowlist is on by default; while on, only these may:
 *
 * - loopback, and MAIN's own `server_ip` / `private_ip`;
 * - every other `servers` row, LBs and proxies alike (a proxy may hold a
 *   `db_grant`), except LBs whose cluster node runs in mode 2 and proxies that
 *   sign their channel (ProxyKey), which no longer use either port;
 * - the admin's `cluster_db_allowlist_extra` (IPs or CIDRs, one per line).
 *
 * `cluster:lockdown` (ClusterLockdown) narrows the same chain to loopback,
 * MAIN and the extra list, and keeps it on whatever the setting says, until
 * `--undo`.
 *
 * The rules live in a chain of their own, `XCVM_DB`, jumped to from INPUT for
 * the two ports; nothing else in the firewall is touched. reconcile() runs
 * every minute from the root signals cron: it compares the live chain with the
 * wanted one and rewrites it only when they differ, so a flush, a reboot or a
 * new server is corrected within a minute. Turning the setting off removes the
 * jump and the chain.
 *
 * Commands run through an executor with literal argv (no shell); tests pass
 * their own.
 */
final class DbAllowlist {
	use DatabaseAware;

	public const CHAIN = 'XCVM_DB';

	public const PORTS = [3306, 6379];

	/**
	 * `cluster_meta` row that cluster:lockdown writes (ClusterLockdown): while
	 * it stands the chain is on whatever the setting says, and admits only
	 * loopback, MAIN's own addresses and the admin's extra list — no load
	 * balancer, no proxy.
	 */
	public const LOCKDOWN_META = 'db_lockdown';

	/** @var callable(list<string>, ?string): array{0: int, 1: string} */
	private $rExec;

	/** @var callable(string): list<string> */
	private $rResolve;

	/** @var array{4: ?string, 6: ?string} */
	private array $rTools;

	/**
	 * @param null|callable(list<string>, ?string): array{0: int, 1: string} $rExec    Runs argv with optional stdin: [exit code, stdout].
	 * @param null|callable(string): list<string>                          $rResolve Hostname → IPv4 addresses.
	 * @param null|array{4: ?string, 6: ?string}                            $rTools   iptables / ip6tables paths (null = not installed).
	 */
	public function __construct(?callable $rExec = null, ?callable $rResolve = null, ?array $rTools = null) {
		$this->rExec = $rExec ?? [self::class, 'run'];
		$this->rResolve = $rResolve ?? static fn (string $rHost): array => gethostbynamel($rHost) ?: [];
		$this->rTools = $rTools ?? [4 => self::tool('iptables'), 6 => self::tool('ip6tables')];
	}

	/**
	 * Parse the admin's extra list: IPs or CIDRs separated by newlines, commas
	 * or spaces. Each is normalised to its network (`10.0.0.7/8` → `10.0.0.0/8`).
	 *
	 * @return array{0: list<string>, 1: bool} [CIDRs, any invalid]
	 */
	public static function parseExtra(string $rValue): array {
		$rOut = [];
		foreach (preg_split('/[\s,]+/', trim($rValue)) ?: [] as $rItem) {
			if ($rItem === '') {
				continue;
			}
			$rCidr = self::cidr($rItem);
			if ($rCidr === null) {
				return [[], true];
			}
			$rOut[] = $rCidr;
		}
		return [array_values(array_unique($rOut)), false];
	}

	/** `ip` or `ip/prefix` → the network in iptables' own spelling, or null. */
	public static function cidr(string $rItem): ?string {
		$rParts = explode('/', trim($rItem), 2);
		$rBin = filter_var($rParts[0], FILTER_VALIDATE_IP) ? inet_pton($rParts[0]) : false;
		if ($rBin === false) {
			return null;
		}
		$rBits = strlen($rBin) * 8;
		if (isset($rParts[1])) {
			if (!ctype_digit($rParts[1]) || (int) $rParts[1] > $rBits) {
				return null;
			}
			$rPrefix = (int) $rParts[1];
		} else {
			$rPrefix = $rBits;
		}
		for ($i = 0; $i < strlen($rBin); $i++) {
			$rKeep = max(0, min(8, $rPrefix - $i * 8));
			$rBin[$i] = chr(ord($rBin[$i]) & (0xFF << (8 - $rKeep)) & 0xFF);
		}
		return inet_ntop($rBin) . '/' . $rPrefix;
	}

	/**
	 * The allowed sources, split by family.
	 *
	 * @param list<array<string, mixed>> $rServers  `servers` rows (id, is_main, server_ip, private_ip).
	 * @param list<int>                  $rMode2Ids Server ids whose cluster node is in mode 2.
	 * @param list<string>               $rExtra    Normalised CIDRs from the setting.
	 * @return array{4: list<string>, 6: list<string>}
	 */
	public function compute(array $rServers, array $rMode2Ids, array $rExtra): array {
		$rOut = [4 => [], 6 => []];
		foreach ($rServers as $rServer) {
			if (empty($rServer['is_main']) && in_array((int) $rServer['id'], $rMode2Ids, true)) {
				continue;
			}
			foreach (['server_ip', 'private_ip'] as $rKey) {
				$rAddress = trim((string) ($rServer[$rKey] ?? ''));
				if ($rAddress === '') {
					continue;
				}
				$rList = filter_var($rAddress, FILTER_VALIDATE_IP) ? [$rAddress] : ($this->rResolve)($rAddress);
				foreach ($rList as $rIP) {
					$rCidr = self::cidr($rIP);
					if ($rCidr !== null) {
						$rOut[str_contains($rCidr, ':') ? 6 : 4][] = $rCidr;
					}
				}
			}
		}
		foreach ($rExtra as $rCidr) {
			$rOut[str_contains($rCidr, ':') ? 6 : 4][] = $rCidr;
		}
		foreach ($rOut as $rFamily => $rList) {
			$rList = array_values(array_unique($rList));
			sort($rList);
			$rOut[$rFamily] = $rList;
		}
		return $rOut;
	}

	/**
	 * One cron pass: read the setting, bring the firewall to it, and audit a
	 * change. Returns the per-family result, or null when nothing was done
	 * because the settings or servers could not be read (the firewall is then
	 * left as it is, never opened or closed on a guess).
	 *
	 * @return array{4: string, 6: string}|null
	 */
	public function sync(string $rActor = 'root'): ?array {
		$db = self::db();
		if (!$db->query('SELECT `cluster_db_allowlist` FROM `settings` LIMIT 1;')) {
			return null;
		}
		$rOn = !empty($db->get_row()['cluster_db_allowlist']) || self::lockedDown();
		$rWanted = null;
		if ($rOn) {
			$rWanted = $this->wanted();
			if ($rWanted === null) {
				return null;
			}
		}
		$rResult = $this->reconcile($rWanted);
		if (in_array('applied', $rResult, true) || in_array('removed', $rResult, true)) {
			ClusterAudit::log('cluster.db_allowlist', null, ['on' => $rOn, 'v4' => $rResult[4], 'v6' => $rResult[6], 'sources' => $rWanted === null ? 0 : count($rWanted[4]) + count($rWanted[6])], $rActor);
		}
		return $rResult;
	}

	/** What the database says should be allowed, or null when the servers table cannot be read. */
	public function wanted(): ?array {
		$db = self::db();
		if (!$db->query('SELECT `id`, `is_main`, `server_ip`, `private_ip` FROM `servers`;')) {
			return null;
		}
		$rServers = $db->get_rows();
		$rHasMain = false;
		foreach ($rServers as $rServer) {
			$rHasMain = $rHasMain || !empty($rServer['is_main']);
		}
		if (!$rHasMain) {
			return null;
		}
		if (self::lockedDown()) {
			// Lockdown: MAIN's own addresses and the extra list only.
			$rServers = array_values(array_filter($rServers, static fn(array $rServer): bool => !empty($rServer['is_main'])));
		}
		$rMode2 = [];
		if ($db->query('SELECT `server_id` FROM `cluster_nodes` WHERE `mode` = 2;')) {
			foreach ($db->get_rows() as $rRow) {
				$rMode2[] = (int) $rRow['server_id'];
			}
		}
		// A proxy that signs its channel (ProxyKey, D8) uses MAIN's API only.
		if ($db->query('SELECT `id` FROM `servers` WHERE `server_type` = 1 AND `proxy_signed` = 1;')) {
			foreach ($db->get_rows() as $rRow) {
				$rMode2[] = (int) $rRow['id'];
			}
		}
		$db->query('SELECT `cluster_db_allowlist_extra` FROM `settings` LIMIT 1;');
		[$rExtra] = self::parseExtra((string) ($db->get_row()['cluster_db_allowlist_extra'] ?? ''));
		return $this->compute($rServers, $rMode2, $rExtra);
	}

	/** Is MAIN locked down (cluster:lockdown, until --undo)? */
	public static function lockedDown(): bool {
		try {
			return ClusterMeta::get(self::LOCKDOWN_META) !== null;
		} catch (\Throwable) {
			return false; // no cluster_meta table: never locked down
		}
	}

	/**
	 * The chain as `iptables -S XCVM_DB` prints it.
	 *
	 * @param list<string> $rCidrs
	 * @return list<string>
	 */
	public static function chainLines(array $rCidrs): array {
		$rLines = ['-N ' . self::CHAIN, '-A ' . self::CHAIN . ' -i lo -j ACCEPT'];
		foreach ($rCidrs as $rCidr) {
			$rLines[] = '-A ' . self::CHAIN . ' -s ' . $rCidr . ' -j ACCEPT';
		}
		$rLines[] = '-A ' . self::CHAIN . ' -j DROP';
		return $rLines;
	}

	/**
	 * `iptables-restore --noflush` input that replaces the chain's rules in one
	 * commit (declaring a chain flushes it) and leaves every other chain alone.
	 *
	 * @param list<string> $rCidrs
	 */
	public static function ruleset(array $rCidrs): string {
		$rOut = "*filter\n:" . self::CHAIN . " - [0:0]\n";
		foreach (array_slice(self::chainLines($rCidrs), 1) as $rLine) {
			$rOut .= $rLine . "\n";
		}
		return $rOut . "COMMIT\n";
	}

	/** @return list<string> The INPUT rule, after the `-A/-C/-D/-I INPUT` part. */
	public static function jumpRule(): array {
		return ['-p', 'tcp', '-m', 'multiport', '--dports', implode(',', self::PORTS), '-j', self::CHAIN];
	}

	/**
	 * Bring the firewall to the wanted state and say what changed per family:
	 * `ok` (already right), `applied`, `removed`, `absent` (off and nothing to
	 * remove), `skipped` (no such tool) or `error: …`.
	 *
	 * @param array{4: list<string>, 6: list<string>}|null $rWanted null = remove.
	 * @return array{4: string, 6: string}
	 */
	public function reconcile(?array $rWanted): array {
		$rOut = [];
		foreach ([4, 6] as $rFamily) {
			$rTool = $this->rTools[$rFamily];
			if ($rTool === null) {
				$rOut[$rFamily] = 'skipped';
				continue;
			}
			$rOut[$rFamily] = $rWanted === null ? $this->remove($rTool) : $this->apply($rTool, $rWanted[$rFamily]);
		}
		return $rOut;
	}

	/**
	 * The live chain per family, or null when it does not exist.
	 *
	 * @return array{4: ?list<string>, 6: ?list<string>}
	 */
	public function live(): array {
		$rOut = [4 => null, 6 => null];
		foreach ([4, 6] as $rFamily) {
			if ($this->rTools[$rFamily] !== null) {
				$rOut[$rFamily] = $this->chain($this->rTools[$rFamily]);
			}
		}
		return $rOut;
	}

	/**
	 * Established remote peers on 3306/6379 that the wanted list would refuse:
	 * what turning the allowlist on would cut.
	 *
	 * @param array{4: list<string>, 6: list<string>} $rWanted
	 * @return list<string> "ip:port" of the peer, sorted.
	 */
	public function refusedPeers(array $rWanted): array {
		[$rCode, $rStdout] = ($this->rExec)(['ss', '-Htn', 'state', 'established', '(', 'sport', '=', ':3306', 'or', 'sport', '=', ':6379', ')'], null);
		if ($rCode !== 0) {
			return [];
		}
		$rOut = [];
		foreach (preg_split('/\n/', trim($rStdout)) ?: [] as $rLine) {
			$rCols = preg_split('/\s+/', trim($rLine));
			$rPeer = $rCols[3] ?? '';
			if (!preg_match('/^\[?(.+?)\]?:(\d+)$/', $rPeer, $rM)) {
				continue;
			}
			$rIP = str_starts_with($rM[1], '::ffff:') && filter_var(substr($rM[1], 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? substr($rM[1], 7) : $rM[1];
			if (!filter_var($rIP, FILTER_VALIDATE_IP) || $this->isLoopback($rIP) || self::covers($rWanted[str_contains($rIP, ':') ? 6 : 4], $rIP)) {
				continue;
			}
			$rOut[] = $rPeer;
		}
		$rOut = array_values(array_unique($rOut));
		sort($rOut);
		return $rOut;
	}

	/** @param list<string> $rCidrs */
	public static function covers(array $rCidrs, string $rIP): bool {
		$rBin = inet_pton($rIP);
		foreach ($rCidrs as $rCidr) {
			[$rNet, $rPrefix] = explode('/', $rCidr);
			$rNetBin = inet_pton($rNet);
			if ($rBin === false || $rNetBin === false || strlen($rNetBin) !== strlen($rBin)) {
				continue;
			}
			$rMasked = self::cidr($rIP . '/' . $rPrefix);
			if ($rMasked === $rCidr) {
				return true;
			}
		}
		return false;
	}

	/** @param list<string> $rCidrs */
	private function apply(string $rTool, array $rCidrs): string {
		$rChanged = false;
		if ($this->chain($rTool) !== self::chainLines($rCidrs)) {
			[$rCode, $rStdout] = ($this->rExec)([$rTool . '-restore', '--noflush'], self::ruleset($rCidrs));
			if ($rCode !== 0) {
				return 'error: ' . basename($rTool) . '-restore exited ' . $rCode . ($rStdout !== '' ? ' (' . trim($rStdout) . ')' : '');
			}
			$rChanged = true;
		}
		if (($this->rExec)(array_merge([$rTool, '-C', 'INPUT'], self::jumpRule()), null)[0] !== 0) {
			[$rCode] = ($this->rExec)(array_merge([$rTool, '-I', 'INPUT', '1'], self::jumpRule()), null);
			if ($rCode !== 0) {
				return 'error: ' . basename($rTool) . ' -I INPUT exited ' . $rCode;
			}
			$rChanged = true;
		}
		return $rChanged ? 'applied' : 'ok';
	}

	private function remove(string $rTool): string {
		$rRemoved = false;
		for ($i = 0; $i < 16 && ($this->rExec)(array_merge([$rTool, '-D', 'INPUT'], self::jumpRule()), null)[0] === 0; $i++) {
			$rRemoved = true;
		}
		if ($this->chain($rTool) !== null) {
			($this->rExec)([$rTool, '-F', self::CHAIN], null);
			[$rCode] = ($this->rExec)([$rTool, '-X', self::CHAIN], null);
			if ($rCode !== 0) {
				return 'error: ' . basename($rTool) . ' -X exited ' . $rCode;
			}
			$rRemoved = true;
		}
		return $rRemoved ? 'removed' : 'absent';
	}

	/** @return ?list<string> */
	private function chain(string $rTool): ?array {
		[$rCode, $rStdout] = ($this->rExec)([$rTool, '-S', self::CHAIN], null);
		if ($rCode !== 0) {
			return null;
		}
		return array_values(array_filter(array_map('trim', explode("\n", $rStdout)), static fn (string $rLine): bool => $rLine !== ''));
	}

	private function isLoopback(string $rIP): bool {
		return $rIP === '::1' || str_starts_with($rIP, '127.');
	}

	/** Absolute path of an iptables tool, or null when the host has none. */
	public static function tool(string $rName): ?string {
		foreach (['/usr/sbin/', '/sbin/', '/usr/bin/', '/bin/'] as $rDir) {
			if (is_executable($rDir . $rName) && is_executable($rDir . $rName . '-restore')) {
				return $rDir . $rName;
			}
		}
		return null;
	}

	/**
	 * Run argv without a shell.
	 *
	 * @param list<string> $rArgv
	 * @return array{0: int, 1: string}
	 */
	public static function run(array $rArgv, ?string $rStdin): array {
		$rProc = proc_open($rArgv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		if (!is_resource($rProc)) {
			return [127, ''];
		}
		if ($rStdin !== null) {
			fwrite($rPipes[0], $rStdin);
		}
		fclose($rPipes[0]);
		$rStdout = (string) stream_get_contents($rPipes[1]);
		$rStderr = (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		$rCode = proc_close($rProc);
		return [$rCode, $rCode === 0 ? $rStdout : trim($rStdout . "\n" . $rStderr)];
	}
}

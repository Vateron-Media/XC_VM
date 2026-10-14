<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\Seal;

/**
 * The node's root side of MAIN's credential rotations (plan, section 10,
 * step 3; ADR 0004, Phase 9): `node.root rotate_redis` and `rotate_db`, run
 * by cluster:root (or, on a node without root commands, by the root signals
 * cron from a `signals` row) through RootSignalsCronJob::executeAction().
 *
 * A signed command carries the new secret SEALed to the node's box key
 * (purpose SEAL_PURPOSE, the node's uuid as context): the command row MAIN
 * keeps and the agent relays holds ciphertext only, and the node opens it
 * with the key its agent holds. A `signals` row — a legacy node that takes
 * no root command — carries no secret: that node reads MAIN's settings row
 * (`redis_password`), as it always has, which
 * it can only because it still holds MAIN's database. The DB password has no
 * such path, so `rotate_db` needs the sealed form.
 *
 * config.enc is written by xcvm_core (config_set_redis, config_set_db) as
 * root; it is handed back to the config directory's
 * owner so php-fpm (xc_vm) still reads it.
 */
final class RootCredentials {
	/** The SEAL purpose of a credential a root command carries. */
	public const SEAL_PURPOSE = 'root.credentials';

	/**
	 * The extension's setter for the DB password in config.enc:
	 * `XC_VM::config_set_db(string $password): bool`, keeping host, port,
	 * database and user (xcvm_core's ADR-002). `cluster:rotate-db-password`
	 * sends the password it takes.
	 */
	public const DB_SETTER = 'config_set_db';

	/** @var (callable(string, int, string): bool)|null */
	private static $rRedisSetter = null;

	/** @var (callable(string): bool)|null */
	private static $rDbSetter = null;

	private static ?string $rStatePath = null;

	private static ?string $rConfigDir = null;

	/**
	 * Tests: other setters, agent state and config directory; null restores
	 * the extension and the defaults.
	 *
	 * @param (callable(string, int, string): bool)|null $rRedis
	 * @param (callable(string): bool)|null $rDb
	 */
	public static function useSeams(?callable $rRedis, ?callable $rDb = null, ?string $rStatePath = null, ?string $rConfigDir = null): void {
		self::$rRedisSetter = $rRedis;
		self::$rDbSetter = $rDb;
		self::$rStatePath = $rStatePath;
		self::$rConfigDir = $rConfigDir;
	}

	/**
	 * `rotate_redis {host, port, auth_sealed?}`: point config.enc's Redis at
	 * $host:$port with the new password. Returns the line for the result.
	 *
	 * @param array<string, mixed> $rData
	 * @throws \RuntimeException refused (the ack carries the message)
	 */
	public static function rotateRedis(array $rData, ?object $rDb): string {
		$rHost = self::host($rData['host'] ?? null);
		$rPort = $rData['port'] ?? 6379;
		if (!is_int($rPort) || $rPort < 1 || $rPort > 65535) {
			throw new \RuntimeException('rotate_redis: bad port');
		}
		if (isset($rData['auth_sealed'])) {
			$rPass = self::open($rData['auth_sealed']);
		} else {
			// A signals row: this legacy node reads MAIN's settings, as it always has.
			if ($rDb === null || !$rDb->query('SELECT `redis_password` FROM `settings` LIMIT 1;')) {
				throw new \RuntimeException('rotate_redis: MAIN\'s settings cannot be read');
			}
			$rPass = (string) ($rDb->get_row()['redis_password'] ?? '');
		}
		if ($rPass === '' || $rPass === '#PASSWORD#') {
			throw new \RuntimeException('rotate_redis: no password');
		}
		$rSet = self::$rRedisSetter ?? static fn(string $rH, int $rP, string $rA): bool => method_exists('XC_VM', 'config_set_redis') && \XC_VM::config_set_redis($rH, $rP, $rA);
		if (!$rSet($rHost, $rPort, $rPass)) {
			throw new \RuntimeException('rotate_redis: config.enc was not written (xcvm_core without config_set_redis?)');
		}
		self::handBack();
		return 'Redis credentials updated (' . $rHost . ':' . $rPort . ')';
	}

	/**
	 * `rotate_db {auth_sealed}`: the new DB password into config.enc. Only a
	 * sealed one: nothing else could carry it.
	 *
	 * @param array<string, mixed> $rData
	 * @throws \RuntimeException refused
	 */
	public static function rotateDb(array $rData): string {
		if (!isset($rData['auth_sealed'])) {
			throw new \RuntimeException('rotate_db: only a signed root command carries the password');
		}
		$rPass = self::open($rData['auth_sealed']);
		$rSet = self::$rDbSetter ?? static fn(string $rP): bool => method_exists('XC_VM', self::DB_SETTER) && (bool) call_user_func(['XC_VM', self::DB_SETTER], $rP);
		if (!$rSet($rPass)) {
			throw new \RuntimeException('rotate_db: config.enc was not written (xcvm_core without ' . self::DB_SETTER . '?)');
		}
		self::handBack();
		return 'Database credentials updated';
	}

	/** Seal a credential for a node's root side (MAIN). */
	public static function seal(string $rNodeBoxPub, string $rNodeUuid, string $rSecret): string {
		return base64_encode(Seal::seal($rNodeBoxPub, self::SEAL_PURPOSE, $rNodeUuid, $rSecret));
	}

	/** Open a sealed credential with the node's box key, as its agent holds it. */
	private static function open(mixed $rSealed): string {
		$rState = AgentPaths::readState(self::$rStatePath);
		$rSk = base64_decode((string) ($rState['node_box_sk'] ?? ''), true);
		$rBlob = is_string($rSealed) ? base64_decode($rSealed, true) : false;
		$rPlain = ($rSk === false || $rBlob === false) ? null : Seal::open($rSk, self::SEAL_PURPOSE, (string) ($rState['node_uuid'] ?? ''), $rBlob);
		if ($rPlain === null || $rPlain === '') {
			throw new \RuntimeException('the credential does not open for this node');
		}
		return $rPlain;
	}

	/** An IP address or a plain host name. */
	private static function host(mixed $rHost): string {
		if (!is_string($rHost) || !(filter_var($rHost, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]*[A-Za-z0-9])?)*$/', $rHost))) {
			throw new \RuntimeException('bad host');
		}
		return $rHost;
	}

	/** config.enc back to the config directory's owner (the extension wrote it as root). */
	private static function handBack(): void {
		$rDir = self::$rConfigDir ?? (defined('CONFIG_PATH') ? (string) CONFIG_PATH : null);
		if ($rDir === null || !is_file($rDir . 'config.enc')) {
			return;
		}
		if (($rOwner = @fileowner($rDir)) !== false) {
			@chown($rDir . 'config.enc', $rOwner);
		}
		if (($rGroup = @filegroup($rDir)) !== false) {
			@chgrp($rDir . 'config.enc', $rGroup);
		}
		@chmod($rDir . 'config.enc', 0600);
	}
}

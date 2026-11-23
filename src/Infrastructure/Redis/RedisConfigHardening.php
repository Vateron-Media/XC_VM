<?php

namespace XcVm\Infrastructure\Redis;

/**
 * RedisConfigHardening — disables the Redis admin commands XC_VM never uses.
 *
 * MAIN's Redis listens on every interface (LBs connect to it), so anyone who
 * learns the password could otherwise rewrite the server's config (CONFIG SET
 * dir/dbfilename is the classic file-write), make it a replica of a hostile
 * host, load a module or shut it down. Renaming a command to "" removes it.
 *
 * Kept on purpose: FLUSHALL (admin cache page) and FLUSHDB (RedisCache::clear),
 * EVAL/EVALSHA (Lua scripts). An operator who needs one of the disabled
 * commands renames it to a secret name instead of deleting the line: any
 * `rename-command <CMD>` line counts as handled and is never re-added.
 *
 * Applied to the template (fresh installs) and, by StatusCommand, to the
 * generated config of existing installs (bin/redis is never overwritten by
 * an update). Takes effect on the next Redis restart.
 *
 * @package XC_VM_Infrastructure_Redis
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RedisConfigHardening {
	/** Commands renamed away. Every one is supported by the distro Redis packages. */
	public const DISABLED = ['CONFIG', 'DEBUG', 'SHUTDOWN', 'SLAVEOF', 'REPLICAOF', 'MIGRATE', 'MODULE'];

	/**
	 * Remove obsolete KeyDB-only threading directives and append a
	 * `rename-command <CMD> ""` line for each disabled command not handled yet.
	 *
	 * @return array{0: string, 1: string[]} The config and the commands added.
	 */
	public static function apply(string $rConfig): array {
		$rConfig = preg_replace('/^[ \t]*server-(?:threads|thread-affinity)[ \t]+[^\r\n]*(?:\R|$)/mi', '', $rConfig) ?? $rConfig;
		$rAdded = [];
		foreach (self::DISABLED as $rCommand) {
			if (preg_match('/^[ \t]*rename-command[ \t]+' . $rCommand . '[ \t]/mi', $rConfig)) {
				continue;
			}
			$rConfig = rtrim($rConfig, "\n") . "\nrename-command " . $rCommand . ' ""' . "\n";
			$rAdded[] = $rCommand;
		}
		return [$rConfig, $rAdded];
	}
}

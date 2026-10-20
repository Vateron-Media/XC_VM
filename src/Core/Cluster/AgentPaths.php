<?php

namespace XcVm\Core\Cluster;

/**
 * Where the node's agent (xc_agent) and PHP meet on disk: `config/cluster/`
 * under CONFIG_PATH. Lives in Core: it ships to LBs, where the agent runs.
 *
 * A process may ask before CONFIG_PATH is defined, and each caller keeps its
 * own answer for that, named here rather than written out at each site:
 *
 * - file(): the install's config directory (`/home/xc_vm/config/`), for
 *   the agent's socket and the directories PHP and the agent share;
 * - fileOrNull(): null, for a file whose absence means "off";
 * - fileEarly(): MAIN_HOME's `config/`, else null, for the file read before
 *   the boot defines the constants (console.php asks ReplicaBoot before it
 *   boots; bootstrap.php defines MAIN_HOME as it loads).
 */
final class AgentPaths {
	/** The agent's directory, relative to the config directory. */
	public const DIR = 'cluster/';

	/** The agent's state (node_uuid, node_box_sk, panel_sign_pub, ...). */
	public const STATE = self::DIR . 'agent.json';

	/** The agent's local socket (AgentClient). */
	public const SOCKET = self::DIR . 'agent.sock';

	/** The node-crons ownership marker ({"crons":[...]}) the chores loop touches. */
	public const NODE_CRONS = self::DIR . 'node_crons';

	/** The config directory when CONFIG_PATH is not defined (file()). */
	public const INSTALL_CONFIG = '/home/xc_vm/config/';

	/** CONFIG_PATH, or the install's config directory without it. */
	public static function configDir(): string {
		return defined('CONFIG_PATH') ? CONFIG_PATH : self::INSTALL_CONFIG;
	}

	/** $rRel under configDir(). */
	public static function file(string $rRel): string {
		return self::configDir() . $rRel;
	}

	/** $rRel under CONFIG_PATH, or null without it. */
	public static function fileOrNull(string $rRel): ?string {
		return defined('CONFIG_PATH') ? CONFIG_PATH . $rRel : null;
	}

	/** $rRel under CONFIG_PATH, else under MAIN_HOME's config/, else null. */
	public static function fileEarly(string $rRel): ?string {
		return self::fileOrNull($rRel) ?? (defined('MAIN_HOME') ? MAIN_HOME . 'config/' . $rRel : null);
	}

	/**
	 * The agent's state file, decoded: [] when it is missing or not a JSON
	 * object or list. Nothing in it is checked here.
	 *
	 * @param string|null $rPath another file (a test's, or one beside a moved replica); null for file(STATE)
	 * @return array<mixed>
	 */
	public static function readState(?string $rPath = null): array {
		$rState = json_decode((string) @file_get_contents($rPath ?? self::file(self::STATE)), true);
		return is_array($rState) ? $rState : [];
	}
}

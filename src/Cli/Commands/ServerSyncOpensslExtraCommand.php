<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Bring load balancers onto the main's OPENSSL_EXTRA.
 *
 * Run on the MAIN: php console.php server:sync-openssl-extra <server_id>|--all [--force]
 *
 * OPENSSL_EXTRA keys the stream tokens the main mints for the redirects an LB
 * serves. A main set up by the current installer holds a random value in
 * config/openssl_extra, while an LB added with server:install before
 * provisioning shipped that file runs on the built-in default and rejects
 * those tokens. Every node publishes a fingerprint of its value
 * (cron:servers); server:diagnose shows a mismatch.
 *
 * For each streaming LB that reports another fingerprint this queues one root
 * signal carrying the main's value. The LB's cron:root_signals writes it
 * (OpensslExtra::install) and, for OpensslExtra::PREVIOUS_WINDOW seconds, still
 * opens tokens it minted with the value it replaced. --force also sends to LBs
 * that report the same value, none (not updated yet) or are offline.
 *
 * It refuses to send a value the main's php-fpm does not mint with (see sync()).
 * The value passes through the signals table until the LB's root cron deletes
 * the row (about a minute); a legacy LB reads that database in full anyway, and
 * proxy_api never hands these rows to a proxy.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServerSyncOpensslExtraCommand implements CommandInterface {
	use DatabaseAware;

	public function getName(): string {
		return 'server:sync-openssl-extra';
	}

	public function getDescription(): string {
		return "Send the main's OPENSSL_EXTRA to load balancers that report another one";
	}

	public function execute(array $rArgs): int {
		$rForce = in_array('--force', $rArgs, true);
		$rArgs = array_values(array_diff($rArgs, ['--force']));
		$rAll = (($rArgs[0] ?? '') === '--all');
		$rTargetID = $rAll ? 0 : intval($rArgs[0] ?? 0);
		if (!$rAll && $rTargetID <= 0) {
			echo "Usage: php console.php server:sync-openssl-extra <server_id>|--all [--force]\n";
			return 1;
		}
		if (!defined('SERVER_ID')) {
			echo "Run this on the MAIN server.\n";
			return 1;
		}

		return self::sync(ServerRepository::getAll(true), (int) SERVER_ID, $rAll, $rTargetID, $rForce, CONFIG_PATH . 'openssl_extra');
	}

	/**
	 * Queue the main's OPENSSL_EXTRA for the chosen nodes.
	 *
	 * $rFile is the main's config/openssl_extra: without a value there the main
	 * runs on the built-in default and nothing is sent. The value sent is this
	 * process's OPENSSL_EXTRA, and only when it is the one the main's php-fpm
	 * mints with, as the main's own cron:servers (xc_vm) publishes it: run as
	 * root, this process can read a file that xc_vm cannot.
	 *
	 * @param array<int,array<string,mixed>> $rServers ServerRepository::getAll() rows.
	 */
	public static function sync(array $rServers, int $rMainID, bool $rAll, int $rTargetID, bool $rForce, string $rFile, ?callable $rSend = null): int {
		if (empty($rServers[$rMainID]['is_main'])) {
			echo "Run this on the MAIN server.\n";
			return 1;
		}
		if (!$rAll && !isset($rServers[$rTargetID])) {
			echo "Server #{$rTargetID} not found.\n";
			return 1;
		}

		$rValue = is_file($rFile) ? @file_get_contents($rFile) : '';
		if ($rValue === false) {
			echo "Cannot read {$rFile}: run this as xc_vm or root.\n";
			return 1;
		}
		if (trim($rValue) === '') {
			echo "The main has no {$rFile} and runs on the built-in OPENSSL_EXTRA, as LBs installed by server:install do: nothing to send.\n";
			echo "A node that still reports a mismatch has a stale {$rFile} of its own; delete it there.\n";
			return 0;
		}

		$rMainPrint = OpensslExtra::fingerprint(OPENSSL_EXTRA);
		$rPublished = OpensslExtra::reportedFingerprint($rServers[$rMainID]);
		if ($rPublished === null) {
			echo "The main has not published its OPENSSL_EXTRA fingerprint yet: wait for its next cron:servers run (within a minute) and try again.\n";
			return 1;
		}
		if (!hash_equals($rPublished, $rMainPrint)) {
			echo "The main publishes another OPENSSL_EXTRA than {$rFile} holds, so its php-fpm does not mint with that value; sending it would break every token the main mints.\n";
			echo "Either xc_vm cannot read the file (chown xc_vm:xc_vm {$rFile} && chmod 600 {$rFile}), or it changed within the last minute (wait for cron:servers).\n";
			return 1;
		}

		$rCustomData = OpensslExtra::signal(OPENSSL_EXTRA);
		$rQueued = 0;
		$rFailed = 0;
		$rSend ??= static fn(int $rServerID, string $rData): bool => NodeActions::send($rServerID, $rData, self::db());
		foreach (self::targets($rServers, $rAll, $rTargetID, $rMainPrint, $rForce) as $rServerID => $rSkip) {
			$rName = $rServers[$rServerID]['server_name'] ?? '';
			if ($rSkip !== null) {
				echo "#{$rServerID} {$rName}: skipped, {$rSkip}\n";
				continue;
			}
			if ($rSend((int) $rServerID, $rCustomData)) {
				echo "#{$rServerID} {$rName}: queued\n";
				$rQueued++;
			} else {
				echo "#{$rServerID} {$rName}: not queued (the node has no ready signed root-command channel)\n";
				$rFailed++;
			}
		}
		echo "{$rQueued} node(s) queued. Each applies it at its next cluster:root run and reports it at its next cron:servers run; check with server:diagnose <id>.\n";

		return $rFailed === 0 ? 0 : 1;
	}

	/**
	 * The nodes a run considers, each with why it is skipped (null: it gets the
	 * main's value). --all leaves the main out; a single target is always listed.
	 *
	 * @param array<int,array<string,mixed>> $rServers
	 * @return array<int,?string> server_id => skip reason
	 */
	public static function targets(array $rServers, bool $rAll, int $rTargetID, string $rMainPrint, bool $rForce): array {
		$rTargets = [];
		foreach (($rAll ? $rServers : [$rTargetID => $rServers[$rTargetID] ?? []]) as $rServerID => $rServer) {
			if ($rAll && !empty($rServer['is_main'])) {
				continue;
			}
			$rTargets[$rServerID] = self::skipReason($rServer, $rMainPrint, $rForce);
		}

		return $rTargets;
	}

	/**
	 * Why $rServer does not get the main's value, or null when it does.
	 *
	 * @param string $rMainPrint OpensslExtra::fingerprint() of the main's value.
	 */
	public static function skipReason(array $rServer, string $rMainPrint, bool $rForce): ?string {
		if (!empty($rServer['is_main'])) {
			return 'the main';
		}
		if ((int) ($rServer['server_type'] ?? 0) === 1) {
			return 'a proxy';
		}
		if ($rForce) {
			return null;
		}
		$rPrint = OpensslExtra::reportedFingerprint($rServer);
		if ($rPrint === null) {
			return 'unknown (node not updated): update it, or pass --force';
		}
		if (hash_equals($rMainPrint, $rPrint)) {
			return 'already in sync';
		}
		if (empty($rServer['server_online'])) {
			return 'offline: pass --force to queue it anyway';
		}

		return null;
	}
}

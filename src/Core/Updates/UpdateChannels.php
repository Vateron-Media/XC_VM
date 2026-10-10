<?php

namespace XcVm\Core\Updates;

use XcVm\Core\Config\SettingsManager;

/**
 * UpdateChannels — resolves the per-repository release channel (stable/beta/dev).
 *
 * Replaces the former single global `update_channel` setting with one channel
 * per core GitHub repository:
 *   - MAIN   (panel updates)           → `update_channel_main`
 *   - BIN    (compiled binaries)       → `update_channel_bin`
 *   - FANOUT (xc_fanout daemon)        → `update_channel_fanout`
 *
 * The ffmpeg builds (FFMPEG) are compiled binaries too, and follow BIN.
 *
 * Only MAIN offers the 'dev' channel: nightly panel builds published to the
 * releases-only GIT_REPO_DEV repository. BIN and FANOUT have no nightly builds.
 *
 * The GeoLite/ASN data repo (UPDATE) and the proxy archive repo (PROXY) have no
 * channel of their own and follow the MAIN panel channel ('dev' there behaves
 * like 'beta', as those repos have no dev repository).
 *
 * @package XC_VM_Core_Updates
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class UpdateChannels {
	/**
	 * Channel for the MAIN panel repository (also used by UPDATE/PROXY).
	 *
	 * @return string 'stable', 'beta' or 'dev'
	 */
	public static function main(): string {
		$channel = SettingsManager::getString('update_channel_main', 'stable');
		return $channel === 'dev' ? 'dev' : self::normalize($channel);
	}

	/**
	 * Channel for the BIN compiled-binaries repository.
	 *
	 * @return string 'stable' or 'beta'
	 */
	public static function bin(): string {
		return self::normalize(SettingsManager::getString('update_channel_bin', 'stable'));
	}

	/**
	 * Channel for the FANOUT (xc_fanout daemon) repository.
	 *
	 * @return string 'stable' or 'beta'
	 */
	public static function fanout(): string {
		return self::normalize(SettingsManager::getString('update_channel_fanout', 'stable'));
	}

	/**
	 * Channel for a repository identified by its GitHub repo name (a GIT_REPO_*
	 * constant value). Unknown repos — including UPDATE and PROXY — follow MAIN.
	 *
	 * @param string $repo Repository name (e.g. GIT_REPO_BIN)
	 * @return string 'stable', 'beta' or (MAIN-following repos only) 'dev'
	 */
	public static function forRepo(string $repo): string {
		if ((defined('GIT_REPO_BIN') && $repo === GIT_REPO_BIN) || (defined('GIT_REPO_FFMPEG') && $repo === GIT_REPO_FFMPEG)) {
			return self::bin();
		}
		if (defined('GIT_REPO_FANOUT') && $repo === GIT_REPO_FANOUT) {
			return self::fanout();
		}
		return self::main();
	}

	/**
	 * Release client for the panel (MAIN) repository on the configured channel.
	 * On 'dev' it also reads the nightly builds from GIT_REPO_DEV.
	 */
	public static function mainReleases(): GitHubReleases {
		return new GitHubReleases(GIT_OWNER, GIT_REPO_MAIN, self::main(), null, GIT_REPO_DEV);
	}

	/**
	 * The panel's channel in the single `update_channel` setting a server on an
	 * older release reads to pick its update (stable 2.3.9 and the betas up to
	 * 2.4.x; they know 'stable' and 'unstable' only, and take anything else,
	 * a missing value included, as 'stable'). A load balancer still on such a
	 * release under an updated MAIN follows the panel's channel through it.
	 *
	 * @param array $rSave Settings about to be saved (column => value).
	 * @return array The same, with `update_channel` set from `update_channel_main`
	 *               when that is saved, and never taken from the request.
	 */
	public static function withLegacy(array $rSave): array {
		unset($rSave['update_channel']);
		if (isset($rSave['update_channel_main'])) {
			$rSave['update_channel'] = in_array($rSave['update_channel_main'], ['beta', 'dev', 'unstable'], true) ? 'unstable' : 'stable';
		}
		return $rSave;
	}

	/**
	 * Normalize a raw channel value. 'unstable' is a legacy alias for 'beta';
	 * anything unrecognized (including 'dev' outside MAIN) falls back to 'stable'.
	 *
	 * @param string|null $channel Raw channel value (e.g. from settings)
	 * @return string 'stable' or 'beta'
	 */
	private static function normalize(?string $channel): string {
		$channel = ($channel === 'unstable') ? 'beta' : (string) $channel;
		return in_array($channel, ['stable', 'beta'], true) ? $channel : 'stable';
	}
}

<?php

namespace XcVm\Core\License;

use XcVm\Core\Cluster\NodeLease;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * Reads the panel's licence verdict for the capabilities it gates: the
 * xc_fanout live-delivery daemon and adding load-balancer nodes.
 *
 * The actual enforcement lives in the compiled xcvm_core extension. On MAIN the
 * verdict is attribution AND an HWID-bound activation key (tied to install_id),
 * with the hidden method-selector bytes, the daily re-validation request and
 * the 14-day initial offline grace. A cluster node holds no key: its verdict is
 * the live lease MAIN signed, handed over by NodeLease::feedExtension(). PHP only
 * asks it "may this install use fanout right now?" and never sees the key logic.
 *
 * Enforcement is SOFT: when the verdict is negative, {@see FanoutClient::available()}
 * reports the daemon as unavailable and live streams fall back to the legacy
 * delivery path automatically — nothing is hard-blocked and no data is touched.
 *
 * Fail-open: where the extension (or the method) is absent — dev/CI and installs
 * without xcvm_core — fanout stays available, so this never bricks a working panel.
 *
 * @package XC_VM_Core_License
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class LicenseGate {
	/**
	 * Whether the fanout daemon is reachable AND this install is licensed to use it.
	 *
	 * This is the single gate the delivery/supervision choke points consult
	 * (replacing bare file_exists(FANOUT_CTL_SOCK) checks). False means the control
	 * socket is absent, the admin switched fanout off (FanoutMode), OR the
	 * licence denies fanout; callers then fall back to the
	 * legacy delivery path automatically (soft enforcement).
	 *
	 * @return bool True when fanout should be used for this request.
	 */
	public static function fanoutUsable(): bool {
		return FanoutMode::enabled() && defined('FANOUT_CTL_SOCK') && file_exists(FANOUT_CTL_SOCK) && self::fanoutAllowed();
	}

	/**
	 * @return bool True when this install's licence permits the fanout daemon.
	 */
	public static function fanoutAllowed(): bool {
		return self::licensed();
	}

	/**
	 * Whether this install is licensed — on MAIN the attribution is intact AND it
	 * holds a valid activation key (neither substitutes for the other); on a
	 * cluster node, it holds a live lease. This is
	 * the gate for licence-restricted capabilities (fanout delivery, adding
	 * load-balancer nodes). Fail-open where the extension or the method is absent
	 * (dev / CI / installs without xcvm_core), so it never blocks a working panel.
	 *
	 * On a cluster node the extension's verdict is the node's lease, so it is
	 * handed the agent's newest one first (NodeLease::feedExtension).
	 *
	 * @return bool True when the install is licensed.
	 */
	public static function licensed(): bool {
		NodeLease::feedExtension();
		if (class_exists('XC_VM') && method_exists('XC_VM', 'license_valid')) {
			return (bool) \XC_VM::license_valid();
		}

		return true;
	}
}

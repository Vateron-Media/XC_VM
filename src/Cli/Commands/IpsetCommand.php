<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Logging\UpdateLogger;

/**
 * IpsetCommand — install ipset where a server lacks it, so the panel's
 * blocks move into its sets (RootSignalsCronJob::syncSets) at the next
 * minute. A server installed before the installers took ipset gets it with
 * its update: UpdateCommand's post-update starts this in the background.
 *
 * With ipset-persistent beside an iptables-persistent the server has, as the
 * installers do: a firewall saved with netfilter-persistent then saves the
 * sets its rules name. Never where iptables-persistent is missing (Ubuntu
 * 24.04's ufw breaks netfilter-persistent, which ipset-persistent needs, and
 * apt would refuse the whole install), and ipset alone where
 * ipset-persistent will not install.
 *
 * Best-effort: no apt (another family), apt busy past its lock timeout, or
 * no mirror leaves the rule-by-rule path, and the next update tries again.
 * The commands are fixed: nothing of them is put together when they run.
 *
 * Usage: `console.php ipset` (root).
 *
 * @package XC_VM_CLI_Commands
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class IpsetCommand implements CommandInterface {
	public function getName(): string {
		return 'ipset';
	}

	public function getDescription(): string {
		return 'Install ipset where it is missing, for the blocklist sets (best-effort)';
	}

	public function execute(array $rArgs): int {
		exec('command -v ipset', $rOut, $rCode);
		if ($rCode === 0) {
			echo "ipset is installed\n";
			return 0;
		}
		exec('command -v apt-get', $rOut, $rCode);
		if ($rCode !== 0) {
			UpdateLogger::info('ipset not installed: no apt-get here');
			return 0;
		}
		$rStatus = [];
		exec("dpkg-query -W -f='\${Status}' iptables-persistent 2>/dev/null", $rStatus);
		$rTries = str_contains(implode('', $rStatus), 'install ok installed') ? [true, false] : [false];
		// A second round after the package lists are refreshed: a server that never ran
		// `apt-get update` since its install may not know the package.
		foreach ([false, true] as $rRefreshed) {
			if ($rRefreshed) {
				exec('DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=120 update -q >/dev/null 2>&1');
			}
			foreach ($rTries as $rPersistent) {
				if ($this->install($rPersistent)) {
					UpdateLogger::info('Installed ' . ($rPersistent ? 'ipset and ipset-persistent' : 'ipset') . ': the blocks move into its sets within a minute');
					return 0;
				}
			}
		}
		UpdateLogger::error('ipset could not be installed (apt busy, or no mirror): the blocks stay one firewall rule each');
		return 0;
	}

	/** One `apt-get install`; true when it succeeded. */
	private function install(bool $rPersistent): bool {
		$rOut = [];
		$rCode = 1;
		if ($rPersistent) {
			exec('DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset ipset-persistent >/dev/null 2>&1', $rOut, $rCode);
		} else {
			exec('DEBIAN_FRONTEND=noninteractive apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset >/dev/null 2>&1', $rOut, $rCode);
		}
		return $rCode === 0;
	}
}

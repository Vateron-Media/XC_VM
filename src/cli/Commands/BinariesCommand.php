<?php

/**
 * BinariesCommand — binaries command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BinariesCommand implements CommandInterface {

	public function getName(): string {
		return 'binaries';
	}

	public function getDescription(): string {
		return 'Update binaries and GeoLite from GitHub';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		// Check if apparmor_status command exists
		if (shell_exec('which apparmor_status')) {
			exec('sudo apparmor_status', $rAppArmor);

			if (strtolower(trim($rAppArmor[0])) == 'apparmor module is loaded.') {
				exec('sudo systemctl is-active apparmor', $rStatus);

				if (strtolower(trim($rStatus[0])) == 'active') {
					echo 'AppArmor is loaded! Disabling...' . "\n";
					shell_exec('sudo systemctl stop apparmor');
					shell_exec('sudo systemctl disable apparmor');
				}
			}
		}

		// The logic for updating binary files, which is not currently implemented, will be added here.

		return 0;
	}
}

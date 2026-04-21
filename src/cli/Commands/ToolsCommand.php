<?php

/**
 * ToolsCommand — tools command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ToolsCommand implements CommandInterface {

	public function getName(): string {
		return 'tools';
	}

	public function getDescription(): string {
		return 'Utilities: images, duplicates, bouquets, rescue, access, ports, migration, user, mysql, database, flush';
	}

	public function execute(array $rArgs): int {
		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;

		$rMethod = (!empty($rArgs[0]) ? $rArgs[0] : null);
		$rUser = posix_getpwuid(posix_geteuid())['name'];

		// root-only subcommands
		if (in_array($rMethod, array('rescue', 'access', 'ports', 'migration', 'user', 'mysql', 'database', 'flush'), true)) {
			if ($rUser !== 'root') {
				echo "Please run as root!\n";
				return 1;
			}

			$rServers = ServerRepository::getAll();

			switch ($rMethod) {
				case 'rescue':
					return $this->processRescue($db, $rServers);
				case 'access':
					return $this->processAccess($db, $rServers);
				case 'ports':
					return $this->processPorts($db, $rServers);
				case 'migration':
					return $this->processMigration($db, $rArgs, $rServers);
				case 'user':
					return $this->processUser($db);
				case 'mysql':
					return $this->processMysql($db, $rServers);
				case 'database':
					return $this->processDatabase($db, $rArgs);
				case 'flush':
					return $this->processFlush($db);
			}
		}

		// xc_vm subcommands
		if ($rUser !== 'xc_vm') {
			echo "Please run as XC_VM!\n";
			return 1;
		}

		switch ($rMethod) {
			case 'images':
				$this->processImages($db);
				break;
			case 'duplicates':
				$this->processDuplicates($db);
				break;
			case 'bouquets':
				$this->processBouquets($db);
				break;
			default:
				echo "Usage: console.php tools <subcommand>\n\n";
				echo "Subcommands (run as xc_vm):\n";
				echo "  images      Download missing stream/movie/series images from TMDB\n";
				echo "  duplicates  Find and remove duplicate VOD streams\n";
				echo "  bouquets    Clean stale references from bouquets\n\n";
				echo "Subcommands (run as root):\n";
				echo "  rescue      Create temporary rescue access code\n";
				echo "  access      Regenerate nginx access code configs and reload\n";
				echo "  ports       Regenerate nginx port configs and reload\n";
				echo "  migration   Clear migration database and optionally restore .sql backup\n";
				echo "  user        Create a rescue admin user for the admin panel\n";
				echo "  mysql       Reauthorise load balancers on MySQL\n";
				echo "  database    Restore blank XC_VM database (requires --confirm)\n";
				echo "  flush       Flush all blocked IPs (iptables + database)\n";
				return 1;
		}

		return 0;
	}

	private function processMigration($db, array $rArgs, array $rServers): int {
		$db->query('DROP DATABASE IF EXISTS `xc_vm_migrate`;');
		$db->query('CREATE DATABASE IF NOT EXISTS `xc_vm_migrate`;');
		echo "Migration database has been cleared.\n";
		$rConfig = ConfigReader::getAll();

		$database = (!empty($rArgs[1]) ? $rArgs[1] : null);

		if ($database && file_exists($database)) {
			$rExtension = strtolower(pathinfo($database, PATHINFO_EXTENSION));
			if ($rExtension === 'sql') {
				echo 'Restoring: ' . $database . "\n";
				$rCommand = 'mariadb -h 127.0.0.1 -P ' . intval($rConfig['port']) . ' -u ' . escapeshellarg($rConfig['username']) . ' -p' . escapeshellarg($rConfig['password']) . ' xc_vm_migrate < ' . escapeshellarg($database) . ' 2>&1';
				$rOutput = shell_exec($rCommand);
				if (!empty($rOutput)) {
					echo $rOutput;
				}
				echo "If no errors were shown above, restore was completed.\n\n";
			} else {
				echo "Error: File must have .sql extension\n";
			}
		} else {
			echo "You can restore a database to it using:\n";
			echo 'mariadb -h 127.0.0.1 -P ' . intval($rConfig['port']) . ' -u ' . $rConfig['username'] . ' -p*** xc_vm_migrate < backup.sql' . "\n\n";
		}

		foreach ($rServers as $rServer) {
			BackupService::grantPrivileges($rServer['server_ip'], DatabaseFactory::get(), $rConfig);
		}

		return 0;
	}

	private function processUser($db): int {
		$rUsername = 'admin_' . bin2hex(random_bytes(4));
		$rPassword = bin2hex(random_bytes(8));
		$rHash = crypt($rPassword, sprintf('$6$rounds=%d$%s$', 20000, 'xc_vm'));
		$db->query(
			"INSERT INTO `users`(`username`, `password`, `email`, `ip`, `date_registered`, `last_login`, `member_group_id`, `status`) VALUES(?, ?, '', '', ?, ?, 1, 1);",
			$rUsername, $rHash, time(), time()
		);
		echo "Rescue admin user created:\n";
		echo "  Username: {$rUsername}\n";
		echo "  Password: {$rPassword}\n\n";
		echo "Please change the password and delete this user when done.\n";
		return 0;
	}

	private function processMysql($db, array $rServers): int {
		$rConfig = ConfigReader::getAll();
		foreach ($rServers as $rServerID => $rServer) {
			echo 'Granting privileges to: ' . $rServer['server_ip'] . " (ID: {$rServerID})\n";
			BackupService::grantPrivileges($rServer['server_ip'], DatabaseFactory::get(), $rConfig);
		}
		echo "\nMySQL privileges have been reauthorised for all servers.\n";
		return 0;
	}

	private function processDatabase($db, array $rArgs): int {
		if (empty($rArgs[1]) || $rArgs[1] !== '--confirm') {
			echo "WARNING: This will erase ALL data and restore a blank database!\n";
			echo "To confirm, run: sudo console.php tools database --confirm\n";
			return 1;
		}
		$rDatabaseFile = MAIN_HOME . 'bin/install/database.sql';
		if (!file_exists($rDatabaseFile)) {
			echo "Error: Database file not found: {$rDatabaseFile}\n";
			return 1;
		}
		echo "Restoring blank database...\n";
		shell_exec('sudo mariadb -u root xc_vm < ' . escapeshellarg($rDatabaseFile));
		echo "Blank database has been restored.\n";
		return 0;
	}

	private function processFlush($db): int {
		echo "Flushing iptables rules...\n";
		exec('sudo iptables -F && sudo ip6tables -F');
		shell_exec('sudo rm -f ' . escapeshellarg(FLOOD_TMP_PATH) . 'block_*');
		exec('sudo iptables-save && sudo ip6tables-save');
		$db->query('TRUNCATE `blocked_ips`;');
		echo "All blocked IPs have been flushed (iptables + database).\n";
		return 0;
	}

	private function processRescue($db, array $rServers): int {
		$db->query("DELETE FROM `access_codes` WHERE `code` = 'rescue';");
		$db->query("INSERT INTO `access_codes`(`code`, `type`, `enabled`, `groups`) VALUES('rescue', 0, 1, '[1]');");
		echo "A rescue access code has been created.\nPlease ensure you delete this after you're done with it.\n";
		echo 'Access: http://' . $rServers[SERVER_ID]['server_ip'] . ':' . $rServers[SERVER_ID]['http_broadcast_port'] . "/rescue/\n\n";
		AuthRepository::updateCodes();
		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');
		return 0;
	}

	private function processAccess($db, array $rServers): int {
		echo "Generating access code configuration...\n\n";
		AuthRepository::updateCodes();
		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');

		foreach (AuthRepository::getAllCodes(0) as $rCode) {
			echo 'http://' . $rServers[SERVER_ID]['server_ip'] . ':' . $rServers[SERVER_ID]['http_broadcast_port'] . '/' . $rCode['code'] . "/\n";
		}
		echo "\n";
		return 0;
	}

	private function processPorts($db, array $rServers): int {
		echo "Generating port configuration...\n\n";
		$rConfig = array(
			'http' => array_unique(array_merge(
				array($rServers[SERVER_ID]['http_broadcast_port']),
				(explode(',', $rServers[SERVER_ID]['http_ports_add']) ?: array())
			)),
			'https' => array_unique(array_merge(
				array($rServers[SERVER_ID]['https_broadcast_port']),
				(explode(',', $rServers[SERVER_ID]['https_ports_add']) ?: array())
			)),
			'rtmp' => $rServers[SERVER_ID]['rtmp_port'],
		);

		foreach ($rConfig as $rKey => $rPorts) {
			if ($rKey === 'http') {
				$rListen = array();
				foreach ($rPorts as $rPort) {
					if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535) {
						$rListen[] = 'listen ' . intval($rPort) . ';';
					}
				}
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf', implode(' ', $rListen));
				file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/live.conf', 'on_play http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp; on_publish http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp; on_play_done http://127.0.0.1:' . intval($rPorts[0]) . '/stream/rtmp;');
			} elseif ($rKey === 'https') {
				$rListen = array();
				foreach ($rPorts as $rPort) {
					if (is_numeric($rPort) && 80 <= $rPort && $rPort <= 65535) {
						$rListen[] = 'listen ' . intval($rPort) . ' ssl;';
					}
				}
				file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf', implode(' ', $rListen));
			} elseif ($rKey === 'rtmp') {
				file_put_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/port.conf', 'listen ' . intval($rPorts) . ';');
			}
		}

		if (count($rConfig['http']) > 0) {
			echo 'HTTP Ports: ' . implode(', ', $rConfig['http']) . "\n";
		}
		if (count($rConfig['https']) > 0) {
			echo 'SSL Ports: ' . implode(', ', $rConfig['https']) . "\n";
		}
		if (!empty($rConfig['rtmp'])) {
			echo 'RTMP Port: ' . $rConfig['rtmp'] . "\n";
		}

		shell_exec('sudo ' . MAIN_HOME . 'service reload 2>/dev/null');
		echo "\n";
		return 0;
	}

	private function processImages($db): void {
		$rImages = array();
		$db->query('SELECT COUNT(*) AS `count` FROM `streams`;');
		$rCount = $db->get_row()['count'];
		if ($rCount > 0) {
			$rSteps = range(0, $rCount, 1000);
			if (!$rSteps) {
				$rSteps = array(0);
			}
			foreach ($rSteps as $rStep) {
				try {
					$db->query('SELECT `stream_icon`, `movie_properties` FROM `streams` LIMIT ' . $rStep . ', 1000;');
					$rResults = $db->get_rows();
					foreach ($rResults as $rResult) {
						$rProperties = json_decode($rResult['movie_properties'], true);
						if (!empty($rResult['stream_icon']) && substr($rResult['stream_icon'], 0, 2) == 's:') {
							$rImages[] = $rResult['stream_icon'];
						}
						if (!empty($rProperties['movie_image']) && substr($rProperties['movie_image'], 0, 2) == 's:') {
							$rImages[] = $rProperties['movie_image'];
						}
						if (!empty($rProperties['cover_big']) && substr($rProperties['cover_big'], 0, 2) == 's:') {
							$rImages[] = $rProperties['cover_big'];
						}
						if (!empty($rProperties['backdrop_path'][0]) && substr($rProperties['backdrop_path'][0], 0, 2) == 's:') {
							$rImages[] = $rProperties['backdrop_path'][0];
						}
					}
				} catch (Exception $e) {
					echo 'Error: ' . $e . "\n";
				}
			}
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_series`;');
		$rCount = $db->get_row()['count'];
		if ($rCount > 0) {
			$rSteps = range(0, $rCount, 1000);
			if (!$rSteps) {
				$rSteps = array(0);
			}
			foreach ($rSteps as $rStep) {
				try {
					$db->query('SELECT `cover`, `cover_big` FROM `streams_series` LIMIT ' . $rStep . ', 1000;');
					$rResults = $db->get_rows();
					foreach ($rResults as $rResult) {
						if (!empty($rResult['cover']) && substr($rResult['cover'], 0, 2) == 's:') {
							$rImages[] = $rResult['cover'];
						}
						if (!empty($rResult['cover_big']) && substr($rResult['cover_big'], 0, 2) == 's:') {
							$rImages[] = $rResult['cover_big'];
						}
					}
				} catch (Exception $e) {
					echo 'Error: ' . $e . "\n";
				}
			}
		}
		$rImages = array_unique($rImages);
		foreach ($rImages as $rImage) {
			$rSplit = explode(':', $rImage, 3);
			if (intval($rSplit[1]) == SERVER_ID) {
				$rImageSplit = explode('/', $rSplit[2]);
				$rPathInfo = pathinfo($rImageSplit[count($rImageSplit) - 1]);
				$rImage = $rPathInfo['filename'];
				$rOriginalURL = Encryption::decrypt($rImage, SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA);
				if (!empty($rOriginalURL) && substr($rOriginalURL, 0, 4) == 'http') {
					if (!file_exists(IMAGES_PATH . $rPathInfo['basename'])) {
						echo 'Downloading: ' . $rOriginalURL . "\n";
						ImageUtils::downloadImage($rOriginalURL);
					}
				}
			}
		}
	}

	private function processDuplicates($db): void {
		$rGroups = $rStreamIDs = array();
		$db->query('SELECT `a`.`id`, `a`.`stream_source` FROM `streams` `a` INNER JOIN (SELECT  `stream_source`, COUNT(*) `totalCount` FROM `streams` WHERE `type` IN (2,5) GROUP BY `stream_source`) `b` ON `a`.`stream_source` = `b`.`stream_source` WHERE `b`.`totalCount` > 1;');
		foreach ($db->get_rows() as $rRow) {
			$rGroups[md5($rRow['stream_source'])][] = $rRow['id'];
		}
		foreach ($rGroups as $rID => $rGroupIDs) {
			array_shift($rGroupIDs);
			foreach ($rGroupIDs as $rStreamID) {
				$rStreamIDs[] = intval($rStreamID);
			}
		}
		if (count($rStreamIDs) > 0) {
			foreach (array_chunk($rStreamIDs, 100) as $rChunk) {
				$this->deleteStreams($db, $rChunk);
			}
		}
	}

	private function processBouquets($db): void {
		$rStreamIDs = array(array(), array());
		$db->query('SELECT `id` FROM `streams`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rStreamIDs[0][] = intval($rRow['id']);
			}
		}
		$db->query('SELECT `id` FROM `streams_series`;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rStreamIDs[1][] = intval($rRow['id']);
			}
		}
		$db->query('SELECT * FROM `bouquets` ORDER BY `bouquet_order` ASC;');
		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rBouquet) {
				$UpdateData = array(array(), array(), array(), array());
				foreach ((json_decode($rBouquet['bouquet_channels'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[0][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_movies'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[1][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_radios'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[0])) {
						$UpdateData[2][] = intval($rID);
					}
				}
				foreach ((json_decode($rBouquet['bouquet_series'], true) ?: array()) as $rID) {
					if (0 < intval($rID) && in_array(intval($rID), $rStreamIDs[1])) {
						$UpdateData[3][] = intval($rID);
					}
				}
				$db->query("UPDATE `bouquets` SET `bouquet_channels` = '[" . implode(',', array_map('intval', $UpdateData[0])) . "]', `bouquet_movies` = '[" . implode(',', array_map('intval', $UpdateData[1])) . "]', `bouquet_radios` = '[" . implode(',', array_map('intval', $UpdateData[2])) . "]', `bouquet_series` = '[" . implode(',', array_map('intval', $UpdateData[3])) . "]' WHERE `id` = ?;", $rBouquet['id']);
			}
		}
	}

	private function deleteStreams($db, $rIDs): bool {
		$db->query('DELETE FROM `lines_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `mag_claims` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_errors` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_stats` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_refresh` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `watch_logs` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_live` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `recordings` WHERE `created_id` IN (' . implode(',', $rIDs) . ') OR `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `stream_id` = 0 WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('SELECT `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `streams_servers` WHERE `parent_id` IS NOT NULL AND `parent_id` > 0 AND `parent_id` NOT IN (SELECT `id` FROM `servers` WHERE `server_type` = 0);');
		$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', SERVER_ID, time(), json_encode(array('type' => 'update_streams', 'id' => $rIDs)));
		foreach (array_keys(ServerRepository::getAll()) as $rServerID) {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', $rServerID, time(), json_encode(array('type' => 'delete_vods', 'id' => $rIDs)));
		}
		return true;
	}
}

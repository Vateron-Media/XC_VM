<?php

/**
 * CertbotCommand — certbot command
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CertbotCommand implements CommandInterface {

	public function getName(): string {
		return 'certbot';
	}

	public function getDescription(): string {
		return 'Generate SSL certificate via certbot';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] != 'root') {
			echo "Please run as root!\n";
			return 1;
		}

		if (empty($rArgs[0])) {
			return 0;
		}

		register_shutdown_function(function () {
			global $db;
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		global $db;

		$rData = json_decode(base64_decode($rArgs[0]), true);
		if ($rData['action'] == 'certbot_generate') {
			if (file_exists(BIN_PATH . 'certbot/logs/xc_vm.log')) {
				unlink(BIN_PATH . 'certbot/logs/xc_vm.log');
			}
			foreach (array('logs', 'config', 'work') as $rPath) {
				if (file_exists(BIN_PATH . 'certbot/' . $rPath . '/.certbot.lock')) {
					unlink(BIN_PATH . 'certbot/' . $rPath . '/.certbot.lock');
				}
			}
			$rActiveDomains = array();
			foreach ($rData['domain'] as $rDomain) {
				if (!empty($rDomain) && !filter_var($rDomain, FILTER_VALIDATE_IP)) {
					$rActiveDomains[] = $rDomain;
				}
			}
			$rError = null;
			$rOutput = array();
			$rResult = false;
			if (0 < count($rActiveDomains)) {
				foreach (array('--dry-run ', '') as $rDry) {
					if (ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] == 80) {
						$rCommand = 'sudo certbot ' . $rDry . '--config-dir ' . BIN_PATH . 'certbot/config --work-dir ' . BIN_PATH . 'certbot/work --logs-dir ' . BIN_PATH . 'certbot/logs certonly --agree-tos --expand --non-interactive --register-unsafely-without-email --webroot -w /home/xc_vm/www/';
					} else {
						$rCommand = 'sudo certbot ' . $rDry . '--config-dir ' . BIN_PATH . 'certbot/config --work-dir ' . BIN_PATH . 'certbot/work --logs-dir ' . BIN_PATH . 'certbot/logs certonly --agree-tos --expand --non-interactive --register-unsafely-without-email --standalone';
					}
					foreach ($rActiveDomains as $rDomain) {
						$rCommand .= ' -d ' . basename($rDomain);
					}
					$rCommand .= ' 2>&1';
					$rOutput = array();
					exec($rCommand, $rOutput, $rReturn);

					if (empty($rDry)) {
						if (stripos(implode("\n", $rOutput), 'certificate is saved at') !== false) {
							$rDirectory = null;
							foreach ($rOutput as $rLine) {
								if (preg_match('/(certificate is saved at:|key is saved at:)\s*(\S+)/i', $rLine, $matches)) {
									$rDirectory = pathinfo($matches[2], PATHINFO_DIRNAME);
									break;
								}
							}
							if ($rDirectory) {
								$rCertificate = $rDirectory . '/fullchain.pem';
								$rChain = $rDirectory . '/chain.pem';
								$rPrivateKey = $rDirectory . '/privkey.pem';
								if (file_exists($rCertificate) && file_exists($rChain) && file_exists($rPrivateKey)) {
									$rSSLConfig = $this->buildSslConfig($rCertificate, $rPrivateKey, $rChain);
									file_put_contents(BIN_PATH . 'nginx/conf/ssl.conf', $rSSLConfig);
									shell_exec('chown xc_vm:xc_vm ' . BIN_PATH . 'nginx/conf/ssl.conf');
									$rInfo = DiagnosticsService::getCertificateInfo();
									if ($rInfo['serial']) {
										$db->query('UPDATE `servers` SET `certbot_ssl` = ? WHERE `id` = ?;', json_encode($rInfo), SERVER_ID);
									}
									$rResult = true;
								} else {
									echo 'Error: Failed to generate certificate!' . "\n";
									$rError = 0;
								}
							} else {
								echo 'Error: Failed to generate certificate!' . "\n";
								$rError = 0;
							}
						} else {
							$rOutBlob = implode("\n", $rOutput);
							if (stripos($rOutBlob, 'not yet due for renewal') !== false) {
								echo 'Warning: Certificate not due for renewal!' . "\n";
								$rError = 1;
							} else {
								echo 'Error: An error occured!' . "\n";
								$rError = 2;
							}
						}
					} else {
						if (stripos(implode("\n", $rOutput), 'the dry run was successful') !== false) {
							echo 'Dry run successful!' . "\n";
						} else {
							echo 'Error: Dry run failed!' . "\n";
							$rError = 4;
							break;
						}
					}
				}
			} else {
				$rError = 3;
			}
			if (in_array($rError, array(0, 1))) {
				$db->query('SELECT `certbot_ssl` FROM `servers` WHERE `id` = ?;', SERVER_ID);
				$rCertInfo = json_decode($db->get_row()['certbot_ssl'], true);
				if (!$rCertInfo) {
					$rSelectedDomain = array(null, null);
					foreach (scandir(BIN_PATH . 'certbot/config/live/') as $rDir) {
						if ($rDir != '.' && $rDir != '..') {
							$rSplit = explode('-', $rDir);
							if (is_numeric($rSplit[count($rSplit) - 1])) {
								$rDomain = implode('-', array_slice($rSplit, 0, count($rSplit) - 1));
							} else {
								$rDomain = $rDir;
							}
							if (in_array(strtolower($rDomain), array_map('strtolower', $rActiveDomains))) {
								$rInfo = DiagnosticsService::getCertificateInfo(BIN_PATH . 'certbot/config/live/' . $rDir . '/fullchain.pem');
								if (($rInfo['serial'] && $rSelectedDomain[0] < $rInfo['expiration']) && !$rSelectedDomain[0]) {
									$rSelectedDomain = array($rInfo['expiration'], $rInfo);
								}
							}
						}
					}
					if ($rSelectedDomain[0]) {
						$rDirectory = $rSelectedDomain[1]['path'];
						$rCertificate = $rDirectory . '/fullchain.pem';
						$rChain = $rDirectory . '/chain.pem';
						$rPrivateKey = $rDirectory . '/privkey.pem';
						if (file_exists($rCertificate) && file_exists($rChain) && file_exists($rPrivateKey)) {
							$rSSLConfig = $this->buildSslConfig($rCertificate, $rPrivateKey, $rChain);
							file_put_contents(BIN_PATH . 'nginx/conf/ssl.conf', $rSSLConfig);
							shell_exec('chown xc_vm:xc_vm ' . BIN_PATH . 'nginx/conf/ssl.conf');
							$db->query('UPDATE `servers` SET `certbot_ssl` = ? WHERE `id` = ?;', json_encode($rSelectedDomain[1]), SERVER_ID);
							$rResult = true;
						}
					}
				}
			}
			$rReturn = array('status' => $rResult, 'error' => $rError, 'output' => $rOutput);
			shell_exec('chown -R xc_vm:xc_vm ' . BIN_PATH . 'certbot/');
			file_put_contents(BIN_PATH . 'certbot/logs/xc_vm.log', json_encode($rReturn));
			if ($rResult) {
				shell_exec(MAIN_HOME . 'service reload');
			}
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:certbot 1 > /dev/null 2>/dev/null &');
		}

		return 0;
	}

	private function buildSslConfig($rCertificate, $rPrivateKey, $rChain): string {
		return 'ssl_certificate ' . $rCertificate . ';' . "\n"
			. 'ssl_certificate_key ' . $rPrivateKey . ';' . "\n"
			. 'ssl_trusted_certificate ' . $rChain . ';' . "\n"
			. 'ssl_protocols TLSv1.2 TLSv1.3;' . "\n"
			. 'ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;' . "\n"
			. 'ssl_prefer_server_ciphers off;' . "\n"
			. 'ssl_ecdh_curve auto;' . "\n"
			. 'ssl_session_timeout 10m;' . "\n"
			. 'ssl_session_cache shared:MozSSL:10m;' . "\n"
			. 'ssl_session_tickets off;';
	}
}

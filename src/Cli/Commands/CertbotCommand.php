<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Domain\Server\ServerRepository;

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
			// The admin's regenerate cleared MAIN's record first: a node in
			// mode 2, which cannot read it, forgets its copy of it too. It
			// does for MAIN's renewal (NodeCertbot) as well, which keeps its
			// record: when certbot then writes no certificate, the node
			// points nginx at the newest one it holds for the names and
			// reports it, which mode 0 and 1 leave as it is.
			NodeStateSink::forget('certbot_ssl');
			if (file_exists(BIN_PATH . 'certbot/logs/xc_vm.log')) {
				unlink(BIN_PATH . 'certbot/logs/xc_vm.log');
			}
			foreach (['logs', 'config', 'work'] as $rPath) {
				if (file_exists(BIN_PATH . 'certbot/' . $rPath . '/.certbot.lock')) {
					unlink(BIN_PATH . 'certbot/' . $rPath . '/.certbot.lock');
				}
			}
			$rActiveDomains = self::hostNames($rData['domain'] ?? null);
			$rError = null;
			$rOutput = [];
			$rResult = false;
			if (0 < count($rActiveDomains)) {
				$rCertbotWebroot = MAIN_HOME . 'certbot-webroot';
				if (!is_dir($rCertbotWebroot)) {
					mkdir($rCertbotWebroot, 0775, true);
				}
				foreach (['--dry-run ', ''] as $rDry) {
					// A serve-only node (marker) must use --webroot: --standalone binds its own
					// :80 and would collide with the Go front that owns the node's ports (the
					// front serves /.well-known/acme-challenge/ from certbot's -w directory).
					if (is_file(CONFIG_PATH . 'cluster/serve_only') || ServerRepository::getAll()[SERVER_ID]['http_broadcast_port'] == 80) {
						$rCommand = 'sudo certbot ' . $rDry . '--config-dir ' . BIN_PATH . 'certbot/config --work-dir ' . BIN_PATH . 'certbot/work --logs-dir ' . BIN_PATH . 'certbot/logs certonly --agree-tos --expand --non-interactive --register-unsafely-without-email --webroot -w ' . $rCertbotWebroot;
					} else {
						$rCommand = 'sudo certbot ' . $rDry . '--config-dir ' . BIN_PATH . 'certbot/config --work-dir ' . BIN_PATH . 'certbot/work --logs-dir ' . BIN_PATH . 'certbot/logs certonly --agree-tos --expand --non-interactive --register-unsafely-without-email --standalone';
					}
					foreach ($rActiveDomains as $rDomain) {
						$rCommand .= ' -d ' . escapeshellarg($rDomain);
					}
					$rCommand .= ' 2>&1';
					$rOutput = [];
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
									self::syncFrontCert(is_file(CONFIG_PATH . 'cluster/serve_only'), $rCertificate, $rPrivateKey, BIN_PATH);
									$rInfo = DiagnosticsService::getCertificateInfo();
									if ($rInfo['serial']) {
										NodeStateSink::state(['certbot_ssl' => json_encode($rInfo)], $db);
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
			if (in_array($rError, [0, 1])) {
				// MAIN's record of this node's certificate. A node in mode 2
				// reaches no database of MAIN's: its copy of what it
				// reported since this command began (none: forgotten above).
				if (NodeRole::refusesConnects()) {
					$rCertInfo = json_decode((string) NodeStateSink::reported('certbot_ssl'), true);
				} else {
					$db->query('SELECT `certbot_ssl` FROM `servers` WHERE `id` = ?;', SERVER_ID);
					$rCertInfo = json_decode($db->get_row()['certbot_ssl'], true);
				}
				if (!$rCertInfo) {
					$rSelectedDomain = [null, null];
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
									$rSelectedDomain = [$rInfo['expiration'], $rInfo];
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
							self::syncFrontCert(is_file(CONFIG_PATH . 'cluster/serve_only'), $rCertificate, $rPrivateKey, BIN_PATH);
							NodeStateSink::state(['certbot_ssl' => json_encode($rSelectedDomain[1])], $db);
							$rResult = true;
						}
					}
				}
			}
			$rReturn = ['status' => $rResult, 'error' => $rError, 'output' => $rOutput];
			if (!is_dir(BIN_PATH . 'certbot/logs')) {
				mkdir(BIN_PATH . 'certbot/logs', 0775, true);
			}
			shell_exec('chown -R xc_vm:xc_vm ' . BIN_PATH . 'certbot/');
			file_put_contents(BIN_PATH . 'certbot/logs/xc_vm.log', json_encode($rReturn));
			if ($rResult) {
				shell_exec(MAIN_HOME . 'service reload');
			}
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:certbot 1 > /dev/null 2>/dev/null &');
		}

		return 0;
	}

	/**
	 * The names certbot is asked a certificate for: the host names of the
	 * list. An entry stored as a URL, or with blanks around it, counts as the
	 * host name it ends with. An address, or anything else the list holds, is
	 * left out.
	 *
	 * @param mixed $rDomains The request's `domain` list.
	 * @return list<string>
	 */
	private static function hostNames(mixed $rDomains): array {
		$rNames = [];
		foreach ((is_array($rDomains) ? $rDomains : []) as $rDomain) {
			$rDomain = is_string($rDomain) ? basename(trim($rDomain)) : '';
			if (!empty($rDomain) && !filter_var($rDomain, FILTER_VALIDATE_IP) && filter_var($rDomain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false) {
				$rNames[] = $rDomain;
			}
		}
		return $rNames;
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

	/**
	 * Install the certbot cert into the files the Go front reads (server.crt/server.key),
	 * so a serve-only node's front picks up a renewal (its CertReloader watches their
	 * mtime). A no-op on a non-serve-only node — nginx reads ssl.conf, which still points
	 * at the certbot live paths. Both files are staged to a temp and renamed into place,
	 * so a mid-write failure never leaves a new cert beside an old key (that would break
	 * the front's only HTTPS surface); a read/stage failure leaves the live pair untouched
	 * and is logged. Chown to xc_vm only when running as root. $rBinPath is BIN_PATH (a
	 * parameter so the copy is testable).
	 */
	public static function syncFrontCert(bool $rServeOnly, string $rCert, string $rKey, string $rBinPath): void {
		if (!$rServeOnly) {
			return;
		}
		$rCertData = is_file($rCert) ? @file_get_contents($rCert) : false;
		$rKeyData = is_file($rKey) ? @file_get_contents($rKey) : false;
		if ($rCertData === false || $rKeyData === false) {
			error_log('syncFrontCert: cannot read the certbot cert/key (' . $rCert . ', ' . $rKey . '); the front cert is unchanged');
			return;
		}
		$rDir = $rBinPath . 'nginx/conf/';
		// Stage both to randomly-named owner-only temp files (tempnam creates with O_EXCL,
		// so a local user cannot pre-plant a symlink at a predictable `.tmp` path for this
		// root process to follow), the private key 0600, both handed to xc_vm (the front
		// reads them). Rename into place only once both are staged, so the front never
		// reloads a new cert beside an old key.
		$rCrtTmp = self::stageFrontFile($rDir, $rCertData, 0644);
		$rKeyTmp = self::stageFrontFile($rDir, $rKeyData, 0600);
		if ($rCrtTmp === null || $rKeyTmp === null) {
			if ($rCrtTmp !== null) {
				@unlink($rCrtTmp);
			}
			if ($rKeyTmp !== null) {
				@unlink($rKeyTmp);
			}
			error_log('syncFrontCert: cannot stage the front cert under ' . $rDir . '; the front cert is unchanged');
			return;
		}
		if (!@rename($rCrtTmp, $rDir . 'server.crt') || !@rename($rKeyTmp, $rDir . 'server.key')) {
			@unlink($rCrtTmp);
			@unlink($rKeyTmp);
			error_log('syncFrontCert: cannot install the front cert into ' . $rDir . '; the front cert may be stale');
		}
	}

	/**
	 * Stage $rData in a fresh owner-only temp file in $rDir — a random name (tempnam is
	 * O_EXCL, so no pre-planted symlink at a predictable path is followed) — set $rMode,
	 * and hand it to xc_vm when running as root. Returns the temp path, or null on failure.
	 */
	private static function stageFrontFile(string $rDir, string $rData, int $rMode): ?string {
		$rTmp = @tempnam($rDir, '.cert_');
		if ($rTmp === false) {
			return null;
		}
		if (@file_put_contents($rTmp, $rData) === false || !@chmod($rTmp, $rMode)) {
			@unlink($rTmp);
			return null;
		}
		if (posix_geteuid() === 0) {
			@chown($rTmp, 'xc_vm');
			@chgrp($rTmp, 'xc_vm');
		}
		return $rTmp;
	}
}

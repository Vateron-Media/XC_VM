<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\NodeStateSink;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Cluster\NodeCertbot;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * CertbotCronJob — certbot cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CertbotCronJob implements CommandInterface {
	use DatabaseAware;
	use CronTrait;

	public function getName(): string {
		return 'cron:certbot';
	}

	public function getDescription(): string {
		return 'Cron: check/renew SSL certificates via certbot';
	}

	public function execute(array $rArgs): int {
		$this->registerShutdown();
		$rCheck = !empty($rArgs[0]);
		$this->loadCron($rCheck);
		return 0;
	}

	private function loadCron(bool $rCheck): void {
		$this->checkCertificate($rCheck);
		// MAIN's daily run, not the check the certbot command starts.
		if (!$rCheck) {
			$this->renewNodes();
		}
	}

	/**
	 * This server's own certificate: its renewal when due, its record (MAIN's
	 * `servers.certbot_ssl`) kept current, nginx's configuration repaired.
	 */
	protected function checkCertificate(bool $rCheck): void {
		$db = self::db();
		$rCertInfo = null;
		// A node in mode 2 reaches no database of MAIN's (plan, section 10):
		// MAIN sends its renewal, it compares with the record it last
		// reported, and it reloads its own nginx.
		$rApi = NodeRole::refusesConnects();

		if (!$rCheck) {
			// MAIN's panel logs: the table is cluster-wide and the rows are marked
			// sent, so an LB uploading them took MAIN's logs and hid them from it.
			if (!PHP_ERRORS && ServerRepository::getAll()[SERVER_ID]['is_main']) {
				DiagnosticsService::submitPanelLogs();
			}
			$rCertInfo = DiagnosticsService::getCertificateInfo();
			if (ServerRepository::getAll()[SERVER_ID]['enable_https'] && $rCertInfo) {
				if ($rCertInfo['expiration'] - time() < 604800) {
					echo 'Certificate due for renewal.' . "\n";
					$rData = ['action' => 'certbot_generate', 'domain' => []];
					foreach (explode(',', ServerRepository::getAll()[SERVER_ID]['domain_name']) as $rDomain) {
						if (!filter_var($rDomain, FILTER_VALIDATE_IP)) {
							$rData['domain'][] = $rDomain;
						}
					}
					if ($rApi) {
						// Its root reads no `signals` row: MAIN sends it the
						// signed node.root certbot_generate (NodeCertbot).
						echo 'MAIN sends the renewal.' . "\n";
					} elseif (count($rData['domain']) > 0) {
						NodeActions::send(intval(SERVER_ID), $rData, $db);
					}
				} else {
					echo 'Certificate valid, not due for renewal.' . "\n";
				}
			}
		}

		// This node's own record, read from its row: the servers cache a node
		// replica builds carries no certbot_ssl (ReplicaSections::SERVER_LOCAL).
		// In mode 2, its copy of what it last reported (MAIN's record, unless
		// MAIN cleared it since: the daily run reports again below).
		if ($rApi) {
			$rDBCert = (string) NodeStateSink::reported('certbot_ssl');
		} else {
			$db->query('SELECT `certbot_ssl` FROM `servers` WHERE `id` = ?;', SERVER_ID);
			$rDBCert = (string) (($db->get_row() ?: [])['certbot_ssl'] ?? '');
		}
		$rDBCertInfo = json_decode($rDBCert, true);
		$rLines = explode("\n", file_get_contents(MAIN_HOME . 'bin/nginx/conf/ssl.conf'));

		foreach ($rLines as $rLine) {
			if (explode(' ', $rLine)[0] == 'ssl_certificate') {
				list($rCertificate) = explode(';', explode(' ', $rLine)[1]);
				if ($rCertificate != 'server.crt') {
					$rCertInfoFile = DiagnosticsService::getCertificateInfo($rCertificate);
					$rChanged = $rCertInfoFile && ($rCertInfo === null || $rCertInfo['serial'] != $rCertInfoFile['serial'] || !$rDBCert || ($rDBCertInfo['serial'] ?? null) != $rCertInfoFile['serial']);
					if ($rChanged) {
						NodeStateSink::state(['certbot_ssl' => json_encode($rCertInfoFile)], $db);
						echo 'Updated ssl configuration in database' . "\n";
						$this->reloadNginx($rApi, $db);
					} elseif ($rCertInfoFile && $rApi && !$rCheck) {
						// Mode 2: the daily run reports it again, nginx left as it is.
						// MAIN renews from its record (NodeCertbot), which the admin's
						// regenerate may have cleared with no certbot_generate
						// reaching this node to make it forget its copy.
						NodeStateSink::state(['certbot_ssl' => json_encode($rCertInfoFile)], $db);
						echo 'Reported ssl configuration to MAIN' . "\n";
					}
				} else {
					if (is_array($rDBCertInfo) && !empty($rDBCertInfo['path'])) {
						$rCertInfo = $rDBCertInfo;
						if (file_exists($rCertInfo['path'] . '/fullchain.pem')) {
							$rCertificate = $rCertInfo['path'] . '/fullchain.pem';
							$rChain = $rCertInfo['path'] . '/chain.pem';
							$rPrivateKey = $rCertInfo['path'] . '/privkey.pem';
							$rSSLConfig = 'ssl_certificate ' . $rCertificate . ';' . "\n" . 'ssl_certificate_key ' . $rPrivateKey . ';' . "\n" . 'ssl_trusted_certificate ' . $rChain . ';' . "\n" . 'ssl_protocols TLSv1.2 TLSv1.3;' . "\n" . 'ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;' . "\n" . 'ssl_prefer_server_ciphers off;' . "\n" . 'ssl_ecdh_curve auto;' . "\n" . 'ssl_session_timeout 10m;' . "\n" . 'ssl_session_cache shared:MozSSL:10m;' . "\n" . 'ssl_session_tickets off;';
							file_put_contents(BIN_PATH . 'nginx/conf/ssl.conf', $rSSLConfig);
							echo 'Fixed ssl configuration file' . "\n";
							$this->reloadNginx($rApi, $db);
						}
					}
				}
			}
		}
	}

	/** MAIN sends the renewals of nodes in mode 2, which cannot queue their own (NodeCertbot). */
	protected function renewNodes(): void {
		if (!class_exists(NodeCertbot::class) || empty(SettingsManager::get('cluster_api_enabled')) || !NodeRole::isMain()) {
			return;
		}
		try {
			foreach (NodeCertbot::renewDue() as $rServerID) {
				echo 'Certificate renewal sent to server ' . $rServerID . '.' . "\n";
			}
		} catch (\Throwable $rE) {
			echo 'Node certificates: ' . $rE->getMessage() . "\n";
		}
	}

	/**
	 * Reload nginx for a new SSL configuration: root's `reload_nginx`, as a
	 * `signals` row (NodeActions). A node in mode 2 has no row for its root
	 * to read, and its nginx runs as xc_vm: it reloads it here, as the
	 * `reload_nginx` RPC does (each binary from its argv list, no shell), and
	 * logs root's line through its agent.
	 */
	private function reloadNginx(bool $rApi, object $db): void {
		if (!$rApi) {
			NodeActions::reloadNginx(intval(SERVER_ID), $db);
			return;
		}
		LogSink::syslog('RELOAD', 'NGINX services reloaded on request.');
		ProcessRunner::run(['sudo', '-u', 'xc_vm', BIN_PATH . 'nginx_rtmp/sbin/nginx_rtmp', '-s', 'reload']);
		ProcessRunner::run(['sudo', '-u', 'xc_vm', BIN_PATH . 'nginx/sbin/nginx', '-s', 'reload']);
	}
}

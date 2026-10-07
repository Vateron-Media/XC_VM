<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterCli;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterEnrolApproveCommand — decide a node's pending code enrolment. The
 * admin types the SAS the node printed; it binds the node's keys, so a code
 * sniffed on the way yields nothing. Five wrong SAS entries reject the
 * request. Without a SAS the pending request is shown (never its SAS).
 *
 * Usage: `console.php cluster:enrol-approve <serverID> [<SAS> | --reject]`. MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
class ClusterEnrolApproveCommand implements CommandInterface {
	public function getName(): string {
		return 'cluster:enrol-approve';
	}

	public function getDescription(): string {
		return 'Approve (by its SAS) or reject a pending code enrolment';
	}

	public function execute(array $rArgs): int {
		$rServerID = intval($rArgs[0] ?? 0);
		$rSas = trim(implode(' ', array_slice($rArgs, 1)));
		if ($rServerID <= 0) {
			echo "Usage: cluster:enrol-approve <serverID> [<SAS> | --reject]\n";
			return 1;
		}
		$rReq = EnrolCodeService::request($rServerID);
		if ($rReq === null || $rReq['state'] !== 'pending_approval') {
			echo "No enrolment request is waiting for server {$rServerID}.\n";
			return 1;
		}
		if ($rSas === '') {
			echo "Pending: node {$rReq['node_uuid']}, instance " . ($rReq['attest'] ?? '-') . ', since ' . gmdate('Y-m-d H:i:s', (int) $rReq['created_at']) . " UTC.\n";
			echo "Read the SAS on the node and run: cluster:enrol-approve {$rServerID} <SAS>\n";
			return 0;
		}
		if ($rSas === '--reject') {
			EnrolCodeService::reject($rServerID);
			echo "Rejected.\n";
			return 0;
		}
		$rCrypto = ClusterCli::crypto();
		if ($rCrypto === null) {
			return 1;
		}
		$rServers = ServerRepository::getAll(true);
		try {
			$rResult = EnrolCodeService::approve($rCrypto, $rServerID, $rSas, SettingsManager::getAll(), $rServers[SERVER_ID] ?? []);
		} catch (ClusterRefusedException $rE) {
			echo match ($rE->reason()) {
				'LICENCE' => 'CLUSTER_LICENCE_REQUIRED',
				default => 'Cluster token refused: ' . $rE->reason(),
			} . ". The request stays pending.\n";
			return 1;
		}
		echo match ($rResult) {
			'approved' => "Approved: the node collects its first token within 10 s and completes enrolment.\n",
			'wrong_sas' => "That SAS does not match the node's keys. Check it on the node; the request is rejected after " . EnrolCodeService::MAX_ATTEMPTS . " wrong entries.\n",
			'rejected' => "Too many wrong SAS entries: the request is rejected. Issue a new code.\n",
			default => "No enrolment request is waiting for server {$rServerID}.\n",
		};
		return $rResult === 'approved' ? 0 : 1;
	}
}

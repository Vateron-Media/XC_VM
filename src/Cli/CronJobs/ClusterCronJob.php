<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ArtefactGrants;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\BlocklistDelta;
use XcVm\Domain\Cluster\ClusterAudit;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\CommandBus;
use XcVm\Domain\Cluster\CorePins;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\MainDataPlane;
use XcVm\Domain\Cluster\NonceStore;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Domain\Cluster\TokenService;
use XcVm\Domain\Server\ServerRepository;

/**
 * ClusterCronJob — MAIN's cluster API housekeeping, every minute:
 *
 * - expired token epochs are deleted, which erases their `z`;
 * - the replay cache and single-use challenges past their 180 s go;
 * - enrolment codes nobody used, and decided requests after a day, go;
 * - the blocklist's change log keeps seven days (also with the API off);
 * - MAIN's old cluster API ports past their seven days go, and so do those
 *   every node has moved off (ClusterEndpoint::release()); nginx's cluster
 *   config is rendered from the settings (also with the API off);
 * - the liveness loop runs once (the signals daemon runs it every second);
 * - nodes whose agent downloads artefacts are granted the admin's off-air
 *   videos they do not hold yet (ArtefactGrants::offerOffAir);
 * - in the hard revocation mode without a licence, every node that takes
 *   commands is sent the licence fence (ClusterRoute::licenceFences);
 * - a node that takes root commands and whose xcvm_core is not pinned to this
 *   panel's key is sent `node.root pin_core` (CorePins::offer);
 * - with MAIN's data-plane client on, MAIN's own tickets are minted anew at
 *   each epoch and its agent's servers section kept current
 *   (MainDataPlane::refresh).
 *
 * The crontab row (`cluster`, role `main`) is copied to load balancers with
 * the rest; there the job returns before touching anything, as the cluster
 * domain classes are not in the LB build.
 *
 * @package XC_VM_CLI_CronJobs
 */
class ClusterCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:cluster';
	}

	public function getDescription(): string {
		return 'Cron: cluster API housekeeping (expired epochs, nonces, enrolment codes)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}
		if (!NodeRole::isMain()) {
			return 0;
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			// The blocklist's change log and the stream versions are written
			// either way; they are kept short. Ports kept before the API was
			// switched off still expire.
			foreach ([static fn() => BlocklistDelta::prune(), static fn() => StreamReplica::prune(), static fn() => self::endpoint()] as $rRun) {
				try {
					$rRun();
				} catch (\Throwable) {
					// The next minute tries again.
				}
			}
			return 0;
		}
		$this->setProcessTitle('XC_VM[Cluster]');
		$this->acquireCronLock();
		self::housekeep();
		@unlink($this->rIdentifier);
		return 0;
	}

	/** One pass; each step on its own, so one failing table does not stop the others. */
	public static function housekeep(): void {
		foreach ([
			'epochs' => static fn() => TokenService::prune(),
			'nonces' => static fn() => NonceStore::purge(),
			'enrol_codes' => static fn() => EnrolCodeService::prune(),
			'commands' => static fn() => CommandBus::prune(),
			'blocklist_changes' => static fn() => BlocklistDelta::prune(),
			'stream_versions' => static fn() => StreamReplica::prune(),
			'endpoint' => static fn() => self::endpoint(),
			'artefacts' => static fn() => ArtefactGrants::offerOffAir(static fn() => ClusterCryptoFactory::create(), SettingsManager::getAll()),
			// lb_revocation_mode=hard without a licence: every node that takes
			// commands is fenced, the fence riding its refused session.
			'licence_fence' => static fn() => ClusterRoute::licenceFences(ClusterCryptoFactory::create(), SettingsManager::getAll()),
			// Every node that takes root commands gets this panel's key pinned in
			// its xcvm_core, which its compiled lease verdict needs (Phase 9).
			'core_pin' => static fn() => CorePins::offer(),
			// MAIN's own data-plane client: its tickets anew at each epoch, its
			// agent's servers section when it changed (Phase 9).
			'main_dataplane' => static fn() => MainDataPlane::refresh(),
			// A node in mode 2 that lost its own streams goes back to mode 1 (opt-in).
			'auto_mode_down' => static fn() => ClusterAdmin::autoModeDown(SettingsManager::getAll()),
			// The signals daemon runs this every second; the minute is its fallback.
			'liveness' => static function () {
				if (LivenessService::tick(ClusterSettings::int('cluster_offline_after_sec', SettingsManager::get('cluster_offline_after_sec'))) !== []) {
					ServerRepository::getAll(true);
				}
			},
		] as $rStep => $rRun) {
			try {
				$rRun();
			} catch (\Throwable $rE) {
				ClusterAudit::log('cron.error', null, ['step' => $rStep, 'error' => substr($rE->getMessage(), 0, 200)], 'cron');
			}
		}
	}

	/**
	 * MAIN's old cluster API ports past their 7 days leave the settings, and
	 * so do those every node has moved off before then (every node heard on
	 * the current policy, none on the port). nginx's cluster config is then
	 * rendered from the stored settings, as xc_vm (the user this job runs
	 * as). Every minute, not only when a port goes: a render that matches
	 * the files is a no-op, so this retries one that failed and undoes one
	 * that raced a settings save.
	 */
	public static function endpoint(): void {
		$rSettings = SettingsManager::getAll();
		ClusterEndpoint::prune($rSettings);
		ClusterEndpoint::release($rSettings);
		ClusterNginxConfig::apply();
	}
}

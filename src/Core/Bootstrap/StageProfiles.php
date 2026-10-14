<?php

namespace XcVm\Core\Bootstrap;

use XcVm\Core\Bootstrap\Stage\AdminApiStage;
use XcVm\Core\Bootstrap\Stage\AdminGlobalsStage;
use XcVm\Core\Bootstrap\Stage\AdminShutdownStage;
use XcVm\Core\Bootstrap\Stage\AttributionVerificationStage;
use XcVm\Core\Bootstrap\Stage\ConfigStage;
use XcVm\Core\Bootstrap\Stage\ConstantsStage;
use XcVm\Core\Bootstrap\Stage\ContainerPopulateStage;
use XcVm\Core\Bootstrap\Stage\DatabaseStage;
use XcVm\Core\Bootstrap\Stage\FloodProtectionStage;
use XcVm\Core\Bootstrap\Stage\HealthCheckStage;
use XcVm\Core\Bootstrap\Stage\HostVerificationStage;
use XcVm\Core\Bootstrap\Stage\LegacyCoreStage;
use XcVm\Core\Bootstrap\Stage\ProcessTitleStage;
use XcVm\Core\Bootstrap\Stage\RedisStage;
use XcVm\Core\Bootstrap\Stage\ReplicaStage;
use XcVm\Core\Bootstrap\Stage\SessionStage;
use XcVm\Core\Bootstrap\Stage\StatusConstantsStage;
use XcVm\Core\Bootstrap\Stage\TranslatorStage;
use XcVm\Core\Enum\BootContext;

/**
 * Builds the ordered stage list for a boot context.
 *
 * This is where the previously separate boot paths converge on one stage set:
 * each context selects a different ordered list, mirroring the exact sequence
 * the old XC_Bootstrap ran for that context. Flood/host/session stages self-skip
 * on CLI, so they are safe to include unconditionally.
 *
 * @package XC_VM_Core_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StageProfiles {
	/**
	 * @param array<string,mixed> $options Resolved boot options.
	 * @return list<BootStageInterface>
	 */
	public static function for(BootContext $context, array $options): array {
		// Common prefix, run for every context (HTTP-only stages self-skip on CLI).
		// StatusConstantsStage defines the STATUS_* constants the Domain services
		// return (StreamService, MovieService, …); they used to be Admin-only, so
		// a CLI/Stream path that reached such a service fatally hit an undefined
		// STATUS_SUCCESS. They are cheap integer defines and initStatus() is
		// idempotent, so define them in every context.
		$stages = [
			new ConstantsStage(),
			new StatusConstantsStage(),
			new ConfigStage(),
			new FloodProtectionStage(),
			new HostVerificationStage(),
		];

		switch ($context) {
			case BootContext::Minimal:
				break;

			case BootContext::Cli:
				// A node in mode 2 or in mode 1 with CONFIG on, and
				// cluster:apply everywhere, boots from its replica
				// (BootKernel::resolve() decides).
				if (is_string($options['replica'] ?? null)) {
					$stages[] = new ReplicaStage((bool) ($options['cached'] ?? false), $options['replica']);
				} else {
					$stages[] = new DatabaseStage();
					$stages[] = new LegacyCoreStage((bool) ($options['cached'] ?? false));
				}
				if (!empty($options['redis'])) {
					$stages[] = new RedisStage();
				}
				if (!empty($options['process'])) {
					$stages[] = new ProcessTitleStage((string) $options['process']);
				}
				break;

			case BootContext::Stream:
				$stages[] = new DatabaseStage();
				break;

			case BootContext::Admin:
				// UI scopes (admin/reseller/player) all boot as Admin; lock the
				// panel UI if the required attribution notice was stripped.
				$stages[] = new AttributionVerificationStage();
				$stages[] = new SessionStage();
				$stages[] = new DatabaseStage();
				$stages[] = new LegacyCoreStage(false);
				$stages[] = new RedisStage();
				$stages[] = new AdminApiStage();
				$stages[] = new TranslatorStage();
				$stages[] = new AdminShutdownStage();
				$stages[] = new AdminGlobalsStage();
				break;

			case BootContext::WebApi:
				// WebApi has its own pipeline (WebApiBootstrap::init) and never
				// routes through the kernel; fail loudly instead of silently
				// building a wrong stage list from the common prefix/suffix.
				throw new \LogicException(
					'BootContext::WebApi boots via WebApiBootstrap::init(), not the kernel StageProfiles.'
				);
		}

		$stages[] = new ContainerPopulateStage();

		if ($context !== BootContext::Minimal) {
			$stages[] = new HealthCheckStage();
		}

		return $stages;
	}
}

<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * `xcvm_core` refused a cluster call. The reason code is what
 * `XC_VM::cluster_last_error()` reported: `LICENCE`, `CLOCK`, `REVOKED`,
 * `NOT_INITIALISED`, `ARG:<name>`, … MAIN also throws it for its own refusal
 * of an enrolment: `REDIS_HANDLER`, a mode 2 enrolment while the Redis
 * connection handler is on (EnrolmentService::begin).
 */
final class ClusterRefusedException extends \RuntimeException {
	public function __construct(private string $rReason, string $rCall) {
		parent::__construct($rCall . ' refused: ' . $rReason);
	}

	public function reason(): string {
		return $this->rReason;
	}
}

<?php

namespace XcVm\Core\Util;

/** Serialize every XC_VM writer that replaces xc_vm's crontab. */
final class CrontabLock {
	/** @return resource|null */
	public static function acquire() {
		$rLock = fopen(__FILE__, 'r');
		return $rLock !== false && flock($rLock, LOCK_EX) ? $rLock : null;
	}
}

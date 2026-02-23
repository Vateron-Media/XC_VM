<?php

class HealthChecker {
	public static function isRunning() {
		$rNginx = 0;
		exec('ps -fp $(pgrep -u xc_vm)', $rOutput, $rReturnVar);
		foreach ($rOutput as $rProcess) {
			$rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
			if ($rSplit[8] == 'nginx:' && $rSplit[9] == 'master') {
				$rNginx++;
			}
		}
		return 0 < $rNginx;
	}
}

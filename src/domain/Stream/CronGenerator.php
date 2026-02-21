<?php

class CronGenerator {
	public static function generate($db) {
		if (file_exists(TMP_PATH . 'crontab')) {
			return false;
		}

		$rJobs = array();
		$db->query('SELECT * FROM `crontab` WHERE `enabled` = 1;');
		foreach ($db->get_rows() as $rRow) {
			$rFullPath = CRON_PATH . $rRow['filename'];
			if (pathinfo($rFullPath, PATHINFO_EXTENSION) == 'php' && file_exists($rFullPath)) {
				$rJobs[] = $rRow['time'] . ' ' . PHP_BIN . ' ' . $rFullPath . ' # XC_VM';
			}
		}

		shell_exec('crontab -r');
		$rTempName = tempnam('/tmp', 'crontab');
		$rHandle = fopen($rTempName, 'w');
		fwrite($rHandle, implode("\n", $rJobs) . "\n");
		fclose($rHandle);
		shell_exec('crontab -u xc_vm ' . $rTempName);
		@unlink($rTempName);
		file_put_contents(TMP_PATH . 'crontab', 1);
		return true;
	}
}

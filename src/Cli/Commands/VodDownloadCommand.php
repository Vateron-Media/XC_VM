<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Vod\VodDownload;

/** Download one queued VOD source on the destination server. */
final class VodDownloadCommand implements CommandInterface {
	public function getName(): string {
		return 'vod_download';
	}

	public function getDescription(): string {
		return 'Download a queued M3U VOD source to its selected directory';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'xc_vm') {
			return 1;
		}
		set_time_limit(0);
		return VodDownload::run((string) ($rArgs[0] ?? '')) ? 0 : 1;
	}
}

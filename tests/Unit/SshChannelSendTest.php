<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\SshChannel;

/**
 * A file MAIN does not have is not "sent": its md5 (false) loosely equalled
 * the empty md5sum of the missing remote file, so an install ran
 * /tmp/install_xcvm_core.sh on a node it had never reached.
 */
final class SshChannelSendTest extends TestCase {
	public function testAMissingLocalFileIsNotSent(): void {
		$this->assertFalse(SshChannel::send(null, sys_get_temp_dir() . '/xcvm-no-such-file-' . bin2hex(random_bytes(4)), '/tmp/x'));
	}
}

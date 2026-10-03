<?php

namespace XcVm\Cli\Commands;

/**
 * The two SSH primitives the node install and enrolment flows use: run a
 * command and send a file (SCP, falling back to SFTP when the checksum does
 * not match). MAIN only.
 *
 * @package XC_VM_CLI_Commands
 */
final class SshChannel {
	/** @return array{output: string, error: string} */
	public static function run($rConn, string $rCommand): array {
		$rStream = ssh2_exec($rConn, $rCommand);
		$rError = ssh2_fetch_stream($rStream, SSH2_STREAM_STDERR);
		stream_set_blocking($rError, true);
		stream_set_blocking($rStream, true);
		return ['output' => stream_get_contents($rStream), 'error' => stream_get_contents($rError)];
	}

	public static function send($rConn, string $rPath, string $rOutput, bool $rWarn = false): bool {
		// A file MAIN does not have is not sent: its checksum (false) loosely
		// equalled the empty sum of the missing remote file, and passed for a copy.
		$rSum = is_file($rPath) ? hash_file('sha256', $rPath) : false;
		if ($rSum === false) {
			return false;
		}
		ssh2_scp_send($rConn, $rPath, $rOutput);
		$rOutSum = trim(explode(' ', self::run($rConn, 'sha256sum "' . $rOutput . '"')['output'])[0]);
		if ($rSum === $rOutSum) {
			return true;
		}
		if ($rWarn) {
			echo "Failed to write using SCP, reverting to SFTP transfer... This will be take significantly longer!\n";
		}
		$rSFTP = ssh2_sftp($rConn);
		if (!$rSFTP) {
			return false;
		}
		$rSuccess = true;
		$rStream = @fopen('ssh2.sftp://' . $rSFTP . $rOutput, 'wb');
		if (!$rStream) {
			return false;
		}
		try {
			$rData = @file_get_contents($rPath);
			if ($rData === false || @fwrite($rStream, $rData) === false) {
				$rSuccess = false;
			}
			if (is_resource($rStream)) {
				fclose($rStream);
			}
		} catch (\Exception $e) {
			$rSuccess = false;
			if (is_resource($rStream)) {
				fclose($rStream);
			}
		}
		return $rSuccess;
	}
}

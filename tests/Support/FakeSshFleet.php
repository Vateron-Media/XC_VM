<?php

namespace XcVm\Tests\Support;

use XcVm\Cli\Commands\SshSession;
use XcVm\Domain\Cluster\EnrolmentService;

/**
 * The fleet's side of SSH: one entry per node address, answering as a node
 * that runs this release and xc_agent does (the agent's install from its
 * release, keygen, probe, install).
 */
final class FakeSshFleet extends SshSession {
	/**
	 * xcvm_core on a node, for server:xcvt-pin (ADR-005), when `iid` is set:
	 * its install_id, what `panel_pk()` prints (`PK <hex>`, `NONE`, `ERR`),
	 * the fingerprint `cluster_pin` answers with (or an `ERR …`), and what
	 * `cluster_pinned` reads (by default what cluster_pin left, else
	 * NOT_PINNED).
	 *
	 * @var array<string, array{hostkey: string, password: string, ready?: bool, probe?: bool, agent?: bool, iid?: string, pk?: string, pin?: string, pinned?: string}>
	 */
	public array $rNodes = [];

	/** @var list<string> connect/login/close events and "run <host> <cmd>" */
	public array $rLog = [];

	/** Called with the host and the command before each command runs: to act in the middle of a run, or to throw. */
	public ?\Closure $rHook = null;

	private ?string $rHost = null;

	public function connect(string $rHost, int $rPort): bool {
		$this->rLog[] = 'connect ' . $rHost . ':' . $rPort;
		$this->rHost = isset($this->rNodes[$rHost]) ? $rHost : null;
		return $this->rHost !== null;
	}

	public function hostKey(): string {
		return $this->rNodes[$this->rHost]['hostkey'];
	}

	public function login(string $rUsername, string $rPassword): bool {
		$this->rLog[] = 'login ' . $this->rHost . ' ' . $rUsername;
		return $rPassword === $this->rNodes[$this->rHost]['password'];
	}

	public function run(string $rCommand): array {
		$this->rLog[] = 'run ' . $this->rHost . ' ' . $rCommand;
		if ($this->rHook !== null) {
			($this->rHook)((string) $this->rHost, $rCommand);
		}
		$rNode = $this->rNodes[$this->rHost];
		if (isset($rNode['iid'])) {
			if (str_contains($rCommand, 'sudo -n true')) {
				return ['output' => "SUDO_OK\n", 'error' => ''];
			}
			if (str_contains($rCommand, 'XC_VM::install_id()')) {
				return ['output' => $rNode['iid'] . "\n", 'error' => ''];
			}
			if (str_contains($rCommand, '"panel_pk"')) {
				return ['output' => ($rNode['pk'] ?? 'NONE') . "\n", 'error' => ''];
			}
			if (str_contains($rCommand, 'XC_VM::cluster_pin(')) {
				$rPin = $rNode['pin'] ?? 'ERR CRYPTO';
				if (!str_starts_with($rPin, 'ERR')) {
					$this->rNodes[(string) $this->rHost]['pinned'] ??= 'OK ' . $rPin;
					$rPin = 'OK ' . $rPin;
				}
				return ['output' => $rPin . "\n", 'error' => ''];
			}
			if (str_contains($rCommand, 'cluster_pinned')) {
				return ['output' => ($this->rNodes[(string) $this->rHost]['pinned'] ?? 'NOT_PINNED') . "\n", 'error' => ''];
			}
		}
		if (str_contains($rCommand, 'echo READY')) {
			return ['output' => ($rNode['ready'] ?? true) ? "READY\n" : '', 'error' => ''];
		}
		if (str_contains($rCommand, 'console.php fanout_binary agent')) {
			return ['output' => ($rNode['agent'] ?? true) ? "xc_agent 1.5.0 installed.\nAGENT_OK\n" : "Failed to download xc_agent-linux-amd64\n", 'error' => ''];
		}
		if (preg_match('/ keygen .* -uuid ([0-9a-f-]{36})$/', $rCommand, $rM)) {
			$rSign = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());
			$rBox = sodium_crypto_scalarmult_base(random_bytes(32));
			return ['output' => json_encode([
				'node_uuid' => $rM[1], 'sign_pub' => bin2hex($rSign), 'box_pub' => bin2hex($rBox),
				'eph_pub' => bin2hex(sodium_crypto_scalarmult_base(random_bytes(32))), 'sas' => EnrolmentService::sas($rM[1], $rSign, $rBox),
			]) . "\n", 'error' => ''
			];
		}
		if (str_contains($rCommand, ' probe ')) {
			return ['output' => ($rNode['probe'] ?? true) ? "OK http://10.0.0.1:25461/cluster/v1/\n" : "xc_agent probe: connection refused\n", 'error' => ''];
		}
		if (str_contains($rCommand, ' install ')) {
			return ['output' => "OK\n", 'error' => ''];
		}
		if (str_contains($rCommand, 'run.sh') && str_contains($rCommand, 'pgrep')) {
			return ['output' => "STARTED\n", 'error' => '']; // the agent's start, seen running
		}
		return ['output' => '', 'error' => ''];
	}

	public function send(string $rLocal, string $rRemote, bool $rWarn = false): bool {
		$this->rLog[] = 'send ' . $this->rHost . ' ' . $rRemote;
		return true;
	}

	public function close(): void {
		$this->rLog[] = 'close ' . $this->rHost;
		$this->rHost = null;
	}

	/** @return list<string> the commands run on $rHost */
	public function commands(string $rHost): array {
		$rOut = [];
		foreach ($this->rLog as $rLine) {
			if (str_starts_with($rLine, 'run ' . $rHost . ' ')) {
				$rOut[] = substr($rLine, strlen('run ' . $rHost . ' '));
			}
		}
		return $rOut;
	}
}

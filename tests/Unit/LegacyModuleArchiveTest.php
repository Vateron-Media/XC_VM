<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ModuleInstallCommand;
use XcVm\Core\Module\ModuleManager;

/**
 * A custom module a legacy node pulls with getFile: MAIN announces its
 * archive's size and SHA-256 in the install_module payload, and the node
 * installs only those bytes (the zip magic was the only check before).
 */
final class LegacyModuleArchiveTest extends TestCase {
	private string $rRoot;

	protected function setUp(): void {
		$this->rRoot = sys_get_temp_dir() . '/xcvm-legacy-module-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rRoot . 'Modules', 0777, true);
		mkdir($this->rRoot . 'modules_archives', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rRoot));
	}

	public function testMainAnnouncesACustomModulesArchive(): void {
		$rManager = new ModuleManager($this->rRoot . 'Modules', $this->rRoot . 'modules.php');
		$rZip = "PK\x03\x04 the archive";
		file_put_contents($rManager->archivePathFor('radio', '1.0'), $rZip);
		$this->assertSame(
			['action' => 'install_module', 'source' => 'local', 'name' => 'radio', 'version' => '1.0', 'size' => strlen($rZip), 'sha256' => hash('sha256', $rZip)],
			$rManager->lbInstallPayload('radio', 'local', '1.0')
		);
		$this->assertArrayNotHasKey('sha256', $rManager->lbInstallPayload('radio', 'platform', '1.0'), 'the store module comes from the platform');
		$this->assertArrayNotHasKey('sha256', $rManager->lbInstallPayload('radio', 'local', '2.0'), 'no archive kept: nothing to announce');
	}

	public function testTheNodeInstallsOnlyTheArchiveAnnounced(): void {
		$rFile = $this->rRoot . 'download.zip';
		$rZip = "PK\x03\x04 the archive";
		file_put_contents($rFile, $rZip);
		$rSha = hash('sha256', $rZip);
		$rSize = strlen($rZip);
		$this->assertNull(ModuleInstallCommand::announced($rFile, ['size' => $rSize, 'sha256' => $rSha]));
		$this->assertNull(ModuleInstallCommand::announced($rFile, []), 'a MAIN from before: the zip magic alone, as before');
		$this->assertSame('size or SHA-256 mismatch', ModuleInstallCommand::announced($rFile, ['size' => $rSize + 1, 'sha256' => $rSha]));
		$this->assertSame('size or SHA-256 mismatch', ModuleInstallCommand::announced($rFile, ['size' => $rSize, 'sha256' => hash('sha256', 'another')]));
		$this->assertSame('a malformed size or SHA-256', ModuleInstallCommand::announced($rFile, ['sha256' => $rSha]));
		$this->assertSame('a malformed size or SHA-256', ModuleInstallCommand::announced($rFile, ['size' => (string) $rSize, 'sha256' => $rSha]));
		$this->assertSame('a malformed size or SHA-256', ModuleInstallCommand::announced($rFile, ['size' => $rSize, 'sha256' => strtoupper($rSha)]));
		$this->assertSame('size or SHA-256 mismatch', ModuleInstallCommand::announced($this->rRoot . 'gone.zip', ['size' => $rSize, 'sha256' => $rSha]));

		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ModuleInstallCommand.php');
		$this->assertStringContainsString('$rWrong = self::announced($rTmp, $rPayload);', $rSource, 'the getFile download is checked');
	}
}

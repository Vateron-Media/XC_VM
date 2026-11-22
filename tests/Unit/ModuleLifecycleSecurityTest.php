<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\ModuleManager;
use XcVm\Public\Controllers\Admin\Ajax\ModuleAjaxController;

final class ModuleLifecycleSecurityTest extends TestCase {
	private string $rDir;
	private array $rRequest;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-module-security-' . bin2hex(random_bytes(4));
		mkdir($this->rDir . '/modules', 0777, true);
		file_put_contents($this->rDir . '/modules.php', "<?php\nreturn [];\n");
		$this->rRequest = RequestManager::getAll();
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		putenv('XCVM_ALLOW_LOCAL_MODULE_UPLOAD');
		unset($GLOBALS['rUserInfo'], $GLOBALS['rPermissions'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['REQUEST_METHOD']);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testRestrictedAdministratorCannotCallModuleLifecycleApi(): void {
		$GLOBALS['rUserInfo'] = ['id' => 9, 'member_group_id' => 5];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['settings']];
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
		$_SERVER['REQUEST_METHOD'] = 'POST';
		RequestManager::set(['sub' => 'delete', 'name' => 'watch']);
		http_response_code(200);

		try {
			(new ModuleLifecycleSecurityController())->module();
			$this->fail('The controller did not answer.');
		} catch (ModuleLifecycleSecurityAnswer $answer) {
			$this->assertSame(403, http_response_code());
			$this->assertSame(false, $answer->rData['result']);
		}
	}

	public function testLocalUploadsRequireExplicitOperatorPolicy(): void {
		putenv('XCVM_ALLOW_LOCAL_MODULE_UPLOAD');
		$this->assertFalse(ModuleManager::localUploadsEnabled());
		putenv('XCVM_ALLOW_LOCAL_MODULE_UPLOAD=1');
		$this->assertTrue(ModuleManager::localUploadsEnabled());
		putenv('XCVM_ALLOW_LOCAL_MODULE_UPLOAD=true');
		$this->assertFalse(ModuleManager::localUploadsEnabled());
	}

	public function testTraversalArchiveFailsBeforeInstallation(): void {
		$archive = $this->rDir . '/traversal.zip';
		$zip = new ZipArchive();
		$this->assertTrue($zip->open($archive, ZipArchive::CREATE));
		$zip->addFromString('demo/module.json', json_encode(['name' => 'demo', 'version' => '1.0.0']));
		$zip->addFromString('../escape.php', '<?php');
		$zip->close();

		$this->expectExceptionMessage('Unsafe archive entry');
		$this->manager()->uploadAndInstall($archive);
	}

	public function testDistributedArchiveMustMatchItsGrantIdentity(): void {
		$archive = $this->rDir . '/identity.tar';
		$tar = new PharData($archive);
		$tar->addFromString('other/module.json', json_encode(['name' => 'other', 'version' => '2.0.0']));
		unset($tar);

		$this->expectExceptionMessage('identity does not match');
		$this->manager()->deployFromArchiveFilesOnly($archive, 'granted', '2.0.0');
	}

	public function testZipValidationHasNoCliFallbackWithoutAnExtractionQuota(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Core/Module/ModuleManager.php');
		$this->assertStringNotContainsString('unzip', $rSource);
		$this->assertStringContainsString('Secure ZIP validation requires the PHP zip extension.', $rSource);
	}

	private function manager(): ModuleManager {
		return new ModuleManager($this->rDir . '/modules', $this->rDir . '/modules.php');
	}
}

final class ModuleLifecycleSecurityAnswer extends RuntimeException {
	public function __construct(public array $rData) {
		parent::__construct('answered');
	}
}

final class ModuleLifecycleSecurityController extends ModuleAjaxController {
	protected function json(array $rData, int $rFlags = 0): never {
		throw new ModuleLifecycleSecurityAnswer($rData);
	}
}

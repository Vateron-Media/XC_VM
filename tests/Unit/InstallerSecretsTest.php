<?php

use PHPUnit\Framework\TestCase;

final class InstallerSecretsTest extends TestCase {
	public function testInstallerUsesCsprngAndKeepsDatabaseSecretsOffArgvAndOutput(): void {
		$rInstaller = dirname(__DIR__, 2) . '/install';
		$rSource = (string) file_get_contents($rInstaller);
		$this->assertStringContainsString('secrets.choice(chars)', $rSource);
		$this->assertStringNotContainsString('random.choice(', $rSource);
		$this->assertStringNotContainsString('-p"{root_password}"', $rSource);
		foreach (['Generated root password: {root_password}', 'Generated access code: {admin_code}', '/{admin_code}"'] as $rLeak) {
			$this->assertStringNotContainsString($rLeak, $rSource);
		}

		$rDriver = <<<'PY'
import importlib.machinery, importlib.util, json, os, sys
loader = importlib.machinery.SourceFileLoader("xc_install", sys.argv[1])
mod = importlib.util.module_from_spec(importlib.util.spec_from_loader("xc_install", loader))
loader.exec_module(mod)
seen = {}
class Result: returncode = 0
def run(args, **kwargs):
    seen["args"] = args
    seen["sql"] = kwargs.get("input")
    option = next((x for x in args if x.startswith("--defaults-extra-file=")), "")
    seen["defaults"] = open(option.split("=", 1)[1]).read() if option else ""
    seen["stdout"] = kwargs.get("stdout") is mod.subprocess.DEVNULL
    seen["stderr"] = kwargs.get("stderr") is mod.subprocess.DEVNULL
    return Result()
mod.subprocess.run = run
mod.run_mariadb("SELECT 'sql-secret'", "root-secret", "xc_vm")
print(json.dumps(seen))
PY;
		$rFile = tempnam(sys_get_temp_dir(), 'xcvm-installer-test-');
		file_put_contents($rFile, $rDriver);
		exec('python3 -B ' . escapeshellarg($rFile) . ' ' . escapeshellarg($rInstaller), $rOutput, $rCode);
		@unlink($rFile);
		$this->assertSame(0, $rCode, implode("\n", $rOutput));
		$rSeen = json_decode(implode("\n", $rOutput), true);
		$this->assertIsArray($rSeen);
		$this->assertStringNotContainsString('root-secret', implode(' ', $rSeen['args']));
		$this->assertStringNotContainsString('sql-secret', implode(' ', $rSeen['args']));
		$this->assertStringContainsString('root-secret', $rSeen['defaults']);
		$this->assertSame("SELECT 'sql-secret'", $rSeen['sql']);
		$this->assertTrue($rSeen['stdout']);
		$this->assertTrue($rSeen['stderr']);
	}
}

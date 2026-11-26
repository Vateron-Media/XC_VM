<?php

use PHPUnit\Framework\TestCase;

final class AdminLoginParticlesTest extends TestCase {
	public function testLoginPagesLoadTheSharedParticleBackgroundAndLogo(): void {
		$admin = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/login.php');
		$reseller = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/login.php');
		$player = (string) file_get_contents(MAIN_HOME . 'Public/Views/player_v2/login.php');
		$script = (string) file_get_contents(MAIN_HOME . 'Public/assets/admin/xcvm/login-particles.js');
		$styles = (string) file_get_contents(MAIN_HOME . 'Public/assets/admin/xcvm/login-classic.css');
		$playerStyles = (string) file_get_contents(MAIN_HOME . 'Public/assets/player_v2/css/demo.css');

		foreach ([$admin, $reseller] as $login) {
			$this->assertStringContainsString('id="tsparticles" class="login-particles" aria-hidden="true"', $login);
			$this->assertStringContainsString('@tsparticles/slim@4/tsparticles.slim.bundle.min.js', $login);
			$this->assertStringContainsString('class="panel-logo-image" src="assets/img/logo-topbar.png"', $login);
		}
		$this->assertStringContainsString('id="tsparticles" class="login-particles" aria-hidden="true"', $player);
		$this->assertStringContainsString('@tsparticles/slim@4/tsparticles.slim.bundle.min.js', $player);
		$this->assertStringContainsString('class="login-brand-logo" src="/assets/admin/img/logo-topbar.png"', $player);
		$this->assertStringContainsString('d-flex align-items-center justify-content-center gap-2', $player);
		$this->assertStringNotContainsString('btn btn-primary d-grid', $player);
		$this->assertStringContainsString('prefers-reduced-motion: reduce', $script);
		$this->assertStringContainsString('MutationObserver', $script);
		$this->assertStringContainsString('var accent = dark ? primary : darken(primary)', $script);
		$this->assertStringContainsString('* 0.8', $script);
		$this->assertStringContainsString('paint: {', $script);
		$this->assertStringContainsString('color: { value: dark ? [primary, neutral] : accent }', $script);
		$this->assertStringContainsString('dark ? 0.16 : 0.52', $script);
		$this->assertStringContainsString('dark ? { min: 0.16, max: 0.36 } : { min: 0.58, max: 0.9 }', $script);
		$this->assertStringContainsString('detectsOn: "window"', $script);
		$this->assertStringContainsString('onHover: { enable: !reduceMotion, mode: "grab" }', $script);
		$this->assertStringContainsString('pointer-events: none', $styles);
		$this->assertStringContainsString('pointer-events: none', $playerStyles);
		$this->assertStringContainsString('background-color: var(--bs-body-bg)', $playerStyles);
	}
}

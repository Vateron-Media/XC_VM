<?php

use PHPUnit\Framework\TestCase;

final class WebPlayerRetirementTest extends TestCase {
	public function testOnlyTheCurrentWebPlayerCanBeCreated(): void {
		$rForm = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/code.php');

		$this->assertStringContainsString("6 => 'Web Player'", $rForm);
		$this->assertStringNotContainsString("8 => 'Web Player", $rForm);
	}

	public function testLegacyPlayerTreesAreNotShipped(): void {
		foreach ([
			'Public/Controllers/Player',
			'Public/Views/player',
			'Public/Views/layouts/player',
			'Public/assets/player',
			'Public/routes/player.php',
		] as $rPath) {
			$this->assertFileDoesNotExist(MAIN_HOME . $rPath, $rPath);
		}
	}

	public function testEveryRemovedLegacyFileIsInTheUpgradeManifest(): void {
		$rEntries = file(MAIN_HOME . 'migrations/deleted_files.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$this->assertIsArray($rEntries);
		$rEntries = array_values(array_filter($rEntries, static fn(string $rLine): bool => !str_starts_with($rLine, '#')));
		$rExpected = [
			'Public/Controllers/Player/BasePlayerController.php',
			'Public/Controllers/Player/EpisodesController.php',
			'Public/Controllers/Player/HomeController.php',
			'Public/Controllers/Player/ListingsController.php',
			'Public/Controllers/Player/LiveController.php',
			'Public/Controllers/Player/MoviesController.php',
			'Public/Controllers/Player/PlayerLoginController.php',
			'Public/Controllers/Player/PlayerLogoutController.php',
			'Public/Controllers/Player/PlayerMovieController.php',
			'Public/Controllers/Player/PlayerProfileController.php',
			'Public/Controllers/Player/PlayerProxyController.php',
			'Public/Controllers/Player/PlayerResizeController.php',
			'Public/Controllers/Player/PortalController.php',
			'Public/Controllers/Player/SeriesController.php',
			'Public/Views/layouts/player/footer.php',
			'Public/Views/layouts/player/header.php',
			'Public/Views/player/episodes.php',
			'Public/Views/player/index.php',
			'Public/Views/player/live.php',
			'Public/Views/player/login.php',
			'Public/Views/player/movie.php',
			'Public/Views/player/movies.php',
			'Public/Views/player/profile.php',
			'Public/Views/player/series.php',
			'Public/assets/player/css/app.css',
			'Public/assets/player/css/default-skin.css',
			'Public/assets/player/css/glightbox.css',
			'Public/assets/player/css/ionicons.min.css',
			'Public/assets/player/css/jBox.all.min.css',
			'Public/assets/player/css/jquery.mcustomscrollbar.min.css',
			'Public/assets/player/css/listings.css',
			'Public/assets/player/css/main.css',
			'Public/assets/player/css/nouislider.min.css',
			'Public/assets/player/css/owl.carousel.min.css',
			'Public/assets/player/css/photoswipe.css',
			'Public/assets/player/css/plyr.css',
			'Public/assets/player/css/video-js.min.css',
			'Public/assets/player/css/videojs-fantasy.css',
			'Public/assets/player/fonts/ionicons.eot',
			'Public/assets/player/fonts/ionicons.svg',
			'Public/assets/player/fonts/ionicons.ttf',
			'Public/assets/player/fonts/ionicons.woff',
			'Public/assets/player/images/favicon.ico',
			'Public/assets/player/images/logo-topbar.png',
			'Public/assets/player/images/logo.png',
			'Public/assets/player/images/pattern.png',
			'Public/assets/player/images/placeholder.png',
			'Public/assets/player/images/thumbs/.gitkeep',
			'Public/assets/player/js/glightbox.min.js',
			'Public/assets/player/js/jBox.all.min.js',
			'Public/assets/player/js/jquery.mcustomscrollbar.min.js',
			'Public/assets/player/js/jquery.morelines.min.js',
			'Public/assets/player/js/jquery.mousewheel.min.js',
			'Public/assets/player/js/listings.js',
			'Public/assets/player/js/main.js',
			'Public/assets/player/js/nouislider.min.js',
			'Public/assets/player/js/owl.carousel.min.js',
			'Public/assets/player/js/photoswipe-ui-default.min.js',
			'Public/assets/player/js/photoswipe.min.js',
			'Public/assets/player/js/video.min.js',
			'Public/assets/player/js/wnumb.js',
			'Public/routes/player.php',
		];

		$this->assertSame([], array_values(array_diff($rExpected, $rEntries)));
	}

	public function testDeployedLegacyScopeUsesCurrentRoutes(): void {
		$rFrontController = (string) file_get_contents(MAIN_HOME . 'Public/index.php');

		$this->assertStringContainsString("'player'               => 'player_v2'", $rFrontController);
		$this->assertTrue(class_exists(\XcVm\Public\Controllers\Portal\PortalController::class));
		$this->assertTrue(class_exists(\XcVm\Public\Controllers\PlayerV2\ListingsController::class));
		$this->assertTrue(class_exists(\XcVm\Public\Controllers\PlayerV2\PlayerProxyController::class));
		$this->assertTrue(class_exists(\XcVm\Public\Controllers\PlayerV2\PlayerResizeController::class));
	}
}

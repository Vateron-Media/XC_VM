<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Admin\SettingsController;

/** The settings page names an FFmpeg build by what its binary says, not by its folder. */
final class SettingsFfmpegLabelTest extends TestCase {
	public function testABuildIsLabelledFromItsBanner(): void {
		$this->assertSame('v4.4.5-XCVM', SettingsController::ffmpegLabel('4.0', 'ffmpeg version 4.4.5-XCVM Copyright (c) 2000-2024 the FFmpeg developers'), 'the binary, not the folder');
		$this->assertSame('v8.1-XCVM', SettingsController::ffmpegLabel('8.1', 'ffmpeg version 8.1-XCVM Copyright (c) 2000-2026'));
		$this->assertSame('v4.3.7-XCVM', SettingsController::ffmpegLabel('4.3', 'ffmpeg version n4.3.7-XCVM Copyright'), 'a git tag\'s leading n dropped');
		$this->assertSame('v6.1.1-3ubuntu5', SettingsController::ffmpegLabel('6.1', 'ffmpeg version 6.1.1-3ubuntu5 Copyright'), 'a distro suffix is not read as a builder');
		$this->assertSame('v4.0.2-XUI', SettingsController::ffmpegLabel('4.0-xui', 'ffmpeg version 4.0.2-XUI Copyright'), 'the binary\'s own suffix wins over the folder\'s');
		$this->assertSame('v4.0 (XUI)', SettingsController::ffmpegLabel('4.0-xui', 'ffmpeg version 4.0 Copyright (c) 2000-2018'), 'the builder from the folder when the banner has none');
		$this->assertSame('v7.1', SettingsController::ffmpegLabel('7.1', 'ffmpeg version N-112233-gabcdef Copyright'), 'a banner without a version keeps the folder id');
	}

	public function testAnIdWithNoBuildIsAuto(): void {
		$this->assertStringStartsWith('v8.0 (', SettingsController::ffmpegLabel('8.0', null));
	}
}

<?php

use XcVm\Core\Enum\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeEnumTest extends TestCase {

    public function testCasesAreIntBackedByLegacyIndex(): void {
        $this->assertSame(0, Theme::Light->value);
        $this->assertSame(1, Theme::Dark->value);
    }

    public function testIsDark(): void {
        $this->assertFalse(Theme::Light->isDark());
        $this->assertTrue(Theme::Dark->isDark());
    }

    public function testLabel(): void {
        $this->assertSame('Light', Theme::Light->label());
        $this->assertSame('Dark', Theme::Dark->label());
    }

    public function testFromIdMapsStoredValues(): void {
        $this->assertSame(Theme::Light, Theme::fromId(0));
        $this->assertSame(Theme::Dark, Theme::fromId(1));
        $this->assertSame(Theme::Dark, Theme::fromId('1'));
    }

    public function testFromIdFallsBackToLightForInvalid(): void {
        $this->assertSame(Theme::Light, Theme::fromId(99));
        $this->assertSame(Theme::Light, Theme::fromId(''));
        $this->assertSame(Theme::Light, Theme::fromId(null));
    }

    public function testOptionsPreserveLegacyOrder(): void {
        $this->assertSame([0 => 'Light', 1 => 'Dark'], Theme::options());
    }
}

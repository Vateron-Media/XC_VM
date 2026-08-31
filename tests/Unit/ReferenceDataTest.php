<?php

use XcVm\Core\Reference\GeoReference;
use XcVm\Core\Reference\LocaleReference;
use XcVm\Core\Reference\DeviceReference;
use XcVm\Core\Reference\UiReference;
use XcVm\Core\Reference\PermissionReference;
use PHPUnit\Framework\TestCase;

final class ReferenceDataTest extends TestCase {

    public function testGeoCountryCodes(): void {
        $this->assertSame('United States of America', GeoReference::countryCodes()['US']);
        $this->assertSame('Germany', GeoReference::countryCodes()['DE']);
    }

    public function testGeoCountriesLeadingOffBucket(): void {
        $first = GeoReference::countries()[0];
        $this->assertSame(['id' => '', 'name' => 'Off'], $first);
    }

    public function testGeoCountriesFilterMap(): void {
        $this->assertSame('All Countries', GeoReference::geoCountries()['ALL']);
    }

    public function testTmdbLanguages(): void {
        $this->assertSame('Default - EN', LocaleReference::tmdbLanguages()['']);
        $this->assertSame('English', LocaleReference::tmdbLanguages()['en']);
    }

    public function testMagModels(): void {
        $models = DeviceReference::magModels();
        $this->assertContains('MAG250', $models);
        $this->assertNotEmpty($models);
    }

    public function testHues(): void {
        $this->assertSame('Default', UiReference::hues()['']);
        $this->assertSame('Blue', UiReference::hues()['primary']);
    }

    public function testPermissionKeysAreUniqueAndNonEmpty(): void {
        $keys = PermissionReference::keys();
        $this->assertNotEmpty($keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->assertContains('settings', $keys);
    }
}

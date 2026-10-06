<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\SettingsService;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;

/**
 * The request the admin API hands to the services whose save reaches nginx,
 * the nodes or the processes of the server (edit_server, edit_proxy,
 * edit_access_code, edit_settings): every field it leaves out is added from
 * the record as stored, as the panel's form posts it; a switch it sends as 0
 * or empty is off, a list it sends empty is none.
 */
final class AuditDecisionEditKeepsFormFieldsTest extends TestCase {
	/** @param mixed ...$rArgs */
	private function keep(string $rHelper, ...$rArgs): array {
		return (new ReflectionMethod(AdminAPIWrapper::class, $rHelper))->invoke(null, ...$rArgs);
	}

	/** @return array<string, mixed> a server with a value of its own in every field its form posts */
	private function server(): array {
		return [
			'server_ip' => '192.0.2.10', 'domain_name' => 'a.example,b.example', 'geoip_countries' => '["DE","FR"]', 'isp_names' => '["isp one"]',
			'http_broadcast_port' => 8080, 'http_ports_add' => '8081,8082', 'https_broadcast_port' => 8443, 'https_ports_add' => null, 'total_services' => 4, 'use_disk' => 1,
			'enable_gzip' => 1, 'timeshift_only' => 1, 'enable_https' => 1, 'random_ip' => 0, 'enable_geoip' => 1, 'enable_isp' => 1, 'enabled' => 1, 'enable_proxy' => 0,
		];
	}

	public function testAServerEditKeepsItsPortsServicesListsAndSwitches(): void {
		$rData = $this->keep('keepServerFields', ['edit' => 2, 'server_name' => 'Renamed'], $this->server(), false);

		$this->assertEquals([
			'edit' => 2, 'server_name' => 'Renamed', 'server_ip' => '192.0.2.10', 'domain_name' => ['a.example', 'b.example'], 'geoip_countries' => ['DE', 'FR'], 'isp_names' => ['isp one'],
			'http_broadcast_ports' => [8080, '8081', '8082'], 'https_broadcast_ports' => [8443], 'total_services' => 4,
			'enable_gzip' => 1, 'timeshift_only' => 1, 'enable_https' => 1, 'enable_geoip' => 1, 'enable_isp' => 1, 'enabled' => 1, 'disable_ramdisk' => 1,
		], $rData);
	}

	public function testAServerSwitchSentAsZeroOrEmptyIsOffAndAListSentEmptyIsNone(): void {
		$rData = $this->keep('keepServerFields', ['enable_gzip' => '0', 'disable_ramdisk' => '', 'random_ip' => '1', 'geoip_countries' => '', 'domain_name' => ''], $this->server(), false);

		$this->assertArrayNotHasKey('enable_gzip', $rData);
		$this->assertArrayNotHasKey('disable_ramdisk', $rData);
		$this->assertSame([1, [], []], [$rData['random_ip'], $rData['geoip_countries'], $rData['domain_name']]);
	}

	public function testAProxyEditKeepsItsAddressListsAndSwitches(): void {
		$rData = $this->keep('keepServerFields', ['server_name' => 'Renamed'], $this->server(), true);

		$this->assertEquals([
			'server_name' => 'Renamed', 'server_ip' => '192.0.2.10', 'domain_name' => ['a.example', 'b.example'], 'geoip_countries' => ['DE', 'FR'],
			'enable_https' => 1, 'enable_geoip' => 1, 'enabled' => 1,
		], $rData);
	}

	public function testAnAccessCodeEditKeepsItsCodeTypeWhitelistAndSwitch(): void {
		$rCode = ['code' => 'panelcode', 'type' => 0, 'whitelist' => '["192.0.2.1"]', 'enabled' => 1, 'groups' => '[1]'];

		$this->assertEquals(['edit' => 3, 'code' => 'panelcode', 'type' => 0, 'whitelist' => ['192.0.2.1'], 'enabled' => 1], $this->keep('keepCodeFields', ['edit' => 3], $rCode));
		$this->assertEquals(['whitelist' => [], 'code' => 'panelcode', 'type' => 0], $this->keep('keepCodeFields', ['whitelist' => '', 'enabled' => '0'], $rCode));
	}

	public function testASettingsEditChangesOnlyTheSettingsItNames(): void {
		$rCheckboxes = SettingsService::checkboxes();
		$rStored = array_fill_keys($rCheckboxes, 0) + [
			'search_items' => 30, 'disable_table_responsive' => 0, 'allowed_stb_types' => '["MAG250"]', 'allowed_stb_types_for_local_recording' => '[]',
			'maxmind_editions' => '["GeoLite2-City"]', 'shared_mount_prefixes' => '["/mnt/share"]', 'allow_countries' => '["DE"]',
		];
		$rStored[$rCheckboxes[0]] = 1;
		$rArguments = ['user_agent' => 'Agent <x>', 'proxy' => 'http://proxy.example', 'cookie' => null, 'headers' => 'X-A: 1'];

		$rData = $this->keep('keepSettings', ['server_name' => 'Panel'], $rStored, $rArguments, []);

		$this->assertEquals([
			'server_name' => 'Panel', 'search_items' => 30, 'user_agent' => 'Agent <x>', 'http_proxy' => 'http://proxy.example', 'cookie' => null, 'headers' => 'X-A: 1',
			'allowed_stb_types' => ['MAG250'], 'allowed_stb_types_for_local_recording' => [], 'maxmind_editions' => ['GeoLite2-City'], 'shared_mount_prefixes' => ['/mnt/share'], 'allow_countries' => ['DE'],
			'responsive_tables' => 1, $rCheckboxes[0] => 1, 'submit_settings' => 1,
		], $rData, 'the switches all posted as stored, so the save writes them unchanged');
	}

	public function testASettingsSwitchSentAsZeroIsOffAndAListSentEmptyIsNoneSelected(): void {
		$rCheckboxes = SettingsService::checkboxes();
		$rStored = array_fill_keys($rCheckboxes, 1) + ['search_items' => 30, 'disable_table_responsive' => 1, 'allow_countries' => '["DE"]'];

		$rData = $this->keep('keepSettings', [$rCheckboxes[0] => '0', $rCheckboxes[1] => '', 'responsive_tables' => '1', 'allow_countries' => ''], $rStored, [], []);

		$this->assertArrayNotHasKey($rCheckboxes[0], $rData);
		$this->assertArrayNotHasKey($rCheckboxes[1], $rData);
		$this->assertArrayNotHasKey('allow_countries', $rData, 'none selected: the service stores the form\'s ["ALL"]');
		$this->assertSame([1, 1, 1], [$rData['responsive_tables'], $rData[$rCheckboxes[2]], $rData['submit_settings']]);
	}

	public function testAGenrePostedWithoutItsBouquetsKeepsThem(): void {
		$rGenres = [['genre_id' => 4, 'type' => 1, 'bouquets' => '[2,3]'], ['genre_id' => 4, 'type' => 2, 'bouquets' => '[1]']];

		$rData = $this->keep('keepSettings', ['genre_4' => '7', 'genretv_4' => '8', 'bouquettv_4' => ''], ['search_items' => 30], [], $rGenres);

		$this->assertSame([[2, 3], []], [$rData['bouquet_4'], $rData['bouquettv_4']]);
	}
}

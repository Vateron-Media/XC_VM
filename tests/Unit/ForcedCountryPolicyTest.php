<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Line\ForcedCountryPolicy;

final class ForcedCountryPolicyTest extends TestCase {
	public function testPackageCountryIsAuthoritative(): void {
		$this->assertSame('DE', ForcedCountryPolicy::resolve(['forced_country' => 'DE'], [], ['forced_country' => 'US']));
	}

	public function testDelegatedConcreteCountryAndBlankAreAccepted(): void {
		$rReseller = ['can_set_forced_country' => 1];
		$this->assertSame('US', ForcedCountryPolicy::resolve([], $rReseller, ['forced_country' => 'us']));
		$this->assertNull(ForcedCountryPolicy::resolve([], $rReseller, ['forced_country' => '']));
	}

	#[DataProvider('refusedValues')]
	public function testSpecialUnknownAndMalformedCountriesAreRejected(string $rValue): void {
		$this->expectException(InvalidArgumentException::class);
		ForcedCountryPolicy::resolve([], ['can_set_forced_country' => 1], ['forced_country' => $rValue]);
	}

	public static function refusedValues(): array {
		return [['ALL'], ['A1'], ['ZZ'], ['EU'], ['USA']];
	}

	public function testSelectionNeedsDelegation(): void {
		$this->expectException(InvalidArgumentException::class);
		ForcedCountryPolicy::resolve([], ['can_set_forced_country' => 0], ['forced_country' => 'US']);
	}
}

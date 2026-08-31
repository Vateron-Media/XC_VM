<?php

use XcVm\Core\Enum\ClientFilter;
use PHPUnit\Framework\TestCase;

final class ClientFilterEnumTest extends TestCase {

    public function testBackingValuesMatchLegacyKeys(): void {
        $this->assertSame('LB_TOKEN_INVALID', ClientFilter::LbTokenInvalid->value);
        $this->assertSame('AUTH_FAILED', ClientFilter::AuthFailed->value);
        $this->assertSame('IP_MISMATCH', ClientFilter::IpMismatch->value);
    }

    public function testLabel(): void {
        $this->assertSame('Token Failure', ClientFilter::LbTokenInvalid->label());
        $this->assertSame('Authentication Failed', ClientFilter::AuthFailed->label());
        $this->assertSame('Proxy / VPN Detected', ClientFilter::ProxyDetect->label());
    }

    public function testLabelForKnownKey(): void {
        $this->assertSame('Authentication Failed', ClientFilter::labelFor('AUTH_FAILED'));
        $this->assertSame('IP Banned', ClientFilter::labelFor('IP_BAN'));
    }

    public function testLabelForUnknownKeyReturnsRawKey(): void {
        $this->assertSame('SOMETHING_ELSE', ClientFilter::labelFor('SOMETHING_ELSE'));
        $this->assertSame('', ClientFilter::labelFor(''));
    }

    public function testOptionsCoverAllCases(): void {
        $options = ClientFilter::options();
        $this->assertCount(count(ClientFilter::cases()), $options);
        $this->assertSame('Token Failure', $options['LB_TOKEN_INVALID']);
    }
}

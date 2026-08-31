<?php

use XcVm\Core\Enum\ResellerAction;
use PHPUnit\Framework\TestCase;

final class ResellerActionEnumTest extends TestCase {

    public function testBackingValuesMatchLegacyKeys(): void {
        $this->assertSame('new', ResellerAction::New->value);
        $this->assertSame('send_event', ResellerAction::SendEvent->value);
        $this->assertSame('adjust_credits', ResellerAction::AdjustCredits->value);
    }

    public function testLabel(): void {
        $this->assertSame('Create', ResellerAction::New->label());
        $this->assertSame('MAG Event', ResellerAction::SendEvent->label());
        $this->assertSame('Adjust Credits', ResellerAction::AdjustCredits->label());
    }

    public function testOptionsMatchLegacyMap(): void {
        $options = ResellerAction::options();
        $this->assertCount(9, $options);
        $this->assertSame('Create', $options['new']);
        $this->assertSame('MAG Event', $options['send_event']);
        // Ordering preserved: first key is 'new'.
        $this->assertSame('new', array_key_first($options));
    }
}

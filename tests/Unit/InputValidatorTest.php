<?php

use PHPUnit\Framework\TestCase;

final class InputValidatorTest extends TestCase {
	public function testValidateReturnsFalseWhenRequiredFieldsMissing() {
		$this->assertFalse(InputValidator::validate('processProvider', array()));
	}

	public function testValidateReturnsTrueForMinimalProviderPayload() {
		$payload = array(
			'ip' => '127.0.0.1',
			'port' => 8080,
			'username' => 'user',
			'password' => 'pass',
			'name' => 'provider',
		);

		$this->assertTrue(InputValidator::validate('processProvider', $payload));
	}

	public function testValidateOrFailUsesStatusConstant() {
		if (!defined('STATUS_INVALID_INPUT')) {
			define('STATUS_INVALID_INPUT', 400);
		}

		$result = InputValidator::validateOrFail('processProvider', array('ip' => '127.0.0.1'));
		$this->assertSame(STATUS_INVALID_INPUT, $result['status']);
	}
}

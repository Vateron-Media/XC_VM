<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserRepository;

/**
 * Unit tests for UserRepository::ispChanged() — the ISP-persist gate extracted
 * from getStreamingUserInfo()/getUserInfo(). It guards the block that writes a
 * looked-up ISP back to the line. A GeoIP lookup miss (empty con_isp_name) must
 * NOT persist: the old code did, which read the undefined isp_asn key (the
 * "Undefined array key isp_asn" warning) and stored a null isp_desc/as_number.
 */
final class UserRepositoryTest extends TestCase {

	/** Invoke a private static UserRepository method via reflection. */
	private function call(string $rMethod, ...$rArgs) {
		$rM = new ReflectionMethod(UserRepository::class, $rMethod);
		$rM->setAccessible(true);
		return $rM->invoke(null, ...$rArgs);
	}

	public function testPersistsWhenIspDetectedAndChanged(): void {
		$this->assertTrue(UserRepository::ispChanged('Comcast', 0, 'Verizon'));
	}

	public function testFirstDetectionAgainstNullDescPersists(): void {
		// No ISP stored yet -> a detected ISP counts as changed.
		$this->assertTrue(UserRepository::ispChanged('Comcast', 0, null));
	}

	// ── the bug: a GeoIP miss must not reach the persist branch ──

	public function testGeoipMissDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged(null, 0, 'Verizon'), 'null con_isp_name');
		$this->assertFalse(UserRepository::ispChanged('', 0, 'Verizon'), 'empty con_isp_name');
	}

	// ── no write when nothing actually changed ──

	public function testUnchangedIspDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged('Comcast', 0, 'Comcast'));
		$this->assertFalse(UserRepository::ispChanged('Comcast', 0, 'comcast'), 'case-insensitive');
		$this->assertFalse(UserRepository::ispChanged('COMCAST', 0, 'comcast'));
	}

	// ── a line already flagged in violation is left alone ──

	public function testViolationDoesNotPersist(): void {
		$this->assertFalse(UserRepository::ispChanged('Comcast', 1, 'Verizon'));
	}

	// ── aggregateBouquetIds — locks the streams→channel_ids / channels→live_ids mapping ──

	public function testAggregateBouquetIdsMapsAndDedupes(): void {
		$rBouquets = array(
			1 => array('streams' => array(10, 11), 'series' => array(20), 'channels' => array(30), 'movies' => array(40), 'radios' => array(50)),
			2 => array('streams' => array(11, 12)), // 11 shared across bouquets
		);
		$rOut = $this->call('aggregateBouquetIds', array(1, 2), $rBouquets);
		$this->assertSame(array(10, 11, 12), array_values($rOut['channel_ids']), 'streams -> channel_ids, unique');
		$this->assertSame(array(20), array_values($rOut['series_ids']));
		$this->assertSame(array(30), array_values($rOut['live_ids']), 'channels -> live_ids');
		$this->assertSame(array(40), array_values($rOut['vod_ids']), 'movies -> vod_ids');
		$this->assertSame(array(50), array_values($rOut['radio_ids']));
	}

	public function testAggregateBouquetIdsIgnoresMissingKeys(): void {
		$rOut = $this->call('aggregateBouquetIds', array(1), array(1 => array('streams' => array(7))));
		$this->assertSame(array(7), array_values($rOut['channel_ids']));
		$this->assertSame(array(), $rOut['series_ids']);
		$this->assertSame(array(), $rOut['radio_ids']);
	}

	// ── resolveCategoryIds ──

	public function testResolveCategoryIdsUniqueAcrossBouquets(): void {
		$rMap = array(1 => array(100, 101), 2 => array(101, 102));
		$this->assertSame(array(100, 101, 102), $this->call('resolveCategoryIds', array(1, 2), $rMap));
	}

	// ── decodeUserFields ──

	public function testDecodeUserFieldsNormalises(): void {
		$rOut = $this->call('decodeUserFields', array(
			'allowed_ips' => '["1.2.3.4"," 5.6.7.8 "]',
			'allowed_ua' => 'null',
			'bouquet' => '[1,2,3]',
			'allowed_outputs' => '["1","2"]',
		));
		$this->assertSame(array(1, 2, 3), $rOut['bouquet']);
		$this->assertSame(array('1.2.3.4', '5.6.7.8'), array_values($rOut['allowed_ips']), 'trimmed');
		$this->assertSame(array(), $rOut['allowed_ua'], 'non-array json -> []');
		$this->assertSame(array(1, 2), $rOut['allowed_outputs'], 'intval-mapped');
	}

	// ── verifyCachedCredentials ──

	public function testVerifyTokenLookup(): void {
		$rToken = str_repeat('a', 32);
		$this->assertTrue($this->call('verifyCachedCredentials', array('access_token' => $rToken), null, $rToken, null));
		$this->assertFalse($this->call('verifyCachedCredentials', array('access_token' => 'other'), null, $rToken, null));
	}

	public function testVerifyCredentialLookup(): void {
		$rRow = array('username' => 'u', 'password' => 'p');
		$this->assertTrue($this->call('verifyCachedCredentials', $rRow, null, 'u', 'p'));
		$this->assertFalse($this->call('verifyCachedCredentials', $rRow, null, 'u', 'wrong'));
	}

	public function testVerifyIdLookupSkipsCheck(): void {
		// An id-based lookup carries no username/password to re-verify.
		$this->assertTrue($this->call('verifyCachedCredentials', array(), 5, null, null));
	}
}

<?php

use PHPUnit\Framework\TestCase;

/**
 * The stream scripts send `Access-Control-Allow-Origin: *` and then hand the
 * body to an internal nginx location (X-Accel-Redirect). nginx does not carry
 * that header across the hand-off, so the location has to add it: without it
 * the web player, on MAIN's origin, could not read the segments of a stream
 * that runs on a load balancer and never started playing.
 */
#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
final class NginxHandOffCorsTest extends TestCase {
	public function testEveryInternalLocationAddsTheCorsHeader(): void {
		$rRoot = dirname(__DIR__, 2);
		foreach (['src/bin/nginx/conf/nginx.conf', 'lb_configs/nginx.conf'] as $rFile) {
			preg_match_all('/^\s*location [^{]+\{[^}]*^\s*internal;[^}]*\}/m', (string) file_get_contents($rRoot . '/' . $rFile), $rLocations);
			$this->assertCount(4, $rLocations[0], $rFile . ': /xc_hls/ and the three daemon hand-offs');
			foreach ($rLocations[0] as $rLocation) {
				$this->assertMatchesRegularExpression('/^\s*add_header Access-Control-Allow-Origin \* always;$/m', $rLocation, $rFile . ': ' . strtok(trim($rLocation), '{'));
			}
		}
	}
}

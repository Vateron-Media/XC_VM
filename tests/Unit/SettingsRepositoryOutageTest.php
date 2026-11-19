<?php

declare(strict_types=1);

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\ReplicaBoot;
use XcVm\Core\Config\SettingsRepository;
use XcVm\Core\Database\DatabaseHandler;

/**
 * @covers \XcVm\Core\Config\SettingsRepository::getAll
 *
 * A daemon re-reads the settings every minute (DaemonTrait::refreshOrBreak),
 * and so does cron:servers. When the database did not answer, the read
 * returned an empty row decoded as the panel's settings and cached it: every
 * switch read as off for the caller (cron:servers ends the daemons whose
 * setting is off, fanout_sync writes the fanout daemon's tuning from them) and
 * for every reader of the cache. A read that failed now answers the cache,
 * however old, and leaves it as it was.
 */
final class SettingsRepositoryOutageTest extends TestCase {
	/** @var mixed */
	private $rDb;

	/** @var mixed the settings cache as it was found (false: none) */
	private $rCache;

	/** When that cache was written: put back with it, so an expired one stays expired. */
	private int $rCachedAt = 0;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm_settings_cache_test/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		ReplicaBoot::reset();
		$this->rCache = FileCache::getCache('settings');
		$this->rCachedAt = (int) @filemtime(FileCache::defaultPath() . 'settings');
		FileCache::delCache('settings');
		$this->rDb = $GLOBALS['db'] ?? null;

		// MariaDB is gone: the statement fails, and there is no row to fetch.
		$GLOBALS['db'] = new class extends DatabaseHandler {
			public int $rAsked = 0;

			public function __construct() {
				$this->dbh = false;
			}

			public function query($query, ...$args): bool {
				$this->rAsked++;
				return false;
			}

			public function get_row() {
				return false;
			}
		};
	}

	protected function tearDown(): void {
		if ($this->rCache !== false) {
			FileCache::setCache('settings', $this->rCache);
			touch(FileCache::defaultPath() . 'settings', $this->rCachedAt);
			clearstatcache();
		} else {
			FileCache::delCache('settings');
		}
		unset($GLOBALS['db']);
		if ($this->rDb !== null) {
			$GLOBALS['db'] = $this->rDb;
		}
	}

	public function testAFailedReadAnswersTheCacheAndLeavesItAlone(): void {
		$rKnown = ['server_name' => 'panel', 'fanout_enabled' => 1, 'on_demand_instant_off' => 1, 'api_ips' => ['10.0.0.1']];
		FileCache::setCache('settings', $rKnown);

		$this->assertSame($rKnown, SettingsRepository::getAll(true), 'forced past the cache, and the database did not answer');
		$this->assertSame(1, $GLOBALS['db']->rAsked, 'the database was asked');
		$this->assertSame($rKnown, FileCache::getCache('settings'), 'not replaced by an empty row');
	}

	/** A daemon's refresh asks for null: it then keeps the settings it holds (DaemonTrait::refreshSettings()), cache or no cache. */
	public function testAFailedReadAnswersNullToACallerThatAsksForIt(): void {
		$this->assertNull(SettingsRepository::getAll(true, true));
		$this->assertFalse(FileCache::getCache('settings'));

		$rKnown = ['server_name' => 'panel', 'fanout_enabled' => 1];
		FileCache::setCache('settings', $rKnown);
		$this->assertNull(SettingsRepository::getAll(true, true), 'the caller holds newer settings than a cache however old');
		$this->assertSame($rKnown, FileCache::getCache('settings'));
		$this->assertSame($rKnown, SettingsRepository::getAll(false, true), 'not forced: the cache, as ever');
	}

	public function testAFailedReadWithoutACacheWritesNone(): void {
		$rOut = SettingsRepository::getAll(true);
		$this->assertSame([], $rOut['api_ips'], 'the shape every caller expects');
		$this->assertArrayNotHasKey('server_name', $rOut);
		$this->assertFalse(FileCache::getCache('settings'), 'an empty row is not the panel\'s settings: nothing is cached');
	}
}

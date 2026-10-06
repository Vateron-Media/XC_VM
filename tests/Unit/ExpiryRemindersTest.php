<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Alert\ExpiryReminders;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Expiry reminders (Domain\Alert\ExpiryReminders): the threshold a line is
 * due, once per expiry date and threshold, enabled lines that are not trials,
 * and the MAG message.
 */
final class ExpiryRemindersTest extends TestCase {
	private const NOW = 1800000000;

	private const DAY = 86400;

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['lines', 'line_reminders', 'users', 'mag_devices', 'mag_events', 'alert_channels', 'alert_log'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		SettingsManager::set([]);
		DatabaseFactory::reset();
	}

	public function testTheDaysAndTheThresholdALineIsDue(): void {
		$this->assertSame([7, 3, 1], ExpiryReminders::days(' 1, 3,7,3, 0, 99,x'));
		$this->assertSame([], ExpiryReminders::days(''));
		$rDays = [7, 3, 1];
		$this->assertSame(7, ExpiryReminders::bucket(self::NOW + 6 * self::DAY, self::NOW, $rDays));
		$this->assertSame(3, ExpiryReminders::bucket(self::NOW + 2 * self::DAY + 3600, self::NOW, $rDays), 'only the 3-day reminder, not the 7 too');
		$this->assertSame(1, ExpiryReminders::bucket(self::NOW + 3600, self::NOW, $rDays));
		$this->assertNull(ExpiryReminders::bucket(self::NOW + 8 * self::DAY, self::NOW, $rDays), 'too far');
		$this->assertNull(ExpiryReminders::bucket(self::NOW - 1, self::NOW, $rDays), 'expired');
	}

	public function testEachLineIsRemindedOncePerExpiryAndThreshold(): void {
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `member_id`, `exp_date`, `enabled`, `admin_enabled`, `is_trial`, `is_mag`) VALUES
			(1, 'soon', 5, " . (self::NOW + 2 * self::DAY + 60) . ", 1, 1, 0, 0),
			(2, 'week', 5, " . (self::NOW + 6 * self::DAY) . ", 1, 1, 0, 0),
			(3, 'trial', 5, " . (self::NOW + self::DAY) . ", 1, 1, 1, 0),
			(4, 'off', 5, " . (self::NOW + self::DAY) . ", 0, 1, 0, 0),
			(5, 'gone', 5, " . (self::NOW - 60) . ", 1, 1, 0, 0),
			(6, 'box', 6, " . (self::NOW + 3600) . ", 1, 1, 0, 1)");
		$this->rDb->exec("INSERT INTO `mag_devices` (`mag_id`, `user_id`) VALUES (40, 6)");
		SettingsManager::set(['reminders_days' => '7,3,1', 'reminders_mag' => 1, 'reminders_message' => 'Ends {date} ({line})', 'server_name' => 'P']);

		$this->assertSame(['lines' => 3, 'emails' => 0, 'mag' => 1, 'telegram' => 0, 'webhooks' => 0], ExpiryReminders::run(self::NOW));
		$this->rDb->query('SELECT `line_id`, `days` FROM `line_reminders` ORDER BY `line_id`;');
		$this->assertSame([['1', '3'], ['2', '7'], ['6', '1']], array_map(static fn(array $r): array => [(string) $r['line_id'], (string) $r['days']], $this->rDb->get_raw_rows()));
		$this->rDb->query('SELECT `mag_device_id`, `event`, `msg`, `need_confirm` FROM `mag_events`;');
		$this->assertSame(['mag_device_id' => '40', 'event' => 'send_msg', 'msg' => 'Ends ' . date('Y-m-d', self::NOW + 3600) . ' (box)', 'need_confirm' => '1'], array_map('strval', $this->rDb->get_raw_row()));

		$this->assertSame(0, ExpiryReminders::run(self::NOW + 3600)['lines'], 'the same day again: nothing');
		$this->assertSame(1, ExpiryReminders::run(self::NOW + 4 * self::DAY)['lines'], 'the week line reaches its 3-day reminder');

		$this->rDb->exec('UPDATE `lines` SET `exp_date` = ' . (self::NOW + 4 * self::DAY + 2 * self::DAY) . ' WHERE `id` = 1');
		$this->assertSame(1, ExpiryReminders::run(self::NOW + 4 * self::DAY + 60)['lines'], 'renewed: reminded again at its new expiry');
		$this->rDb->query("SELECT `kind`, `title` FROM `alert_log` WHERE `rule` = 'reminders' ORDER BY `id` LIMIT 1;");
		$this->assertSame('[P] Expiry reminders: 3 line(s)', $this->rDb->get_raw_row()['title']);
	}

	public function testWithoutAnEmailChannelNothingIsMailed(): void {
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `member_id`, `exp_date`, `enabled`, `admin_enabled`, `is_trial`, `is_mag`) VALUES (1, 'soon', 5, " . (self::NOW + self::DAY) . ", 1, 1, 0, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `email`) VALUES (5, 'seller', 'seller@example.com')");
		SettingsManager::set(['reminders_days' => '1', 'reminders_email' => 1]);
		$this->assertSame(0, ExpiryReminders::run(self::NOW)['emails']);
		$this->rDb->query("SELECT `results` FROM `alert_log` WHERE `rule` = 'reminders';");
		$this->assertSame(['e-mail' => 'no enabled e-mail channel'], json_decode($this->rDb->get_raw_row()['results'], true));
	}
}

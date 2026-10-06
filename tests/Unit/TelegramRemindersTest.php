<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Alert\ExpiryReminders;
use XcVm\Domain\Alert\TelegramLinks;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Expiry reminders on Telegram (Domain\Alert\TelegramLinks): a subscriber
 * who sent /start to the bot from the username on their line gets the
 * reminder there; /stop, a group chat or another username gets nothing. Run
 * against a Bot API of the test's own (PHP's built-in server).
 */
final class TelegramRemindersTest extends TestCase {
	private const NOW = 1800000000;

	private const TOKEN = '123456:ABCDEFGHIJKLMNOPQRSTUVWX';

	private TestDb $rDb;

	private string $rDir;

	/** @var resource|null */
	private $rServer = null;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-telegram-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', $this->rDir . 'cache/');
		}
		@mkdir(CACHE_TMP_PATH, 0777, true);
		@unlink(CACHE_TMP_PATH . 'telegram_offset_1');
		$this->rDb = new TestDb();
		foreach (['alert_channels', 'telegram_chats', 'lines', 'line_reminders', 'alert_log', 'mag_devices', 'mag_events', 'users'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		DatabaseFactory::set($this->rDb);
		$this->rDb->query('INSERT INTO `alert_channels` (`id`, `type`, `name`, `enabled`, `config`) VALUES (1, ?, ?, 1, ?);', 'telegram', 'Bot', json_encode(['bot_token' => self::TOKEN, 'chat_id' => '1']));
		SettingsManager::set(['reminders_telegram' => 1, 'reminders_days' => '3', 'reminders_message' => '{line} ends {date}', 'server_name' => 'P']);

		// The fake Bot API: getUpdates answers updates.json, sendMessage is recorded.
		file_put_contents($this->rDir . 'router.php', '<?php $d = ' . var_export($this->rDir, true) . '; if (str_ends_with($_SERVER["REQUEST_URI"], "/getUpdates")) { echo json_encode(["ok" => true, "result" => json_decode((string) @file_get_contents($d . "updates.json"), true) ?: []]); return; } file_put_contents($d . "sent.jsonl", json_encode(["path" => $_SERVER["REQUEST_URI"]] + $_POST) . "\n", FILE_APPEND); echo json_encode(["ok" => true, "result" => ["message_id" => 1]]);');
		file_put_contents($this->rDir . 'start.php', '<?php $s = stream_socket_server("tcp://127.0.0.1:0"); $p = (int) substr(strrchr(stream_socket_get_name($s, false), ":"), 1); fclose($s); echo $p, "\n"; flush(); pcntl_exec(PHP_BINARY, ["-S", "127.0.0.1:" . $p, ' . var_export($this->rDir . 'router.php', true) . ']);');
		$this->rServer = proc_open([PHP_BINARY, $this->rDir . 'start.php'], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $rPipes);
		$rPort = (int) trim((string) fgets($rPipes[1]));
		TelegramLinks::useApi('http://127.0.0.1:' . $rPort);
		for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $rPort); $i++) {
			usleep(100000);
		}
	}

	protected function tearDown(): void {
		TelegramLinks::useApi(null);
		if ($this->rServer !== null) {
			proc_terminate($this->rServer);
			proc_close($this->rServer);
		}
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<array<string, string>> what the bot sent */
	private function sent(): array {
		return array_map(static fn(string $rLine): array => json_decode($rLine, true), array_filter(explode("\n", (string) @file_get_contents($this->rDir . 'sent.jsonl'))));
	}

	public function testAUsernameAsKept(): void {
		$this->assertSame('alice_tv', TelegramLinks::username(' @Alice_TV '));
		$this->assertNull(TelegramLinks::username('ab'), 'too short');
		$this->assertNull(TelegramLinks::username('+491234567'), 'a phone number');
		$this->assertNull(TelegramLinks::username('1alice'));
		$this->assertNull(TelegramLinks::username(null));
	}

	public function testStartLinksTheChatAndTheReminderGoesThere(): void {
		file_put_contents($this->rDir . 'updates.json', json_encode([
			['update_id' => 10, 'message' => ['chat' => ['id' => 555, 'type' => 'private'], 'from' => ['username' => 'Alice_TV'], 'text' => '/start']],
			['update_id' => 11, 'message' => ['chat' => ['id' => -777, 'type' => 'group'], 'from' => ['username' => 'bob_tv'], 'text' => '/start']],
			['update_id' => 12, 'message' => ['chat' => ['id' => 888, 'type' => 'private'], 'from' => ['username' => 'carol99'], 'text' => '/start']],
			['update_id' => 13, 'message' => ['chat' => ['id' => 888, 'type' => 'private'], 'from' => ['username' => 'carol99'], 'text' => '/stop']],
			['update_id' => 14, 'message' => ['chat' => ['id' => 999, 'type' => 'private'], 'from' => ['first_name' => 'No username'], 'text' => '/start']],
		]));
		$rBot = ExpiryReminders::bot();
		$this->assertSame(5, TelegramLinks::poll($rBot, self::NOW));
		$this->assertSame(['alice_tv' => '555'], TelegramLinks::chats(['alice_tv', 'bob_tv', 'carol99']));
		$this->assertSame('15', file_get_contents(CACHE_TMP_PATH . 'telegram_offset_1'), 'the next read starts after what was read');
		$rReplies = array_map(static fn(array $rSent): array => [$rSent['chat_id'], $rSent['text']], $this->sent());
		$this->assertSame([['555', TelegramLinks::START_REPLY], ['888', TelegramLinks::START_REPLY], ['888', TelegramLinks::STOP_REPLY]], $rReplies, 'the same answer whoever asks');

		@unlink($this->rDir . 'sent.jsonl');
		$this->rDb->exec("INSERT INTO `lines` (`id`, `username`, `member_id`, `exp_date`, `enabled`, `admin_enabled`, `is_trial`, `is_mag`, `telegram`) VALUES
			(1, 'alice', 5, " . (self::NOW + 2 * 86400) . ", 1, 1, 0, 0, '@Alice_TV'),
			(2, 'carol', 5, " . (self::NOW + 2 * 86400) . ", 1, 1, 0, 0, 'carol99'),
			(3, 'nobody', 5, " . (self::NOW + 2 * 86400) . ", 1, 1, 0, 0, NULL)");
		$this->assertSame(1, ExpiryReminders::run(self::NOW)['telegram']);
		$this->assertSame([['/bot' . self::TOKEN . '/sendMessage', '555', 'alice ends ' . date('Y-m-d', self::NOW + 2 * 86400)]], array_map(static fn(array $rSent): array => [$rSent['path'], $rSent['chat_id'], $rSent['text']], $this->sent()));
	}

	public function testWithoutTheChosenChannelNothingIsRead(): void {
		SettingsManager::set(['reminders_telegram' => 2]);
		$this->assertNull(ExpiryReminders::bot(), 'no such channel');
		$this->rDb->exec('UPDATE `alert_channels` SET `enabled` = 0');
		SettingsManager::set(['reminders_telegram' => 1]);
		$this->assertNull(ExpiryReminders::bot(), 'disabled');
	}
}

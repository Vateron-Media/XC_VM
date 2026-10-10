<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Alert\AlertChannels;
use XcVm\Domain\Alert\Alerts;
use XcVm\Domain\Alert\SmtpMailer;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Alerts (Domain\Alert): the per-subject state machine (minutes, once,
 * resolved, the quiet period), one message per rule and kind, the channels'
 * settings and secrets, a signed webhook, and the SMTP client against a
 * scripted server.
 */
final class AlertsTest extends TestCase {
	private const NOW = 1800000000;

	private const RULES = ['server_down' => ['enabled' => 1, 'minutes' => 2], 'cpu' => ['enabled' => 1, 'minutes' => 0]];

	/** @var list<resource> */
	private array $rProcesses = [];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-alerts-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
	}

	protected function tearDown(): void {
		foreach ($this->rProcesses as $rProcess) {
			proc_terminate($rProcess);
			proc_close($rProcess);
		}
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** Run step() minute by minute; return each minute's event kinds as "rule:kind:subject". */
	private static function minutes(array $rTimeline, array $rRules = self::RULES): array {
		$rState = [];
		$rOut = [];
		foreach ($rTimeline as $rMinute => $rConditions) {
			[$rState, $rEvents] = Alerts::step($rState, $rConditions, $rRules, self::NOW + $rMinute * 60);
			$rOut[$rMinute] = array_map(static fn(array $rEvent): string => $rEvent['rule'] . ':' . $rEvent['kind'] . ':' . $rEvent['subject'], $rEvents);
		}
		return $rOut;
	}

	public function testATroubleFiresOnceAfterItsMinutesAndIsResolved(): void {
		$rDown = ['server_down' => ['2' => 'LB-2']];
		$this->assertSame([0 => [], 1 => [], 2 => ['server_down:fired:2'], 3 => [], 4 => ['server_down:resolved:2'], 5 => []], self::minutes([$rDown, $rDown, $rDown, $rDown, [], []]));
	}

	public function testATroubleShorterThanItsMinutesSaysNothing(): void {
		$rDown = ['server_down' => ['2' => 'LB-2']];
		$this->assertSame([[], [], [], []], array_values(self::minutes([$rDown, [], $rDown, []])));
	}

	public function testAFlappingSubjectIsAnnouncedOnceInTheQuietPeriod(): void {
		$rHot = ['cpu' => ['2' => 'LB-2: 95 %']];
		$rOut = self::minutes([$rHot, [], $rHot, [], 15 => [], 16 => $rHot]);
		$this->assertSame(['cpu:fired:2'], $rOut[0]);
		$this->assertSame(['cpu:resolved:2'], $rOut[1]);
		$this->assertSame(['cpu:suppressed:2'], $rOut[2], 'fired again within 15 minutes');
		$this->assertSame([], $rOut[3], 'nothing to resolve: the second firing was not sent');
		$this->assertSame(['cpu:fired:2'], $rOut[16], 'after the quiet period');
	}

	/**
	 * The quiet period holds an announcement back, it does not drop it: a
	 * trouble that begins within it and still holds when it ends is announced
	 * then (and resolved later, as any other). Held back, it was never looked
	 * at again, and an outage of hours that began within fifteen minutes of
	 * the last one stayed silent.
	 */
	public function testATroubleThatOutlastsTheQuietPeriodIsAnnounced(): void {
		$rHot = ['cpu' => ['2' => 'LB-2: 95 %']];
		$rTimeline = [0 => $rHot, 1 => []];
		for ($i = 2; $i <= 17; $i++) {
			$rTimeline[$i] = $rHot;
		}
		$rTimeline[18] = [];
		$rOut = self::minutes($rTimeline);
		$this->assertSame(['cpu:suppressed:2'], $rOut[2], 'held back, said once');
		for ($i = 3; $i <= 14; $i++) {
			$this->assertSame([], $rOut[$i], 'minute ' . $i);
		}
		$this->assertSame(['cpu:fired:2'], $rOut[15], 'the quiet period over, and still in trouble');
		$this->assertSame([], $rOut[16]);
		$this->assertSame(['cpu:resolved:2'], $rOut[18], 'announced, so its end is too');
	}

	public function testADisabledRuleEndsItsTroublesSilently(): void {
		$rDown = ['server_down' => ['2' => 'LB-2']];
		$rState = [];
		foreach ([0, 1, 2] as $rMinute) {
			[$rState] = Alerts::step($rState, $rDown, self::RULES, self::NOW + $rMinute * 60);
		}
		[$rState, $rEvents] = Alerts::step($rState, $rDown, ['server_down' => ['enabled' => 0, 'minutes' => 2]], self::NOW + 180);
		$this->assertSame([], $rEvents);
		$this->assertSame(0, $rState['server_down|2']['active']);
		[$rState] = Alerts::step($rState, [], self::RULES, self::NOW + 2 * 86400);
		$this->assertSame([], $rState, 'forgotten a day after');
	}

	public function testOneMessagePerRuleAndKindNamingAtMostTwenty(): void {
		$rEvents = [];
		for ($i = 1; $i <= 25; $i++) {
			$rEvents[] = ['rule' => 'stream_down', 'kind' => 'fired', 'subject' => $i . ':2', 'label' => 'Channel ' . $i . ' (LB-2)'];
		}
		$rEvents[] = ['rule' => 'cpu', 'kind' => 'resolved', 'subject' => '3', 'label' => 'LB-3: 95 %'];
		$rEvents[] = ['rule' => 'cpu', 'kind' => 'suppressed', 'subject' => '4', 'label' => 'LB-4: 96 %'];
		$rMessages = Alerts::messages($rEvents, 'My Panel');
		$this->assertCount(2, $rMessages);
		$this->assertSame('[My Panel] A stream is down (25)', $rMessages[0]['title']);
		$this->assertCount(21, explode("\n", $rMessages[0]['text']));
		$this->assertStringEndsWith('... and 5 more', $rMessages[0]['text']);
		$this->assertCount(25, $rMessages[0]['items'], 'the webhook gets them all');
		$this->assertSame(['[My Panel] Resolved: CPU use is high (1)', '- LB-3: 95 %'], [$rMessages[1]['title'], $rMessages[1]['text']]);
	}

	public function testAChannelsSettingsAreCheckedAndItsSecretsKept(): void {
		$this->assertNull(AlertChannels::invalid('telegram', ['bot_token' => '123456:ABCDEFGHIJKLMNOPQRSTUVWX', 'chat_id' => '-100123']));
		$this->assertSame('bot_token', AlertChannels::invalid('telegram', ['bot_token' => 'nope', 'chat_id' => '1']));
		$this->assertSame('url', AlertChannels::invalid('webhook', ['url' => 'file:///etc/passwd']));
		$this->assertSame('to', AlertChannels::invalid('email', ['host' => 'smtp.example.com', 'port' => '587', 'security' => 'starttls', 'from' => 'panel@example.com', 'to' => 'nobody']));
		$this->assertSame(['a@example.com', 'b@example.com'], AlertChannels::recipients("a@example.com, b@example.com;x\nc@"));

		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('alert_channels'));
		DatabaseFactory::set($rDb);
		$rSaved = AlertChannels::save(['type' => 'webhook', 'name' => 'Ops', 'enabled' => '1', 'url' => 'https://hooks.example.com/x', 'secret' => 's3cret']);
		$this->assertTrue($rSaved['result']);
		$rPage = AlertChannels::forPage(AlertChannels::all()[0]);
		$this->assertSame('********', $rPage['config']['secret']);
		$this->assertTrue(AlertChannels::save(['id' => $rSaved['id'], 'type' => 'webhook', 'name' => 'Ops 2', 'url' => 'https://hooks.example.com/y', 'secret' => '********'])['result']);
		$rStored = AlertChannels::all()[0];
		$this->assertSame(['Ops 2', 0, 's3cret'], [$rStored['name'], $rStored['enabled'], $rStored['config']['secret']], 'the masked secret keeps the stored one');
		$this->assertFalse(AlertChannels::save(['id' => $rSaved['id'], 'type' => 'telegram', 'name' => 'x'])['result'], 'a channel keeps its type');
	}

	/** Start a PHP script server-side; returns the port it listens on (it prints it). */
	private function serve(array $rCommand): int {
		$rProcess = proc_open($rCommand, [1 => ['pipe', 'w'], 2 => ['file', $this->rDir . 'server.err', 'a']], $rPipes);
		$this->rProcesses[] = $rProcess;
		$rLine = (string) fgets($rPipes[1]);
		$this->assertMatchesRegularExpression('/^\d+$/', trim($rLine), (string) @file_get_contents($this->rDir . 'server.err'));
		return (int) trim($rLine);
	}

	public function testAWebhookIsAJsonPostSignedWithItsSecret(): void {
		file_put_contents($this->rDir . 'router.php', '<?php file_put_contents(' . var_export($this->rDir . 'request', true) . ', json_encode(["sig" => $_SERVER["HTTP_X_XCVM_SIGNATURE"] ?? "", "event" => $_SERVER["HTTP_X_XCVM_EVENT"] ?? "", "body" => file_get_contents("php://input")])); http_response_code(204);');
		file_put_contents($this->rDir . 'start.php', '<?php $s = stream_socket_server("tcp://127.0.0.1:0"); $p = (int) substr(strrchr(stream_socket_get_name($s, false), ":"), 1); fclose($s); echo $p, "\n"; flush(); pcntl_exec(PHP_BINARY, ["-S", "127.0.0.1:" . $p, ' . var_export($this->rDir . 'router.php', true) . ']);');
		$rPort = $this->serve([PHP_BINARY, $this->rDir . 'start.php']);
		$rChannel = ['type' => 'webhook', 'config' => ['url' => 'http://127.0.0.1:' . $rPort . '/hook', 'secret' => 's3cret']];
		$rPayload = ['event' => 'alert', 'state' => 'fired', 'rule' => 'cpu', 'items' => [['subject' => '2', 'label' => 'LB-2']]];
		$rError = 'not tried';
		for ($i = 0; $i < 30 && $rError !== null; $i++) {
			usleep(100000);
			$rError = AlertChannels::send($rChannel, 'Title', 'Text', $rPayload);
		}
		$this->assertNull($rError);
		$rGot = json_decode((string) file_get_contents($this->rDir . 'request'), true);
		$this->assertSame($rPayload, json_decode($rGot['body'], true));
		$this->assertSame('sha256=' . hash_hmac('sha256', $rGot['body'], 's3cret'), $rGot['sig']);
		$this->assertSame('alert', $rGot['event']);
	}

	public function testTheMessageIsSafeSmtpData(): void {
		$rData = SmtpMailer::message('panel@example.com', ['ops@example.com'], "Disk\r\nBcc: evil@example.com é", ".hidden\nline\r\n..two", self::NOW);
		$this->assertStringContainsString("Subject: =?UTF-8?B?" . base64_encode('Disk Bcc: evil@example.com é') . "?=\r\n", $rData, 'no header injection, UTF-8 encoded');
		$this->assertStringNotContainsString("\r\nBcc:", $rData);
		$this->assertStringEndsWith("\r\n\r\n..hidden\r\nline\r\n...two\r\n", $rData, 'CRLF and leading dots doubled');
		$this->assertStringContainsString("Date: Fri, 15 Jan 2027 08:00:00 +0000\r\n", $rData);
	}

	public function testTheClientSpeaksSmtpWithLogin(): void {
		file_put_contents($this->rDir . 'smtp.php', <<<'PHP'
<?php
// A scripted SMTP server: one session, every line it reads written to argv[1]; RCPT to reject@ is refused.
$s = stream_socket_server('tcp://127.0.0.1:0');
echo (int) substr(strrchr(stream_socket_get_name($s, false), ':'), 1), "\n";
flush();
$c = stream_socket_accept($s, 10);
$log = fopen($argv[1], 'w');
fwrite($c, "220 fake ESMTP\r\n");
$data = false;
while (($l = fgets($c)) !== false) {
	fwrite($log, $l);
	if ($data) {
		if ($l === ".\r\n") { $data = false; fwrite($c, "250 queued\r\n"); }
		continue;
	}
	$cmd = strtoupper(substr(trim($l), 0, 4));
	$reply = match (true) {
		$cmd === 'EHLO' => "250-fake\r\n250 AUTH LOGIN PLAIN\r\n",
		$cmd === 'AUTH' => "334 VXNlcm5hbWU6\r\n",
		trim($l) === base64_encode('mailer') => "334 UGFzc3dvcmQ6\r\n",
		trim($l) === base64_encode('pa55') => "235 ok\r\n",
		str_starts_with($l, 'RCPT TO:<reject@') => "550 no such user\r\n",
		$cmd === 'DATA' => "354 go\r\n",
		$cmd === 'QUIT' => "221 bye\r\n",
		default => "250 ok\r\n",
	};
	if ($cmd === 'DATA') { $data = true; }
	fwrite($c, $reply);
	if ($cmd === 'QUIT') { break; }
}
PHP);
		$rConfig = ['host' => '127.0.0.1', 'security' => 'none', 'username' => 'mailer', 'password' => 'pa55', 'from' => 'panel@example.com'];
		$rPort = $this->serve([PHP_BINARY, $this->rDir . 'smtp.php', $this->rDir . 'session']);
		$this->assertNull(SmtpMailer::send($rConfig + ['port' => $rPort], ['ops@example.com', 'bad address'], 'Alert', "Line 1\n.dot"));
		$rSession = (string) file_get_contents($this->rDir . 'session');
		$this->assertMatchesRegularExpression('/^EHLO \S+\r\nAUTH LOGIN\r\nbWFpbGVy\r\ncGE1NQ==\r\nMAIL FROM:<panel@example\.com>\r\nRCPT TO:<ops@example\.com>\r\nDATA\r\n/', $rSession);
		$this->assertStringContainsString("\r\n\r\nLine 1\r\n..dot\r\n.\r\nQUIT\r\n", $rSession);

		$rPort = $this->serve([PHP_BINARY, $this->rDir . 'smtp.php', $this->rDir . 'session2']);
		$rError = SmtpMailer::send($rConfig + ['port' => $rPort], ['reject@example.com'], 'Alert', 'x');
		$this->assertSame('answer 550 no such user to RCPT TO:<reject@example.com>', $rError);
		$this->assertStringNotContainsString('pa55', (string) $rError);
		$this->assertSame('a sender and at least one recipient address are needed', SmtpMailer::send(['from' => 'x'] + $rConfig + ['port' => 1], ['ops@example.com'], 'A', 'B'));
	}
}

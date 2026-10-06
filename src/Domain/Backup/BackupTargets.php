<?php

namespace XcVm\Domain\Backup;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Off-site copies of the backups (`backup_targets`, Backups page): an
 * S3-compatible bucket (AWS, Backblaze B2, Wasabi, MinIO...; Signature V4) or
 * an SFTP server. Each enabled target gets every backup and recovery bundle
 * cron:backups makes, and keeps its newest `keep` of each (0: all). The
 * credentials live in this MAIN-only table, never in `settings`.
 *
 * An SFTP target pins the server's host key the first time it connects and
 * refuses any other one after (clear the fingerprint to accept a new key).
 *
 * @package XC_VM_Domain_Backup
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class BackupTargets {
	use DatabaseAware;

	public const TYPES = ['s3', 'sftp'];

	/** Each type's fields; the secret ones are never sent back to the page. */
	public const FIELDS = [
		's3' => ['endpoint' => false, 'region' => false, 'bucket' => false, 'prefix' => false, 'access_key' => false, 'secret_key' => true, 'path_style' => false],
		'sftp' => ['host' => false, 'port' => false, 'username' => false, 'password' => true, 'private_key' => true, 'path' => false, 'fingerprint' => false],
	];

	private const TIMEOUT = 30;

	/** @return list<array{id: int, type: string, name: string, enabled: int, keep: int, config: array<string, string>}> */
	public static function all(): array {
		$db = self::db();
		$db->query('SELECT `id`, `type`, `name`, `enabled`, `keep`, `config` FROM `backup_targets` ORDER BY `id`;');
		return array_map(static fn(array $rRow): array => ['id' => (int) $rRow['id'], 'type' => (string) $rRow['type'], 'name' => (string) $rRow['name'], 'enabled' => (int) $rRow['enabled'], 'keep' => (int) $rRow['keep'], 'config' => json_decode((string) $rRow['config'], true) ?: []], $db->get_raw_rows());
	}

	/** @return list<array> the enabled targets */
	public static function enabled(): array {
		return array_values(array_filter(self::all(), static fn(array $rTarget): bool => (bool) $rTarget['enabled']));
	}

	/** A target for the page: its secrets replaced by whether they are set. */
	public static function forPage(array $rTarget): array {
		foreach (self::FIELDS[$rTarget['type']] ?? [] as $rField => $rSecret) {
			if ($rSecret) {
				$rTarget['config'][$rField] = ($rTarget['config'][$rField] ?? '') !== '' ? '********' : '';
			}
		}
		return $rTarget;
	}

	/**
	 * Add or change a target from the page; a secret left empty or masked keeps
	 * the stored one.
	 *
	 * @return array{result: bool, error?: string, id?: int}
	 */
	public static function save(array $rData): array {
		$rType = (string) ($rData['type'] ?? '');
		$rName = trim((string) ($rData['name'] ?? ''));
		if (!isset(self::FIELDS[$rType])) {
			return ['result' => false, 'error' => 'type'];
		}
		if ($rName === '' || mb_strlen($rName) > 64) {
			return ['result' => false, 'error' => 'name'];
		}
		$rID = (int) ($rData['id'] ?? 0);
		$rStored = [];
		if ($rID > 0) {
			$rOld = self::find($rID);
			if ($rOld === null || $rOld['type'] !== $rType) {
				return ['result' => false, 'error' => 'type'];
			}
			$rStored = $rOld['config'];
		}
		$rConfig = [];
		foreach (self::FIELDS[$rType] as $rField => $rSecret) {
			$rValue = trim((string) ($rData[$rField] ?? ''));
			$rConfig[$rField] = $rSecret && ($rValue === '' || $rValue === '********') ? (string) ($rStored[$rField] ?? '') : $rValue;
		}
		if ($rType === 's3') {
			$rConfig['endpoint'] = rtrim($rConfig['endpoint'] !== '' ? $rConfig['endpoint'] : 'https://s3.amazonaws.com', '/');
			$rConfig['path_style'] = empty($rData['path_style']) ? '0' : '1';
		}
		if (($rError = self::invalid($rType, $rConfig)) !== null) {
			return ['result' => false, 'error' => $rError];
		}
		$rJSON = json_encode($rConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$rEnabled = empty($rData['enabled']) ? 0 : 1;
		$rKeep = max(0, min(1000, (int) ($rData['keep'] ?? 0)));
		if ($rID > 0) {
			self::db()->query('UPDATE `backup_targets` SET `name` = ?, `enabled` = ?, `keep` = ?, `config` = ? WHERE `id` = ?;', $rName, $rEnabled, $rKeep, $rJSON, $rID);
			return ['result' => true, 'id' => $rID];
		}
		self::db()->query('INSERT INTO `backup_targets` (`type`, `name`, `enabled`, `keep`, `config`, `created`) VALUES (?, ?, ?, ?, ?, ?);', $rType, $rName, $rEnabled, $rKeep, $rJSON, time());
		return ['result' => true, 'id' => (int) self::db()->last_insert_id()];
	}

	public static function find(int $rID): ?array {
		return array_values(array_filter(self::all(), static fn(array $rTarget): bool => $rTarget['id'] === $rID))[0] ?? null;
	}

	public static function delete(int $rID): bool {
		self::db()->query('DELETE FROM `backup_targets` WHERE `id` = ?;', $rID);
		return self::db()->num_rows() === 1;
	}

	/** What is wrong with a target's settings, or null. */
	public static function invalid(string $rType, array $rConfig): ?string {
		if ($rType === 's3') {
			if (filter_var($rConfig['endpoint'] ?? '', FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $rConfig['endpoint'])) {
				return 'endpoint';
			}
			if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/', $rConfig['bucket'] ?? '')) {
				return 'bucket';
			}
			if (!preg_match('/^[a-z0-9-]{2,32}$/', $rConfig['region'] ?? '')) {
				return 'region';
			}
			return ($rConfig['access_key'] ?? '') !== '' && ($rConfig['secret_key'] ?? '') !== '' ? null : 'access_key';
		}
		if ($rType === 'sftp') {
			if (!preg_match('/^[A-Za-z0-9.:-]+$/', $rConfig['host'] ?? '') || (int) ($rConfig['port'] ?: 22) < 1 || (int) ($rConfig['port'] ?: 22) > 65535) {
				return 'host';
			}
			if (($rConfig['username'] ?? '') === '' || (($rConfig['password'] ?? '') === '' && ($rConfig['private_key'] ?? '') === '')) {
				return 'username';
			}
			// libssh2 needs the public key too, derived here from an RSA key in PEM form.
			$rKey = ($rConfig['private_key'] ?? '') === '' ? null : @openssl_pkey_get_private((string) $rConfig['private_key'], (string) ($rConfig['password'] ?? ''));
			if ($rKey === false || ($rKey !== null && (openssl_pkey_get_details($rKey)['type'] ?? null) !== OPENSSL_KEYTYPE_RSA)) {
				return 'private_key';
			}
			return str_starts_with((string) ($rConfig['path'] ?? ''), '/') ? null : 'path';
		}
		return 'type';
	}

	/** Copy a local file to a target under $rName. Null when it went, else why not. */
	public static function upload(array $rTarget, string $rFile, string $rName): ?string {
		try {
			return $rTarget['type'] === 's3' ? self::s3Put($rTarget, $rFile, $rName) : self::sftp($rTarget, static function ($rSftp, string $rDir) use ($rFile, $rName): ?string {
				$rOut = @fopen('ssh2.sftp://' . intval($rSftp) . $rDir . '/' . $rName . '.part', 'w');
				$rIn = @fopen($rFile, 'r');
				if ($rOut === false || $rIn === false) {
					return 'cannot write in ' . $rDir;
				}
				$rCopied = stream_copy_to_stream($rIn, $rOut);
				fclose($rIn);
				fclose($rOut);
				if ($rCopied !== filesize($rFile)) {
					@ssh2_sftp_unlink($rSftp, $rDir . '/' . $rName . '.part');
					return 'the copy stopped after ' . (int) $rCopied . ' bytes';
				}
				@ssh2_sftp_unlink($rSftp, $rDir . '/' . $rName);
				return ssh2_sftp_rename($rSftp, $rDir . '/' . $rName . '.part', $rDir . '/' . $rName) ? null : 'cannot rename the copy';
			});
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}

	/**
	 * The backups and bundles a target holds, oldest first.
	 *
	 * @return list<string>|string the names, or why they could not be listed
	 */
	public static function listing(array $rTarget): array|string {
		try {
			$rNames = $rTarget['type'] === 's3' ? self::s3List($rTarget) : self::sftp($rTarget, static function ($rSftp, string $rDir): array|string {
				$rHandle = @opendir('ssh2.sftp://' . intval($rSftp) . $rDir);
				if ($rHandle === false) {
					return 'cannot read ' . $rDir;
				}
				$rOut = [];
				while (($rEntry = readdir($rHandle)) !== false) {
					$rOut[] = $rEntry;
				}
				closedir($rHandle);
				return $rOut;
			});
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
		if (is_string($rNames)) {
			return $rNames;
		}
		// Only what cron:backups puts there: its names sort by date.
		$rNames = array_values(array_filter($rNames, static fn(string $rName): bool => (bool) preg_match('/^(backup_.+\.sql|recovery_.+\.bundle)$/', $rName)));
		sort($rNames);
		return $rNames;
	}

	public static function remove(array $rTarget, string $rName): ?string {
		try {
			return $rTarget['type'] === 's3' ? self::s3Delete($rTarget, $rName) : self::sftp($rTarget, static fn($rSftp, string $rDir): ?string => @ssh2_sftp_unlink($rSftp, $rDir . '/' . $rName) ? null : 'cannot delete ' . $rName);
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}

	/**
	 * Keep a target's newest `keep` backups and bundles (each kind apart).
	 *
	 * @return int how many were removed
	 */
	public static function prune(array $rTarget): int {
		$rNames = self::listing($rTarget);
		if ((int) $rTarget['keep'] <= 0 || !is_array($rNames)) {
			return 0;
		}
		$rRemoved = 0;
		foreach (['/^backup_/', '/^recovery_/'] as $rKind) {
			$rOld = array_values(preg_grep($rKind, $rNames) ?: []);
			foreach (array_slice($rOld, 0, max(0, count($rOld) - (int) $rTarget['keep'])) as $rName) {
				$rRemoved += self::remove($rTarget, $rName) === null ? 1 : 0;
			}
		}
		return $rRemoved;
	}

	/** Write, read back and delete a small file: the page's Test. Null when the target works. */
	public static function test(array $rTarget): ?string {
		$rName = 'backup_xcvm-test-' . bin2hex(random_bytes(4)) . '.sql';
		$rFile = (string) tempnam(sys_get_temp_dir(), 'xcvm-target-');
		file_put_contents($rFile, "-- XC_VM backup target test\n");
		$rError = self::upload($rTarget, $rFile, $rName);
		@unlink($rFile);
		if ($rError !== null) {
			return $rError;
		}
		$rList = self::listing($rTarget);
		if (is_string($rList)) {
			return $rList;
		}
		if (!in_array($rName, $rList, true)) {
			return 'the test file was written but is not listed';
		}
		return self::remove($rTarget, $rName);
	}

	// ── S3 (Signature Version 4) ─────────────────────────────

	/** The object's URL and the Host header, path-style or virtual-hosted. */
	public static function s3Url(array $rConfig, string $rKey, string $rQuery = ''): array {
		$rParts = parse_url($rConfig['endpoint']);
		$rHost = $rParts['host'] . (isset($rParts['port']) ? ':' . $rParts['port'] : '');
		$rPath = '/' . ltrim($rKey, '/');
		if (!empty($rConfig['path_style'])) {
			$rPath = '/' . $rConfig['bucket'] . $rPath;
		} else {
			$rHost = $rConfig['bucket'] . '.' . $rHost;
		}
		$rPath = implode('/', array_map('rawurlencode', explode('/', $rPath)));
		return [$rParts['scheme'] . '://' . $rHost . $rPath . ($rQuery !== '' ? '?' . $rQuery : ''), $rHost, $rPath];
	}

	/**
	 * The Authorization header of an S3 request (AWS Signature Version 4).
	 *
	 * @param array<string, string> $rHeaders lowercase name => value, host and x-amz-* included
	 */
	public static function s3Sign(array $rConfig, string $rMethod, string $rPath, string $rQuery, array $rHeaders, string $rPayloadHash, int $rNow): string {
		ksort($rHeaders);
		$rDate = gmdate('Ymd', $rNow);
		$rScope = $rDate . '/' . $rConfig['region'] . '/s3/aws4_request';
		parse_str($rQuery, $rParams);
		ksort($rParams);
		$rCanonicalQuery = implode('&', array_map(static fn($rKey, $rValue): string => rawurlencode((string) $rKey) . '=' . rawurlencode((string) $rValue), array_keys($rParams), $rParams));
		$rSigned = implode(';', array_keys($rHeaders));
		$rCanonical = implode("\n", [$rMethod, $rPath, $rCanonicalQuery, implode('', array_map(static fn($rName, $rValue): string => $rName . ':' . trim($rValue) . "\n", array_keys($rHeaders), $rHeaders)), $rSigned, $rPayloadHash]);
		$rToSign = "AWS4-HMAC-SHA256\n" . gmdate('Ymd\THis\Z', $rNow) . "\n" . $rScope . "\n" . hash('sha256', $rCanonical);
		$rKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $rConfig['region'], hash_hmac('sha256', $rDate, 'AWS4' . $rConfig['secret_key'], true), true), true), true);
		return 'AWS4-HMAC-SHA256 Credential=' . $rConfig['access_key'] . '/' . $rScope . ', SignedHeaders=' . $rSigned . ', Signature=' . hash_hmac('sha256', $rToSign, $rKey);
	}

	/** @return array{0: int, 1: string} HTTP status and body */
	private static function s3Request(array $rConfig, string $rMethod, string $rKey, string $rQuery = '', ?string $rFile = null): array {
		$rNow = time();
		[$rURL, $rHost, $rPath] = self::s3Url($rConfig, $rKey, $rQuery);
		$rHash = $rFile !== null ? hash_file('sha256', $rFile) : hash('sha256', '');
		$rHeaders = ['host' => $rHost, 'x-amz-content-sha256' => $rHash, 'x-amz-date' => gmdate('Ymd\THis\Z', $rNow)];
		$rAuth = self::s3Sign($rConfig, $rMethod, $rPath, $rQuery, $rHeaders, $rHash, $rNow);
		$rCurl = curl_init($rURL);
		$rOpts = [
			CURLOPT_CUSTOMREQUEST => $rMethod,
			CURLOPT_HTTPHEADER => ['Authorization: ' . $rAuth, 'x-amz-content-sha256: ' . $rHash, 'x-amz-date: ' . $rHeaders['x-amz-date']],
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => self::TIMEOUT,
			CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
		];
		$rIn = null;
		if ($rFile !== null) {
			$rIn = fopen($rFile, 'r');
			$rOpts += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $rIn, CURLOPT_INFILESIZE => filesize($rFile)];
		}
		curl_setopt_array($rCurl, $rOpts);
		$rBody = curl_exec($rCurl);
		$rCode = (int) curl_getinfo($rCurl, CURLINFO_RESPONSE_CODE);
		$rError = curl_error($rCurl);
		curl_close($rCurl);
		if ($rIn !== null) {
			fclose($rIn);
		}
		if ($rBody === false) {
			throw new \RuntimeException('no answer from ' . $rHost . ($rError !== '' ? ' (' . $rError . ')' : ''));
		}
		return [$rCode, (string) $rBody];
	}

	private static function s3Key(array $rConfig, string $rName): string {
		return trim((string) ($rConfig['prefix'] ?? ''), '/') === '' ? $rName : trim($rConfig['prefix'], '/') . '/' . $rName;
	}

	/** The S3 error's code and message, from its XML answer. */
	private static function s3Error(int $rCode, string $rBody): string {
		preg_match('#<Code>(.*?)</Code>#', $rBody, $rError);
		preg_match('#<Message>(.*?)</Message>#', $rBody, $rMessage);
		return 'S3 answered HTTP ' . $rCode . (isset($rError[1]) ? ' ' . $rError[1] : '') . (isset($rMessage[1]) ? ': ' . html_entity_decode($rMessage[1]) : '');
	}

	private static function s3Put(array $rTarget, string $rFile, string $rName): ?string {
		[$rCode, $rBody] = self::s3Request($rTarget['config'], 'PUT', self::s3Key($rTarget['config'], $rName), '', $rFile);
		return $rCode === 200 ? null : self::s3Error($rCode, $rBody);
	}

	private static function s3Delete(array $rTarget, string $rName): ?string {
		[$rCode, $rBody] = self::s3Request($rTarget['config'], 'DELETE', self::s3Key($rTarget['config'], $rName));
		return $rCode === 204 || $rCode === 200 ? null : self::s3Error($rCode, $rBody);
	}

	/** @return list<string> the names under the prefix, all pages */
	private static function s3List(array $rTarget): array|string {
		$rPrefix = trim((string) ($rTarget['config']['prefix'] ?? ''), '/');
		$rNames = [];
		$rToken = null;
		do {
			$rQuery = http_build_query(array_filter(['list-type' => '2', 'prefix' => $rPrefix === '' ? null : $rPrefix . '/', 'continuation-token' => $rToken], static fn($rValue): bool => $rValue !== null), '', '&', PHP_QUERY_RFC3986);
			[$rCode, $rBody] = self::s3Request($rTarget['config'], 'GET', '', $rQuery);
			if ($rCode !== 200) {
				return self::s3Error($rCode, $rBody);
			}
			[$rPage, $rToken] = self::s3ParseList($rBody);
			foreach ($rPage as $rKey) {
				$rNames[] = basename($rKey);
			}
		} while ($rToken !== null);
		return $rNames;
	}

	/** @return array{0: list<string>, 1: ?string} the keys of a ListObjectsV2 answer, and the next page's token */
	public static function s3ParseList(string $rXML): array {
		preg_match_all('#<Key>(.*?)</Key>#s', $rXML, $rKeys);
		$rNext = preg_match('#<IsTruncated>true</IsTruncated>#', $rXML) && preg_match('#<NextContinuationToken>(.*?)</NextContinuationToken>#s', $rXML, $rToken) ? html_entity_decode($rToken[1]) : null;
		return [array_map('html_entity_decode', $rKeys[1]), $rNext];
	}

	// ── SFTP (ssh2) ──────────────────────────────────────────

	/** Connect, check the host key, sign in, and run $rWork with the SFTP handle and the directory. */
	private static function sftp(array $rTarget, callable $rWork): mixed {
		if (!function_exists('ssh2_connect')) {
			return 'the ssh2 PHP extension is missing';
		}
		$rConfig = $rTarget['config'];
		$rSession = @ssh2_connect($rConfig['host'], (int) ($rConfig['port'] ?: 22));
		if ($rSession === false) {
			return 'cannot connect to ' . $rConfig['host'];
		}
		$rFingerprint = (string) ssh2_fingerprint($rSession, SSH2_FINGERPRINT_SHA1 | SSH2_FINGERPRINT_HEX);
		if (($rConfig['fingerprint'] ?? '') === '') {
			// Pinned on first use: a later connection to another key is refused.
			$rTarget['config']['fingerprint'] = $rFingerprint;
			self::db()->query('UPDATE `backup_targets` SET `config` = ? WHERE `id` = ?;', json_encode($rTarget['config'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $rTarget['id']);
		} elseif (!hash_equals(strtolower($rConfig['fingerprint']), strtolower($rFingerprint))) {
			return 'the server\'s host key changed (' . $rFingerprint . '); clear the target\'s fingerprint if this is expected';
		}
		if (($rConfig['private_key'] ?? '') !== '') {
			$rKeyFile = (string) tempnam(sys_get_temp_dir(), 'xcvm-key-');
			chmod($rKeyFile, 0600);
			file_put_contents($rKeyFile, str_replace("\r\n", "\n", $rConfig['private_key']) . "\n");
			$rPublic = (string) tempnam(sys_get_temp_dir(), 'xcvm-pub-');
			$rOk = self::publicKey($rKeyFile, $rPublic) && @ssh2_auth_pubkey_file($rSession, $rConfig['username'], $rPublic, $rKeyFile, (string) ($rConfig['password'] ?? ''));
			@unlink($rKeyFile);
			@unlink($rPublic);
		} else {
			$rOk = @ssh2_auth_password($rSession, $rConfig['username'], (string) $rConfig['password']);
		}
		if (!$rOk) {
			return 'the server refused the login';
		}
		$rSftp = @ssh2_sftp($rSession);
		if ($rSftp === false) {
			return 'the server offers no SFTP';
		}
		return $rWork($rSftp, rtrim($rConfig['path'], '/'));
	}

	/** The public key of a private key file, in OpenSSH format, written to $rPublic. */
	private static function publicKey(string $rPrivate, string $rPublic): bool {
		$rKey = @openssl_pkey_get_private((string) file_get_contents($rPrivate));
		if ($rKey === false) {
			return false;
		}
		$rDetails = openssl_pkey_get_details($rKey);
		if (($rDetails['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
			return false;
		}
		$rEncode = static fn(string $rValue): string => pack('N', strlen($rValue)) . $rValue;
		$rMpint = static fn(string $rValue): string => $rEncode(ord($rValue[0]) & 0x80 ? "\0" . $rValue : $rValue);
		$rBlob = $rEncode('ssh-rsa') . $rMpint($rDetails['rsa']['e']) . $rMpint($rDetails['rsa']['n']);
		return file_put_contents($rPublic, 'ssh-rsa ' . base64_encode($rBlob) . "\n") !== false;
	}
}

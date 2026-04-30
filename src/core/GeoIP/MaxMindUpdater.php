<?php

/**
 * MaxMindUpdater PHP class - GeoIP updater for MaxMind API
 *
 * @package XC_VM_Core_GeoIP
 * @author  Kondoooo <https://github.com/Kondoooo>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 *
 * A PHP class created for the XC_VM project to update GeoIP databases.
 * Supports free GeoLite2 and paid GeoIP2 editions via MaxMind API,
 * downloads tar.gz archives, extracts .mmdb files, and updates version metadata.
 *
 * Implemented for: https://github.com/Vateron-Media/XC_VM/issues/102
 */
class MaxMindUpdater {

	private const DOWNLOAD_URL = 'https://download.maxmind.com/geoip/databases/%s/download?suffix=tar.gz';
	private const MAXMIND_DIR  = '/home/xc_vm/bin/maxmind/';
	private const VERSION_FILE = '/home/xc_vm/bin/maxmind/version.json';
	private const TIMEOUT      = 300;

	private string $accountId;
	private string $licenseKey;
	/** @var string[] */
	private array $editions;

	public function __construct(string $accountId, string $licenseKey, array $editions) {
		$this->accountId  = $accountId;
		$this->licenseKey = $licenseKey;
		$this->editions   = $editions;
	}

	public static function fromSettings(array $settings): ?self {
		$accountId  = trim($settings['maxmind_account_id'] ?? '');
		$licenseKey = trim($settings['maxmind_license_key'] ?? '');
		$editions   = json_decode($settings['maxmind_editions'] ?? '[]', true);

		if ($accountId === '' || $licenseKey === '' || empty($editions)) {
			return null;
		}

		return new self($accountId, $licenseKey, $editions);
	}

	/**
	 * Download all configured editions.
	 * Returns array of results: ['edition' => string, 'updated' => bool, 'error' => string|null]
	 */
	public function update(): array {
		$results = [];
		foreach ($this->editions as $edition) {
			$results[] = $this->downloadEdition($edition);
		}
		return $results;
	}

	private function downloadEdition(string $edition): array {
		$url      = sprintf(self::DOWNLOAD_URL, $edition);
		$destPath = self::MAXMIND_DIR . $edition . '.mmdb';
		$result   = ['edition' => $edition, 'updated' => false, 'error' => null];

		$headers = ['User-Agent: XC_VM-MaxMind-Updater/1.0'];

		if (file_exists($destPath)) {
			$headers[] = 'If-Modified-Since: ' . gmdate('D, d M Y H:i:s', filemtime($destPath)) . ' GMT';
		}

		$tmpTar = self::MAXMIND_DIR . $edition . '.tar.gz.tmp';

		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($ch, CURLOPT_USERPWD, $this->accountId . ':' . $this->licenseKey);
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

		$body     = curl_exec($ch);
		$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$curlErr  = curl_error($ch);
		curl_close($ch);

		if ($body === false || $curlErr !== '') {
			$result['error'] = 'cURL error: ' . $curlErr;
			return $result;
		}

		if ($httpCode === 304) {
			return $result; // Not Modified
		}

		if ($httpCode === 401) {
			$result['error'] = 'Authentication failed — check Account ID and License Key';
			return $result;
		}

		if ($httpCode === 403) {
			$result['error'] = 'Access denied — verify your MaxMind subscription includes ' . $edition;
			return $result;
		}

		if ($httpCode !== 200) {
			$result['error'] = 'Unexpected HTTP ' . $httpCode . ' for ' . $edition;
			return $result;
		}

		if (!is_dir(self::MAXMIND_DIR)) {
			mkdir(self::MAXMIND_DIR, 0750, true);
		}

		if (file_put_contents($tmpTar, $body) === false) {
			$result['error'] = 'Failed to write tar.gz';
			return $result;
		}

		$mmdbPath = $this->extractMmdb($tmpTar, $edition);
		unlink($tmpTar);

		if ($mmdbPath === null) {
			$result['error'] = 'Could not find .mmdb inside tar.gz';
			return $result;
		}

		rename($mmdbPath, $destPath);
		chown($destPath, 'xc_vm');
		chmod($destPath, 0750);

		$this->updateVersionFile($edition);

		$result['updated'] = true;
		return $result;
	}

	/**
	 * Extract the .mmdb file from a tar.gz archive.
	 * Returns the path to the extracted file, or null on failure.
	 */
	private function extractMmdb(string $tarPath, string $edition): ?string {
		$extractDir = self::MAXMIND_DIR . 'extract_' . $edition . '/';

		if (is_dir($extractDir)) {
			exec('rm -rf ' . escapeshellarg($extractDir));
		}
		mkdir($extractDir, 0750, true);

		exec('tar -xzf ' . escapeshellarg($tarPath) . ' -C ' . escapeshellarg($extractDir) . ' 2>/dev/null', $out, $rc);

		if ($rc !== 0) {
			exec('rm -rf ' . escapeshellarg($extractDir));
			return null;
		}

		// Find the .mmdb file anywhere in the extracted tree
		$mmdbFiles = glob($extractDir . '*/' . $edition . '.mmdb');
		if (empty($mmdbFiles)) {
			$mmdbFiles = glob($extractDir . '*/*.mmdb');
		}

		if (empty($mmdbFiles)) {
			exec('rm -rf ' . escapeshellarg($extractDir));
			return null;
		}

		$found = $mmdbFiles[0];

		// Move it up to maxmind dir temporarily
		$tmpDest = self::MAXMIND_DIR . $edition . '.mmdb.new';
		rename($found, $tmpDest);
		exec('rm -rf ' . escapeshellarg($extractDir));

		return $tmpDest;
	}

	private function updateVersionFile(string $edition): void {
		$data  = json_decode(@file_get_contents(self::VERSION_FILE), true) ?: [];
		$today = date('d.m.y');

		if (strpos($edition, 'GeoLite2') !== false) {
			$data['geolite2_version'] = $today;
		} else {
			if ($edition === 'GeoIP2-ISP') {
				$data['geoisp_version'] = $today;
			}
			$key = strtolower(str_replace(['GeoIP2-', '-'], ['geo', '_'], $edition)) . '_version';
			$data[$key] = $today;
		}

		file_put_contents(self::VERSION_FILE, json_encode($data, JSON_PRETTY_PRINT));
	}

	public static function availableEditions(): array {
		return [
			'GeoLite2-Country'    => 'GeoLite2-Country (free)',
			'GeoLite2-City'       => 'GeoLite2-City (free)',
			'GeoLite2-ASN'        => 'GeoLite2-ASN (free)',
			'GeoIP2-Country'      => 'GeoIP2-Country (paid)',
			'GeoIP2-City'         => 'GeoIP2-City (paid)',
			'GeoIP2-ISP'          => 'GeoIP2-ISP (paid)',
			'GeoIP2-Anonymous-IP' => 'GeoIP2-Anonymous-IP (paid)',
		];
	}
}
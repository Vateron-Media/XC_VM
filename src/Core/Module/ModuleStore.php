<?php

namespace XcVm\Core\Module;

/**
 * The official module store (xcvm.tech) as the modules page lists it.
 *
 * The catalogue comes from the core extension, `XC_VM::extensions_list()`:
 * every published module with its latest version and price, but nothing
 * about the caller. Whether a paid module is the API key's own is asked per
 * module, `XC_VM::plugins_check()` (`allowed`): the page marks it purchased,
 * and offers a paid module not bought on its store page instead of installing it.
 *
 * The answer is cached a few minutes per API key: a store call can take up to
 * 15 s, and one per paid module follows it.
 *
 * @package XC_VM_Core_Module
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleStore {
	/** Seconds a fetched catalogue is served from the cache. */
	public const TTL = 300;

	public static function cacheFile(): string {
		return CACHE_TMP_PATH . 'module_store.json';
	}

	/**
	 * The catalogue and the paid slugs the key owns, from the cache when fresh.
	 *
	 * @param callable(string): array<string, mixed>|null         $rList  tests: stands in for XC_VM::extensions_list
	 * @param callable(string, string): array<string, mixed>|null $rCheck tests: stands in for XC_VM::plugins_check
	 * @return array{ok: bool, reason?: string, extensions?: list<array<string, mixed>>, owned?: list<string>}
	 */
	public static function catalogue(string $rApiKey, bool $rRefresh = false, ?callable $rList = null, ?callable $rCheck = null): array {
		if ($rApiKey === '') {
			return ['ok' => false, 'reason' => 'no_api_key'];
		}
		$rKeyHash = hash('sha256', $rApiKey);
		$rCached  = json_decode((string) @file_get_contents(self::cacheFile()), true);
		if (!$rRefresh && is_array($rCached) && ($rCached['key'] ?? '') === $rKeyHash && time() - (int) ($rCached['fetched'] ?? 0) < self::TTL) {
			return $rCached['data'];
		}

		if ($rList === null || $rCheck === null) {
			if (!class_exists('XC_VM') || !method_exists('XC_VM', 'extensions_list') || !method_exists('XC_VM', 'plugins_check')) {
				return ['ok' => false, 'reason' => 'no_extension'];
			}
			$rInstallId = (string) \XC_VM::install_id();
			$rList ??= static fn(string $rKey): array => (array) \XC_VM::extensions_list(null, $rKey);
			$rCheck ??= static fn(string $rSlug, string $rKey): array => (array) \XC_VM::plugins_check($rSlug, $rInstallId, $rKey);
		}

		$rAnswer = $rList($rApiKey);
		if (empty($rAnswer['ok']) || !is_array($rAnswer['extensions'] ?? null)) {
			// Not cached: the next look asks again.
			return ['ok' => false, 'reason' => (string) ($rAnswer['reason'] ?? 'bad_response')];
		}

		$rOwned = [];
		foreach ($rAnswer['extensions'] as $rExt) {
			if ((float) ($rExt['price'] ?? 0) > 0 && (($rCheck((string) $rExt['slug'], $rApiKey))['allowed'] ?? false) === true) {
				$rOwned[] = (string) $rExt['slug'];
			}
		}

		$rData = ['ok' => true, 'extensions' => array_values($rAnswer['extensions']), 'owned' => $rOwned];
		$rTemp = self::cacheFile() . '.tmp';
		if (@file_put_contents($rTemp, (string) json_encode(['key' => $rKeyHash, 'fetched' => time(), 'data' => $rData], JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) !== false) {
			@rename($rTemp, self::cacheFile());
		}
		return $rData;
	}

	/** Forget the cached catalogue (after a store install, so the page shows it installed). */
	public static function forget(): void {
		@unlink(self::cacheFile());
	}

	/**
	 * The store's modules as the page lists them, each with whether it is free,
	 * bought or for sale, and what is installed of it here.
	 *
	 * @param list<array<string, mixed>> $rExtensions the catalogue's `extensions`
	 * @param list<string>               $rOwned      paid slugs the key owns
	 * @param list<array<string, mixed>> $rInstalled  ModuleManager::listModules()
	 * @return list<array<string, mixed>>
	 */
	public static function rows(array $rExtensions, array $rOwned, array $rInstalled): array {
		$rHere = [];
		foreach ($rInstalled as $rModule) {
			$rSlug = (string) ($rModule['update']['slug'] ?? '');
			$rHere[$rSlug !== '' ? $rSlug : (string) $rModule['name']] = $rModule;
		}

		$rRows = [];
		foreach ($rExtensions as $rExt) {
			$rSlug  = (string) ($rExt['slug'] ?? '');
			$rPrice = (float) ($rExt['price'] ?? 0);
			if ($rSlug === '') {
				continue;
			}
			$rVersion   = (string) ($rExt['version'] ?? '');
			$rInstalled = (string) ($rHere[$rSlug]['installed_version'] ?? '');
			$rRows[] = [
				'slug'              => $rSlug,
				'name'              => (string) ($rExt['name'] ?? $rSlug),
				'version'           => $rVersion,
				'environment'       => (string) ($rExt['environment'] ?? ''),
				'compatibility'     => (string) ($rExt['xc_vm_compatibility'] ?? ''),
				'badge'             => $rPrice <= 0 ? 'free' : (in_array($rSlug, $rOwned, true) ? 'purchased' : 'paid'),
				'price'             => $rPrice,
				'module'            => isset($rHere[$rSlug]) ? (string) $rHere[$rSlug]['name'] : '',
				'installed_version' => $rInstalled,
				'update_to'         => $rInstalled !== '' && $rVersion !== '' && version_compare($rVersion, $rInstalled, '>') ? $rVersion : '',
			];
		}
		usort($rRows, static fn(array $a, array $b): int => strcasecmp($a['name'], $b['name']));
		return $rRows;
	}
}

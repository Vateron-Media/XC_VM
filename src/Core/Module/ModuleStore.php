<?php

namespace XcVm\Core\Module;

/**
 * The official module store (xcvm.tech) as the modules page lists it.
 *
 * The catalogue is public and comes a page at a time from the core extension,
 * `XC_VM::extensions_list(null, $key, $options)` (xcvm_core >= 2.4.0): no
 * Modules API key is needed to list it or to install a free module. With a key
 * each paid module also says whether that key's user bought it (`owned`).
 *
 * An older extension only takes a key and answers the whole catalogue: there
 * ownership is asked per paid module, `XC_VM::plugins_check()`, and the
 * search, sort and page are applied here, so the page sees the same answer.
 *
 * Answers are cached briefly, one file per key and request: a store call can
 * take up to 15 s.
 *
 * @package XC_VM_Core_Module
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleStore {
	/** Seconds a fetched answer is served from the cache. */
	public const TTL = 120;

	public const SORTS = ['name', 'popular', 'newest', 'price_asc', 'price_desc'];

	public const PER_PAGE = 50;

	public static function cacheDir(): string {
		return CACHE_TMP_PATH . 'module_store/';
	}

	/**
	 * The request options the store accepts, from whatever the page sent.
	 *
	 * @param array<string, mixed> $rRaw page, per_page, search, sort
	 * @return array{page: int, per_page: int, search: string, sort: string}
	 */
	public static function options(array $rRaw): array {
		$rPerPage = (int) ($rRaw['per_page'] ?? 0);
		$rSort    = $rRaw['sort'] ?? '';
		$rSearch  = $rRaw['search'] ?? '';
		return [
			'page'     => max(1, (int) ($rRaw['page'] ?? 1)),
			'per_page' => $rPerPage >= 1 ? min($rPerPage, 100) : self::PER_PAGE,
			// Cut by bytes on a character boundary: within 100 either way the store counts.
			'search'   => is_string($rSearch) ? mb_strcut(trim($rSearch), 0, 100, 'UTF-8') : '',
			'sort'     => in_array($rSort, self::SORTS, true) ? $rSort : 'name',
		];
	}

	/**
	 * One page of the catalogue, each module with whether the key owns it.
	 *
	 * @param array<string, mixed>                                $rOptions see options()
	 * @param callable(?string, array): array<string, mixed>|null $rList    tests: stands in for XC_VM::extensions_list
	 * @param callable(string, string): array<string, mixed>|null $rCheck   tests: stands in for XC_VM::plugins_check;
	 *                                                                       given, $rList is an extension older than 2.4.0
	 * @return array{ok: bool, reason?: string, extensions?: list<array<string, mixed>>, page?: int, per_page?: int, total?: int, last_page?: int}
	 */
	public static function catalogue(string $rApiKey, array $rOptions = [], bool $rRefresh = false, ?callable $rList = null, ?callable $rCheck = null): array {
		$rOptions = self::options($rOptions);

		if ($rList === null) {
			$rBound = self::extension();
			if ($rBound === null) {
				return ['ok' => false, 'reason' => 'no_extension'];
			}
			[$rList, $rCheck] = $rBound;
		}

		if ($rCheck !== null) {
			return self::legacy($rApiKey, $rOptions, $rRefresh, $rList, $rCheck);
		}

		return self::cached($rApiKey, (string) json_encode($rOptions), $rRefresh, static function () use ($rList, $rApiKey, $rOptions): array {
			$rAnswer = $rList($rApiKey !== '' ? $rApiKey : null, $rOptions);
			if (empty($rAnswer['ok']) || !is_array($rAnswer['extensions'] ?? null)) {
				return ['ok' => false, 'reason' => (string) ($rAnswer['reason'] ?? 'bad_response')];
			}
			$rExtensions = [];
			foreach ($rAnswer['extensions'] as $rExt) {
				$rExt['owned']  = ($rExt['owned'] ?? false) === true;
				$rExtensions[] = $rExt;
			}
			$rTotal = (int) ($rAnswer['total'] ?? count($rExtensions));
			return [
				'ok'         => true,
				'extensions' => $rExtensions,
				'page'       => (int) ($rAnswer['page'] ?? $rOptions['page']),
				'per_page'   => (int) ($rAnswer['per_page'] ?? $rOptions['per_page']),
				'total'      => $rTotal,
				'last_page'  => (int) ($rAnswer['last_page'] ?? max(1, (int) ceil($rTotal / $rOptions['per_page']))),
			];
		});
	}

	/**
	 * The catalogue through an extension older than 2.4.0: a key is required,
	 * ownership is asked per paid module, and the whole list is cached once and
	 * every page cut from it here.
	 *
	 * @param array{page: int, per_page: int, search: string, sort: string} $rOptions
	 * @return array<string, mixed>
	 */
	private static function legacy(string $rApiKey, array $rOptions, bool $rRefresh, callable $rList, callable $rCheck): array {
		if ($rApiKey === '') {
			return ['ok' => false, 'reason' => 'no_api_key'];
		}
		$rAll = self::cached($rApiKey, 'full', $rRefresh, static function () use ($rList, $rCheck, $rApiKey): array {
			$rAnswer = $rList($rApiKey, []);
			if (empty($rAnswer['ok']) || !is_array($rAnswer['extensions'] ?? null)) {
				return ['ok' => false, 'reason' => (string) ($rAnswer['reason'] ?? 'bad_response')];
			}
			$rExtensions = [];
			foreach ($rAnswer['extensions'] as $rExt) {
				$rExt['owned']  = (float) ($rExt['price'] ?? 0) > 0 && (($rCheck((string) ($rExt['slug'] ?? ''), $rApiKey))['allowed'] ?? false) === true;
				$rExtensions[] = $rExt;
			}
			return ['ok' => true, 'extensions' => $rExtensions];
		});
		return $rAll['ok'] ? self::page($rAll['extensions'], $rOptions) : $rAll;
	}

	/**
	 * The loaded core extension's calls: the paged list, or, for one older than
	 * 2.4.0, the whole list and the per-module ownership check. Null without it.
	 *
	 * @return array{0: callable, 1: callable|null}|null
	 */
	private static function extension(): ?array {
		if (!class_exists('XC_VM') || !method_exists('XC_VM', 'extensions_list')) {
			return null;
		}
		if ((new \ReflectionMethod('XC_VM', 'extensions_list'))->getNumberOfParameters() >= 3) {
			return [static fn(?string $rKey, array $rOpts): array => (array) \XC_VM::extensions_list(null, $rKey, $rOpts), null];
		}
		if (!method_exists('XC_VM', 'plugins_check')) {
			return null;
		}
		$rInstallId = (string) \XC_VM::install_id();
		return [
			static fn(?string $rKey): array => (array) \XC_VM::extensions_list(null, (string) $rKey),
			static fn(string $rSlug, string $rKey): array => (array) \XC_VM::plugins_check($rSlug, $rInstallId, $rKey),
		];
	}

	/** Forget every cached answer (after a store install, so the page shows it installed). */
	public static function forget(): void {
		foreach (glob(self::cacheDir() . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	/**
	 * The store's modules as the page lists them, in the store's order, each
	 * with whether it is free, bought or for sale, and what is installed of it here.
	 *
	 * @param list<array<string, mixed>> $rExtensions the catalogue's `extensions`
	 * @param list<array<string, mixed>> $rInstalled  ModuleManager::listModules()
	 * @return list<array<string, mixed>>
	 */
	public static function rows(array $rExtensions, array $rInstalled): array {
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
				'badge'             => $rPrice <= 0 ? 'free' : (($rExt['owned'] ?? false) === true ? 'purchased' : 'paid'),
				'price'             => $rPrice,
				'module'            => isset($rHere[$rSlug]) ? (string) $rHere[$rSlug]['name'] : '',
				'installed_version' => $rInstalled,
				'update_to'         => $rInstalled !== '' && $rVersion !== '' && version_compare($rVersion, $rInstalled, '>') ? $rVersion : '',
			];
		}
		return $rRows;
	}

	/**
	 * Search, sort and cut one page of a whole catalogue (an extension older than 2.4.0).
	 *
	 * @param list<array<string, mixed>>                                       $rExtensions
	 * @param array{page: int, per_page: int, search: string, sort: string} $rOptions
	 * @return array{ok: true, extensions: list<array<string, mixed>>, page: int, per_page: int, total: int, last_page: int}
	 */
	private static function page(array $rExtensions, array $rOptions): array {
		if ($rOptions['search'] !== '') {
			$rExtensions = array_values(array_filter($rExtensions, static fn(array $rExt): bool => stripos(
				($rExt['name'] ?? '') . ' ' . ($rExt['slug'] ?? '') . ' ' . ($rExt['description'] ?? ''),
				$rOptions['search']
			) !== false));
		}
		// ponytail: the old list has no downloads or dates, so popular/newest keep the store's order.
		$rDir = ['name' => 0, 'price_asc' => 1, 'price_desc' => -1][$rOptions['sort']] ?? null;
		if ($rDir !== null) {
			usort($rExtensions, static fn(array $a, array $b): int => ($rDir * ((float) ($a['price'] ?? 0) <=> (float) ($b['price'] ?? 0)))
				?: strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
		}
		$rTotal = count($rExtensions);
		return [
			'ok'         => true,
			'extensions' => array_slice($rExtensions, ($rOptions['page'] - 1) * $rOptions['per_page'], $rOptions['per_page']),
			'page'       => $rOptions['page'],
			'per_page'   => $rOptions['per_page'],
			'total'      => $rTotal,
			'last_page'  => max(1, (int) ceil($rTotal / $rOptions['per_page'])),
		];
	}

	/**
	 * A store answer from the cache when fresh, otherwise fetched; only a
	 * successful one is kept, so a failed look asks again next time.
	 *
	 * @param callable(): array<string, mixed> $rFetch
	 * @return array<string, mixed>
	 */
	private static function cached(string $rApiKey, string $rRequest, bool $rRefresh, callable $rFetch): array {
		$rFile = self::cacheDir() . hash('sha256', hash('sha256', $rApiKey) . "\0" . $rRequest) . '.json';
		if (!$rRefresh && is_file($rFile) && time() - (int) @filemtime($rFile) < self::TTL) {
			$rCached = json_decode((string) @file_get_contents($rFile), true);
			if (is_array($rCached)) {
				return $rCached;
			}
		}

		$rData = $rFetch();
		if (!$rData['ok']) {
			return $rData;
		}
		@mkdir(self::cacheDir(), 0755, true);
		// Bounded: every search makes a file, so expired ones go on each write.
		foreach (glob(self::cacheDir() . '*.json') ?: [] as $rOld) {
			if (time() - (int) @filemtime($rOld) >= self::TTL) {
				@unlink($rOld);
			}
		}
		$rTemp = $rFile . '.' . getmypid() . '.tmp';
		if (@file_put_contents($rTemp, (string) json_encode($rData, JSON_PRESERVE_ZERO_FRACTION), LOCK_EX) !== false) {
			@rename($rTemp, $rFile);
		}
		return $rData;
	}
}

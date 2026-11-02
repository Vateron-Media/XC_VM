<?php

namespace XcVm\Core\Module;

/**
 * SourceDriverRegistry — which module's {@see SourceDriverInterface} owns a
 * source URL, by its scheme.
 *
 * Filled lazily from the `source_drivers` manifest key of the loaded modules
 * (ModuleLoader::loadAll(), never bootAll(): the streaming entry point starts
 * streams without booting modules), once per process. A scheme core already
 * reads can't be claimed, and a scheme two modules claim goes to neither.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class SourceDriverRegistry {
	/**
	 * Schemes ffmpeg (or core) reads itself; no module may claim them, and none
	 * is ever refused. The union of `ffmpeg -protocols` inputs across the bundled
	 * 4.0/7.1/8.0 builds and common distro builds, plus rtsp(s) and mms, which
	 * ffmpeg reads through demuxers — a scheme missing here would stop existing
	 * streams that use it, so err on the side of listing it.
	 */
	public const CORE_SCHEMES = [
		'amqp', 'async', 'bluray', 'cache', 'concat', 'concatf', 'crypto', 'data', 'fd', 'ffrtmphttp', 'file',
		'ftp', 'gopher', 'gophers', 'hls', 'http', 'httpproxy', 'https', 'ipfs', 'ipns', 'mms', 'mmsh', 'mmst',
		'pipe', 'rist', 'rtmp', 'rtmpe', 'rtmps', 'rtmpt', 'rtmpte', 'rtmpts', 'rtp', 'rtsp', 'rtsps', 'sctp',
		'sftp', 'smb', 'srt', 'srtp', 'subfile', 'tcp', 'tee', 'tls', 'udp', 'udplite', 'unix', 'zmq',
	];

	/** @var array<string, array{driver: SourceDriverInterface, start_timeout: int, lb: bool}>|null scheme => entry */
	private static ?array $drivers = null;

	/** The driver owning this URL's scheme, or null (a core source, or no driver here). */
	public static function for(string $url): ?SourceDriverInterface {
		$scheme = self::scheme($url);
		return $scheme === null ? null : (self::all()[$scheme]['driver'] ?? null);
	}

	/**
	 * Why core must not run this source at all, or null: a scheme neither
	 * ffmpeg nor any driver on this node reads. ffmpeg would only fail on it and
	 * restart forever — typically a module source on a node without the module.
	 */
	public static function refusal(string $url): ?string {
		$scheme = self::scheme($url);
		if ($scheme === null || in_array($scheme, self::CORE_SCHEMES, true) || isset(self::all()[$scheme])) {
			return null;
		}
		return 'no source driver for ' . $scheme . ':// on this node';
	}

	/** Seconds the driver's module asked core to wait for a first output; 0 = core default. */
	public static function startTimeout(string $url): int {
		$scheme = self::scheme($url);
		return $scheme === null ? 0 : (self::all()[$scheme]['start_timeout'] ?? 0);
	}

	/** A module explicitly permits this source driver to produce on an LB. */
	public static function supportsLoadBalancer(string $url): bool {
		$scheme = self::scheme($url);
		return $scheme !== null && (self::all()[$scheme]['lb'] ?? false);
	}

	/** @return string[] Engine executable basenames of every registered driver. */
	public static function binaries(): array {
		return array_values(array_unique(array_map(static fn(array $entry): string => $entry['driver']->binary(), self::all())));
	}

	/**
	 * Register a driver directly (tests, or a caller that already holds one).
	 * Returns the schemes it could not take.
	 *
	 * @return string[]
	 */
	public static function register(SourceDriverInterface $driver, int $startTimeout = 0, bool $lb = false): array {
		self::$drivers ??= [];
		$rejected = [];
		foreach ($driver->schemes() as $scheme) {
			$scheme = strtolower((string) $scheme);
			if (!preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) || in_array($scheme, self::CORE_SCHEMES, true) || isset(self::$drivers[$scheme])) {
				$rejected[] = $scheme;
				continue;
			}
			self::$drivers[$scheme] = ['driver' => $driver, 'start_timeout' => max(0, $startTimeout), 'lb' => $lb];
		}
		return $rejected;
	}

	/**
	 * (Re)load the drivers of the modules under $modulesDir (null: the install's).
	 * A scheme claimed by two modules is dropped for both and logged.
	 */
	public static function discover(?string $modulesDir = null): void {
		self::$drivers = [];
		$owners = [];
		$contested = [];
		foreach (self::declaredDrivers($modulesDir) as [$module, $driver, $startTimeout, $lb]) {
			foreach (self::register($driver, $startTimeout, $lb) as $scheme) {
				if (isset($owners[$scheme])) {
					$contested[$scheme] = true;
					error_log("SourceDriverRegistry: {$scheme}:// is claimed by both '{$owners[$scheme]}' and '{$module}'; neither gets it");
				} else {
					error_log("SourceDriverRegistry: module '{$module}' cannot claim {$scheme}:// (invalid or read by core)");
				}
			}
			foreach ($driver->schemes() as $scheme) {
				$owners[strtolower((string) $scheme)] ??= $module;
			}
		}
		// Dropped only now: dropping at the first clash would hand the scheme to a third claimant.
		self::$drivers = array_diff_key(self::$drivers, $contested);
	}

	/** Forget every driver; the next lookup discovers again. */
	public static function reset(): void {
		self::$drivers = null;
	}

	/** @return array<string, array{driver: SourceDriverInterface, start_timeout: int, lb: bool}> */
	private static function all(): array {
		if (self::$drivers === null) {
			self::discover();
		}
		return self::$drivers ?? [];
	}

	/**
	 * Every driver the loaded modules declare, instantiated.
	 *
	 * @return list<array{0: string, 1: SourceDriverInterface, 2: int, 3: bool}> [module, driver, start_timeout, lb]
	 */
	private static function declaredDrivers(?string $modulesDir): array {
		$loader = new ModuleLoader();
		try {
			$loader->loadAll($modulesDir);
		} catch (\Throwable $e) {
			error_log('SourceDriverRegistry: module discovery failed: ' . $e->getMessage());
			return [];
		}
		$declared = [];
		foreach ($loader->getManifests() as $module => $manifest) {
			foreach ($manifest['source_drivers'] ?? [] as $class) {
				$driver = self::instantiate($module, (string) $class);
				if ($driver !== null) {
					$declared[] = [$module, $driver, (int) ($manifest['start_timeout'] ?? 0), (bool) ($manifest['lb_source_drivers'] ?? false)];
				}
			}
		}
		return $declared;
	}

	private static function instantiate(string $module, string $class): ?SourceDriverInterface {
		try {
			$driver = new $class();
		} catch (\Throwable $e) {
			error_log("SourceDriverRegistry: module '{$module}' source driver {$class} failed: " . $e->getMessage());
			return null;
		}
		if (!$driver instanceof SourceDriverInterface) {
			error_log("SourceDriverRegistry: module '{$module}' source driver {$class} does not implement SourceDriverInterface");
			return null;
		}
		return $driver;
	}

	private static function scheme(string $url): ?string {
		return preg_match('~^([A-Za-z][A-Za-z0-9+.-]*)://~', trim($url), $m) ? strtolower($m[1]) : null;
	}
}

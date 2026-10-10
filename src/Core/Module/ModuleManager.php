<?php

namespace XcVm\Core\Module;

use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Module\PackageInstalledEvent;
use XcVm\Core\Exception\Module\ModuleException;
use XcVm\Core\Exception\Module\ModuleNotFoundException;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Http\Router;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * ModuleManager — administrative operations with modules.
 *
 * Provides:
 * - listing module metadata and status
 * - install / uninstall
 * - enable / disable via config/modules.php
 * - upload zip + install
 *
 * @package XC_VM_Core_Module
 * @author  obscuremind <https://github.com/obscuremind>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleManager {
	/**
	 * The line module:migrate prints once the steps have run. Its exit status
	 * alone does not say so: PHP also ends with 0 on exit('message').
	 */
	public const STEPS_DONE = 'module:migrate: steps done';

	private string $modulesPath;

	private string $overridesPath;

	private string $archivesPath;

	private ?ServiceContainer $container;

	/**
	 * Initialize the module manager.
	 *
	 * @param string|null $modulesPath   Path to the modules directory.
	 * @param string|null $overridesPath Path to the config/modules.php overrides file.
	 * @param ServiceContainer|null $container Service container for DB access and DI.
	 */
	public function __construct(
		?string $modulesPath = null,
		?string $overridesPath = null,
		?ServiceContainer $container = null
	) {
		$this->modulesPath   = $modulesPath ?: (defined('MAIN_HOME') ? MAIN_HOME . 'Modules' : dirname(__DIR__, 2) . '/Modules');
		$this->overridesPath = $overridesPath ?: (defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php' : dirname(__DIR__, 2) . '/config/modules.php');
		// Sits beside the modules directory (same idiom as the .module_backups
		// area) so it follows an injected modulesPath instead of always resolving
		// under MAIN_HOME. Identical path in production, where modulesPath is
		// MAIN_HOME . 'Modules'.
		$this->archivesPath  = dirname($this->modulesPath) . '/modules_archives';
		$this->container     = $container;
	}

	/**
	 * Absolute path of the stored archive for a custom (non-store) module.
	 *
	 * MAIN keeps a copy of every uploaded module archive named
	 * {name}_{version}.zip so LB servers can pull it back over the internal
	 * system API (action=getFile). Because every server shares the same
	 * MAIN_HOME layout, the LB can reconstruct this exact path from the name +
	 * version carried in the install_module signal.
	 */
	public function archivePathFor(string $name, string $version): string {
		$name    = $this->sanitizeModuleName($name);
		$version = preg_replace('/[^0-9A-Za-z._\-]/', '', $version);
		return $this->archivesPath . '/' . $name . '_' . $version . '.zip';
	}

	/** @return object|null Database instance from the container, or null if unavailable. */
	private function getDb(): ?object {
		if ($this->container instanceof \XcVm\Core\Container\ServiceContainer && $this->container->has('db')) {
			return $this->container->get('db');
		}
		return null;
	}

	/**
	 * Absolute path of a module's directory on disk, resolving the
	 * `{name}_{hash5}` directory convention.
	 *
	 * A module's logical name (config key, class namespace) never carries the hash
	 * — sanitizeModuleName() forbids `_`. The directory does: `{name}_{hash5}` (5
	 * hex chars of hash_id), so two modules that share a name don't clash on disk.
	 * Resolution order: exact `{name}` (legacy / back-compat) → the first
	 * `{name}_*` directory that has a module.json → fall back to exact.
	 */
	private function modulePathFor(string $name): string {
		$name  = $this->sanitizeModuleName($name);
		$exact = $this->modulesPath . '/' . $name;
		if (is_dir($exact)) {
			return $exact;
		}
		foreach (glob($this->modulesPath . '/' . $name . '_*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (is_file($dir . '/module.json')) {
				return $dir;
			}
		}
		return $exact;
	}

	/**
	 * Directory name for a module: `{name}_{hash5}` where hash5 is the first 5 hex
	 * chars of its permanent hash_id. The name itself never contains `_`.
	 *
	 * A hash_id is mandatory — callers must run the manifest through ensureHashId()
	 * first so no module is ever placed in a hash-less directory. Passing an empty
	 * hash_id is a programming error and throws.
	 */
	private function moduleDirName(string $name, string $hashId): string {
		$name   = $this->sanitizeModuleName($name);
		$hashId = strtolower(preg_replace('/[^a-f0-9]/i', '', $hashId) ?? '');
		if ($hashId === '') {
			throw new \RuntimeException("module '{$name}' has no hash_id — cannot build directory name");
		}
		return $name . '_' . substr($hashId, 0, 5);
	}

	/**
	 * Ensure the module at $moduleDir has a permanent hash_id, generating and
	 * persisting one into its module.json when absent. Returns the 32-hex value.
	 *
	 * Every module must carry a hash_id: it forms the `{name}_{hash5}` directory
	 * suffix and is the module's stable identity. Uploaded or legacy modules that
	 * ship without one get a fresh random id written here (once, mirroring
	 * tools/gen-module-hashes.php); a valid existing id is immutable and left as-is.
	 */
	private function ensureHashId(string $moduleDir): string {
		$file = $moduleDir . '/module.json';
		$meta = json_decode((string) @file_get_contents($file), true);
		if (!is_array($meta)) {
			$meta = [];
		}
		$hash = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) ($meta['hash_id'] ?? '')) ?? '');

		if (strlen($hash) < 32) {
			$hash = bin2hex(random_bytes(16));
			$meta = $this->withHashIdAfterName($meta, $hash);
			@file_put_contents(
				$file,
				json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n"
			);
		}
		return $hash;
	}

	/**
	 * Return $meta with hash_id set immediately after the `name` key (or first when
	 * there is no name), dropping any pre-existing empty hash_id entry.
	 *
	 * @param array<string, mixed> $meta
	 * @return array<string, mixed>
	 */
	private function withHashIdAfterName(array $meta, string $hash): array {
		$out = [];
		foreach ($meta as $k => $v) {
			if ($k === 'hash_id') {
				continue;
			}
			$out[$k] = $v;
			if ($k === 'name') {
				$out['hash_id'] = $hash;
			}
		}
		if (!isset($out['hash_id'])) {
			return ['hash_id' => $hash] + $out;
		}
		return $out;
	}

	/**
	 * Copy an extracted module directory into modulesPath as `{name}_{hash5}`,
	 * replacing any existing install of the same module (one per name is active).
	 *
	 * @param string $moduleDir Extracted directory containing module.json.
	 * @return string Canonical module name that was placed.
	 */
	private function placeModuleFiles(string $moduleDir): string {
		$name = $this->sanitizeModuleName($this->manifestNameFromDir($moduleDir));
		// Before anything is replaced: an installed copy stays as it is.
		$this->assertCoreCompatible($moduleDir, $name);
		// Guarantee a hash_id (generating + persisting one when the upload lacks it)
		// so the module is always placed in a `{name}_{hash5}` directory, never bare.
		$hashId    = $this->ensureHashId($moduleDir);
		$targetDir = $this->modulesPath . '/' . $this->moduleDirName($name, $hashId);

		// A release that renamed the module: the copy installed under its old name.
		$this->adoptRenamedCopies($name, $hashId, $moduleDir);

		// Remove every other copy of this module (a legacy bare `{name}` directory,
		// or an install under a different hash). The source is skipped on purpose: a
		// platform pull extracts straight INTO the modules directory, and deleting
		// it here would destroy the very files being placed.
		$this->dropRivalModuleDirs($name, $moduleDir);

		if (realpath($targetDir) === realpath($moduleDir)) {
			return $name;
		}
		if (!@rename($moduleDir, $targetDir)) {
			$this->copyDirectory($moduleDir, $targetDir);
		}
		return $name;
	}

	/**
	 * Refuse a module the loader would leave out, or could not load anything
	 * with: one whose module.json it cannot use, whose `requires_core` rules out
	 * this core, or whose dependencies lead back to itself. A refused copy
	 * that already sits in modulesPath (the platform extension extracts there)
	 * is removed, so it is not left beside the installed one.
	 *
	 * @param string $moduleDir Directory holding the new module.json.
	 * @param string $name      Module name, for the message.
	 * @throws \RuntimeException When the manifest is unusable, the module needs another core,
	 *                           or it would close a dependency cycle.
	 */
	private function assertCoreCompatible(string $moduleDir, string $name): void {
		$meta   = json_decode((string) @file_get_contents($moduleDir . '/module.json'), true);
		$reason = ModuleLoader::manifestError($moduleDir . '/module.json', $name);
		$error  = $reason !== null
			? "has a module.json that cannot be loaded ({$reason})"
			: ModuleLoader::coreRequirementError((string) (is_array($meta) ? ($meta['requires_core'] ?? '') : ''));
		if ($error === null && is_array($meta)) {
			$cycle = $this->dependencyCycle($name, $meta);
			$error = $cycle === null ? null : "would close a dependency cycle ({$cycle})";
		}
		if ($error === null) {
			return;
		}
		if ($this->isInsideModulesPath($moduleDir)) {
			$this->deleteDirectory($moduleDir);
		}
		throw new \RuntimeException("Module '{$name}' {$error}.");
	}

	/**
	 * The dependency cycle a module with this manifest would close with the
	 * modules on disk, as `a -> b -> a`, or null when there is none.
	 *
	 * The loader stops on a cycle instead of leaving its modules out
	 * (ModuleLoader::resolveLoadOrder()), so one must not be put in place.
	 * Optional dependencies count, as they do for the load order; so does a
	 * module that is switched off, since switching it on would close the cycle.
	 *
	 * @param string $name Module name.
	 * @param array  $meta Its new module.json.
	 */
	private function dependencyCycle(string $name, array $meta): ?string {
		$names = static fn(array $manifest): array => array_map('trim', array_filter(
			array_merge($manifest['dependencies'] ?? [], $manifest['optional_dependencies'] ?? []),
			'is_string'
		));

		$requires = [];
		foreach ($this->listModules() as $module) {
			$requires[$module['name']] = $names($module);
		}
		$requires[$name] = $names($meta);

		// Depth-first from $name: only a path that comes back to it counts.
		$seen = [];
		$walk = function (string $node) use (&$walk, &$seen, $requires, $name): ?array {
			foreach ($requires[$node] as $next) {
				if ($next === $name) {
					return [$node, $next];
				}
				if (isset($requires[$next]) && !isset($seen[$next])) {
					$seen[$next] = true;
					$tail = $walk($next);
					if ($tail !== null) {
						return [$node, ...$tail];
					}
				}
			}
			return null;
		};

		$cycle = $walk($name);
		return $cycle === null ? null : implode(' -> ', $cycle);
	}

	/**
	 * The fetched module must be the SAME module (identity pinning: a repo/URL
	 * can't impersonate another module) and run on this core.
	 *
	 * @param string $moduleDir Extracted update.
	 * @param array  $manifest  The installed module.json.
	 * @param string $name
	 * @throws \RuntimeException
	 */
	private function assertSameModuleForThisCore(string $moduleDir, array $manifest, string $name): void {
		$newMeta = json_decode((string) @file_get_contents($moduleDir . '/module.json'), true);
		$newHash = is_array($newMeta) ? (string) ($newMeta['hash_id'] ?? '') : '';
		$ownHash = (string) ($manifest['hash_id'] ?? '');
		if ($ownHash !== '' && $newHash !== '' && !hash_equals($ownHash, $newHash)) {
			throw new \RuntimeException("hash_id mismatch — refusing to overwrite '{$name}' with a different module.");
		}
		$this->assertCoreCompatible($moduleDir, $name);
	}

	/**
	 * The Modules table's warning for a module built for another core (the
	 * loader skips it, ModuleLoader::discoverModules()).
	 *
	 * @param array $meta module.json
	 * @return string[]
	 */
	private static function coreWarnings(array $meta): array {
		$error = ModuleLoader::coreRequirementError((string) ($meta['requires_core'] ?? ''));
		return $error === null ? [] : ["Not loaded: it {$error}."];
	}

	/** Whether $dir lies inside modulesPath. */
	private function isInsideModulesPath(string $dir): bool {
		$realModules = realpath($this->modulesPath);
		$realDir     = realpath($dir);
		return $realModules !== false && $realDir !== false && str_starts_with($realDir, $realModules . '/');
	}

	/** Remove every on-disk copy of $name except $keep — one install per name. */
	private function dropRivalModuleDirs(string $name, string $keep): void {
		$keepReal = realpath($keep);
		foreach ($this->moduleDirsFor($name) as $dir) {
			if (realpath($dir) !== $keepReal) {
				$this->deleteDirectory($dir);
			}
		}
	}

	/**
	 * Hand an install made under a module's old name over to $name. A directory
	 * whose manifest carries the same hash_id under another name is this module
	 * before a rename: its config/modules.php entry (state, installed_version)
	 * moves to $name and the directory goes, so the loader never boots both and
	 * the schema is not installed a second time.
	 */
	private function adoptRenamedCopies(string $name, string $hashId, string $keep): void {
		$keepReal = realpath($keep);
		foreach (glob($this->modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			$meta = json_decode((string) @file_get_contents($dir . '/module.json'), true);
			$old  = is_array($meta) ? trim((string) ($meta['name'] ?? '')) : '';
			if ($old === '' || $old === $name || realpath($dir) === $keepReal
				|| strtolower((string) ($meta['hash_id'] ?? '')) !== $hashId
			) {
				continue;
			}
			$this->renameOverrides($old, $name);
			$this->deleteDirectory($dir);
		}
	}

	/** Move $old's config/modules.php entry to $new, unless $new already has one. */
	private function renameOverrides(string $old, string $new): void {
		$overrides = $this->readOverrides();
		if (!isset($overrides[$old])) {
			return;
		}
		$overrides[$new] ??= $overrides[$old];
		unset($overrides[$old]);
		$this->writeOverrides($overrides);
	}

	/**
	 * Every directory under modulesPath whose manifest declares $name.
	 *
	 * @return string[]
	 */
	private function moduleDirsFor(string $name): array {
		$out = [];
		foreach (glob($this->modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (is_file($dir . '/module.json') && $this->manifestNameFromDir($dir) === $name) {
				$out[] = $dir;
			}
		}
		return $out;
	}

	/**
	 * Retire legacy hash-less module directories: rename every bare `{name}`
	 * directory to the canonical `{name}_{hash5}`, generating a hash_id when the
	 * manifest lacks one.
	 *
	 * Older deployments (and modules placed before the hash convention) live in a
	 * bare `{name}` directory. This one-shot, idempotent migration — run on every
	 * `status` pass before anything scans the modules folder — brings them onto the
	 * `{name}_{hash5}` scheme so the legacy layout can be dropped. Already-hashed
	 * directories and non-module directories are skipped. When both a bare and a
	 * hashed copy of the same module exist, the stale bare copy is removed.
	 *
	 * @return string[] Canonical names of modules migrated this pass.
	 */
	public function migrateLegacyModuleDirs(): array {
		$migrated = [];
		foreach (glob($this->modulesPath . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
			if (!is_file($dir . '/module.json')) {
				continue;
			}
			$name = $this->sanitizeModuleName($this->manifestNameFromDir($dir));
			if ($name === '') {
				continue;
			}
			// A canonical `{name}_{hash5}` directory has a basename different from the
			// logical name; only a bare `{name}` directory is legacy.
			if (basename($dir) !== $name) {
				continue;
			}

			$hash   = $this->ensureHashId($dir);
			$target = $this->modulesPath . '/' . $this->moduleDirName($name, $hash);
			if ($target === $dir) {
				continue;
			}
			if (is_dir($target)) {
				// A hashed copy already exists — the bare directory is a stale dup.
				$this->deleteDirectory($dir);
			} else {
				@rename($dir, $target);
			}
			$migrated[] = $name;
		}
		return $migrated;
	}

	/**
	 * Remove on-disk copies and recorded state of modules that are now part of
	 * the core codebase (ModuleLoader::CORE_PROVIDED_MODULES).
	 *
	 * Upgraded panels may still carry the old module directory (any hash
	 * suffix); left in place it would boot alongside the core implementation
	 * and its commands/routes would collide.
	 *
	 * @return string[] Names that were purged.
	 */
	public function purgeCoreProvidedModules(): array {
		$purged = [];
		foreach (ModuleLoader::CORE_PROVIDED_MODULES as $name) {
			$dir = $this->modulePathFor($name);
			if (is_dir($dir) && is_file($dir . '/module.json')) {
				$this->deleteDirectory($dir);
				error_log("purgeCoreProvidedModules: removed stale module directory '" . basename($dir) . "' — '{$name}' now ships in core.");
				$purged[] = $name;
			}

			$overrides = $this->readOverrides();
			if (isset($overrides[$name])) {
				unset($overrides[$name]);
				$this->writeOverrides($overrides);
				if (!in_array($name, $purged, true)) {
					$purged[] = $name;
				}
			}
		}
		return $purged;
	}

	/**
	 * Names of currently-installed modules that declare $name as a dependency.
	 *
	 * @return string[]
	 */
	private function installedDependentsOf(string $name): array {
		$out = [];
		foreach ($this->listModules() as $module) {
			if (($module['installed_version'] ?? '') === '') {
				continue; // not installed — its requirements don't apply
			}
			if (in_array($name, $module['dependencies'] ?? [], true)) {
				$out[] = $module['name'];
			}
		}
		return $out;
	}

	/**
	 * Names of installed dependents that would actually stop working if $name
	 * were disabled.
	 *
	 * Used to guard disabling. Only a dependent that currently loads can break,
	 * and being nominally Enabled is not enough: ModuleLoader drops any module
	 * whose required dependency is unavailable and cascades that transitively
	 * (see ModuleLoader::pruneUnsatisfiableModules), so a dependent sitting on an
	 * already-broken chain is not running either. Counting it would make a module
	 * whose own dependency is disabled impossible to disable, for no runtime gain.
	 *
	 * @return string[]
	 */
	private function breakableDependentsOf(string $name): array {
		$out = [];
		foreach ($this->loadableModules() as $module) {
			if ($module['installed_version'] === '') {
				continue; // not installed — its requirements don't apply
			}
			if (in_array($name, $module['dependencies'], true)) {
				$out[] = $module['name'];
			}
		}
		return $out;
	}

	/**
	 * Modules that ModuleLoader would actually boot right now, keyed by name.
	 *
	 * @return array<string, array> listModules() entries that are enabled and
	 *                              whose required dependency chain is satisfied.
	 */
	private function loadableModules(): array {
		$enabled = [];
		foreach ($this->listModules() as $module) {
			if ($module['state']->isLoadable()) {
				$enabled[$module['name']] = $module;
			}
		}
		return $this->pruneUnsatisfiable($enabled);
	}

	/**
	 * Drop every module with a required dependency outside the set, repeating
	 * until it settles — the same transitive cascade ModuleLoader applies.
	 *
	 * @param  array<string, array> $modules Candidate modules keyed by name.
	 * @return array<string, array>
	 */
	private function pruneUnsatisfiable(array $modules): array {
		do {
			$removed = false;
			foreach ($modules as $name => $module) {
				foreach ($module['dependencies'] as $dependency) {
					if (!isset($modules[$dependency])) {
						unset($modules[$name]);
						$removed = true;
						break;
					}
				}
			}
		} while ($removed);

		return $modules;
	}

	/**
	 * Install any on-disk module that has never been installed.
	 *
	 * Nothing is fetched here: a module reaches the disk only when the operator
	 * installs it (the Modules page, the store, an uploaded archive).
	 *
	 * Bundled modules (e.g. ministra) are booted on every request but only get
	 * their install() / migrations run when explicitly installed. On a fresh
	 * panel nothing would create their tables, so this runs once during the
	 * migrate step (see StatusCommand) to provision them. It is idempotent:
	 * already-installed modules are skipped, and a module's up-migrations use
	 * CREATE TABLE IF NOT EXISTS so re-provisioning an existing panel is safe.
	 *
	 * Modules are installed in dependency order. Only admin-disabled modules are
	 * left untouched — a module whose previous install FAILED (or crashed mid-way,
	 * leaving it Installing) is retried here, so re-running `console.php status`
	 * self-heals once the root cause is fixed. Retrying is safe: the master schema
	 * uses CREATE TABLE IF NOT EXISTS.
	 *
	 * @return string[] Names of modules that were installed this pass.
	 */
	public function syncBundledModules(): array {
		// Retire any legacy hash-less {name} directory before anything scans the
		// modules folder, so every module ends up on the {name}_{hash5} scheme.
		$this->migrateLegacyModuleDirs();

		// Drop leftovers of modules whose functionality moved into the core
		// (e.g. tmdb): a stale on-disk copy would still boot and its commands
		// would collide with the core-registered ones.
		$this->purgeCoreProvidedModules();

		$modules   = $this->listModules();
		$installed = [];
		foreach ($modules as $module) {
			if (($module['installed_version'] ?? '') !== '') {
				$installed[$module['name']] = true;
			}
		}

		// Candidates: present on disk, not yet installed, not admin-disabled.
		// Failed/Installing (a never-completed install) are retried, not skipped.
		$pending = [];
		foreach ($modules as $module) {
			$name = $module['name'];
			if (isset($installed[$name])) {
				continue;
			}
			if (($module['state'] ?? null) === ModuleState::Disabled) {
				continue;
			}
			$pending[$name] = $module;
		}

		// Install in dependency order: a module installs once all its declared
		// dependencies are themselves installed.
		$done  = [];
		$guard = 0;
		while ($pending !== [] && $guard++ < 1000) {
			$progressed = false;
			foreach (array_keys($pending) as $name) {
				$ready = true;
				foreach ($pending[$name]['dependencies'] ?? [] as $dep) {
					if (!isset($installed[$dep])) {
						$ready = false;
						break;
					}
				}
				if (!$ready) {
					continue;
				}
				try {
					$this->installModule($name);
					$installed[$name] = true;
					$done[] = $name;
				} catch (\Throwable $e) {
					error_log("syncBundledModules: install '{$name}' failed: " . $e->getMessage());
				}
				unset($pending[$name]);
				$progressed = true;
			}
			if (!$progressed) {
				break; // unmet or circular dependencies — stop
			}
		}

		return $done;
	}

	/**
	 * List all installed modules with their metadata and status.
	 *
	 * Scans the modules directory for module.json files, merges with
	 * config/modules.php overrides, and returns sorted results.
	 *
	 * @return array<int, array{name: string, description: string, version: string, requires_core: string, environment: string, priority: int, dependencies: array, optional_dependencies: array, has_navbar: bool, has_settings: bool, enabled: bool, state: ModuleState, path: string, installed_version: string, source: string, previous_version: string, dependency_warnings: string[]}> Module list.
	 */
	public function listModules(): array {
		$overrides = $this->readOverrides();
		$items = [];

		$jsonFiles = $this->dedupeManifestFiles(glob($this->modulesPath . '/*/module.json') ?: []);

		// Pre-resolve each module's state by name so the dependency diagnostics
		// below can see the full set while building items.
		$stateByName = [];
		foreach ($jsonFiles as $jsonFile) {
			$meta    = json_decode((string) @file_get_contents($jsonFile), true) ?: [];
			// Key by the CANONICAL manifest name, not the `{name}_{hash5}` directory —
			// config/modules.php and `dependencies` both reference the logical name.
			$depName = (string) ($meta['name'] ?? basename(dirname($jsonFile)));
			$stateByName[$depName] = ModuleState::fromRaw(
				$overrides[$depName]['state'] ?? ($overrides[$depName]['enabled'] ?? null)
			);
		}

		foreach ($jsonFiles as $jsonFile) {
			$meta  = json_decode((string) @file_get_contents($jsonFile), true);
			$meta  = is_array($meta) ? $meta : [];
			$name  = (string) ($meta['name'] ?? basename(dirname($jsonFile))); // canonical name
			$state = $stateByName[$name] ?? ModuleState::fromRaw(null);

			// Names only: a module.json the loader leaves out may hold anything here.
			$dependencies = ModuleLoader::filterCoreProvidedDependencies(
				is_array($meta['dependencies'] ?? null) ? array_filter($meta['dependencies'], 'is_string') : []
			);

			// Flag a module that is nominally Enabled but won't actually load:
			// ModuleLoader skips it when a required dependency is missing or not
			// loadable (e.g. plex is Enabled but watch is Failed/Disabled). Mirrors
			// ModuleLoader::pruneUnsatisfiableModules(). A module built for another
			// core, or with a module.json the loader cannot use, is skipped the
			// same way (ModuleLoader::discoverModules()).
			$dependencyWarnings = self::coreWarnings($meta);
			$manifestError      = ModuleLoader::manifestError($jsonFile, $name);
			if ($manifestError !== null) {
				$dependencyWarnings[] = "Not loaded: it has a module.json that cannot be loaded ({$manifestError}).";
			}
			foreach ($dependencies as $dep) {
				if (!isset($stateByName[$dep])) {
					$dependencyWarnings[] = "Required dependency '{$dep}' is missing.";
				} elseif (!$stateByName[$dep]->isLoadable()) {
					$dependencyWarnings[] = "Required dependency '{$dep}' is not enabled ("
						. $stateByName[$dep]->value . ').';
				}
			}

			$items[] = [
				'name'                  => $name,
				'hash_id'               => (string) ($meta['hash_id'] ?? ''),
				'update'                => ModuleLoader::normalizeUpdateBlock($meta, $name),
				'description'           => $meta['description'] ?? '',
				'version'               => $meta['version'] ?? '',
				'requires_core'         => $meta['requires_core'] ?? '',
				'environment'           => $meta['environment'] ?? 'main',
				'priority'              => (int) ($meta['priority'] ?? 0),
				'dependencies'          => $dependencies,
				'optional_dependencies' => is_array($meta['optional_dependencies'] ?? null) ? $meta['optional_dependencies'] : [],
				'has_navbar'            => (bool) ($meta['has_navbar'] ?? false),
				'has_settings'          => (bool) ($meta['has_settings'] ?? false),
				'enabled'               => $state->isLoadable(),
				'state'                 => $state,
				'path'                  => dirname($jsonFile),
				'installed_version'     => $overrides[$name]['installed_version'] ?? '',
				'available_version'     => $overrides[$name]['available_version'] ?? '',
				'source'                => $overrides[$name]['source'] ?? '',
				'previous_version'      => $overrides[$name]['previous_version'] ?? '',
				'dependency_warnings'   => $dependencyWarnings,
			];
		}

		usort($items, function ($a, $b) {
			return strcmp($a['name'], $b['name']);
		});

		return $items;
	}

	/**
	 * Keep one manifest per module name, preferring the canonical directory.
	 *
	 * A module should occupy exactly one directory, but a legacy bare `{name}`
	 * copy can linger beside the canonical `{name}_{hash5}` one; listing both
	 * would show the module twice in the admin table. The hashed directory wins.
	 *
	 * @param string[] $jsonFiles
	 * @return string[]
	 */
	private function dedupeManifestFiles(array $jsonFiles): array {
		$byName = [];
		foreach ($jsonFiles as $jsonFile) {
			$dir  = dirname($jsonFile);
			$name = $this->manifestNameFromDir($dir);
			if (!isset($byName[$name]) || basename($dir) !== $name) {
				$byName[$name] = $jsonFile;
			}
		}
		return array_values($byName);
	}

	/**
	 * Install a module by name.
	 *
	 * Loads the module instance, runs install(), and enables it. Over an
	 * existing install, the deltas between the version its schema is at and
	 * the new one are applied first, as updateModule() does.
	 *
	 * A process that already loaded the module (a web request boots every
	 * enabled module first) keeps that class: install() and getMigrations()
	 * are then those of the version loaded. The schema files are always the
	 * ones on disk. A caller that has just replaced the files therefore goes
	 * through migrateReplaced().
	 *
	 * @param string $name Module name (lowercase, alphanumeric + hyphens).
	 * @throws \RuntimeException If the module cannot be loaded.
	 */
	public function installModule(string $name, ?string $version = null): void {
		$name = $this->sanitizeModuleName($name);
		$module = $this->loadModuleInstance($name);

		// Version priority: explicit $version (platform installs pass the
		// authoritative SaaS release version) → module.json manifest → the
		// module's hardcoded getVersion() (which can drift from the manifest).
		$targetVersion = $version ?? $this->manifestVersion($name) ?? $module->getVersion();
		$modulePath = $this->modulePathFor($name);

		// Capture the admin's on/off choice BEFORE the Installing transition
		// overwrites it, or updating a disabled module switches it back on.
		$rRestore = $this->stateAfterInstall($name);

		// Set when this is an install over an existing one (a store update or
		// rollback, an archive uploaded again): the version the schema is at.
		$rFrom = self::schemaVersion((array) ($this->readOverrides()[$name] ?? []));

		$this->setState($name, ModuleState::Installing);

		try {
			// The master schema leaves the tables of an existing install as they
			// are, so the deltas since that version run first; without them the
			// new version would be recorded over the old schema.
			if ($rFrom !== '') {
				$this->applyUpdateSteps($name, $this->pendingUpdateSteps($name, $module, $rFrom, (string) $targetVersion));
			}

			$db = $this->getDb() ?? DatabaseFactory::get();
			// Apply the module's master schema, then its own install() hook for any
			// non-SQL setup. NB: schema files are DDL (CREATE/ALTER), which
			// MySQL/MariaDB implicitly commit — a wrapping transaction gives no
			// rollback safety, and its rollback() on error throws "no active
			// transaction", masking the real SQL error. So run directly and let the
			// genuine failure propagate to the catch below (and into the logs).
			// A module without a master installs by replaying its deltas: over an
			// existing install the ones above are all that is due.
			if ($db !== null && ($rFrom === '' || is_file($modulePath . '/database.sql'))) {
				// Fresh install applies the module's master schema (database.sql).
				ModuleMigrator::install($modulePath, $db, (string) $targetVersion);
			}
			$module->install();
		} catch (\Throwable $e) {
			$this->setState($name, ModuleState::Failed);
			throw $e;
		}

		// writeState, not setState: restoring Disabled is an internal lifecycle
		// transition and must not be second-guessed by the dependents guard.
		$this->writeState($name, $rRestore);
		$this->recordInstalledVersion($name, $targetVersion);
	}

	/**
	 * Uninstall a module by name.
	 *
	 * Runs uninstall() and disables the module.
	 *
	 * @param string $name Module name.
	 * @throws \RuntimeException If the module cannot be loaded.
	 */
	public function uninstallModule(string $name): void {
		$name = $this->sanitizeModuleName($name);
		$this->assertNoInstalledDependents($name, 'uninstall');

		$module = $this->loadModuleInstance($name);

		// The module's own uninstall() hook runs first (it clears the data/rows
		// it created), then the module's schema is torn down via its single
		// teardown file (database_drop.sql). A failure between the two would
		// otherwise leave the module recorded as installed and Enabled with
		// half-deleted data, so mark it Failed and let the admin see it.
		try {
			$module->uninstall();
			$db = $this->getDb() ?? DatabaseFactory::get();
			if ($db !== null) {
				ModuleMigrator::uninstall($this->modulePathFor($name), $db);
			}
		} catch (\Throwable $e) {
			$this->setState($name, ModuleState::Failed);
			throw $e;
		}

		$this->clearInstalledVersion($name);
		$this->setState($name, ModuleState::Disabled);
	}

	/**
	 * Refuse an action that would strip a module out from under installed
	 * dependents (e.g. plex depends on watch — watch cannot be removed under it).
	 *
	 * Unlike the disable guard this counts every INSTALLED dependent regardless of
	 * state: a disabled dependent still owns rows that reference this module's
	 * tables, and uninstalling drops them.
	 *
	 * @param string $verb Lowercase action name used in the message ('uninstall', 'delete').
	 */
	private function assertNoInstalledDependents(string $name, string $verb): void {
		$dependents = $this->installedDependentsOf($name);
		if ($dependents === []) {
			return;
		}
		throw new \RuntimeException(
			"Cannot {$verb} '{$name}': still required by " . implode(', ', $dependents)
			. '. ' . ucfirst($verb) . ' ' . (count($dependents) === 1 ? 'it' : 'them') . ' first.'
		);
	}

	/**
	 * Fully delete a module: uninstall it, then remove its directory from disk and
	 * its override entry from config/modules.php.
	 *
	 * Unlike uninstallModule() — which drops the tables and disables the module but
	 * leaves its files on disk so it stays listed and re-installable — this removes
	 * the module entirely. For a BUNDLED module (shipped in the deploy) the files
	 * come back on the next panel update; deletion is still honoured until then.
	 *
	 * Order matters: uninstall runs FIRST (drop tables + uninstall() hook) while the
	 * files are still on disk (the teardown SQL and the module class live there);
	 * only after a clean uninstall are the files removed, so no orphaned tables are
	 * left behind. A failing uninstall aborts the delete. The dependents guard is
	 * enforced up front.
	 *
	 * @param string $name Module name.
	 * @throws \RuntimeException If an installed dependent still requires the module.
	 */
	public function deleteModule(string $name): void {
		$name = $this->sanitizeModuleName($name);

		// Same guard as uninstall: refuse while an installed dependent needs it.
		$this->assertNoInstalledDependents($name, 'delete');

		// Read the manifest (for LB propagation) BEFORE the files are removed.
		$manifest = [];
		try {
			$manifest = $this->readModuleManifest($name);
		} catch (\Throwable $e) {
			// No readable manifest — LB propagation just skips below.
		}

		// Step 1 — uninstall FIRST, while the module's files are still on disk:
		// run its uninstall() hook and drop its tables (teardown SQL + module class
		// both live in the directory). A failure aborts the delete and propagates,
		// so the files are never removed with orphaned tables left behind.
		$this->uninstallModule($name);

		// Step 2 — remove the files, stored archives and the config override.
		$this->deleteModuleFilesOnly($name);

		// Step 3 — propagate the deletion to every LB node that received this module.
		$this->distributeDeletionToLoadBalancers($name, $manifest);
	}

	/**
	 * Remove a module's files WITHOUT touching the database.
	 *
	 * Used on LB nodes (which share MAIN's database — dropping tables there would
	 * delete MAIN's data) and as the file-removal step of deleteModule(). Removes
	 * the module directory, its stored archives, and its config/modules.php entry.
	 *
	 * @param string $name Module name.
	 */
	public function deleteModuleFilesOnly(string $name): void {
		$name = $this->sanitizeModuleName($name);

		$modulePath = $this->modulePathFor($name);
		if (is_dir($modulePath)) {
			$this->deleteDirectory($modulePath);
		}

		// Remove any stored marketplace archives (name_<version>.zip).
		foreach (glob($this->archivesPath . '/' . $name . '_*.zip') ?: [] as $rArchive) {
			@unlink($rArchive);
		}

		// Drop the config/modules.php override entry entirely.
		$overrides = $this->readOverrides();
		if (isset($overrides[$name])) {
			unset($overrides[$name]);
			$this->writeOverrides($overrides);
		}
	}

	/**
	 * Tell every load balancer that received this module to delete it too.
	 *
	 * Mirrors distributeToLoadBalancers(): only MAIN dispatches, and only for
	 * modules that were distributed (environment lb/any). LB nodes act on the
	 * `delete_module` signal via RootSignalsCronJob → `console.php module:delete`
	 * (files-only — never a DB drop on the shared MAIN database).
	 *
	 * @param string $name     Module name.
	 * @param array  $manifest The module's manifest (read before deletion).
	 */
	private function distributeDeletionToLoadBalancers(string $name, array $manifest): void {
		// Cheap manifest check first — a MAIN-only module was never on any LB, so
		// return before touching config (isLoadBalancer() reads via the extension).
		$environment = strtolower((string) ($manifest['environment'] ?? 'main'));
		if (!in_array($environment, ['lb', 'any'], true)) {
			return; // MAIN-only — LB never had it
		}
		if ($this->isLoadBalancer()) {
			return;
		}
		$db = $this->getDb();
		if ($db === null) {
			return;
		}

		$payload = json_encode(['action' => 'delete_module', 'name' => $name]);

		$db->query('SELECT `id` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 AND `enabled` = 1;');
		$rServerIDs = [];
		foreach ($db->get_rows() as $rRow) {
			$rServerIDs[] = intval($rRow['id']);
		}
		foreach ($rServerIDs as $rServerID) {
			NodeActions::send($rServerID, $payload, $db);
		}
	}

	/**
	 * Update a module, running only the incremental migrations needed.
	 *
	 * Reads the recorded installed_version from config/modules.php.
	 * If no version is recorded (legacy install), falls back to full installModule().
	 * If already at the current version, does nothing.
	 * Otherwise runs all getMigrations() entries with version > installedVersion
	 * (or the later version the schema is already at, see schemaVersion())
	 * and version <= module->getVersion(), in ascending semver order.
	 *
	 * @param string $name Module name.
	 */
	public function updateModule(string $name): void {
		$name        = $this->sanitizeModuleName($name);
		$overrides   = $this->readOverrides();
		$fromVersion = $overrides[$name]['installed_version'] ?? null;

		if ($fromVersion === null) {
			$this->installModule($name);
			return;
		}

		$module    = $this->loadModuleInstance($name);
		$toVersion = (string) ($this->manifestVersion($name) ?? $module->getVersion());

		if (version_compare($fromVersion, $toVersion, '>=')) {
			return;
		}

		// As installModule(): the state an attempt that failed left (Failed) goes
		// once the steps have run, and a module switched off stays off. Without
		// it a module updated at the second attempt stayed Failed, and unloaded.
		$rRestore = $this->stateAfterInstall($name);
		$this->applyUpdateSteps($name, $this->pendingUpdateSteps($name, $module, self::schemaVersion($overrides[$name]), $toVersion));
		$this->writeState($name, $rRestore);
		$this->recordInstalledVersion($name, $toVersion);
	}

	/**
	 * installModule() or updateModule() for a module whose files this process
	 * has just replaced.
	 *
	 * PHP keeps the class it loaded first. A process that had loaded the module
	 * before (a web request boots every enabled module first) would run the
	 * install() and getMigrations() of the version it replaced, so the steps
	 * then run in a console.php process of their own (module:migrate), started
	 * as this user, which loads the class from the files now on disk. That
	 * process does not boot the module first (ModuleLoader::stepsRunHere()):
	 * the steps come before the new version's boot(), as on a first install.
	 * What it recorded in config/modules.php is read back here. Unless it
	 * printed STEPS_DONE and ended with status 0, this throws what it printed,
	 * so the caller puts the previous files back as it does when a step fails
	 * in this process.
	 *
	 * A manager of other directories than the panel's own runs the steps
	 * itself: console.php would run them on the panel's.
	 *
	 * @param string      $name    Module name (already sanitized).
	 * @param string      $action  'install' or 'update'.
	 * @param string|null $version install: the version to record, see installModule().
	 * @throws \RuntimeException When a step fails or the process does not run.
	 */
	private function migrateReplaced(string $name, string $action, ?string $version = null): void {
		$ownPaths = defined('MAIN_HOME') && defined('CONFIG_PATH') && defined('PHP_BIN')
			&& $this->modulesPath === MAIN_HOME . 'Modules'
			&& $this->overridesPath === CONFIG_PATH . 'modules.php';

		if (!$ownPaths || !(new ModuleLoader())->isDeclared($name, $this->modulePathFor($name))) {
			if ($action === 'update') {
				$this->updateModule($name);
			} else {
				$this->installModule($name, $version);
			}
			return;
		}

		// ModuleLoader::stepsRunHere() reads the module's name off this line.
		$command = [PHP_BIN, MAIN_HOME . 'console.php', 'module:migrate', $action, $name];
		if ($version !== null) {
			$command[] = $version;
		}
		[$status, $output] = ProcessRunner::capture($command);

		// The other process rewrote config/modules.php: drop the copy OPcache
		// holds for this one (see writeOverrides()).
		if (function_exists('opcache_invalidate')) {
			opcache_invalidate($this->overridesPath, true);
		}

		$output = trim($output);
		if ($status !== 0 || !str_contains($output, self::STEPS_DONE)) {
			throw new \RuntimeException($output !== '' ? $output : "module:migrate {$action} {$name} ended with status {$status}");
		}
	}

	/**
	 * Run each version's update steps, advancing the recorded version only once a
	 * version is fully applied.
	 *
	 * The watermark moves per COMPLETED version rather than once at the end, so a
	 * failure half-way leaves the module Failed at the last version that actually
	 * landed and a retry resumes from there. Without that, a retry replays deltas
	 * that already ran — and a non-idempotent one (`ALTER TABLE ADD COLUMN`) then
	 * fails forever, wedging the module's updates.
	 *
	 * @param array<string, list<callable>> $steps version => steps, ascending.
	 */
	private function applyUpdateSteps(string $name, array $steps): void {
		try {
			foreach ($steps as $version => $versionSteps) {
				foreach ($versionSteps as $step) {
					$step();
				}
				$this->recordInstalledVersion($name, $version);
			}
		} catch (\Throwable $e) {
			$this->setState($name, ModuleState::Failed);
			throw $e;
		}
	}

	/**
	 * Everything still to run to get from $from to $to, grouped by version.
	 *
	 * Merges the two migration systems — file deltas and programmatic callables —
	 * into one ascending timeline so a version's schema change and its code hook
	 * are applied together and watermarked together.
	 *
	 * @return array<string, list<callable>> version => steps, ascending.
	 */
	private function pendingUpdateSteps(string $name, ModuleInterface $module, string $from, string $to): array {
		$steps = $this->pendingSchemaSteps($this->modulePathFor($name), $from, $to);
		foreach ($this->pendingCallableSteps($module, $from, $to) as $version => $step) {
			$steps[$version][] = $step;
		}
		uksort($steps, 'version_compare');
		return $steps;
	}

	/**
	 * File-based schema deltas in (`$from`, `$to`], one step per version.
	 *
	 * @return array<string, list<callable>>
	 */
	private function pendingSchemaSteps(string $modulePath, string $from, string $to): array {
		$db = $this->getDb() ?? DatabaseFactory::get();
		if ($db === null) {
			return [];
		}

		$steps = [];
		foreach (ModuleMigrator::pending($modulePath, $from, $to) as $version => $file) {
			$steps[$version] = [static fn() => ModuleMigrator::applyFile($db, $file)];
		}
		return $steps;
	}

	/**
	 * Programmatic migrations declared by the module in (`$from`, `$to`].
	 *
	 * @return array<string, callable>
	 */
	private function pendingCallableSteps(ModuleInterface $module, string $from, string $to): array {
		if (!$module instanceof MigratableInterface) {
			return [];
		}

		$steps = [];
		foreach ($module->getMigrations() as $version => $callable) {
			if (version_compare($version, $from, '>') && version_compare($version, $to, '<=')) {
				$steps[$version] = fn() => $this->runMigration($callable);
			}
		}
		return $steps;
	}

	/** Run one programmatic migration, in a transaction when the handler supports it. */
	private function runMigration(callable $callable): void {
		$db = $this->getDb();
		if ($db !== null && method_exists($db, 'transactional')) {
			$db->transactional(fn() => $callable($this->container));
			return;
		}
		$callable($this->container);
	}

	/**
	 * Update a module by fetching new files from its declared source, then running
	 * migrations. This is what the panel's "Update" button triggers (P4).
	 *
	 *  - bundled  : files already ship with the panel → migrations only (updateModule()).
	 *  - platform : delegated to the store flow (downloadFromPlatform — backup/restore/LB inside).
	 *  - git/url  : download archive → verify `hash_id` → backup → replace files → migrate →
	 *               restore on failure → distribute to LB.
	 *
	 * Identity pinning: for git/url the fetched module.json `hash_id` must equal the
	 * installed one — a repo/URL cannot impersonate another module or hijack a rename.
	 *
	 * @param string $name Module name.
	 * @return string|null Version now on disk after the update, or null when the
	 *                     source had nothing newer (stale available_version cleared).
	 * @throws \RuntimeException on download/verify/apply failure (files rolled back).
	 */
	public function updateModuleFromSource(string $name): ?string {
		$name      = $this->sanitizeModuleName($name);
		$manifest  = $this->readModuleManifest($name);
		$overrides = $this->readOverrides();

		$update    = ModuleLoader::normalizeUpdateBlock($manifest, $name);
		$installed = (string) ($overrides[$name]['installed_version'] ?? '');
		$source    = $update['source'];

		// bundled — the panel already replaced the files; just catch the schema up.
		if ($source === 'bundled') {
			$this->updateModule($name);
			$this->recordAvailableVersion($name, null);
			return $this->manifestVersion($name);
		}

		// platform — reuse the full store flow (self-contained rollback + LB fan-out).
		if ($source === 'platform') {
			$apiKey = (string) (SettingsManager::get('platform_api_key') ?? '');
			$this->downloadFromPlatform(($update['slug'] !== '' ? $update['slug'] : $name), '', $apiKey);
			$this->recordAvailableVersion($name, null);
			return $this->manifestVersion($name);
		}

		// git / url — fetch, verify, apply.
		$checker = new ModuleUpdateChecker();
		$version = $checker->latestAvailable([
			'update'            => $update,
			'version'           => (string) ($manifest['version'] ?? ''),
			'installed_version' => $installed,
		]);
		if ($version === null && $checker->lastError() !== null) {
			// Source unreachable (e.g. GitHub API rate limit) — fail loudly instead
			// of silently doing nothing while the caller reports "updated".
			throw new \RuntimeException(
				"Cannot resolve the latest version of '{$name}' from its {$source} source: " . $checker->lastError()
			);
		}
		if ($version === null || ($installed !== '' && version_compare($version, $installed, '<='))) {
			// Nothing newer at the source — the recorded available_version is stale
			// (already updated, or the release was pulled). Clear it so the Update
			// button disappears instead of reappearing forever.
			$this->recordAvailableVersion($name, null);
			return null;
		}

		[$downloadUrl, $expectedMd5] = $this->resolveSourceDownload($update, $version);
		if ($downloadUrl === '') {
			throw new \RuntimeException("No download URL resolved for module '{$name}' (source '{$source}').");
		}

		$archive = (string) @tempnam(sys_get_temp_dir(), 'xc_modupd_');
		if ($archive === '') {
			throw new \RuntimeException('Unable to create a temp file for the module download.');
		}
		$tempBase = rtrim(sys_get_temp_dir(), '/') . '/xc_modupd_' . bin2hex(random_bytes(8));

		try {
			$this->downloadToFile($downloadUrl, $archive);
			if ($expectedMd5 !== '' && !hash_equals(strtolower($expectedMd5), (string) md5_file($archive))) {
				throw new \RuntimeException('Checksum mismatch on the downloaded module archive.');
			}

			$this->extractArchive($archive, $tempBase);
			$moduleDir = $this->resolveExtractedModuleDir($tempBase);

			$this->assertSameModuleForThisCore($moduleDir, $manifest, $name);

			$targetDir = $this->modulePathFor($name);
			$rEntry    = $this->readOverrides()[$name] ?? [];
			$rState    = ModuleState::fromRaw($rEntry['state'] ?? ($rEntry['enabled'] ?? null));
			$backupDir = $this->backupModuleDir($name, $targetDir);
			try {
				$this->copyDirectory($moduleDir, $targetDir);
				$this->migrateReplaced($name, 'update'); // incremental migrations to the new manifest version

				$fresh          = $this->readModuleManifest($name);
				$resolvedVer    = (string) ($fresh['version'] ?? $version);
				$this->recordAvailableVersion($name, null);

				// Keep a local archive so LB nodes can pull it (getFile), then fan out.
				$this->storeModuleArchive($archive, $name, $resolvedVer);
				$this->distributeToLoadBalancers($name, $fresh, 'local', $resolvedVer);

				if ($backupDir !== null) {
					$this->deleteDirectory($backupDir);
				}

				// A release that renamed the module landed in the old directory and
				// config entry — both move to the new name (identity was pinned above).
				$newName = $this->sanitizeModuleName($this->manifestNameFromDir($targetDir));
				if ($newName !== $name) {
					$this->renameOverrides($name, $newName);
					$renamedDir = $this->modulesPath . '/' . $this->moduleDirName($newName, $this->ensureHashId($targetDir));
					if (!@rename($targetDir, $renamedDir)) {
						$this->copyDirectory($targetDir, $renamedDir);
						$this->deleteDirectory($targetDir);
					}
				}

				return $resolvedVer;
			} catch (\Throwable $e) {
				$this->restoreModuleBackup($name, $targetDir, $backupDir, $installed !== '' ? $installed : null, $rState);
				throw new \RuntimeException("Update of '{$name}' failed — rolled back: " . $e->getMessage(), 0, $e);
			}
		} finally {
			@unlink($archive);
			$this->deleteDirectory($tempBase);
		}
	}

	/**
	 * Resolve the download URL (+ optional expected md5) for a git/url source.
	 *
	 * git : release asset `module.tar.gz` at the tag == $version; md5 (if any)
	 *       comes from the release's `hashes.md5` via GitHubReleases::getAssetHash().
	 * url : re-fetch the `version.json` for its `download` (https) and optional `md5`.
	 *
	 * @return array{0:string,1:string} [downloadUrl, expectedMd5] — url '' if unresolved.
	 */
	private function resolveSourceDownload(array $update, string $version): array {
		if (($update['source'] ?? '') === 'git') {
			if (!preg_match('~github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$~i', (string) ($update['repository'] ?? ''), $m)) {
				return ['', ''];
			}
			$asset = 'module.tar.gz'; // convention: module repo release ships this asset
			$url   = "https://github.com/{$m[1]}/{$m[2]}/releases/download/{$version}/{$asset}";
			$md5   = '';
			try {
				$channel = in_array((string) ($update['channel'] ?? 'stable'), ['beta', 'unstable'], true) ? 'beta' : 'stable';
				$md5 = (new GitHubReleases($m[1], $m[2], $channel))->getAssetHash($version, $asset) ?? '';
			} catch (\Throwable $e) {
				// no hash available → download proceeds unverified
			}
			return [$url, $md5];
		}

		if (($update['source'] ?? '') === 'url') {
			$data = json_decode($this->httpGetString((string) ($update['url'] ?? '')), true);
			$dl   = is_array($data) ? trim((string) ($data['download'] ?? '')) : '';
			$md5  = is_array($data) ? trim((string) ($data['md5'] ?? '')) : '';
			if (stripos($dl, 'https://') !== 0) {
				$dl = '';
			}
			return [$dl, $md5];
		}

		return ['', ''];
	}

	/** cURL GET a small https resource to a string ('' on failure/non-https). */
	private function httpGetString(string $url): string {
		if (stripos($url, 'https://') !== 0) {
			return '';
		}
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_USERAGENT      => 'XC_VM-ModuleManager',
		]);
		$body = curl_exec($ch);
		curl_close($ch);
		return is_string($body) ? $body : '';
	}

	/** Download an https URL straight to $dest; throws on non-https / HTTP error. */
	private function downloadToFile(string $url, string $dest): void {
		// Shared streaming primitive (see CurlClient::downloadToFile); kept as a
		// thin wrapper so existing call sites and error text stay stable.
		CurlClient::downloadToFile($url, $dest);
	}

	/**
	 * Set the lifecycle state of a module in config/modules.php.
	 *
	 * When state is Enabled the 'state' key is removed entirely (clean default).
	 * When state is anything else the string value is persisted as 'state'.
	 *
	 * @param string      $name  Module name.
	 * @param ModuleState $state Target lifecycle state.
	 */
	public function setState(string $name, ModuleState $state): void {
		$name = $this->sanitizeModuleName($name);

		// Refuse to disable a module that a still-working dependent relies on
		// (e.g. plex requires watch — watch cannot be disabled under it, or
		// ModuleLoader would skip plex on the next boot). Mirrors the guard in
		// uninstallModule(). Scoped strictly to a deliberate Disabled transition:
		// the internal lifecycle states (Installing, Failed) are also non-loadable
		// but are set by installModule() itself and must never be blocked.
		if ($state === ModuleState::Disabled) {
			$dependents = $this->breakableDependentsOf($name);
			if ($dependents !== []) {
				throw new \RuntimeException(
					"Cannot disable '{$name}': still required by " . implode(', ', $dependents)
					. '. Disable ' . (count($dependents) === 1 ? 'it' : 'them') . ' first.'
				);
			}
		}

		$this->writeState($name, $state);
	}

	/**
	 * The state a (re)install should leave the module in.
	 *
	 * An admin who switched the module off expects it to stay off across a
	 * reinstall or a store update; everything else — never installed, or one of
	 * the transient Installing / Failed states — means Enabled. Reads the raw
	 * override rather than listModules() precisely so those transient states
	 * cannot be mistaken for a deliberate "disabled".
	 */
	private function stateAfterInstall(string $name): ModuleState {
		$rRaw = $this->readOverrides()[$name]['state'] ?? null;

		return $rRaw === ModuleState::Disabled->value ? ModuleState::Disabled : ModuleState::Enabled;
	}

	/**
	 * Persist a lifecycle state without the dependents guard.
	 *
	 * That guard belongs to a deliberate admin "disable", not to the transitions
	 * install/uninstall drive themselves: putting a module back into the state it
	 * already had must never fail because something depends on it.
	 *
	 * @param string      $name  Module name (already sanitized).
	 * @param ModuleState $state Target lifecycle state.
	 */
	private function writeState(string $name, ModuleState $state): void {
		$overrides = $this->readOverrides();

		if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
			$overrides[$name] = [];
		}

		// Remove any legacy bool 'enabled' key — we use 'state' now.
		unset($overrides[$name]['enabled']);

		if ($state === ModuleState::Enabled) {
			// Enabled is the default: clean up the key so the file stays minimal.
			unset($overrides[$name]['state']);
			if (empty($overrides[$name])) {
				unset($overrides[$name]);
			}
		} else {
			$overrides[$name]['state'] = $state->value;
		}

		$this->writeOverrides($overrides);
	}

	/**
	 * Enable or disable a module in config/modules.php.
	 *
	 * @deprecated Use setState(name, ModuleState::Enabled / ModuleState::Disabled) instead.
	 *
	 * @param string $name    Module name.
	 * @param bool   $enabled True to enable, false to disable.
	 */
	public function setEnabled(string $name, bool $enabled): void {
		$this->setState($name, $enabled ? ModuleState::Enabled : ModuleState::Disabled);
	}

	/**
	 * Upload a zip archive and install the module from it.
	 *
	 * Extracts the archive to a temp directory, validates structure,
	 * copies to the modules path, and runs installModule().
	 *
	 * @param string $zipFilePath Path to the uploaded zip file.
	 * @return string Installed module name.
	 * @throws \RuntimeException If extraction or installation fails.
	 * @throws \InvalidArgumentException If the zip file is not found.
	 */
	public function uploadAndInstall(string $zipFilePath): string {
		if (!is_file($zipFilePath)) {
			throw new \InvalidArgumentException('Uploaded zip file not found.');
		}

		$tempBase = rtrim(sys_get_temp_dir(), '/') . '/xc_module_' . bin2hex(random_bytes(8));
		if (!@mkdir($tempBase, 0755, true) && !is_dir($tempBase)) {
			throw new \RuntimeException('Unable to create temporary directory.');
		}

		try {
			$this->extractArchive($zipFilePath, $tempBase);

			$moduleDir  = $this->resolveExtractedModuleDir($tempBase);
			$moduleName = $this->sanitizeModuleName($this->manifestNameFromDir($moduleDir));

			// An archive uploaded over a module already on disk replaces its files.
			// Once the archive is accepted that copy is set aside, and put back as it
			// was, version and state, if the new one cannot be placed or installed.
			$this->assertCoreCompatible($moduleDir, $moduleName);
			$previous      = $this->readOverrides()[$moduleName] ?? [];
			$previousState = ModuleState::fromRaw($previous['state'] ?? ($previous['enabled'] ?? null));
			$previousDir   = $this->modulePathFor($moduleName);
			$backupDir     = $this->backupModuleDir($moduleName, $previousDir);
			try {
				$this->placeModuleFiles($moduleDir);
				$this->migrateReplaced($moduleName, 'install');
			} catch (\Throwable $e) {
				if ($backupDir !== null) {
					$this->dropRivalModuleDirs($moduleName, $backupDir);
					$this->restoreModuleBackup($moduleName, $previousDir, $backupDir, $previous['installed_version'] ?? null);
					$this->writeState($moduleName, $previousState);
				}
				throw $e;
			}
			if ($backupDir !== null) {
				$this->deleteDirectory($backupDir);
			}

			// Keep a copy of the uploaded archive so it can be redistributed to
			// LB servers (which have no access to the store for custom modules).
			$manifest = $this->readModuleManifest($moduleName);
			$version  = (string) ($manifest['version'] ?? '0.0.0');
			$this->storeModuleArchive($zipFilePath, $moduleName, $version);

			// Custom (non-store) module — no store rollback available.
			$this->recordModuleSource($moduleName, 'local');

			// If the manifest targets load balancers, push the archive to every LB.
			$this->distributeToLoadBalancers($moduleName, $manifest, 'local', $version);

			return $moduleName;
		} finally {
			$this->deleteDirectory($tempBase);
		}
	}

	/**
	 * LB-side: install a custom (non-store) module from a local archive,
	 * deploying its FILES ONLY — no DB migrations (the shared DB was already
	 * migrated by MAIN).
	 *
	 * @param string $zipFilePath Path to the .zip archive fetched from MAIN.
	 * @return string Installed module name.
	 */
	public function deployFromArchiveFilesOnly(string $zipFilePath): string {
		if (!is_file($zipFilePath)) {
			throw new \InvalidArgumentException('Module archive not found.');
		}

		$tempBase = rtrim(sys_get_temp_dir(), '/') . '/xc_module_' . bin2hex(random_bytes(8));
		if (!@mkdir($tempBase, 0755, true) && !is_dir($tempBase)) {
			throw new \RuntimeException('Unable to create temporary directory.');
		}

		try {
			$this->extractArchive($zipFilePath, $tempBase);

			$moduleDir  = $this->resolveExtractedModuleDir($tempBase);
			$moduleName = $this->placeModuleFiles($moduleDir);
			$targetDir  = $this->modulePathFor($moduleName);

			// Keep the archive locally too, so this LB can re-seed if needed.
			$manifest = $this->readModuleManifest($moduleName);
			$version  = (string) ($manifest['version'] ?? '0.0.0');
			$this->storeModuleArchive($zipFilePath, $moduleName, $version);

			$this->recordInstalledVersion($moduleName, $version);
			$this->setState($moduleName, ModuleState::Enabled);
			$this->hotReloadSafe($moduleName, $targetDir);

			return $moduleName;
		} finally {
			$this->deleteDirectory($tempBase);
		}
	}

	/**
	 * Copy a module archive into the local archives directory as
	 * {name}_{version}.zip (idempotent — overwrites any previous copy).
	 */
	private function storeModuleArchive(string $sourceZip, string $name, string $version): void {
		$dest = $this->archivePathFor($name, $version);
		$dir  = dirname($dest);
		if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
			throw new \RuntimeException('Unable to create modules archive directory.');
		}
		if (realpath($sourceZip) !== realpath($dest) && !@copy($sourceZip, $dest)) {
			throw new \RuntimeException('Unable to store module archive.');
		}
		@chmod($dest, 0644);
	}

	/**
	 * Read a module's manifest (module.json) from the modules directory.
	 *
	 * @return array Decoded manifest, or [] if missing/invalid.
	 */
	private function readModuleManifest(string $name): array {
		$name = $this->sanitizeModuleName($name);
		// Resolve the real {name}_{hash5} (or legacy bare) directory — reading the
		// bare path would miss the manifest for a hash-suffixed module.
		$file = $this->modulePathFor($name) . '/module.json';
		if (!is_file($file)) {
			return [];
		}
		$data = json_decode((string) @file_get_contents($file), true);
		return is_array($data) ? $data : [];
	}

	/**
	 * The module's version as declared in its module.json, or null if absent.
	 * Authoritative source for the installed version (the module's getVersion()
	 * is hardcoded and may drift from the shipped manifest).
	 *
	 * @param string $name Module name / directory.
	 */
	private function manifestVersion(string $name): ?string {
		$v = $this->readModuleManifest($name)['version'] ?? null;
		return (is_string($v) && $v !== '') ? $v : null;
	}

	/**
	 * Tell every enabled load balancer to install a module, if the manifest
	 * targets the LB environment. Runs only on MAIN.
	 *
	 * Inserts one `signals` row per LB with custom_data:
	 *   {"action":"install_module","source":"platform|local","name":"…","version":"…"}
	 * The LB's root signals daemon picks it up and runs `console.php module:install`.
	 *
	 * @param string $name     Module name / slug.
	 * @param array  $manifest Decoded module.json.
	 * @param string $source   'platform' (pull from store) or 'local' (pull archive from MAIN).
	 * @param string $version  Module version.
	 */
	private function distributeToLoadBalancers(string $name, array $manifest, string $source, string $version): void {
		// Only MAIN distributes. LB installs are file-only and never re-dispatch.
		if ($this->isLoadBalancer()) {
			return;
		}

		$environment = strtolower((string) ($manifest['environment'] ?? 'main'));
		if (!in_array($environment, ['lb', 'any'], true)) {
			return; // module is MAIN-only — nothing to distribute
		}

		$db = $this->getDb();
		if ($db === null) {
			return;
		}

		$payload = (string) json_encode($this->lbInstallPayload($name, $source, $version));

		// LB servers are streaming servers (server_type = 0) that are not the
		// main panel and are enabled. Collect ids first so the INSERT loop does
		// not clobber the active result set.
		$db->query('SELECT `id` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 AND `enabled` = 1;');
		$rServerIDs = [];
		foreach ($db->get_rows() as $rRow) {
			$rServerIDs[] = intval($rRow['id']);
		}

		foreach ($rServerIDs as $rServerID) {
			NodeActions::send($rServerID, $payload, $db);
		}
	}

	/**
	 * The install_module payload a load balancer gets. A custom module's
	 * carries its archive's size and SHA-256, so a node that pulls the
	 * archive the legacy way (getFile) installs only these bytes.
	 *
	 * @return array{action: string, source: string, name: string, version: string, size?: int, sha256?: string}
	 */
	public function lbInstallPayload(string $name, string $source, string $version): array {
		$rPayload = [
			'action'  => 'install_module',
			'source'  => $source === 'platform' ? 'platform' : 'local',
			'name'    => $name,
			'version' => $version,
		];
		$rArchive = $this->archivePathFor($name, $version);
		if ($rPayload['source'] === 'local' && is_file($rArchive)) {
			$rPayload['size']   = (int) filesize($rArchive);
			$rPayload['sha256'] = (string) hash_file('sha256', $rArchive);
		}
		return $rPayload;
	}

	/**
	 * @return bool True when running on a load balancer (is_lb = 1 in config).
	 */
	private function isLoadBalancer(): bool {
		if (class_exists(ConfigReader::class)) {
			return (bool) ConfigReader::get('is_lb');
		}
		if (defined('SERVER_TYPE')) {
			// SERVER_TYPE is an external runtime constant (not define()d in-tree);
			// use constant() so static analysis doesn't flag an undefined constant.
			// Mirrors ModuleLoader::detectEnvironment().
			return constant('SERVER_TYPE') === 'lb';
		}
		return false;
	}

	/**
	 * Download a module from the SaaS platform and install it.
	 *
	 * Delegates the full download → key-unwrap → extract flow to the
	 * XC_VM C extension, then runs installModule() to register it.
	 * Fires PackageInstalledEvent and hot-reloads the module into the
	 * current ServiceContainer without requiring a PHP-FPM restart.
	 *
	 * @param string      $slug    Module slug as listed on the platform.
	 * @param string      $version Exact version string (e.g. "1.2.0"), or '' for the latest.
	 * @param string|null $apiKey  Modules API key; null or '' for a free module.
	 * @throws \RuntimeException If the C extension is missing, download fails, or install fails.
	 */
	public function downloadFromPlatform(string $slug, string $version = '', ?string $apiKey = null): void {
		$slug = $this->sanitizeModuleName($slug);
		// Resolve the module's ACTUAL directory (canonical `{name}_{hash5}`, or a
		// legacy bare one) rather than assuming the bare form: otherwise the backup
		// below finds nothing and the pulled copy lands beside the existing install
		// instead of replacing it.
		$targetDir = $this->modulePathFor($slug);

		// Snapshot current state so a failed (re)install rolls back cleanly. We
		// MOVE the existing module aside (outside modulesPath so the loader never
		// scans it) — this both gives a clean dir for the new extract and a
		// restore point. installed_version is captured for the version record.
		$rEntry      = $this->readOverrides()[$slug] ?? [];
		$prevVersion = $rEntry['installed_version'] ?? null;
		$rState      = ModuleState::fromRaw($rEntry['state'] ?? ($rEntry['enabled'] ?? null));
		$backupDir   = $this->backupModuleDir($slug, $targetDir);

		try {
			$result = $this->pullFilesFromPlatform($slug, $version, $apiKey);
			// The extension extracts wherever it likes; fold that into the canonical
			// `{name}_{hash5}` directory and track it as the rollback target below.
			$this->placeModuleFiles($result['path']);
			$modulePath      = $this->modulePathFor($slug);
			$targetDir       = $modulePath;
			$resolvedVersion = (string) ($result['version'] ?: $version);

			// Acquire the per-machine ionCube license BEFORE installModule(): if the
			// platform encoded the module with --with-license, the loader rejects the
			// encoded files at require-time unless a valid .lic is already present.
			// No-op when the platform has licensing disabled.
			$this->acquireModuleLicense($slug, $apiKey);

			// Record the version the PLATFORM served (authoritative for store
			// installs); the module's module.json/getVersion() may lag behind.
			$this->migrateReplaced($slug, 'install', $resolvedVersion);

			EventDispatcher::dispatch(new PackageInstalledEvent(
				slug:        $result['module'],
				version:     $resolvedVersion,
				path:        $modulePath,
				installedAt: time(),
			));

			$this->hotReload($slug, $modulePath);

			// Mark as store-installed and remember the version the Rollback
			// button should target. The previous version is authoritative from
			// the PLATFORM (it holds release history); it may be null when the
			// platform has no prior approved version. (NB: $prevVersion below is
			// the LOCAL pre-install version, used only for failure rollback.)
			$this->recordPlatformSource($slug, $result['previous_version'] ?? null);

			// If the manifest targets load balancers, tell every LB to pull the
			// same module from the platform with its own install_id (MAIN-only).
			$this->distributeToLoadBalancers($slug, $this->readModuleManifest($slug), 'platform', $resolvedVersion);

			// Success — drop the rollback snapshot.
			if ($backupDir !== null) {
				$this->deleteDirectory($backupDir);
			}
		} catch (\Throwable $e) {
			$restored = $this->restoreModuleBackup($slug, $targetDir, $backupDir, $prevVersion, $rState);
			throw new \RuntimeException(
				"Platform install of '{$slug}' failed"
				. ($restored
					? ' — rolled back to previous version' . ($prevVersion ? " {$prevVersion}" : '')
					: '')
				. ': ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * Roll a store-installed module back to its previously installed version.
	 *
	 * Uses the `previous_version` recorded at the last store install (still
	 * available on the platform within its retention window). Re-installs that
	 * exact version, then clears `previous_version` (one-shot rollback). LBs are
	 * re-synced to the rolled-back version via the normal distribution path.
	 *
	 * @param string      $slug   Module slug.
	 * @param string|null $apiKey Platform API key.
	 * @throws \RuntimeException If the module was not installed from the store or
	 *                          has no recorded previous version.
	 */
	public function rollbackFromPlatform(string $slug, ?string $apiKey = null): void {
		$slug      = $this->sanitizeModuleName($slug);
		$overrides = $this->readOverrides();
		$entry     = $overrides[$slug] ?? [];

		if (($entry['source'] ?? '') !== 'platform') {
			throw new \RuntimeException("Module '{$slug}' was not installed from the store; cannot roll back.");
		}
		$previous = $entry['previous_version'] ?? '';
		if ($previous === '') {
			throw new \RuntimeException("No previous version recorded for '{$slug}'.");
		}

		// Re-installs the previous version (explicit, not "latest").
		$this->downloadFromPlatform($slug, $previous, $apiKey);

		// One-shot: drop the previous-version marker so the rollback button hides
		// until the next successful update creates a new restore point.
		$this->clearPreviousVersion($slug);
	}

	/** Mark a module as store-installed and (optionally) record the replaced version. */
	private function recordPlatformSource(string $name, ?string $previousVersion): void {
		$overrides = $this->readOverrides();
		if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
			$overrides[$name] = [];
		}
		$overrides[$name]['source'] = 'platform';
		if ($previousVersion !== null && $previousVersion !== '') {
			$overrides[$name]['previous_version'] = $previousVersion;
		}
		$this->writeOverrides($overrides);
	}

	/** Record the install source for a module (e.g. 'platform' or 'local'). */
	private function recordModuleSource(string $name, string $source): void {
		$overrides = $this->readOverrides();
		if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
			$overrides[$name] = [];
		}
		$overrides[$name]['source'] = $source;
		$this->writeOverrides($overrides);
	}

	/**
	 * Fetch and install the per-machine ionCube license for a module.
	 *
	 * Generates this machine's ionCube server-data, asks the platform to mint a
	 * hardware-bound + expiring .lic (XC_VM::module_license) and writes it next to
	 * the encoded files (the name the encoder's --with-license expects).
	 *
	 * Best-effort: when the platform has licensing disabled, the module is not
	 * entitled, or the loader/extension lacks the needed functions, it silently
	 * writes nothing — so unlicensed-encoded modules still install normally.
	 *
	 * @return bool True if a .lic was written.
	 */
	private function acquireModuleLicense(string $slug, ?string $apiKey): bool {
		if (!class_exists('XC_VM') || !function_exists('ioncube_server_data')) {
			return false;
		}

		$serverData = @ioncube_server_data();
		if (!is_string($serverData) || $serverData === '') {
			error_log("ModuleManager: license skipped for '{$slug}': ioncube_server_data() empty");
			return false;
		}

		try {
			$res = \XC_VM::module_license($slug, base64_encode($serverData), ($apiKey ?? '') !== '' ? $apiKey : null);
		} catch (\Throwable $e) {
			error_log("ModuleManager: license request failed for '{$slug}': " . $e->getMessage());
			return false;
		}

		if (!is_array($res) || empty($res['ok'])) {
			$reason = is_array($res) ? ($res['reason'] ?? 'unknown') : 'no_response';
			error_log("ModuleManager: license NOT issued for '{$slug}': {$reason}"
				. (($apiKey ?? '') === '' ? ' (no Modules API key: a paid module needs one)' : ''));
			return false;
		}

		$licName  = basename((string) ($res['license_name'] ?? 'module.lic'));
		$licBytes = base64_decode((string) ($res['license'] ?? ''), true);
		if ($licName === '' || $licBytes === false || $licBytes === '') {
			error_log("ModuleManager: license for '{$slug}' empty/undecodable in response");
			return false;
		}

		$dir = $this->modulePathFor($slug);
		if (!is_dir($dir)) {
			error_log("ModuleManager: module dir missing for '{$slug}': {$dir}");
			return false;
		}

		if (@file_put_contents($dir . '/' . $licName, $licBytes) === false) {
			error_log("ModuleManager: failed to write license {$dir}/{$licName} (права?)");
			return false;
		}
		return true;
	}

	/**
	 * Re-issue the per-machine license for an installed platform module — call
	 * before expiry, or after a "license expired/invalid" load failure. The SaaS
	 * refusing to mint (lapsed subscription / revoked) is the effective kill.
	 *
	 * @return bool True if a fresh .lic was written.
	 */
	public function renewModuleLicense(string $slug, ?string $apiKey = null): bool {
		return $this->acquireModuleLicense($this->sanitizeModuleName($slug), $apiKey);
	}

	/** Remove the recorded previous version for a module. */
	private function clearPreviousVersion(string $name): void {
		$overrides = $this->readOverrides();
		if (isset($overrides[$name]['previous_version'])) {
			unset($overrides[$name]['previous_version']);
			$this->writeOverrides($overrides);
		}
	}

	/**
	 * Move an installed module directory to a backup location outside the
	 * modules path (so ModuleLoader never scans it). Returns the backup path,
	 * or null if the module was not installed. Falls back to copy+delete if the
	 * move (rename) fails.
	 */
	private function backupModuleDir(string $slug, string $targetDir): ?string {
		if (!is_dir($targetDir)) {
			return null;
		}
		$base = dirname($this->modulesPath) . '/.module_backups';
		if (!is_dir($base) && !@mkdir($base, 0755, true) && !is_dir($base)) {
			// Cannot create a backup area — copy in place as a last resort is not
			// possible; proceed without rollback rather than block the install.
			return null;
		}
		$backupDir = $base . '/' . $slug . '_' . bin2hex(random_bytes(4));
		if (@rename($targetDir, $backupDir)) {
			return $backupDir;
		}
		// rename failed (e.g. cross-device) — copy then remove the original.
		$this->copyDirectory($targetDir, $backupDir);
		$this->deleteDirectory($targetDir);
		return $backupDir;
	}

	/**
	 * Restore a module backup created by backupModuleDir() after a failed
	 * install, re-recording the previous installed version. Returns true if a
	 * backup was restored.
	 *
	 * The files go back, the schema does not: the version the deltas reached
	 * stays on record (see recordInstalledVersion()), so the next attempt
	 * resumes after them.
	 *
	 * $rState is the state to leave the module in: the one it had before the
	 * failed install, so a module that was not loaded (switched off, or left
	 * failed by an earlier attempt) does not start loading.
	 */
	private function restoreModuleBackup(string $slug, string $targetDir, ?string $backupDir, ?string $prevVersion, ModuleState $rState = ModuleState::Enabled): bool {
		// Remove the (possibly partial) failed install first.
		$realModules = realpath($this->modulesPath);
		$realTarget  = realpath($targetDir) ?: $targetDir;
		if ($realModules && str_starts_with($realTarget, $realModules . '/')) {
			$this->deleteDirectory($targetDir);
		}

		if ($backupDir === null || !is_dir($backupDir)) {
			return false;
		}

		if (!@rename($backupDir, $targetDir)) {
			$this->copyDirectory($backupDir, $targetDir);
			$this->deleteDirectory($backupDir);
		}

		if ($prevVersion !== null) {
			$this->recordInstalledVersion($slug, $prevVersion);
		}
		$this->writeState($slug, $rState);
		return true;
	}

	/**
	 * LB-side: download a store module from the platform and deploy its FILES
	 * ONLY — no DB migrations.
	 *
	 * LB servers share MAIN's database, so the module's schema migrations have
	 * already been applied by MAIN. Here we only need the decrypted code on the
	 * LB so it loads under environment=lb/any. Each LB registers and downloads
	 * with its OWN install_id.
	 *
	 * @param string      $slug    Module slug on the platform.
	 * @param string      $version Exact version string.
	 * @param string|null $apiKey  Shared platform API key (from settings).
	 */
	public function deployFromPlatformFilesOnly(string $slug, string $version, ?string $apiKey = null): void {
		$result = $this->pullFilesFromPlatform($slug, $version, $apiKey);
		$this->placeModuleFiles($result['path']);
		$modulePath = $this->modulePathFor($slug);

		EventDispatcher::dispatch(new PackageInstalledEvent(
			slug:        $result['module'],
			version:     $result['version'],
			path:        $modulePath,
			installedAt: time(),
		));

		$this->recordInstalledVersion($slug, (string) ($result['version'] ?: $version));
		$this->setState($slug, ModuleState::Enabled);
		$this->hotReloadSafe($slug, $modulePath);
	}

	/**
	 * Register the panel (with a key) and pull (download + decrypt + extract) a
	 * module's files from the platform via the C extension. Does NOT run installModule().
	 *
	 * @return array{ok: bool, module: string, version: string, path: string}
	 * @throws \RuntimeException On a missing extension, registration or download failure.
	 */
	private function pullFilesFromPlatform(string $slug, string $version, ?string $apiKey): array {
		if (!class_exists('XC_VM')) {
			throw new \RuntimeException('XC_VM extension is not loaded. Install xcvm_core.so and enable it in php.ini.');
		}

		$apiKey = ($apiKey ?? '') !== '' ? $apiKey : null;

		// A paid module's key is wrapped in an X25519 SealedBox for *this* panel's
		// public key, so the panel registers first (an idempotent upsert keyed by
		// install_id, which also keeps the server's public key matching our current
		// secret key). Registration needs the Modules API key; a free module
		// installs without either.
		if ($apiKey !== null) {
			$reg = \XC_VM::panel_register($apiKey);
			if (!is_array($reg) || empty($reg['ok'])) {
				$regReason = $reg['message'] ?? ($reg['reason'] ?? 'unknown');
				throw new \RuntimeException("Panel registration with platform failed for module '{$slug}': {$regReason}");
			}
		}

		$result = \XC_VM::module_install($slug, $version, $apiKey);

		if (!is_array($result) || empty($result['ok'])) {
			$reason = $result['error'] ?? ($result['reason'] ?? 'unknown');
			if ($reason === 'not_entitled') {
				throw new \RuntimeException('This module is paid: buy it on xcvm.tech and set the Modules API key (Settings → API).');
			}
			throw new \RuntimeException("Platform download failed for module '{$slug}': {$reason}");
		}

		return [
			'ok'               => true,
			'module'           => $result['module'] ?? $slug,
			'version'          => $result['version'] ?? $version,
			'path'             => $result['path'] ?? $this->modulePathFor($slug),
			// Previous approved version reported by the platform (for the
			// Rollback button). May be absent/null when there is no prior version.
			'previous_version' => $result['previous_version'] ?? null,
		];
	}

	/**
	 * Hot-reload a newly installed module into the running ServiceContainer.
	 *
	 * Loads and boots the module within the current request so it becomes
	 * immediately usable without a PHP-FPM restart.
	 *
	 * @param string $slug       Module name.
	 * @param string $modulePath Absolute path to the module directory.
	 */

	/**
	 * Hot-reload only when serving a web request, never crash the caller.
	 *
	 * On an LB the install runs from a root CLI cron where there is no live
	 * request to keep warm and no router/container wired — the module simply
	 * loads from disk on the next request. So skip hot-reload under CLI and
	 * swallow any error.
	 */
	private function hotReloadSafe(string $slug, string $modulePath): void {
		if (PHP_SAPI === 'cli') {
			return;
		}
		try {
			$this->hotReload($slug, $modulePath);
		} catch (\Throwable $e) {
			error_log("ModuleManager: hot-reload skipped for '{$slug}': " . $e->getMessage());
		}
	}

	/**
	 * Hot-reload a freshly installed module without restarting PHP-FPM.
	 *
	 * Loads the module, boots it and registers its routes against the live
	 * container so it becomes usable within the current request lifecycle.
	 *
	 * @param string $slug       Module slug.
	 * @param string $modulePath Filesystem path to the module.
	 */
	private function hotReload(string $slug, string $modulePath): void {
		$container = ServiceContainer::getInstance();

		$loader = new ModuleLoader();
		if (!$loader->load($slug, $modulePath)) {
			return;
		}

		$router = $container->getOrDefault('router');
		$loader->bootAll($container, $router instanceof Router ? $router : null);
	}

	/**
	 * Persist the installed version for a module in config/modules.php.
	 *
	 * The recorded version follows the files, and may go back (a rollback, an
	 * update whose files were restored). The schema does not: when the version
	 * goes back, the one its deltas reached stays beside it as `schema_version`.
	 *
	 * @param string $name    Module name.
	 * @param string $version Installed version string.
	 */
	private function recordInstalledVersion(string $name, string $version): void {
		$overrides = $this->readOverrides();
		if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
			$overrides[$name] = [];
		}
		$schema = self::schemaVersion($overrides[$name]);
		$overrides[$name]['installed_version'] = $version;
		unset($overrides[$name]['schema_version']);
		if ($schema !== '' && version_compare($schema, $version, '>')) {
			$overrides[$name]['schema_version'] = $schema;
		}
		$this->writeOverrides($overrides);
	}

	/**
	 * The version a module's schema is at: the recorded version, or the later
	 * one its deltas reached before that went back. Deltas are due from here.
	 *
	 * @param array $entry The module's entry in config/modules.php.
	 * @return string '' when the module is not installed.
	 */
	private static function schemaVersion(array $entry): string {
		$installed = (string) ($entry['installed_version'] ?? '');
		$schema    = (string) ($entry['schema_version'] ?? '');
		return version_compare($schema, $installed, '>') ? $schema : $installed;
	}

	/**
	 * Remove the recorded installed version for a module from config/modules.php.
	 *
	 * @param string $name Module name.
	 */
	private function clearInstalledVersion(string $name): void {
		$overrides = $this->readOverrides();
		if (!isset($overrides[$name]['installed_version'])) {
			return;
		}
		unset($overrides[$name]['installed_version'], $overrides[$name]['schema_version']);
		if (empty($overrides[$name])) {
			unset($overrides[$name]);
		}
		$this->writeOverrides($overrides);
	}

	/**
	 * Record (or clear) the latest available version for a module in
	 * config/modules.php — written by the update-availability check
	 * (ModuleUpdatesCronJob) and read back by listModules()/the UI to show the
	 * Update button only when a newer version actually exists at the source.
	 *
	 * A null/empty version clears the flag (nothing newer, or not checkable).
	 *
	 * @param string      $name    Module name.
	 * @param string|null $version Latest available version, or null to clear.
	 */
	public function recordAvailableVersion(string $name, ?string $version): void {
		$name    = $this->sanitizeModuleName($name);
		$version = $version !== null ? trim($version) : '';

		$overrides = $this->readOverrides();
		$current   = (string) ($overrides[$name]['available_version'] ?? '');
		if ($current === $version) {
			return; // no change — avoid a needless config rewrite
		}

		if ($version === '') {
			unset($overrides[$name]['available_version']);
			if (isset($overrides[$name]) && empty($overrides[$name])) {
				unset($overrides[$name]);
			}
		} else {
			$overrides[$name]['available_version'] = $version;
		}
		$this->writeOverrides($overrides);
	}

	/**
	 * Look for a newer version of every installed module at its declared source
	 * and record it (or clear a stale flag). A source that does not answer keeps
	 * the flag it had: clearing it would hide a real update until the next check.
	 * ModuleUpdatesCronJob runs it weekly, the modules page on demand.
	 *
	 * @return array{found: list<string>, failed: list<string>, checked: int}
	 *         found: "name (installed -> latest)"; failed: "name: reason"
	 */
	public function checkUpdates(?ModuleUpdateChecker $rChecker = null): array {
		$rChecker ??= new ModuleUpdateChecker();
		$rResult  = ['found' => [], 'failed' => [], 'checked' => 0];

		foreach ($this->listModules() as $rModule) {
			$rInstalled = (string) ($rModule['installed_version'] ?? '');
			if ($rInstalled === '') {
				continue;
			}
			$rResult['checked']++;
			$rLatest = $rChecker->latestAvailable($rModule);

			if ($rLatest !== null && version_compare($rLatest, $rInstalled, '>')) {
				$this->recordAvailableVersion($rModule['name'], $rLatest);
				$rResult['found'][] = $rModule['name'] . ' (' . $rInstalled . ' -> ' . $rLatest . ')';
			} elseif ($rChecker->lastError() !== null) {
				$rResult['failed'][] = $rModule['name'] . ': ' . $rChecker->lastError();
			} else {
				$this->recordAvailableVersion($rModule['name'], null);
			}
		}

		return $rResult;
	}

	/**
	 * Load and return a module instance by name.
	 *
	 * @param string $name Module name.
	 * @return object Module instance implementing ModuleInterface.
	 * @throws \RuntimeException If the module cannot be loaded or instantiated.
	 */
	private function loadModuleInstance(string $name) {
		$name = $this->sanitizeModuleName($name);
		$loader = new ModuleLoader();
		// Resolve the real directory ({name}_{hash5}, or a legacy bare {name}) — the
		// module rarely lives at the bare path, so passing that would fail to find
		// the class file for a freshly-uploaded module and abort its install.
		$ok = $loader->load($name, $this->modulePathFor($name));
		if (!$ok) {
			throw new ModuleNotFoundException('Cannot load module: ' . $name);
		}

		$module = $loader->getModule($name);
		if (!$module instanceof \XcVm\Core\Module\ModuleInterface) {
			throw new ModuleNotFoundException('Module instance is not available: ' . $name);
		}

		return $module;
	}

	/**
	 * Validate and sanitize a module name.
	 *
	 * @param string $name Raw module name.
	 * @return string Sanitized module name.
	 * @throws \InvalidArgumentException If the name is invalid.
	 */
	private function sanitizeModuleName(string $name): string {
		$name = trim($name);
		if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $name)) {
			throw new ModuleException('Invalid module name.');
		}
		return $name;
	}

	/**
	 * Read module overrides from config/modules.php.
	 *
	 * @return array<string, array> Module overrides keyed by module name.
	 */
	private function readOverrides(): array {
		return $this->rOverridesRead = $this->loadOverrides();
	}

	/** What readOverrides() last answered: writeOverrides() puts only what changed since onto the file. */
	private ?array $rOverridesRead = null;

	/** config/modules.php as it is on disk now. */
	private function loadOverrides(): array {
		if (!file_exists($this->overridesPath)) {
			return [];
		}

		self::revalidate($this->overridesPath);
		$data = require $this->overridesPath;
		return is_array($data) ? $data : [];
	}

	/**
	 * $rMine's changes since $rBase, put onto $rTheirs: a module's entry field
	 * by field, an entry that was removed removed. What $rMine left as it read
	 * it stays as $rTheirs has it.
	 *
	 * @param array<string, mixed> $rBase   The file as this manager read it.
	 * @param array<string, mixed> $rMine   What it wants written.
	 * @param array<string, mixed> $rTheirs The file as it is now.
	 * @return array<string, mixed>
	 */
	public static function mergeOverrides(array $rBase, array $rMine, array $rTheirs): array {
		foreach (array_keys($rBase + $rMine) as $rName) {
			$rWas = $rBase[$rName] ?? null;
			$rIs = $rMine[$rName] ?? null;
			if ($rWas === $rIs) {
				continue;
			}
			if (!is_array($rIs) || !is_array($rTheirs[$rName] ?? null)) {
				if ($rIs === null) {
					unset($rTheirs[$rName]);
				} else {
					$rTheirs[$rName] = $rIs;
				}
				continue;
			}
			$rWas = is_array($rWas) ? $rWas : [];
			foreach (array_keys($rWas + $rIs) as $rField) {
				if (!array_key_exists($rField, $rIs)) {
					unset($rTheirs[$rName][$rField]);
				} elseif (!array_key_exists($rField, $rWas) || $rWas[$rField] !== $rIs[$rField]) {
					$rTheirs[$rName][$rField] = $rIs[$rField];
				}
			}
		}
		return $rTheirs;
	}

	/**
	 * Drop OPcache's copy of a state file that changed on disk, before a require.
	 *
	 * nginx spreads the panel over four PHP-FPM masters, each with an OPcache of
	 * its own (revalidate_freq 20). writeOverrides() invalidates only the master
	 * that wrote, so the other three served, and booted, the old module state
	 * for up to 20 s. Not forced: recompiled only when the mtime changed.
	 */
	public static function revalidate(string $rPath): void {
		if (function_exists('opcache_invalidate')) {
			opcache_invalidate($rPath);
		}
	}

	/**
	 * Write module overrides to config/modules.php atomically.
	 *
	 * Writes to a sibling temp file then renames into place, so concurrent
	 * requests can never read a partially-written file.
	 *
	 * @param array $overrides Module overrides to persist.
	 * @throws \RuntimeException If the file cannot be written or renamed.
	 */
	private function writeOverrides(array $overrides): void {
		// One writer at a time, and each writes what it changed since it read
		// the file onto the file as it is now. It was written back whole, as it
		// was read: of two processes changing it at once (a module job, a cron,
		// `status`, a load balancer's install) the second undid the first's
		// change. The lock is on this file, read-only: none to create.
		$rLock = @fopen(__FILE__, 'r');
		if ($rLock !== false) {
			flock($rLock, LOCK_EX);
		}
		try {
			if ($this->rOverridesRead !== null) {
				$overrides = self::mergeOverrides($this->rOverridesRead, $overrides, $this->loadOverrides());
			}
			$this->storeOverrides($overrides);
			$this->rOverridesRead = $overrides;
		} finally {
			if ($rLock !== false) {
				flock($rLock, LOCK_UN);
				fclose($rLock);
			}
		}
	}

	/** Replace config/modules.php with $overrides (writeOverrides(), under its lock). */
	private function storeOverrides(array $overrides): void {
		ksort($overrides);

		$content = "<?php\n\nreturn " . var_export($overrides, true) . ";\n";

		$dir  = dirname($this->overridesPath);
		$tmp  = @tempnam($dir, '.modules_tmp_');
		if ($tmp === false) {
			throw new \RuntimeException('Unable to create temporary file for config/modules.php');
		}

		try {
			if (@file_put_contents($tmp, $content, LOCK_EX) === false) {
				throw new \RuntimeException('Unable to write config/modules.php (temp stage)');
			}

			@chmod($tmp, 0644);

			if (!@rename($tmp, $this->overridesPath)) {
				throw new \RuntimeException('Unable to atomically replace config/modules.php');
			}

			// config/modules.php is read back via require(), which OPcache caches
			// (validate_timestamps + revalidate_freq). Install does several
			// read-modify-write cycles in one request (setState → recordInstalled
			// → recordSource); without invalidation the later reads see a STALE
			// array and the final write clobbers the module's state, leaving it
			// not-Enabled until a manual disable/enable. Drop the cached entry so
			// every readOverrides() in this request sees what we just wrote.
			if (function_exists('opcache_invalidate')) {
				opcache_invalidate($this->overridesPath, true);
			}
		} catch (\Throwable $e) {
			@unlink($tmp);
			throw $e;
		}
	}

	/**
	 * Extract a module archive (.zip or .tar.gz/.tgz/.tar) into $destination.
	 *
	 * The type is detected by magic bytes (uploads arrive as an extension-less tmp
	 * file), then routed:
	 *   - tar.gz : PharData (bundled with PHP, no extension) → fallback to `tar` CLI
	 *   - zip    : ZipArchive (validated) → fallback to the `unzip` CLI
	 *
	 * $destination is always an isolated temp dir created by the caller, and the
	 * CLI tools refuse to write outside it (they strip `../`/absolute members), so
	 * extraction stays contained even without the per-entry PHP validation.
	 *
	 * @throws \RuntimeException If the archive cannot be extracted in this environment.
	 */
	private function extractArchive(string $archivePath, string $destination): void {
		if (!is_dir($destination) && !@mkdir($destination, 0755, true) && !is_dir($destination)) {
			throw new \RuntimeException('Unable to create extraction directory.');
		}

		if ($this->looksLikeTar($archivePath)) {
			$this->extractTarArchive($archivePath, $destination);
			return;
		}

		// ZIP: prefer the validated PHP extension, else the `unzip` CLI.
		if (class_exists('ZipArchive')) {
			$this->extractZipViaZipArchive($archivePath, $destination);
			return;
		}
		if ($this->hasBinary('unzip')) {
			// unzip exit 1 = success with warnings (e.g. skipped unsafe paths).
			$this->runExtractor('unzip -oqq ' . escapeshellarg($archivePath) . ' -d ' . escapeshellarg($destination), 1);
			return;
		}
		throw new \RuntimeException('Cannot extract .zip: install the PHP zip extension or the `unzip` command (or upload a .tar.gz).');
	}

	/**
	 * What kind of archive a file is, by its content: 'zip', 'tar' (plain or
	 * gzip) or null. The name does not say: an upload has none, and a stored
	 * archive is named .zip whatever it is (archivePathFor()).
	 */
	public static function archiveKind(string $path): ?string {
		$head = (string) @file_get_contents($path, false, null, 0, 262);

		return match (true) {
			str_starts_with($head, 'PK') => 'zip',
			str_starts_with($head, "\x1f\x8b"), substr($head, 257, 5) === 'ustar' => 'tar',
			default => null,
		};
	}

	/** Detect a tar/tar.gz archive by its content (archiveKind()), else by its extension. */
	private function looksLikeTar(string $path): bool {
		$kind = self::archiveKind($path);
		if ($kind !== null) {
			return $kind === 'tar';
		}
		$lower = strtolower($path);
		if (str_ends_with($lower, '.tar.gz') || str_ends_with($lower, '.tgz') || str_ends_with($lower, '.tar')) {
			return true;
		}
		if (str_ends_with($lower, '.zip')) {
			return false;
		}
		// Extension-less (uploaded tmp file): sniff magic. gzip = 1f 8b.
		$fh = @fopen($path, 'rb');
		if ($fh === false) {
			return false;
		}
		$magic = fread($fh, 3);
		fclose($fh);
		return strlen($magic) >= 2 && ord($magic[0]) === 0x1f && ord($magic[1]) === 0x8b;
	}

	/** Extract .tar/.tar.gz via PharData (no PHP extension needed) or the `tar` CLI. */
	private function extractTarArchive(string $archivePath, string $destination): void {
		if (class_exists('PharData')) {
			// No per-entry check here (unlike the zip branch): PharData resolves
			// members itself and refuses to write outside $destination — verified
			// against a hand-built tar carrying a `../` member, which it parses but
			// neither exposes nor extracts.
			try {
				(new \PharData($archivePath))->extractTo($destination, null, true);
				return;
			} catch (\Throwable $e) {
				// fall through to the CLI
			}
		}
		if ($this->hasBinary('tar')) {
			// GNU tar auto-detects gzip and strips unsafe (../, absolute) members.
			$this->runExtractor('tar -xf ' . escapeshellarg($archivePath) . ' -C ' . escapeshellarg($destination), 0);
			return;
		}
		throw new \RuntimeException('Cannot extract .tar.gz: PharData is unavailable and the `tar` command is missing.');
	}

	/** True if $bin resolves on PATH. */
	private function hasBinary(string $bin): bool {
		$out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
		return is_string($out) && trim($out) !== '';
	}

	/** Run a CLI extractor; treat exit codes above $maxOkCode as failures. */
	private function runExtractor(string $cmd, int $maxOkCode): void {
		$out  = [];
		$code = 0;
		@exec($cmd . ' 2>&1', $out, $code);
		if ($code > $maxOkCode) {
			throw new \RuntimeException('Archive extraction failed (exit ' . $code . '): ' . implode(' ', array_slice($out, -3)));
		}
	}

	/**
	 * Safely extract a ZIP archive via the PHP zip extension.
	 *
	 * Validates each entry for path traversal attacks before extracting.
	 *
	 * @param string $zipFilePath  Path to the zip file.
	 * @param string $destination  Extraction target directory.
	 * @throws \RuntimeException If extraction fails or unsafe entries are detected.
	 */
	private function extractZipViaZipArchive(string $zipFilePath, string $destination): void {
		$zip = new \ZipArchive();
		if ($zip->open($zipFilePath) !== true) {
			throw new \RuntimeException('Unable to open zip archive.');
		}

		try {
			for ($i = 0; $i < $zip->numFiles; $i++) {
				$entry = $zip->getNameIndex($i);
				if ($entry === false || $entry === '') {
					continue;
				}

				$entry = str_replace('\\', '/', $entry);
				if (strpos($entry, '../') !== false || strpos($entry, '..\\') !== false || strpos($entry, ':') !== false) {
					throw new \RuntimeException('Unsafe zip entry detected.');
				}

				$targetPath = rtrim($destination, '/') . '/' . ltrim($entry, '/');

				if (substr($entry, -1) === '/') {
					if (!is_dir($targetPath) && !@mkdir($targetPath, 0755, true)) {
						throw new \RuntimeException('Unable to create directory while extracting zip.');
					}
					continue;
				}

				$dir = dirname($targetPath);
				if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
					throw new \RuntimeException('Unable to create directory while extracting zip.');
				}

				$in = $zip->getStream($entry);
				if (!$in) {
					throw new \RuntimeException('Unable to read zip entry stream.');
				}

				$out = @fopen($targetPath, 'wb');
				if (!$out) {
					fclose($in);
					throw new \RuntimeException('Unable to write extracted file.');
				}

				while (!feof($in)) {
					$chunk = fread($in, 8192);
					if ($chunk === false) {
						break;
					}
					fwrite($out, $chunk);
				}

				fclose($in);
				fclose($out);
			}
		} finally {
			$zip->close();
		}
	}

	/**
	 * Resolve the module root directory from the extracted temp path.
	 *
	 * Handles both flat and nested zip layouts.
	 *
	 * @param string $tempBase Temporary extraction directory.
	 * @return string Path to the directory containing module.json.
	 * @throws \RuntimeException If module.json is not found or ambiguous.
	 */
	private function resolveExtractedModuleDir(string $tempBase): string {
		$rootJson = $tempBase . '/module.json';
		if (is_file($rootJson)) {
			return $tempBase;
		}

		$jsonFiles = glob($tempBase . '/*/module.json') ?: [];
		if (count($jsonFiles) !== 1) {
			throw new \RuntimeException('Archive must contain exactly one module with module.json.');
		}

		return dirname($jsonFiles[0]);
	}

	/**
	 * The module's canonical name from its module.json ("name"), falling back to
	 * the directory basename. The manifest is authoritative: extracting a flat
	 * archive (module.json at the root) or a differently-named wrapper dir would
	 * otherwise yield the random temp-dir name (e.g. "xc_module_ab12"), which fails
	 * sanitizeModuleName() with "Invalid module name."
	 */
	private function manifestNameFromDir(string $moduleDir): string {
		$meta = json_decode((string) @file_get_contents($moduleDir . '/module.json'), true);
		$name = is_array($meta) ? trim((string) ($meta['name'] ?? '')) : '';
		return $name !== '' ? $name : basename($moduleDir);
	}

	/**
	 * Recursively delete a directory and its contents.
	 *
	 * @param string $path Path to delete.
	 */
	private function deleteDirectory(string $path): void {
		if (!file_exists($path)) {
			return;
		}

		if (is_file($path) || is_link($path)) {
			@unlink($path);
			return;
		}

		$items = scandir($path);
		if (!$items) {
			@rmdir($path);
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}
			$this->deleteDirectory($path . '/' . $item);
		}

		@rmdir($path);
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source      Source directory path.
	 * @param string $destination Destination directory path.
	 * @throws \RuntimeException If copying fails.
	 */
	private function copyDirectory(string $source, string $destination): void {
		if (!is_dir($source)) {
			throw new \RuntimeException('Source directory not found: ' . $source);
		}

		if (!is_dir($destination) && !@mkdir($destination, 0755, true)) {
			throw new \RuntimeException('Unable to create module directory.');
		}

		$items = scandir($source);
		if (!$items) {
			return;
		}

		foreach ($items as $item) {
			if ($item === '.' || $item === '..') {
				continue;
			}

			$src = $source . '/' . $item;
			$dst = $destination . '/' . $item;

			if (is_dir($src)) {
				$this->copyDirectory($src, $dst);
			} else {
				if (!@copy($src, $dst)) {
					throw new \RuntimeException('Unable to copy file: ' . $item);
				}
			}
		}
	}
}

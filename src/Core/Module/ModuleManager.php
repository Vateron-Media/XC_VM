<?php

namespace XcVm\Core\Module;

use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Http\Router;
use XcVm\Core\Updates\GitHubReleases;

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
    private string $modulesPath;
    private string $overridesPath;
    private string $archivesPath;
    private ?\XcVm\Core\Container\ServiceContainer $container;

    /**
     * Initialize the module manager.
     *
     * @param string|null $modulesPath   Path to the modules directory.
     * @param string|null $overridesPath Path to the config/modules.php overrides file.
     * @param \XcVm\Core\Container\ServiceContainer|null $container Service container for DB access and DI.
     */
    public function __construct(
        ?string $modulesPath = null,
        ?string $overridesPath = null,
        ?\XcVm\Core\Container\ServiceContainer $container = null
    ) {
        $this->modulesPath   = $modulesPath   ?: (defined('MAIN_HOME')   ? MAIN_HOME   . 'Modules'      : dirname(__DIR__, 2) . '/Modules');
        $this->overridesPath = $overridesPath ?: (defined('CONFIG_PATH') ? CONFIG_PATH . 'modules.php'  : dirname(__DIR__, 2) . '/config/modules.php');
        $this->archivesPath  = defined('MAIN_HOME') ? MAIN_HOME . 'modules_archives' : dirname(__DIR__, 2) . '/modules_archives';
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
        $version = preg_replace('/[^0-9A-Za-z._\-]/', '', (string) $version);
        return $this->archivesPath . '/' . $name . '_' . $version . '.zip';
    }

    /** @return object|null Database instance from the container, or null if unavailable. */
    private function getDb(): ?object {
        if ($this->container !== null && $this->container->has('db')) {
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
            $out = ['hash_id' => $hash] + $out;
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
        // Guarantee a hash_id (generating + persisting one when the upload lacks it)
        // so the module is always placed in a `{name}_{hash5}` directory, never bare.
        $hash = $this->ensureHashId($moduleDir);

        // Remove any current install of the same module (may live under a different
        // {name}_{hash5} or a legacy {name} directory).
        $existing = $this->modulePathFor($name);
        if (is_dir($existing)) {
            $this->deleteDirectory($existing);
        }

        $targetDir = $this->modulesPath . '/' . $this->moduleDirName($name, $hash);
        if (is_dir($targetDir)) {
            $this->deleteDirectory($targetDir);
        }
        $this->copyDirectory($moduleDir, $targetDir);

        return $name;
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
     * Names of currently-loadable (enabled) installed modules that declare $name
     * as a required dependency.
     *
     * Used to guard disabling: a dependent that is itself disabled won't be loaded
     * either, so disabling $name under it is harmless and must not be blocked.
     * Only enabled dependents would be broken (ModuleLoader skips them on the next
     * boot), so only those count.
     *
     * @return string[]
     */
    private function enabledDependentsOf(string $name): array {
        $out = [];
        foreach ($this->listModules() as $module) {
            if (($module['installed_version'] ?? '') === '') {
                continue; // not installed — its requirements don't apply
            }
            $state = $module['state'] ?? null;
            if (!($state instanceof \XcVm\Core\Enum\ModuleState) || !$state->isLoadable()) {
                continue; // already disabled/failed — disabling its dep won't break it
            }
            if (in_array($name, $module['dependencies'] ?? [], true)) {
                $out[] = $module['name'];
            }
        }
        return $out;
    }

    /**
     * Install any on-disk module that has never been installed.
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

        // Fetch any standard-set module that lives in a remote source (git/url/
        // platform) and isn't on disk yet — a no-op while every standard module is
        // bundled on-disk. Fetched modules are installed by provisionStandardSet(),
        // so the on-disk pass below simply skips them.
        $provisioned = $this->provisionStandardSet();

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
            if (($module['state'] ?? null) === \XcVm\Core\Enum\ModuleState::Disabled) {
                continue;
            }
            $pending[$name] = $module;
        }

        // Install in dependency order: a module installs once all its declared
        // dependencies are themselves installed.
        $done  = [];
        $guard = 0;
        while (!empty($pending) && $guard++ < 1000) {
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

        return array_values(array_unique(array_merge($provisioned, $done)));
    }

    /**
     * The standard module set the panel provisions by default (config/bundled_modules.php).
     *
     * Foundation for modules-in-separate-repos: each entry is keyed by the module's
     * permanent `hash_id`. When the config file is absent, falls back to whatever
     * is on disk (treated as `bundled`).
     *
     * @return array<int, array{hash_id?:string,name?:string,source?:string,repository?:string,channel?:string,slug?:string,url?:string}>
     */
    public function getStandardSet(): array {
        $path = defined('CONFIG_PATH')
            ? CONFIG_PATH . 'bundled_modules.php'
            : dirname(__DIR__, 2) . '/config/bundled_modules.php';

        if (is_file($path)) {
            $data = require $path;
            if (is_array($data)) {
                return array_values(array_filter($data, 'is_array'));
            }
        }

        // Fallback: derive from on-disk modules (all treated as bundled).
        $out = [];
        foreach ($this->listModules() as $m) {
            $out[] = ['hash_id' => (string) ($m['hash_id'] ?? ''), 'name' => $m['name'], 'source' => 'bundled'];
        }
        return $out;
    }

    /**
     * Resolve the on-disk module name that carries a given permanent `hash_id`.
     *
     * The stable identity lookup: lets the panel recognise "the same module" across
     * a rename or a repo move, where `name` alone is unreliable.
     *
     * @param string $hashId Permanent module hash_id.
     * @return string|null Module name, or null if no on-disk module has that hash_id.
     */
    public function findModuleByHashId(string $hashId): ?string {
        $hashId = trim($hashId);
        if ($hashId === '') {
            return null;
        }
        foreach ($this->listModules() as $m) {
            if (($m['hash_id'] ?? '') === $hashId) {
                return $m['name'];
            }
        }
        return null;
    }

    /**
     * Provision the standard set: fetch+install any standard-set module that lives
     * in a remote source (git/url/platform) and is not on disk yet.
     *
     * `bundled` entries and modules already present (matched by `hash_id`) are left
     * to syncBundledModules()'s on-disk install. Per-entry failures are logged, not
     * fatal. No-op today (every standard module is bundled on-disk).
     *
     * @return string[] Names/hash_ids of modules fetched this pass.
     */
    public function provisionStandardSet(): array {
        $done = [];
        foreach ($this->getStandardSet() as $entry) {
            $hash = (string) ($entry['hash_id'] ?? '');
            $name = (string) ($entry['name'] ?? '');

            // Already on disk (bundled or previously fetched)? Nothing to fetch.
            // A same-name directory whose identity does NOT match the pinned
            // hash_id is a stale pre-pin copy (e.g. a legacy-migrated bundled
            // module from an older release) — it must be replaced, not kept:
            // otherwise the panel runs outdated module code forever.
            $onDisk   = $hash !== '' ? $this->findModuleByHashId($hash) : null;
            $staleDir = null;
            if ($onDisk === null && $name !== '') {
                $dir = $this->modulePathFor($name);
                if (is_dir($dir) && is_file($dir . '/module.json')) {
                    if ($hash === '') {
                        $onDisk = $name; // no pin — any same-name copy counts
                    } else {
                        $staleDir = $dir;
                    }
                }
            }
            if ($onDisk !== null) {
                continue;
            }

            $source = (string) ($entry['source'] ?? 'bundled');
            if ($source === 'bundled') {
                error_log("provisionStandardSet: '{$name}' is declared bundled but missing on disk — skipped.");
                continue;
            }

            try {
                if ($staleDir !== null) {
                    error_log("provisionStandardSet: '{$name}' on disk (" . basename($staleDir) . ") does not match the pinned hash_id — replacing with the pinned release.");
                    $this->deleteDirectory($staleDir);
                }
                $this->installModuleFromSource($entry);
                $done[] = $name !== '' ? $name : $hash;
            } catch (\Throwable $e) {
                error_log("provisionStandardSet: fetch of '" . ($name !== '' ? $name : $hash) . "' failed: " . $e->getMessage());
            }
        }
        return $done;
    }

    /**
     * Fetch a NOT-yet-present module from a standard-set entry's source and install it.
     *
     * Mirrors updateModuleFromSource() but for a first install (no local module.json
     * to read the source from — it comes from the entry). Verifies the fetched
     * `hash_id` against the entry so a repo/URL cannot supply a different module.
     *
     * @param array $entry A getStandardSet() entry (source/repository/…, hash_id).
     * @return void
     * @throws \RuntimeException on download/verify/install failure.
     */
    private function installModuleFromSource(array $entry): void {
        $update = [
            'source'     => (string) ($entry['source'] ?? 'bundled'),
            'repository' => (string) ($entry['repository'] ?? ''),
            'channel'    => (string) ($entry['channel'] ?? 'stable'),
            'slug'       => (string) ($entry['slug'] ?? ($entry['name'] ?? '')),
            'url'        => (string) ($entry['url'] ?? ''),
        ];
        $expectedHash = (string) ($entry['hash_id'] ?? '');

        // platform — the store install flow handles download/decrypt/license/LB.
        if ($update['source'] === 'platform') {
            $apiKey = (string) (SettingsManager::get('platform_api_key') ?? '');
            $slug   = $update['slug'] !== '' ? $update['slug'] : (string) ($entry['name'] ?? '');
            $this->downloadFromPlatform($slug, '', $apiKey);
            return;
        }

        $version = (new ModuleUpdateChecker())->latestAvailable([
            'update'            => $update,
            'version'           => '',
            'installed_version' => '',
        ]);
        if ($version === null) {
            throw new \RuntimeException('No installable version resolved from source.');
        }

        [$url, $md5] = $this->resolveSourceDownload($update, $version);
        if ($url === '') {
            throw new \RuntimeException('No download URL resolved from source.');
        }

        $archive  = (string) @tempnam(sys_get_temp_dir(), 'xc_modinst_');
        $tempBase = rtrim(sys_get_temp_dir(), '/') . '/xc_modinst_' . bin2hex(random_bytes(8));
        try {
            $this->downloadToFile($url, $archive);
            if ($md5 !== '' && !hash_equals(strtolower($md5), (string) md5_file($archive))) {
                throw new \RuntimeException('Checksum mismatch on the downloaded module archive.');
            }

            $this->extractArchive($archive, $tempBase);
            $moduleDir = $this->resolveExtractedModuleDir($tempBase);

            $meta    = json_decode((string) @file_get_contents($moduleDir . '/module.json'), true);
            $gotHash = is_array($meta) ? (string) ($meta['hash_id'] ?? '') : '';
            if ($expectedHash !== '' && $gotHash !== '' && !hash_equals($expectedHash, $gotHash)) {
                throw new \RuntimeException('hash_id mismatch — fetched module is not the expected one.');
            }

            $name     = $this->placeModuleFiles($moduleDir);
            $manifest = $this->readModuleManifest($name);
            $ver      = (string) ($manifest['version'] ?? $version);

            $this->storeModuleArchive($archive, $name, $ver);
            if ((string) ($this->readOverrides()[$name]['installed_version'] ?? '') !== '') {
                // Files were re-provisioned for an already-installed module (a
                // stale copy was replaced) — catch the schema up incrementally
                // instead of re-running the initial install migrations.
                $this->updateModule($name);
            } else {
                $this->installModule($name, $ver);
            }
            $this->recordModuleSource($name, 'local');
            $this->distributeToLoadBalancers($name, $manifest, 'local', $ver);
        } finally {
            @unlink($archive);
            $this->deleteDirectory($tempBase);
        }
    }

    /**
     * List all installed modules with their metadata and status.
     *
     * Scans the modules directory for module.json files, merges with
     * config/modules.php overrides, and returns sorted results.
     *
     * @return array<int, array{name: string, description: string, version: string, requires_core: string, environment: string, priority: int, dependencies: array, optional_dependencies: array, has_navbar: bool, has_settings: bool, enabled: bool, state: \XcVm\Core\Enum\ModuleState, path: string, installed_version: string, source: string, previous_version: string, dependency_warnings: string[]}> Module list.
     */
    public function listModules(): array {
        $overrides = $this->readOverrides();
        $items = [];

        $jsonFiles = glob($this->modulesPath . '/*/module.json') ?: [];

        // Pre-resolve each module's state by name so the dependency diagnostics
        // below can see the full set while building items.
        $stateByName = [];
        foreach ($jsonFiles as $jsonFile) {
            $meta    = json_decode((string) @file_get_contents($jsonFile), true) ?: [];
            // Key by the CANONICAL manifest name, not the `{name}_{hash5}` directory —
            // config/modules.php and `dependencies` both reference the logical name.
            $depName = (string) ($meta['name'] ?? basename(dirname($jsonFile)));
            $stateByName[$depName] = \XcVm\Core\Enum\ModuleState::fromRaw(
                $overrides[$depName]['state'] ?? ($overrides[$depName]['enabled'] ?? null)
            );
        }

        foreach ($jsonFiles as $jsonFile) {
            $meta  = json_decode((string) @file_get_contents($jsonFile), true) ?: [];
            $name  = (string) ($meta['name'] ?? basename(dirname($jsonFile))); // canonical name
            $state = $stateByName[$name] ?? \XcVm\Core\Enum\ModuleState::fromRaw(null);

            $dependencies = ModuleLoader::filterCoreProvidedDependencies(
                is_array($meta['dependencies'] ?? null) ? $meta['dependencies'] : []
            );

            // Flag a module that is nominally Enabled but won't actually load:
            // ModuleLoader skips it when a required dependency is missing or not
            // loadable (e.g. plex is Enabled but watch is Failed/Disabled). Mirrors
            // ModuleLoader::pruneUnsatisfiableModules().
            $dependencyWarnings = [];
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
     * Install a module by name.
     *
     * Loads the module instance, runs install(), and enables it.
     *
     * @param string $name Module name (lowercase, alphanumeric + hyphens).
     * @return void
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

        $this->setState($name, \XcVm\Core\Enum\ModuleState::Installing);

        try {
            $db = $this->getDb() ?? \XcVm\Infrastructure\Database\DatabaseFactory::get();
            // Apply the module's master schema, then its own install() hook for any
            // non-SQL setup. NB: schema files are DDL (CREATE/ALTER), which
            // MySQL/MariaDB implicitly commit — a wrapping transaction gives no
            // rollback safety, and its rollback() on error throws "no active
            // transaction", masking the real SQL error. So run directly and let the
            // genuine failure propagate to the catch below (and into the logs).
            if ($db !== null) {
                // Fresh install applies the module's master schema (database.sql).
                ModuleMigrator::install($modulePath, $db, (string) $targetVersion);
            }
            $module->install();
        } catch (\Throwable $e) {
            $this->setState($name, \XcVm\Core\Enum\ModuleState::Failed);
            throw $e;
        }

        $this->setState($name, \XcVm\Core\Enum\ModuleState::Enabled);
        $this->recordInstalledVersion($name, $targetVersion);
    }

    /**
     * Uninstall a module by name.
     *
     * Runs uninstall() and disables the module.
     *
     * @param string $name Module name.
     * @return void
     * @throws \RuntimeException If the module cannot be loaded.
     */
    public function uninstallModule(string $name): void {
        $name = $this->sanitizeModuleName($name);

        // Refuse to remove a module that still-installed dependents rely on
        // (e.g. plex depends on watch — watch cannot be removed under it).
        $dependents = $this->installedDependentsOf($name);
        if (!empty($dependents)) {
            throw new \RuntimeException(
                "Cannot uninstall '{$name}': still required by " . implode(', ', $dependents)
                . '. Uninstall ' . (count($dependents) === 1 ? 'it' : 'them') . ' first.'
            );
        }

        $module = $this->loadModuleInstance($name);

        // The module's own uninstall() hook runs first (it clears the data/rows
        // it created), then the module's schema is torn down via its single
        // teardown file (database_drop.sql).
        $module->uninstall();
        $db = $this->getDb() ?? \XcVm\Infrastructure\Database\DatabaseFactory::get();
        if ($db !== null) {
            ModuleMigrator::uninstall($this->modulePathFor($name), $db);
        }

        $this->clearInstalledVersion($name);
        $this->setState($name, \XcVm\Core\Enum\ModuleState::Disabled);
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
     * @return void
     * @throws \RuntimeException If an installed dependent still requires the module.
     */
    public function deleteModule(string $name): void {
        $name = $this->sanitizeModuleName($name);

        // Same guard as uninstall: refuse while an installed dependent needs it.
        $dependents = $this->installedDependentsOf($name);
        if (!empty($dependents)) {
            throw new \RuntimeException(
                "Cannot delete '{$name}': still required by " . implode(', ', $dependents)
                . '. Delete ' . (count($dependents) === 1 ? 'it' : 'them') . ' first.'
            );
        }

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
     * @return void
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
     * @return void
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
        $rServerIDs = array();
        foreach ($db->get_rows() as $rRow) {
            $rServerIDs[] = intval($rRow['id']);
        }
        foreach ($rServerIDs as $rServerID) {
            $db->query(
                'INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);',
                $rServerID,
                time(),
                $payload
            );
        }
    }

    /**
     * Update a module, running only the incremental migrations needed.
     *
     * Reads the recorded installed_version from config/modules.php.
     * If no version is recorded (legacy install), falls back to full installModule().
     * If already at the current version, does nothing.
     * Otherwise runs all getMigrations() entries with version > installedVersion
     * and version <= module->getVersion(), in ascending semver order.
     *
     * @param string $name Module name.
     * @return void
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
        $toVersion = $this->manifestVersion($name) ?? $module->getVersion();

        if (version_compare($fromVersion, $toVersion, '>=')) {
            return;
        }

        // File-based schema migrations: every up file in (fromVersion, toVersion].
        $db = $this->getDb() ?? \XcVm\Infrastructure\Database\DatabaseFactory::get();
        if ($db !== null) {
            ModuleMigrator::up($this->modulePathFor($name), $db, $fromVersion, (string) $toVersion);
        }

        // Programmatic migrations (callables) — coexist with the file-based ones.
        if ($module instanceof MigratableInterface) {
            $this->runPendingMigrations($module->getMigrations(), $fromVersion, $toVersion);
        }

        $this->recordInstalledVersion($name, $toVersion);
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

            // Identity pinning — the fetched module must be the SAME module.
            $newMeta = json_decode((string) @file_get_contents($moduleDir . '/module.json'), true);
            $newHash = is_array($newMeta) ? (string) ($newMeta['hash_id'] ?? '') : '';
            $ownHash = (string) ($manifest['hash_id'] ?? '');
            if ($ownHash !== '' && $newHash !== '' && !hash_equals($ownHash, $newHash)) {
                throw new \RuntimeException("hash_id mismatch — refusing to overwrite '{$name}' with a different module.");
            }

            $targetDir = $this->modulePathFor($name);
            $backupDir = $this->backupModuleDir($name, $targetDir);
            try {
                $this->copyDirectory($moduleDir, $targetDir);
                $this->updateModule($name); // incremental migrations to the new manifest version

                $fresh          = $this->readModuleManifest($name);
                $resolvedVer    = (string) ($fresh['version'] ?? $version);
                $this->recordAvailableVersion($name, null);

                // Keep a local archive so LB nodes can pull it (getFile), then fan out.
                $this->storeModuleArchive($archive, $name, $resolvedVer);
                $this->distributeToLoadBalancers($name, $fresh, 'local', $resolvedVer);

                if ($backupDir !== null) {
                    $this->deleteDirectory($backupDir);
                }

                return $resolvedVer;
            } catch (\Throwable $e) {
                $this->restoreModuleBackup($name, $targetDir, $backupDir, $installed !== '' ? $installed : null);
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
                $channel = in_array((string) ($update['channel'] ?? 'stable'), ['beta', 'unstable'], true) ? 'unstable' : 'stable';
                $md5 = (string) ((new GitHubReleases($m[1], $m[2], $channel))->getAssetHash($version, $asset) ?? '');
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
     * @param \XcVm\Core\Enum\ModuleState $state Target lifecycle state.
     * @return void
     */
    public function setState(string $name, \XcVm\Core\Enum\ModuleState $state): void {
        $name      = $this->sanitizeModuleName($name);

        // Refuse to disable a module that still-enabled dependents rely on
        // (e.g. plex requires watch — watch cannot be disabled under it, or
        // ModuleLoader would skip plex on the next boot). Mirrors the guard in
        // uninstallModule(). Scoped strictly to a deliberate Disabled transition:
        // the internal lifecycle states (Installing, Failed) are also non-loadable
        // but are set by installModule() itself and must never be blocked.
        if ($state === \XcVm\Core\Enum\ModuleState::Disabled) {
            $dependents = $this->enabledDependentsOf($name);
            if (!empty($dependents)) {
                throw new \RuntimeException(
                    "Cannot disable '{$name}': still required by " . implode(', ', $dependents)
                    . '. Disable ' . (count($dependents) === 1 ? 'it' : 'them') . ' first.'
                );
            }
        }

        $overrides = $this->readOverrides();

        if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
            $overrides[$name] = [];
        }

        // Remove any legacy bool 'enabled' key — we use 'state' now.
        unset($overrides[$name]['enabled']);

        if ($state === \XcVm\Core\Enum\ModuleState::Enabled) {
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
     * @deprecated Use setState(name, \XcVm\Core\Enum\ModuleState::Enabled / \XcVm\Core\Enum\ModuleState::Disabled) instead.
     *
     * @param string $name    Module name.
     * @param bool   $enabled True to enable, false to disable.
     * @return void
     */
    public function setEnabled(string $name, bool $enabled): void {
        $this->setState($name, $enabled ? \XcVm\Core\Enum\ModuleState::Enabled : \XcVm\Core\Enum\ModuleState::Disabled);
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
            $moduleName = $this->placeModuleFiles($moduleDir);

            $this->installModule($moduleName);

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
            $this->setState($moduleName, \XcVm\Core\Enum\ModuleState::Enabled);
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
     * @return string|null
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

        $payload = json_encode([
            'action'  => 'install_module',
            'source'  => $source === 'platform' ? 'platform' : 'local',
            'name'    => $name,
            'version' => $version,
        ]);

        // LB servers are streaming servers (server_type = 0) that are not the
        // main panel and are enabled. Collect ids first so the INSERT loop does
        // not clobber the active result set.
        $db->query('SELECT `id` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 AND `enabled` = 1;');
        $rServerIDs = array();
        foreach ($db->get_rows() as $rRow) {
            $rServerIDs[] = intval($rRow['id']);
        }

        foreach ($rServerIDs as $rServerID) {
            $db->query(
                'INSERT INTO `signals`(`server_id`, `time`, `custom_data`) VALUES(?, ?, ?);',
                $rServerID,
                time(),
                $payload
            );
        }
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
     * Fires \XcVm\Core\Events\Module\PackageInstalledEvent and hot-reloads the module into the
     * current \XcVm\Core\Container\ServiceContainer without requiring a PHP-FPM restart.
     *
     * @param string      $slug    Module slug as listed on the platform.
     * @param string      $version Exact version string (e.g. "1.2.0"), or '' for the latest.
     * @param string|null $apiKey  API key for the SaaS platform.
     * @return void
     * @throws \RuntimeException If the C extension is missing, download fails, or install fails.
     */
    public function downloadFromPlatform(string $slug, string $version = '', ?string $apiKey = null): void {
        $slug      = $this->sanitizeModuleName($slug);
        $targetDir = $this->modulesPath . '/' . $slug;

        // Snapshot current state so a failed (re)install rolls back cleanly. We
        // MOVE the existing module aside (outside modulesPath so the loader never
        // scans it) — this both gives a clean dir for the new extract and a
        // restore point. installed_version is captured for the version record.
        $prevVersion = $this->readOverrides()[$slug]['installed_version'] ?? null;
        $backupDir   = $this->backupModuleDir($slug, $targetDir);

        try {
            $result          = $this->pullFilesFromPlatform($slug, $version, $apiKey);
            $modulePath      = $result['path'];
            $resolvedVersion = (string) ($result['version'] ?: $version);

            // Acquire the per-machine ionCube license BEFORE installModule(): if the
            // platform encoded the module with --with-license, the loader rejects the
            // encoded files at require-time unless a valid .lic is already present.
            // No-op when the platform has licensing disabled.
            $this->acquireModuleLicense($slug, $apiKey);

            // Record the version the PLATFORM served (authoritative for store
            // installs); the module's module.json/getVersion() may lag behind.
            $this->installModule($slug, $resolvedVersion);

            \XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Module\PackageInstalledEvent(
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
            $restored = $this->restoreModuleBackup($slug, $targetDir, $backupDir, $prevVersion);
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
            $res = \XC_VM::module_license($slug, base64_encode($serverData), $apiKey ?? '');
        } catch (\Throwable $e) {
            error_log("ModuleManager: license request failed for '{$slug}': " . $e->getMessage());
            return false;
        }

        if (!is_array($res) || empty($res['ok'])) {
            $reason = is_array($res) ? ($res['reason'] ?? 'unknown') : 'no_response';
            error_log("ModuleManager: license NOT issued for '{$slug}': {$reason}"
                . (($apiKey ?? '') === '' ? ' (api_key пуст — для лицензии он обязателен)' : ''));
            return false;
        }

        $licName  = basename((string) ($res['license_name'] ?? 'module.lic'));
        $licBytes = base64_decode((string) ($res['license'] ?? ''), true);
        if ($licName === '' || $licBytes === false || $licBytes === '') {
            error_log("ModuleManager: license for '{$slug}' empty/undecodable in response");
            return false;
        }

        $dir = $this->modulesPath . '/' . $slug;
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
     */
    private function restoreModuleBackup(string $slug, string $targetDir, ?string $backupDir, ?string $prevVersion): bool {
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
        $this->setState($slug, \XcVm\Core\Enum\ModuleState::Enabled);
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
     * @return void
     */
    public function deployFromPlatformFilesOnly(string $slug, string $version, ?string $apiKey = null): void {
        $result = $this->pullFilesFromPlatform($slug, $version, $apiKey);

        \XcVm\Core\Events\EventDispatcher::dispatch(new \XcVm\Core\Events\Module\PackageInstalledEvent(
            slug:        $result['module'],
            version:     $result['version'],
            path:        $result['path'],
            installedAt: time(),
        ));

        $this->recordInstalledVersion($slug, (string) ($result['version'] ?: $version));
        $this->setState($slug, \XcVm\Core\Enum\ModuleState::Enabled);
        $this->hotReloadSafe($slug, $result['path']);
    }

    /**
     * Register the panel and pull (download + decrypt + extract) a module's
     * files from the platform via the C extension. Does NOT run installModule().
     *
     * @return array{ok: bool, module: string, version: string, path: string}
     * @throws \RuntimeException On a missing extension, registration or download failure.
     */
    private function pullFilesFromPlatform(string $slug, string $version, ?string $apiKey): array {
        if (!class_exists('XC_VM')) {
            throw new \RuntimeException('XC_VM extension is not loaded. Install xcvm_core.so and enable it in php.ini.');
        }

        // Ensure this panel is registered with the platform before installing.
        // module_install() asks the SaaS to wrap the module key in an X25519
        // SealedBox for *this* panel's public key; if the panel was never
        // registered the /plugins/key endpoint answers "panel_not_registered"
        // and the install fails. Registration is an idempotent upsert keyed by
        // install_id, so running it before every install also guarantees the
        // server holds the public key matching our current local secret key.
        $reg = \XC_VM::panel_register($apiKey ?? '');
        if (!is_array($reg) || empty($reg['ok'])) {
            $regReason = $reg['message'] ?? ($reg['reason'] ?? 'unknown');
            throw new \RuntimeException("Panel registration with platform failed for module '{$slug}': {$regReason}");
        }

        $result = \XC_VM::module_install($slug, $version, $apiKey ?? '');

        if (!is_array($result) || empty($result['ok'])) {
            $reason = $result['error'] ?? 'unknown';
            throw new \RuntimeException("Platform download failed for module '{$slug}': {$reason}");
        }

        return [
            'ok'               => true,
            'module'           => $result['module']  ?? $slug,
            'version'          => $result['version'] ?? $version,
            'path'             => $result['path']    ?? ($this->modulesPath . '/' . $slug),
            // Previous approved version reported by the platform (for the
            // Rollback button). May be absent/null when there is no prior version.
            'previous_version' => $result['previous_version'] ?? null,
        ];
    }

    /**
     * Hot-reload a newly installed module into the running \XcVm\Core\Container\ServiceContainer.
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
     * @return void
     */
    private function hotReload(string $slug, string $modulePath): void {
        $container = \XcVm\Core\Container\ServiceContainer::getInstance();

        $loader = new ModuleLoader();
        if (!$loader->load($slug, $modulePath)) {
            return;
        }

        $router = $container->getOrDefault('router');
        $loader->bootAll($container, $router instanceof Router ? $router : null);
    }

    /**
     * Run migrations whose target version falls in (fromVersion, toVersion].
     *
     * @param array<string, callable> $migrations
     * @param string $fromVersion Currently installed version (exclusive lower bound).
     * @param string $toVersion   New version (inclusive upper bound).
     */
    private function runPendingMigrations(array $migrations, string $fromVersion, string $toVersion): void {
        $pending = [];
        foreach ($migrations as $version => $callable) {
            if (
                version_compare($version, $fromVersion, '>') &&
                version_compare($version, $toVersion, '<=')
            ) {
                $pending[$version] = $callable;
            }
        }

        uksort($pending, 'version_compare');

        $db = $this->getDb();
        foreach ($pending as $callable) {
            if ($db !== null && method_exists($db, 'transactional')) {
                $db->transactional(fn() => $callable($this->container));
            } else {
                $callable($this->container);
            }
        }
    }

    /**
     * Persist the installed version for a module in config/modules.php.
     *
     * @param string $name    Module name.
     * @param string $version Installed version string.
     */
    private function recordInstalledVersion(string $name, string $version): void {
        $overrides = $this->readOverrides();
        if (!isset($overrides[$name]) || !is_array($overrides[$name])) {
            $overrides[$name] = [];
        }
        $overrides[$name]['installed_version'] = $version;
        $this->writeOverrides($overrides);
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
        unset($overrides[$name]['installed_version']);
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
     * @return void
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
            throw new \XcVm\Core\Exception\Module\ModuleNotFoundException('Cannot load module: ' . $name);
        }

        $module = $loader->getModule($name);
        if (!$module) {
            throw new \XcVm\Core\Exception\Module\ModuleNotFoundException('Module instance is not available: ' . $name);
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
        $name = trim((string) $name);
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $name)) {
            throw new \XcVm\Core\Exception\Module\ModuleException('Invalid module name.');
        }
        return $name;
    }

    /**
     * Read module overrides from config/modules.php.
     *
     * @return array<string, array> Module overrides keyed by module name.
     */
    private function readOverrides(): array {
        if (!file_exists($this->overridesPath)) {
            return [];
        }

        $data = require $this->overridesPath;
        return is_array($data) ? $data : [];
    }

    /**
     * Write module overrides to config/modules.php atomically.
     *
     * Writes to a sibling temp file then renames into place, so concurrent
     * requests can never read a partially-written file.
     *
     * @param array $overrides Module overrides to persist.
     * @return void
     * @throws \RuntimeException If the file cannot be written or renamed.
     */
    private function writeOverrides(array $overrides): void {
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

    /** Detect a tar/tar.gz archive by extension, or gzip magic bytes for tmp uploads. */
    private function looksLikeTar(string $path): bool {
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
     * @return void
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
     * @return void
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
     * @return void
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

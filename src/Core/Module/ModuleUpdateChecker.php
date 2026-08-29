<?php

namespace XcVm\Core\Module;

use XcVm\Core\Updates\GitHubReleases;

/**
 * ModuleUpdateChecker — resolve the latest available version of a module from its
 * declared update source (the `update` manifest block, see ModuleLoader).
 *
 * Pure read-only resolution — no files are changed here (that is P4's
 * updateModuleFromSource). Returns the latest known version string, or null when
 * there is nothing newer or the source cannot be checked. Every failure is
 * swallowed (logged) and returns null so an update check never breaks the caller.
 *
 * Sources:
 *   - bundled  : files ship with the panel → the on-disk manifest version is authoritative
 *   - git      : GitHub releases of `update.repository` (reuses GitHubReleases)
 *   - url      : a `version.json` (`{"version":"…"}`) at `update.url` (https only)
 *   - platform : the SaaS store via the xcvm_core extension (best-effort; skipped if absent)
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleUpdateChecker {

    /** Why the last latestAvailable() call could not check its source (null = checked fine). */
    private ?string $lastError = null;

    /**
     * Error message from the most recent latestAvailable() call, or null when the
     * source was checked successfully. Lets callers distinguish "no update" from
     * "could not check" (both return null from latestAvailable()).
     */
    public function lastError(): ?string {
        return $this->lastError;
    }

    /**
     * Latest available version for a module (a listModules() row), or null.
     *
     * @param array $module Module row with keys `update`, `version`, `installed_version`.
     * @return string|null Version string, or null if nothing newer / not checkable.
     */
    public function latestAvailable(array $module): ?string {
        $this->lastError = null;

        $update    = is_array($module['update'] ?? null) ? $module['update'] : [];
        $source    = (string) ($update['source'] ?? 'bundled');
        $installed = (string) ($module['installed_version'] ?? '');

        return match ($source) {
            'git'      => $this->fromGit($update, $installed),
            'url'      => $this->fromUrl($update),
            'platform' => $this->fromPlatform($update),
            default    => ((string) ($module['version'] ?? '')) ?: null, // bundled
        };
    }

    /** GitHub releases of update.repository, newest newer-than-installed (or null). */
    private function fromGit(array $update, string $installed): ?string {
        $repo = (string) ($update['repository'] ?? '');
        // https://github.com/OWNER/REPO(.git)  |  git@github.com:OWNER/REPO.git
        if (!preg_match('~github\.com[:/]+([^/]+)/([^/]+?)(?:\.git)?/?$~i', $repo, $m)) {
            $this->lastError = 'update.repository is not a GitHub URL: ' . ($repo !== '' ? $repo : '(empty)');
            return null;
        }
        // Map the manifest channel onto GitHubReleases' stable/beta.
        $channel = in_array((string) ($update['channel'] ?? 'stable'), ['beta', 'unstable'], true)
            ? 'beta'
            : 'stable';
        try {
            $gh = new GitHubReleases($m[1], $m[2], $channel);
            return $gh->getLatestVersion($installed !== '' ? $installed : '0.0.0');
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('ModuleUpdateChecker(git): ' . $e->getMessage());
            return null;
        }
    }

    /** version.json at a self-hosted https URL: {"version":"1.2.3"}. */
    private function fromUrl(array $update): ?string {
        $url = (string) ($update['url'] ?? '');
        if ($url === '' || stripos($url, 'https://') !== 0) {
            $this->lastError = 'update.url must be an https:// URL'; // https only — SSRF/downgrade guard
            return null;
        }
        $data = json_decode($this->httpGet($url), true);
        $ver  = is_array($data) ? trim((string) ($data['version'] ?? '')) : '';
        if ($ver === '') {
            $this->lastError = 'no valid version.json at ' . $url;
            return null;
        }
        return $ver;
    }

    /** SaaS store latest version — best-effort; skipped if the extension has no such API. */
    private function fromPlatform(array $update): ?string {
        if (!class_exists('XC_VM') || !method_exists('XC_VM', 'module_latest')) {
            return null; // store resolves "latest approved" at install time
        }
        try {
            $r = \XC_VM::module_latest((string) ($update['slug'] ?? ''));
            return is_array($r) && !empty($r['version']) ? (string) $r['version'] : null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /** cURL GET (file_get_contents over https does not work under PHP-FPM here). */
    private function httpGet(string $url): string {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'XC_VM-ModuleUpdateChecker');
        $body = curl_exec($ch);
        curl_close($ch);
        return is_string($body) ? $body : '';
    }
}

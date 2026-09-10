<?php

namespace XcVm\Core\GeoIP;

use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\UpdateChannels;

/**
 * GeoLiteReleaseUpdater — syncs the GeoIP databases that ship as GitHub release
 * assets (not from MaxMind): the free GeoLite2-City/Country pair and the free
 * self-built GeoIP2-ISP. Both go through one download path and record their
 * version into maxmind/version.json.
 *
 * @package XC_VM_Core_GeoIP
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class GeoLiteReleaseUpdater {

    private const DIR = '/home/xc_vm/bin/maxmind/';
    private const VERSION_FILE = self::DIR . 'version.json';
    private const GEOLITE_FILES = array('GeoLite2-City.mmdb', 'GeoLite2-Country.mmdb');
    private const ISP_FILE = 'GeoIP2-ISP.mmdb';

    private GitHubReleases $rRepo;

    public function __construct(?GitHubReleases $rRepo = null) {
        $this->rRepo = $rRepo ?? new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE, UpdateChannels::forRepo(GIT_REPO_UPDATE));
    }

    /**
     * Download the GeoLite2 databases from the latest release and record the
     * version. Returns true when any file failed to download.
     */
    public function updateGeoLite(bool $rForce): bool {
        $rVersion = $this->rRepo->getReleases()[0] ?? null;
        if ($rVersion === null) {
            echo "[ERROR] GeoLite2: release metadata unavailable\n";
            return true;
        }

        $rHadError = false;
        foreach (self::GEOLITE_FILES as $rFile) {
            if ($this->downloadReleaseFile($this->assetSpec($rVersion, $rFile), $rForce) === null) {
                $rHadError = true;
            }
        }

        $this->recordVersion('geolite2_version', $rVersion);
        return $rHadError;
    }

    /**
     * Sync the free GeoIP2-ISP database from the latest release and record its
     * version — the exact same path as {@see updateGeoLite()}.
     */
    public function updateIsp(bool $rForce): void {
        $rVersion = $this->rRepo->getReleases()[0] ?? null;
        if ($rVersion === null) {
            return;
        }

        if ($this->downloadReleaseFile($this->assetSpec($rVersion, self::ISP_FILE), $rForce) !== null) {
            $this->recordVersion('geoisp_version', $rVersion);
        }
    }

    /**
     * Build the download spec for one maxmind asset in a given release.
     *
     * @return array{fileurl: string, path: string, md5: ?string}
     */
    private function assetSpec(string $rVersion, string $rFile): array {
        return array(
            'fileurl' => $this->rRepo->assetUrl($rVersion, $rFile),
            'path'    => self::DIR . $rFile,
            'md5'     => $this->rRepo->getAssetHash($rVersion, $rFile),
        );
    }

    /**
     * Merge a single key into maxmind/version.json.
     *
     * @param mixed $rVersion
     */
    protected function recordVersion(string $rKey, $rVersion): void {
        $rData = json_decode(@file_get_contents(self::VERSION_FILE), true) ?: array();
        $rData[$rKey] = $rVersion;
        file_put_contents(self::VERSION_FILE, json_encode($rData, JSON_PRETTY_PRINT));
    }

    /**
     * Download one release asset: md5-gated skip, create the target dir if
     * missing, and ALWAYS save a successfully downloaded file (the checksum only
     * sets the status line — the release `hashes.md5` can time out over SSL or
     * lag a release, and GeoIP data is non-critical).
     *
     * @param array{fileurl: string, path: string, md5: ?string} $rFile
     * @return bool|null true = downloaded, false = skipped (up to date), null = error.
     */
    protected function downloadReleaseFile(array $rFile, bool $rForce): ?bool {
        if (!$rForce && is_file($rFile['path']) && !empty($rFile['md5']) && md5_file($rFile['path']) === $rFile['md5']) {
            echo '[SKIP]  ' . $rFile['path'] . ': already up to date' . "\n";
            return false;
        }

        $rFolderPath = pathinfo($rFile['path'])['dirname'] . '/';
        if (!file_exists($rFolderPath)) {
            shell_exec('sudo mkdir -p "' . $rFolderPath . '"');
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $rFile['fileurl']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        $rData = curl_exec($ch);
        curl_close($ch);

        if ($rData === false || $rData === '') {
            echo '[ERROR] ' . $rFile['path'] . ': download failed' . "\n";
            return null;
        }

        if (empty($rFile['md5'])) {
            echo '[WARN]  ' . $rFile['path'] . ': saved without checksum (hash unavailable)' . "\n";
        } elseif ($rFile['md5'] === md5($rData)) {
            echo '[OK]    ' . $rFile['path'] . ': updated' . "\n";
        } else {
            echo '[WARN]  ' . $rFile['path'] . ': checksum mismatch — saved anyway' . "\n";
        }

        file_put_contents($rFile['path'], $rData);
        chown($rFile['path'], 'xc_vm');
        chmod($rFile['path'], 0750);
        return true;
    }
}

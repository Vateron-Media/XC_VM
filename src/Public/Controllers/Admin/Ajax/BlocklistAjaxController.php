<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Security\BlocklistService;

/**
 * Admin-ajax controller for the "Blocklists & Security" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: useragent, isp,
 * mysql_syslog, ip, ip_whois, asn, decrypt_text. Block logic ported faithfully
 * (scaffolding via gate/ok/fail from {@see BaseAjaxController}; empty-then
 * `if (c) {} else {…}` idioms flattened to `if (!c) {…}` — behaviour-preserving).
 *
 * Note: `ip_whois` has no per-action permission gate in api.php (it is only
 * behind the shared admin-session + XHR guard); that is preserved here.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BlocklistAjaxController extends BaseAjaxController {

    /** action=useragent — remove a blocked user-agent. */
    public function useragent(): never {
        $this->requireXhr();
        $this->gate('adv', 'block_uas');

        if (RequestManager::get('sub') == 'delete') {
            BlocklistService::deleteBlockedUA(RequestManager::get('ua_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=isp — remove a blocked ISP. */
    public function isp(): never {
        $this->requireXhr();
        $this->gate('adv', 'block_isps');

        if (RequestManager::get('sub') == 'delete') {
            BlocklistService::deleteBlockedISP(RequestManager::get('isp_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=mysql_syslog — block a brute-forcing IP surfaced by the MySQL syslog. */
    public function mysqlSyslog(): never {
        $this->requireXhr();
        $this->gate('adv', 'block_ips');

        global $db;

        if (RequestManager::get('sub') == 'block' && filter_var(RequestManager::get('ip'), FILTER_VALIDATE_IP)) {
            $db->query("INSERT INTO `blocked_ips`(`ip`, `notes`, `date`) VALUES(?, 'MySQL Bruteforce', ?);", RequestManager::get('ip'), time());
            touch(FLOOD_TMP_PATH . 'block_' . RequestManager::get('ip'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=ip — remove a blocked IP. */
    public function ip(): never {
        $this->requireXhr();
        $this->gate('adv', 'block_ips');

        if (RequestManager::get('sub') == 'delete') {
            BlocklistService::deleteBlockedIP(RequestManager::get('ip'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=ip_whois — GeoIP/ISP/ASN whois lookup for an IP (no per-action gate). */
    public function ipWhois(): never {
        $this->requireXhr();

        global $db;
        $rIP = RequestManager::get('ip');
        $rReader = new \MaxMind\Db\Reader(GEOLITE2C_BIN);
        $rResponse = $rReader->get($rIP);

        if (isset($rResponse['location']['time_zone'])) {
            $rDate = new \DateTime('now', new \DateTimeZone($rResponse['location']['time_zone']));
            $rResponse['location']['time'] = $rDate->format('Y-m-d H:i:s');
        }

        $rReader->close();

        if (RequestManager::has('isp')) {
            $rReader = new \MaxMind\Db\Reader(GEOISP_BIN);
            $rResponse['isp'] = $rReader->get($rIP);
            $rReader->close();
        }

        $rResponse['type'] = null;

        if (!empty($rResponse['isp']['autonomous_system_number'])) {
            $rASN = $rResponse['isp']['autonomous_system_number'];
            $db->query('SELECT `type` FROM `blocked_asns` WHERE `asn` = ?;', $rASN);

            if (0 < $db->num_rows()) {
                $rResponse['type'] = $db->get_row()['type'];
            }

            if (file_exists(CIDR_TMP_PATH . $rASN)) {
                $rCIDRs = json_decode(file_get_contents(CIDR_TMP_PATH . $rASN), true);

                foreach ($rCIDRs as $rCIDR => $rData) {
                    if (ip2long($rData[1]) <= ip2long($rIP) && ip2long($rIP) <= ip2long($rData[2])) {
                        $rTypes = array();

                        if ($rData[3]) {
                            $rTypes[] = 'HOSTING';
                        }

                        if ($rData[4]) {
                            $rTypes[] = 'PROXY';
                        }

                        $rResponse['type'] = implode(', ', $rTypes);

                        break;
                    }
                }
            }
        }

        $this->ok(array('data' => $rResponse));
    }

    /** action=asn — block/allow a single ASN or a whole ASN type. */
    public function asn(): never {
        $this->requireXhr();
        $this->gate('adv', 'block_isps');

        global $db;
        $rSub = RequestManager::get('sub');
        $rASN = RequestManager::get('id');

        if ($rSub == 'allow') {
            $db->query('UPDATE `blocked_asns` SET `blocked` = 0 WHERE `id` = ?;', $rASN);
            $this->ok();
        }

        if ($rSub == 'block') {
            $db->query('UPDATE `blocked_asns` SET `blocked` = 1 WHERE `id` = ?;', $rASN);
            $this->ok();
        }

        if ($rSub == 'allow_all') {
            $db->query('UPDATE `blocked_asns` SET `blocked` = 0 WHERE `type` = ?;', $rASN);
            $this->ok();
        }

        if ($rSub == 'block_all') {
            $db->query('UPDATE `blocked_asns` SET `blocked` = 1 WHERE `type` = ?;', $rASN);
            $this->ok();
        }

        $this->fail();
    }

    /** action=decrypt_text — decrypt live-stream tokens pasted as text. */
    public function decryptText(): never {
        $this->requireXhr();
        $this->gate('adv', 'stream_tools');

        $rDecryptedArray = array();
        $rText = (RequestManager::get('text') ?: null);

        if ($rText) {
            $rLines = explode("\n", $rText);

            foreach ($rLines as $rLine) {
                $rSplit = explode('/', $rLine);

                foreach ($rSplit as $rPiece) {
                    if (stripos($rPiece, 'token=') !== false) {
                        list(, $rPiece) = explode('token=', $rPiece);
                    }

                    $rDecoded = base64_decode(strtr($rPiece, '-_', '+/'));

                    if (!empty($rDecoded)) {
                        try {
                            $rDecrypted = Encryption::decrypt($rPiece, SettingsManager::get('live_streaming_pass'), OPENSSL_EXTRA);
                        } catch (\Exception) {
                            $rDecrypted = null;
                        }

                        if ($rDecrypted) {
                            $rDecryptedArray[] = utf8_decode($rDecrypted);
                        }
                    }
                }
            }
        }

        if (0 < count($rDecryptedArray)) {
            $this->ok(array('data' => $rDecryptedArray));
        }

        $this->fail();
    }
}

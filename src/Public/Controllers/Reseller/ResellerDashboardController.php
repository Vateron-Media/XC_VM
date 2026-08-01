<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;/**
 * ResellerDashboardController — Reseller dashboard.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerDashboardController extends BaseResellerController
{
    public function index()
    {
        $this->setTitle('Dashboard');

        $rUserInfo = $GLOBALS['rUserInfo'];
        $rPermissions = $GLOBALS['rPermissions'];
        $rSettings = SettingsManager::getAll();

        $rRegisteredUsers = UserRepository::getResellers($rUserInfo['id'], true);
        $rGroups = GroupService::getAll();

        // Sanitize notice HTML
        $rNotice = html_entity_decode($rGroups[$rUserInfo['member_group_id']]['notice_html'] ?? '');
        $rNotice = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $rNotice);
        $rNotice = preg_replace('#</*\\w+:\\w[^>]*+>#i', '', $rNotice);
        $rNotice = str_replace(array('&amp;', '&lt;', '&gt;'), array('&amp;amp;', '&amp;lt;', '&amp;gt;'), $rNotice);
        $rNotice = preg_replace('/(&#*\\w+)[\\x00-\\x20]+;/u', '$1;', $rNotice);
        $rNotice = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $rNotice);
        $rNotice = html_entity_decode($rNotice, ENT_COMPAT, 'UTF-8');
        $rNotice = preg_replace("#(<[^>]+?[\\x00-\\x20\"'])(?:on|xmlns)[^>]*+[>\\b]?#iu", '$1>', $rNotice);
        $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=[\\x00-\\x20]*([`'\"]*)[\\x00-\\x20]*j[\\x00-\\x20]*a[\\x00-\\x20]*v[\\x00-\\x20]*a[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2nojavascript...', $rNotice);
        $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*v[\\x00-\\x20]*b[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2novbscript...', $rNotice);
        $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*-moz-binding[\\x00-\\x20]*:#u", '$1=$2nomozbinding...', $rNotice);
        $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?expression[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
        $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?behaviour[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
        $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:*[^>]*+>#iu", '$1>', $rNotice);

        // Recent activity
        $rPackages = PackageService::getAll();
        global $db;
        $rAllReports = array_merge(array($rUserInfo['id']), $rPermissions['all_reports'] ?? []);
        $db->query('SELECT `users`.`username`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` WHERE `users_logs`.`owner` IN (' . implode(',', array_map('intval', $rAllReports)) . ') ORDER BY `users_logs`.`date` DESC LIMIT 250;');
        $rActivityRows = [];
        $rDeviceMap = ['line' => 'User Line', 'mag' => 'MAG Device', 'enigma' => 'Enigma2 Device', 'user' => 'Reseller'];
        foreach ($db->get_rows() as $rRow) {
            $rDevice = $rDeviceMap[$rRow['type']] ?? '';
            $rText = '';
            switch ($rRow['action']) {
                case 'new':
                    $rText = 'Created New ' . $rDevice . ($rRow['package_id'] && isset($rPackages[$rRow['package_id']]) ? ' with Package:<br/>' . $rPackages[$rRow['package_id']]['package_name'] : '');
                    break;
                case 'extend':
                    $rText = 'Extended ' . $rDevice . ($rRow['package_id'] && isset($rPackages[$rRow['package_id']]) ? ' with Package:<br/>' . $rPackages[$rRow['package_id']]['package_name'] : '');
                    break;
                case 'convert':
                    $rText = 'Converted Device to User Line';
                    break;
                case 'edit':
                    $rText = 'Edited ' . $rDevice;
                    break;
                case 'enable':
                    $rText = 'Enabled ' . $rDevice;
                    break;
                case 'disable':
                    $rText = 'Disabled ' . $rDevice;
                    break;
                case 'delete':
                    $rText = 'Deleted ' . $rDevice;
                    break;
                case 'send_event':
                    $rText = 'Sent Event to ' . $rDevice;
                    break;
                case 'adjust_credits':
                    $rText = 'Adjusted Credits by ' . $rRow['cost'];
                    break;
            }
            $rTargetHtml = '';
            $rTargetId = intval($rRow['log_id'] ?? 0);
            switch ($rRow['type']) {
                case 'line':
                    $rTarget = UserRepository::getLineById($rTargetId);
                    if ($rTarget) {
                        $rTargetHtml = "<a class='text-dark' href='line?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['username'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    break;
                case 'user':
                    $rTarget = UserRepository::getRegisteredUserById($rTargetId);
                    if ($rTarget) {
                        $rTargetHtml = "<a class='text-dark' href='user?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['username'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    break;
                case 'mag':
                    $rTarget = \XcVm\Domain\Device\MagService::getById($rTargetId);
                    if ($rTarget) {
                        $rTargetHtml = "<a class='text-dark' href='mag?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['mac'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    break;
                case 'enigma':
                    $rTarget = \XcVm\Domain\Device\EnigmaService::getById($rTargetId);
                    if ($rTarget) {
                        $rTargetHtml = "<a class='text-dark' href='enigma?id=" . $rTargetId . "'>" . htmlspecialchars((string) $rTarget['mac'], ENT_QUOTES, 'UTF-8') . '</a>';
                    }
                    break;
            }
            if ($rTargetHtml === '') {
                $rDeletedInfo = json_decode((string) ($rRow['deleted_info'] ?? ''), true);
                $rTargetName = is_array($rDeletedInfo)
                    ? (string) ($rDeletedInfo['mac'] ?? $rDeletedInfo['username'] ?? '')
                    : '';
                $rTargetHtml = $rTargetName !== ''
                    ? "<span class='text-secondary'>" . htmlspecialchars($rTargetName, ENT_QUOTES, 'UTF-8') . '</span>'
                    : "<span class='text-secondary'>-</span>";
            }
            $rActivityRows[] = [
                'owner_id'  => $rRow['owner'],
                'username'  => $rRow['username'],
                'text'        => $rText,
                'target_html' => $rTargetHtml,
                'date'        => $rRow['date'],
            ];
        }

        // Expiring lines
        $rExpiringLines = LineService::getExpiring() ?: [];

        $this->render('dashboard', [
            'rRegisteredUsers' => $rRegisteredUsers,
            'rNotice'          => $rNotice,
            'rActivityRows'    => $rActivityRows,
            'rExpiringLines'   => $rExpiringLines,
        ]);
    }
}

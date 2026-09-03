<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Reference\GeoReference;

/**
 * DashboardController — Dashboard page.
 *
 * Complex data-prep: theme colours, connection map queries, server stats.
 * Dashboard has NO PageAuthorization::checkPermissions() — it uses server_id validation instead.
 *
 * @renders Views/admin/dashboard.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class DashboardController extends BaseAdminController {
    public function index() {
        global $db, $rUserInfo, $rServers;

        $rCountryCodes = GeoReference::countryCodes();

        // Theme colour map
        if (Theme::fromId($rUserInfo['theme'])->isDark()) {
            $rColours = array(1 => array('secondary', '#7e8e9d', '#ffffff'), 2 => array('secondary', '#7e8e9d', '#ffffff'), 3 => array('secondary', '#7e8e9d', '#ffffff'), 4 => array('secondary', '#7e8e9d', '#ffffff'));
            $rColourMap = array(array('#7e8e9d', 'bg-map-dark-1'), array('#6c7b8a', 'bg-map-dark-2'), array('#5a6977', 'bg-map-dark-3'), array('#485765', 'bg-map-dark-4'), array('#374654', 'bg-map-dark-5'), array('#273643', 'bg-map-dark-6'));
        } else {
            $rColours = array(1 => array('purple', '#675db7', '#675db7'), 2 => array('success', '#23b397', '#23b397'), 3 => array('pink', '#e36498', '#e36498'), 4 => array('info', '#56C3D6', '#56C3D6'));
            $rColourMap = array(array('#23b397', 'bg-success'), array('#56c2d6', 'bg-info'), array('#5089de', 'bg-primary'), array('#675db7', 'bg-purple'), array('#e36498', 'bg-pink'), array('#98a6ad', 'bg-secondary'));
        }

        // Server ID validation
        if (!RequestManager::has('server_id') || isset($rServers[RequestManager::get('server_id')])) {
        } else {
            $this->redirect('dashboard');
            return;
        }

        // Connection map
        $rConnectionMap = array();
        $rConnectionCount = 0;

        if (RequestManager::has('server_id')) {
            $db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` WHERE (`server_id` = ? OR `proxy_id` = ?) GROUP BY `geoip_country_code` ORDER BY `count` DESC;', intval(RequestManager::get('server_id')), intval(RequestManager::get('server_id')));
        } else {
            $db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` GROUP BY `geoip_country_code` ORDER BY `count` DESC;');
        }

        if (0 >= $db->num_rows()) {
        } else {
            $i = 0;
            foreach ($db->get_rows() as $rRow) {
                if ($i < count($rColourMap)) {
                    $rRow['colour'] = $rColourMap[$i];
                } else {
                    $rRow['colour'] = $rColourMap[count($rColourMap) - 1];
                }
                if (isset($rCountryCodes[$rRow['geoip_country_code']])) {
                    $rRow['name'] = $rCountryCodes[$rRow['geoip_country_code']];
                } else {
                    $rRow['name'] = 'Unknown Country';
                }
                $rConnectionCount += $rRow['count'];
                $rConnectionMap[] = $rRow;
                $i++;
            }
        }

        // Server stats (when no server filter)
        $rServerStats = array();
        if (!RequestManager::has('server_id')) {
            $rLimit = 3600;
            $rTime = time();
            $rNearestRange = $rTime - $rLimit;
            $db->query('SELECT * FROM `servers_stats` WHERE `time` >= ? ORDER BY `time` ASC;', $rNearestRange);
            if (0 < $db->num_rows()) {
                foreach ($db->get_rows() as $rRow) {
                    $rServerStats[intval($rRow['server_id'])][] = $rRow['cpu'];
                }
            }
        }

        $rOrderedServers = $rServers;
        array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

        // Service-status timeline items (prepared here so the view stays free of
        // DB / filesystem probes). Each item: ['state', 'title', 'text'].
        $rStatusItems = $this->buildStatusItems($db, $rOrderedServers);

        // The Bootstrap 5 dashboard renders CPU/network/connection charts with ApexCharts,
        // and (when enabled and there is data) a jsvectormap world map.
        $rVendors = ['apexcharts'];
        if (SettingsManager::get('save_closed_connection') && SettingsManager::get('dashboard_map') && $rConnectionCount > 0) {
            $rVendors[] = 'jsvectormap';
        }
        $GLOBALS['xmBootstrap 5Vendors'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['xmBootstrap 5Vendors'] ?? []),
            $rVendors
        )));

        $this->setTitle('Dashboard');
        $this->render('dashboard', compact(
            'rColours',
            'rColourMap',
            'rConnectionMap',
            'rConnectionCount',
            'rServerStats',
            'rOrderedServers',
            'rStatusItems'
        ));
    }

    /**
     * Build the "Service Status" timeline the dashboard shows.
     *
     * Runs the health probes (MariaDB JSON support, schema watermark, root-cron
     * freshness, per-server xc_fanout watchdog) that used to live inline in the
     * view. Returns an ordered list of ['state' => 'danger|warning|dark',
     * 'title' => string, 'text' => string(html)]; empty when all checks pass and
     * the caller renders the "no issues" item.
     *
     * @param array<int,array<string,mixed>> $orderedServers
     * @return array<int,array{state:string,title:string,text:string}>
     */
    private function buildStatusItems($db, array $orderedServers): array {
        $items = [];
        $bin = defined('PHP_BIN') ? PHP_BIN : 'php';

        $binRepl = ['{bin}' => htmlspecialchars($bin)];

        try {
            $db->dbh->query("SELECT JSON_CONTAINS('0', 0, '$') AS `json_test`;");
        } catch (\Throwable $e) {
            $items[] = [
                'state' => 'danger',
                'title' => Translator::get('dashboard_status_mariadb_title'),
                'text'  => Translator::get('dashboard_status_mariadb_text'),
            ];
        }

        if (empty(SettingsManager::get('status_uuid')) || SettingsManager::get('status_uuid') != md5(XC_VM_VERSION)) {
            $items[] = [
                'state' => 'warning',
                'title' => Translator::get('dashboard_status_db_incomplete_title'),
                'text'  => Translator::get('dashboard_status_db_incomplete_text', $binRepl),
            ];
        }

        if (!file_exists(CONFIG_PATH . 'signals.last') || time() - filemtime(CONFIG_PATH . 'signals.last') > 600) {
            $items[] = [
                'state' => 'dark',
                'title' => Translator::get('dashboard_status_crons_title'),
                'text'  => Translator::get('dashboard_status_crons_text', $binRepl),
            ];
        }

        // xc_fanout live-delivery daemon — flag any reporting server where it is down.
        $multi = count($orderedServers) > 1;
        foreach ($orderedServers as $srv) {
            $wd = json_decode($srv['watchdog_data'] ?? '{}', true) ?: [];
            $fresh = (time() - intval($srv['last_check_ago'] ?? 0)) < 60;
            if ($fresh && isset($wd['fanout']['running']) && !$wd['fanout']['running']) {
                $title = Translator::get('dashboard_status_fanout_title');
                if ($multi) {
                    $title .= ' ' . Translator::get('dashboard_status_fanout_on') . ' ' . htmlspecialchars($srv['server_name']);
                }
                $items[] = [
                    'state' => 'danger',
                    'title' => $title,
                    'text'  => Translator::get('dashboard_status_fanout_text', $binRepl),
                ];
            }
        }

        return $items;
    }
}

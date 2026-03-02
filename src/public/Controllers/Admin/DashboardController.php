<?php
/**
 * DashboardController — Dashboard page (Phase 6.3 — Group M).
 *
 * Complex data-prep: theme colours, connection map queries, server stats.
 * Dashboard has NO checkPermissions() — it uses server_id validation instead.
 */
class DashboardController extends BaseAdminController
{
    public function index()
    {
        global $db, $rThemes, $rUserInfo, $rServers, $rCountryCodes;

        // Theme colour map
        if ($rThemes[$rUserInfo['theme']]['dark']) {
            $rColours = array(1 => array('secondary', '#7e8e9d', '#ffffff'), 2 => array('secondary', '#7e8e9d', '#ffffff'), 3 => array('secondary', '#7e8e9d', '#ffffff'), 4 => array('secondary', '#7e8e9d', '#ffffff'));
            $rColourMap = array(array('#7e8e9d', 'bg-map-dark-1'), array('#6c7b8a', 'bg-map-dark-2'), array('#5a6977', 'bg-map-dark-3'), array('#485765', 'bg-map-dark-4'), array('#374654', 'bg-map-dark-5'), array('#273643', 'bg-map-dark-6'));
        } else {
            $rColours = array(1 => array('purple', '#675db7', '#675db7'), 2 => array('success', '#23b397', '#23b397'), 3 => array('pink', '#e36498', '#e36498'), 4 => array('info', '#56C3D6', '#56C3D6'));
            $rColourMap = array(array('#23b397', 'bg-success'), array('#56c2d6', 'bg-info'), array('#5089de', 'bg-primary'), array('#675db7', 'bg-purple'), array('#e36498', 'bg-pink'), array('#98a6ad', 'bg-secondary'));
        }

        // Server ID validation
        if (!isset(CoreUtilities::$rRequest['server_id']) || isset($rServers[CoreUtilities::$rRequest['server_id']])) {
        } else {
            $this->redirect('dashboard');
            return;
        }

        // Connection map
        $rConnectionMap = array();
        $rConnectionCount = 0;

        if (isset(CoreUtilities::$rRequest['server_id'])) {
            $db->query('SELECT `geoip_country_code`, COUNT(`geoip_country_code`) AS `count` FROM `lines_activity` WHERE (`server_id` = ? OR `proxy_id` = ?) GROUP BY `geoip_country_code` ORDER BY `count` DESC;', intval(CoreUtilities::$rRequest['server_id']), intval(CoreUtilities::$rRequest['server_id']));
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
        if (!isset(CoreUtilities::$rRequest['server_id'])) {
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

        $this->setTitle('Dashboard');
        $this->render('dashboard', compact(
            'rColours', 'rColourMap', 'rConnectionMap', 'rConnectionCount',
            'rServerStats', 'rOrderedServers'
        ));
    }
}

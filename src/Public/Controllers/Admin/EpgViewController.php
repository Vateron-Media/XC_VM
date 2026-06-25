<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Config\SettingsManager;
/**
 * Контроллер просмотра TV Guide (admin/epg_view.php)
 *
 * @renders Views/admin/epg_view.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgViewController extends BaseAdminController {
    public function index() {
        global $db, $rMobile;

        $this->requirePermission();

        if ($rMobile) {
            header('Location: dashboard');
            exit;
        }

        $rPageInt = max(intval(RequestManager::getAll()['page']), 1);
        $rLimit = max(intval(RequestManager::getAll()['entries']), SettingsManager::getAll()['default_entries']);
        $rStart = ($rPageInt - 1) * $rLimit;
        $rWhere = $rWhereV = array();
        $rWhere[] = '`type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL';

        if (isset(RequestManager::getAll()['category']) && intval(RequestManager::getAll()['category']) > 0) {
            $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
            $rWhereV[] = json_encode(intval(RequestManager::getAll()['category']));
        }

        if (!empty(RequestManager::getAll()['search'])) {
            $rWhere[] = '(`stream_display_name` LIKE ? OR `id` LIKE ?)';
            $rWhereV[] = '%' . RequestManager::getAll()['search'] . '%';
            $rWhereV[] = RequestManager::getAll()['search'];
        }

        $rWhereString = (count($rWhere) > 0) ? 'WHERE ' . implode(' AND ', $rWhere) : '';

        $rOrderBy = '`stream_display_name` ASC';
        $rOrder = ['name' => '`stream_display_name` ASC', 'added' => '`added` DESC'];
        if (!empty(RequestManager::getAll()['sort']) && isset($rOrder[RequestManager::getAll()['sort']])) {
            $rOrderBy = $rOrder[RequestManager::getAll()['sort']];
        } else {
            // Honour the configured "Channel Sorting Type" like the playlists and
            // the reseller guide do: manual => the `order` column, otherwise the
            // cached bouquet channel order. Without this the guide fell back to
            // alphabetical and ignored the manual order entirely.
            $rChannelOrder = array();
            if (file_exists(CACHE_TMP_PATH . 'channel_order')) {
                $rChannelOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order')) ?: array();
            }
            if (SettingsManager::getAll()['channel_number_type'] != 'manual' && count($rChannelOrder) > 0) {
                $rOrderBy = 'FIELD(`id`,' . implode(',', array_map('intval', $rChannelOrder)) . ')';
            } else {
                $rOrderBy = '`order` ASC';
            }
        }

        $rStreamIDs = array();
        $db->query('SELECT COUNT(`id`) AS `count` FROM `streams` ' . $rWhereString . ';', ...$rWhereV);
        $rCount = $db->get_row()['count'];
        $db->query('SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';', ...$rWhereV);

        foreach ($db->get_rows() as $rRow) {
            $rStreamIDs[] = $rRow['id'];
        }
        $rPages = ceil($rCount / $rLimit);
        $rPagination = array();

        foreach (range(max($rPageInt - 2, 1), min($rPageInt + 2, $rPages)) as $i) {
            $rPagination[] = $i;
        }

        $this->setTitle('TV Guide');
        $this->render('epg_view', compact(
            'rPageInt',
            'rLimit',
            'rStart',
            'rStreamIDs',
            'rCount',
            'rPages',
            'rPagination',
            'rWhereString',
            'rOrderBy'
        ));
    }
}

<?php
/**
 * ResellerEpgViewController — EPG preview.
 */
class ResellerEpgViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $rMobile = $GLOBALS['rMobile'] ?? false;
        if ($rMobile) {
            header('Location: dashboard');
            exit;
        }

        $rRequest     = $GLOBALS['rRequest'] ?? [];
        $rPermissions = $GLOBALS['rPermissions'] ?? [];
        $db           = $GLOBALS['db'];

        $rPageInt = (intval($rRequest['page'] ?? 0) > 0 ? intval($rRequest['page']) : 1);
        $rLimit   = (intval($rRequest['entries'] ?? 0) > 0 ? intval($rRequest['entries']) : SettingsManager::getAll()['default_entries']);
        $rStart   = ($rPageInt - 1) * $rLimit;

        $rStreamIDs = [];
        $rCount     = 0;

        if (count($rPermissions['stream_ids'] ?? []) > 0) {
            $rWhere  = [];
            $rWhereV = [];
            $rWhere[] = '`type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL';
            $rWhere[] = '`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'] ?? [])) . ')';

            if (isset($rRequest['category']) && intval($rRequest['category']) > 0) {
                $rWhere[]  = "JSON_CONTAINS(`category_id`, ?, '\$')";
                $rWhereV[] = $rRequest['category'];
            }

            if (!empty($rRequest['search'])) {
                $rWhere[]  = '(`stream_display_name` LIKE ? OR `id` LIKE ?)';
                $rWhereV[] = '%' . $rRequest['search'] . '%';
                $rWhereV[] = $rRequest['search'];
            }

            $rWhereString = count($rWhere) > 0 ? 'WHERE ' . implode(' AND ', $rWhere) : '';

            $rOrder = ['name' => '`stream_display_name` ASC', 'added' => '`added` DESC'];

            if (!empty($rRequest['sort']) && isset($rOrder[$rRequest['sort']])) {
                $rOrderBy = $rOrder[$rRequest['sort']];
            } else {
                $rChannelOrder = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'channel_order'));

                if (SettingsManager::getAll()['channel_number_type'] != 'manual' && count($rChannelOrder) > 0) {
                    $rOrderBy = 'FIELD(`id`,' . implode(',', $rChannelOrder) . ')';
                } else {
                    $rOrderBy = '`order` ASC';
                }
            }

            $db->query('SELECT COUNT(`id`) AS `count` FROM `streams` ' . $rWhereString . ';', ...$rWhereV);
            $rCount = $db->get_row()['count'];
            $db->query('SELECT `id` FROM `streams` ' . $rWhereString . ' ORDER BY ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';', ...$rWhereV);

            foreach ($db->get_rows() as $rRow) {
                $rStreamIDs[] = $rRow['id'];
            }
        }

        $rPages      = ceil($rCount / max($rLimit, 1));
        $rPagination = [];
        foreach (range($rPageInt - 2, $rPageInt + 2) as $i) {
            if ($i >= 1 && $i <= $rPages) {
                $rPagination[] = $i;
            }
        }

        $rCategories = CategoryService::getAllByType('live');

        $this->setTitle('TV Guide');
        $this->render('epg_view', [
            'rStreamIDs'  => $rStreamIDs,
            'rCount'      => $rCount,
            'rPageInt'    => $rPageInt,
            'rPages'      => $rPages,
            'rLimit'      => $rLimit,
            'rPagination' => $rPagination,
            'rCategories' => $rCategories,
        ]);
    }
}

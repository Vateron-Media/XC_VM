<?php
/**
 * XC_VM — Контроллер просмотра TV Guide (admin/epg_view.php)
 */

class EpgViewController extends BaseAdminController {
    public function index() {
        global $db, $rMobile;

        $this->requirePermission();

        if ($rMobile) {
            header('Location: dashboard');
            exit;
        }

        $rPageInt = max(intval(CoreUtilities::$rRequest['page']), 1);
        $rLimit = max(intval(CoreUtilities::$rRequest['entries']), CoreUtilities::$rSettings['default_entries']);
        $rStart = ($rPageInt - 1) * $rLimit;
        $rWhere = $rWhereV = array();
        $rWhere[] = '`type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL';

        if (isset(CoreUtilities::$rRequest['category']) && intval(CoreUtilities::$rRequest['category']) > 0) {
            $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
            $rWhereV[] = json_encode(intval(CoreUtilities::$rRequest['category']));
        }

        if (!empty(CoreUtilities::$rRequest['search'])) {
            $rWhere[] = '(`stream_display_name` LIKE ? OR `id` LIKE ?)';
            $rWhereV[] = '%' . CoreUtilities::$rRequest['search'] . '%';
            $rWhereV[] = CoreUtilities::$rRequest['search'];
        }

        $rWhereString = (count($rWhere) > 0) ? 'WHERE ' . implode(' AND ', $rWhere) : '';

        $rOrderBy = '`stream_display_name` ASC';
        $rOrder = ['name' => '`stream_display_name` ASC', 'added' => '`added` DESC'];
        if (!empty(CoreUtilities::$rRequest['sort']) && in_array(CoreUtilities::$rRequest['sort'], array('name', 'added'))) {
            $rOrderBy = $rOrder[CoreUtilities::$rRequest['sort']];
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
            'rPageInt', 'rLimit', 'rStart', 'rStreamIDs',
            'rCount', 'rPages', 'rPagination', 'rWhereString', 'rOrderBy'
        ));
    }
}

<?php
/**
 * ChannelOrderController — порядок каналов (Phase 6.3 — Group A).
 */
class ChannelOrderController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rOverride = isset(CoreUtilities::$rRequest['override']);
        $rOrdered = array('stream' => array(), 'movie' => array(), 'series' => array(), 'radio' => array());
        $db->query('SELECT COUNT(`id`) AS `count` FROM `streams`;');
        $rCount = $db->get_row()['count'];

        if (!($rCount <= 50000 || $rOverride)) {
        } else {
            $db->query('SELECT `id`, `type`, `stream_display_name`, `category_id` FROM `streams` ORDER BY `order` ASC, `stream_display_name` ASC;');

            if (0 >= $db->num_rows()) {
            } else {
                foreach ($db->get_rows() as $rRow) {
                    if ($rRow['type'] == 1 || $rRow['type'] == 3) {
                        $rOrdered['stream'][] = $rRow;
                    } else {
                        if ($rRow['type'] == 2) {
                            $rOrdered['movie'][] = $rRow;
                        } else {
                            if ($rRow['type'] == 4) {
                                $rOrdered['radio'][] = $rRow;
                            } else {
                                if ($rRow['type'] != 5) {
                                } else {
                                    $rOrdered['series'][] = $rRow;
                                }
                            }
                        }
                    }
                }
            }
        }

        $this->setTitle('Channel Order');
        $this->render('channel_order', compact('rOverride', 'rOrdered', 'rCount'));
    }
}

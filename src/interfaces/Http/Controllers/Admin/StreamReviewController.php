<?php
/**
 * StreamReviewController — обзор стримов (Phase 6.3 — Group A).
 */
class StreamReviewController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        if (isset(CoreUtilities::$rRequest['save_changes'])) {
            $rChanges = array();

            foreach (array_keys(CoreUtilities::$rRequest) as $rKey) {
                $rSplit = explode('_', $rKey);

                if (!($rSplit[0] == 'modified' && CoreUtilities::$rRequest[$rKey] == 1)) {
                } else {
                    $rID = intval($rSplit[1]);
                    $rChanges[$rID] = array();

                    foreach (array('name', 'channel_id', 'epg_id') as $rChangeKey) {
                        $rChanges[$rID][$rChangeKey] = CoreUtilities::$rRequest[$rChangeKey . '_' . $rID];
                    }

                    foreach (array('bouquets', 'categories') as $rChangeKey) {
                        $rChanges[$rID][$rChangeKey] = json_decode(CoreUtilities::$rRequest[$rChangeKey . '_' . $rID], true);
                    }
                }
            }

            foreach ($rChanges as $rID => $rStream) {
                if (!CoreUtilities::$rRequest['save_bouquets']) {
                } else {
                    $rHasBouquets = array();

                    foreach (CoreUtilities::$rBouquets as $rBouquetID => $rBouquet) {
                        if (!(in_array($rID, $rBouquet['streams']) || in_array($rID, $rBouquet['channels']))) {
                        } else {
                            $rHasBouquets[] = $rBouquetID;
                        }
                    }
                    $rDelBouquet = $rAddBouquet = array();

                    foreach ($rHasBouquets as $rBouquetID) {
                        if (in_array($rBouquetID, $rStream['bouquets'])) {
                        } else {
                            removeFromBouquet('stream', $rBouquetID, $rID);
                        }
                    }

                    foreach ($rStream['bouquets'] as $rBouquetID) {
                        if (in_array($rBouquetID, $rHasBouquets)) {
                        } else {
                            $rAddBouquet[] = $rBouquetID;
                            addToBouquet('stream', $rBouquetID, $rID);
                        }
                    }
                }

                if (CoreUtilities::$rRequest['save_categories'] && CoreUtilities::$rRequest['save_epg']) {
                    $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ?, `channel_id` = ?, `epg_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', ($rStream['channel_id'] ?: null), (is_null($rStream['epg_id']) ? null : $rStream['epg_id']), $rID);
                } else {
                    if (CoreUtilities::$rRequest['save_categories']) {
                        $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', $rID);
                    } else {
                        if (CoreUtilities::$rRequest['save_epg']) {
                            $db->query('UPDATE `streams` SET `stream_display_name` = ?, `channel_id` = ?, `epg_id` = ?, WHERE `id` = ?;', $rStream['name'], ($rStream['channel_id'] ?: null), (is_null($rStream['epg_id']) ? null : $rStream['epg_id']), $rID);
                        } else {
                            $db->query('UPDATE `streams` SET `stream_display_name` = ? WHERE `id` = ?;', $rStream['name'], $rID);
                        }
                    }
                }
            }
            header('Location: ./streams?status=' . STATUS_SUCCESS);

            exit();
        } else {
            if (!isset(CoreUtilities::$rRequest['streams'])) {
            } else {
                $rStreams = json_decode(CoreUtilities::$rRequest['streams'], true);
                $rCategories = getCategories('live');
                $rBouquets = BouquetService::getAllSimple();
                $rStreamBouquets = array();
                foreach ($rBouquets as $rBouquet) {
                    $rBouquetChannels = json_decode($rBouquet['bouquet_channels'], true);

                    foreach ($rBouquetChannels as $rStreamID) {
                        if (!in_array($rStreamID, $rStreams)) {
                        } else {
                            $rStreamBouquets[$rStreamID][] = $rBouquet['id'];
                        }
                    }
                }
                $rOptions = array('categories' => isset(CoreUtilities::$rRequest['edit_categories']), 'epg' => isset(CoreUtilities::$rRequest['edit_epg']), 'bouquets' => isset(CoreUtilities::$rRequest['edit_bouquets']));
                $rWidth = array(25, 20, 20);

                if ($rOptions['categories'] || $rOptions['bouquets'] || $rOptions['epg']) {
                } else {
                    $rWidth = array(90, 0, 0);
                }

                $rImport = array();

                if (0 >= count($rStreams)) {
                } else {
                    $db->query('SELECT * FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreams)) . ');');

                    foreach ($db->get_rows() as $rRow) {
                        $rImport[] = array('id' => $rRow['id'], 'channel_id' => ($rRow['channel_id'] ?: ''), 'epg_id' => ($rRow['epg_id'] ?: ''), 'title' => ($rRow['stream_display_name'] ?: ''), 'category' => json_decode($rRow['category_id'], true), 'bouquets' => ($rStreamBouquets[$rRow['id']] ?: array()));
                    }
                }

                if (count($rImport) != 0) {
                } else {
                    $_STATUS = STATUS_NO_SOURCES;
                    $rImport = null;
                }
            }
        }

        $this->setTitle('Review');
        $this->render('stream_review', compact('rStreams', 'rCategories', 'rBouquets', 'rStreamBouquets', 'rOptions', 'rWidth', 'rImport'));
    }
}

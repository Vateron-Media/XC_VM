<?php

/**
 * StreamReviewController — обзор стримов.
 *
 * @renders Views/admin/stream_review.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamReviewController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        if (isset(RequestManager::getAll()['save_changes'])) {
            $rChanges = array();

            foreach (array_keys(RequestManager::getAll()) as $rKey) {
                $rSplit = explode('_', $rKey);

                if (!($rSplit[0] == 'modified' && RequestManager::getAll()[$rKey] == 1)) {
                } else {
                    $rID = intval($rSplit[1]);
                    $rChanges[$rID] = array();

                    foreach (array('name', 'channel_id', 'epg_id') as $rChangeKey) {
                        $rChanges[$rID][$rChangeKey] = RequestManager::getAll()[$rChangeKey . '_' . $rID];
                    }

                    foreach (array('bouquets', 'categories') as $rChangeKey) {
                        $rChanges[$rID][$rChangeKey] = json_decode(RequestManager::getAll()[$rChangeKey . '_' . $rID], true);
                    }
                }
            }

            foreach ($rChanges as $rID => $rStream) {
                if (!RequestManager::getAll()['save_bouquets']) {
                } else {
                    $rHasBouquets = array();

                    foreach (BouquetService::getAll() as $rBouquetID => $rBouquet) {
                        if (!(in_array($rID, $rBouquet['streams']) || in_array($rID, $rBouquet['channels']))) {
                        } else {
                            $rHasBouquets[] = $rBouquetID;
                        }
                    }
                    $rDelBouquet = $rAddBouquet = array();

                    foreach ($rHasBouquets as $rBouquetID) {
                        if (in_array($rBouquetID, $rStream['bouquets'])) {
                        } else {
                            BouquetService::removeItems('stream', $rBouquetID, $rID);
                        }
                    }

                    foreach ($rStream['bouquets'] as $rBouquetID) {
                        if (in_array($rBouquetID, $rHasBouquets)) {
                        } else {
                            $rAddBouquet[] = $rBouquetID;
                            BouquetService::addItems('stream', $rBouquetID, $rID);
                        }
                    }
                }

                if (RequestManager::getAll()['save_categories'] && RequestManager::getAll()['save_epg']) {
                    $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ?, `channel_id` = ?, `epg_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', ($rStream['channel_id'] ?: null), (is_null($rStream['epg_id']) ? null : $rStream['epg_id']), $rID);
                } else {
                    if (RequestManager::getAll()['save_categories']) {
                        $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', $rID);
                    } else {
                        if (RequestManager::getAll()['save_epg']) {
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
            if (!isset(RequestManager::getAll()['streams'])) {
            } else {
                $rStreams = json_decode(RequestManager::getAll()['streams'], true);
                $rCategories = CategoryService::getAllByType('live');
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
                $rOptions = array('categories' => isset(RequestManager::getAll()['edit_categories']), 'epg' => isset(RequestManager::getAll()['edit_epg']), 'bouquets' => isset(RequestManager::getAll()['edit_bouquets']));
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

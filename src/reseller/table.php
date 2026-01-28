<?php
session_start();
session_write_close();
if (file_exists('../www/init.php')) {
    require_once '../www/init.php';
} else {
    require_once '../../../www/init.php';
}
if (!PHP_ERRORS) {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        exit();
    }
}
$rReturn = array('draw' => intval(CoreUtilities::$rRequest['draw']), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => array());
$rIsAPI = false;
if (isset(CoreUtilities::$rRequest['api_key'])) {
    $rReturn = array('status' => 'STATUS_SUCCESS', 'data' => array());
    $db->query('SELECT `id` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_reseller` = 1 AND `status` = 1;', CoreUtilities::$rRequest['api_key']);
    if ($db->num_rows() != 0) {
        $rUserID = $db->get_row()['id'];
        $rIsAPI = true;
        require_once MAIN_HOME . 'includes/admin.php';
        $rUserInfo = getRegisteredUser($rUserID);
        $rPermissions = array_merge(getPermissions($rUserInfo['member_group_id']), getGroupPermissions($rUserInfo['id']));
        if (0 >= strlen($rUserInfo['timezone'])) {
        } else {
            date_default_timezone_set($rUserInfo['timezone']);
        }
    } else {
        echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.'));
        exit();
    }
} else {
    if (isset($_SESSION['reseller'])) {
        include 'functions.php';
    } else {
        echo json_encode($rReturn);
        exit();
    }
}
if (!$rUserInfo['id']) {
    echo json_encode($rReturn);
    exit();
}
if (isset($rUserInfo['reports'])) {
    $rType = CoreUtilities::$rRequest['id'];
    $rStart = intval(CoreUtilities::$rRequest['start']);
    $rLimit = intval(CoreUtilities::$rRequest['length']);
    if (1000 < $rLimit || $rLimit <= 0) {
        $rLimit = 1000;
    }
    if ($rType == 'lines') {
        if ($rPermissions['create_line']) {
            $rOrder = array('`lines`.`id`', '`lines`.`username`', '`lines`.`password`', '`users`.`username`', '`lines`.`enabled` - `lines`.`admin_enabled`', '`active_connections` > 0', '`lines`.`is_trial`', '`active_connections`', '`lines`.`max_connections`', '`lines`.`exp_date`', '`last_activity`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rWhere = $rWhereV = array();
            $rWhere[] = '`lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0';
            $rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
            if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
            } else {
                foreach (range(1, 6) as $rInt) {
                    $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                }
                $rWhere[] = '(`lines`.`username` LIKE ? OR `lines`.`password` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`max_connections` LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
            }
            if (0 >= strlen(CoreUtilities::$rRequest['filter'])) {
            } else {
                if (CoreUtilities::$rRequest['filter'] == 1) {
                    $rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
                } else {
                    if (CoreUtilities::$rRequest['filter'] == 2) {
                        $rWhere[] = '`lines`.`enabled` = 0';
                    } else {
                        if (CoreUtilities::$rRequest['filter'] == 3) {
                            $rWhere[] = '`lines`.`admin_enabled` = 0';
                        } else {
                            if (CoreUtilities::$rRequest['filter'] == 4) {
                                $rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
                            } else {
                                if (CoreUtilities::$rRequest['filter'] != 5) {
                                } else {
                                    $rWhere[] = '`lines`.`is_trial` = 1';
                                }
                            }
                        }
                    }
                }
            }
            if (0 >= strlen(CoreUtilities::$rRequest['reseller'])) {
            } else {
                $rWhere[] = '`lines`.`member_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['reseller'];
            }
            if (0 < count($rWhere)) {
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
            } else {
                $rWhereString = '';
            }
            $rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` ' . $rWhereString . ';';
            if (!$rOrder[$rOrderRow]) {
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
            }
            $db->query($rCountQuery, ...$rWhereV);
            if ($db->num_rows() == 1) {
                $rReturn['recordsTotal'] = $db->get_row()['count'];
            } else {
                $rReturn['recordsTotal'] = 0;
            }
            $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
            if (0 >= $rReturn['recordsTotal']) {
            } else {
                $rQuery = 'SELECT `lines`.`id`, `lines`.`member_id`, `lines`.`last_activity`, `lines`.`last_activity_array`, `lines`.`username`, `lines`.`password`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`is_restreamer`, `lines`.`enabled`, `lines`.`admin_notes`, `lines`.`reseller_notes`, `lines`.`max_connections`, `lines`.`is_trial`, `lines`.`contact`, `lines`.`is_isplock`, (SELECT COUNT(*) AS `active_connections` FROM `lines_live` WHERE `user_id` = `lines`.`id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                $db->query($rQuery, ...$rWhereV);
                if (0 >= $db->num_rows()) {
                } else {
                    $rRows = $db->get_rows();
                    $rActivityIDs = $rLineInfo = $rLineIDs = array();
                    foreach ($rRows as $rRow) {
                        $rLineIDs[] = intval($rRow['id']);
                        $rLineInfo[intval($rRow['id'])] = array('owner_name' => null, 'stream_display_name' => null, 'stream_id' => null, 'last_active' => null);
                        if ($rLastInfo = json_decode($rRow['last_activity_array'], true)) {
                            $rLineInfo[intval($rRow['id'])]['stream_id'] = $rLastInfo['stream_id'];
                            $rLineInfo[intval($rRow['id'])]['last_active'] = $rLastInfo['date_end'];
                        } else {
                            if (!$rRow['last_activity']) {
                            } else {
                                $rActivityIDs[] = intval($rRow['last_activity']);
                            }
                        }
                    }
                    if (0 >= count($rLineIDs)) {
                    } else {
                        $db->query('SELECT `users`.`username`, `lines`.`id` FROM `users` LEFT JOIN `lines` ON `lines`.`member_id` = `users`.`id` WHERE `lines`.`id` IN (' . implode(',', $rLineIDs) . ');');
                        foreach ($db->get_rows() as $rRow) {
                            $rLineInfo[$rRow['id']]['owner_name'] = $rRow['username'];
                        }
                        if (CoreUtilities::$rSettings['redis_handler']) {
                            $rConnectionCount = array();
                            $rConnectionMap = CoreUtilities::getUserConnections($rLineIDs, false);
                            $rStreamIDs = array();
                            foreach ($rConnectionMap as $rUserID => $rConnections) {
                                foreach ($rConnections as $rConnection) {
                                    if (in_array($rConnection['stream_id'], $rStreamIDs)) {
                                    } else {
                                        $rStreamIDs[] = intval($rConnection['stream_id']);
                                    }
                                }
                            }
                            $rStreamMap = array();
                            if (0 >= count($rStreamIDs)) {
                            } else {
                                $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');
                                foreach ($db->get_rows() as $rRow) {
                                    $rStreamMap[$rRow['id']] = $rRow['stream_display_name'];
                                }
                            }
                            foreach (array_keys($rConnectionMap) as $rUserID) {
                                array_multisort(array_column($rConnectionMap[$rUserID], 'date_start'), SORT_DESC, $rConnectionMap[$rUserID]);
                                $rLineInfo[$rUserID]['stream_display_name'] = $rStreamMap[$rConnectionMap[$rUserID][0]['stream_id']];
                                $rLineInfo[$rUserID]['stream_id'] = $rConnectionMap[$rUserID][0]['stream_id'];
                                $rLineInfo[$rUserID]['last_active'] = $rConnectionMap[$rUserID][0]['date_start'];
                                $rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
                            }
                            unset($rConnectionMap);
                        } else {
                            $db->query('SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`user_id` IN (' . implode(',', $rLineIDs) . ');');
                            foreach ($db->get_rows() as $rRow) {
                                $rLineInfo[$rRow['user_id']]['stream_display_name'] = $rRow['stream_display_name'];
                                $rLineInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
                                $rLineInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
                            }
                        }
                    }
                    if (0 >= count($rActivityIDs)) {
                    } else {
                        $db->query('SELECT `user_id`, `stream_id`, `date_end` AS `last_active` FROM `lines_activity` WHERE `activity_id` IN (' . implode(',', $rActivityIDs) . ');');
                        foreach ($db->get_rows() as $rRow) {
                            if (isset($rLineInfo[$rRow['user_id']]['stream_id'])) {
                            } else {
                                $rLineInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
                                $rLineInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
                            }
                        }
                    }
                    foreach ($rRows as $rRow) {
                        $rRow = array_merge($rRow, $rLineInfo[$rRow['id']]);
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
                        }
                        if (!$rIsAPI) {
                            if (!$rRow['admin_enabled']) {
                                $rStatus = '<i class="text-danger fas fa-square tooltip" title="Banned"></i>';
                            } else {
                                if (!$rRow['enabled']) {
                                    $rStatus = '<i class="text-secondary fas fa-square tooltip" title="Disabled"></i>';
                                } else {
                                    if ($rRow['exp_date'] && $rRow['exp_date'] < time()) {
                                        $rStatus = '<i class="text-warning far fa-square tooltip" title="Expired"></i>';
                                    } else {
                                        $rStatus = '<i class="text-success fas fa-square tooltip" title="Active"></i>';
                                    }
                                }
                            }
                            if (0 < $rRow['active_connections']) {
                                $rActive = '<i class="text-success fas fa-square"></i>';
                            } else {
                                $rActive = '<i class="text-secondary far fa-square"></i>';
                            }
                            if ($rRow['is_trial']) {
                                $rTrial = '<i class="text-warning fas fa-square"></i>';
                            } else {
                                $rTrial = '<i class="text-secondary far fa-square"></i>';
                            }
                            if ($rRow['exp_date']) {
                                if ($rRow['exp_date'] < time()) {
                                    $rExpDate = '<span class="expired">' . date($rSettings['date_format'], $rRow['exp_date']) . '<br/><small>' . date('H:i:s', $rRow['exp_date']) . '</small></span>';
                                } else {
                                    $rExpDate = date($rSettings['date_format'], $rRow['exp_date']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['exp_date']) . '</small>';
                                }
                            } else {
                                $rExpDate = '&infin;';
                            }
                            if (0 < $rRow['active_connections']) {
                                if ($rPermissions['reseller_client_connection_logs']) {
                                    $rActiveConnections = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . intval($rRow['id']) . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['active_connections'] . '</button></a>';
                                } else {
                                    $rActiveConnections = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['active_connections'] . '</button>';
                                }
                            } else {
                                $rActiveConnections = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                            }
                            if ($rRow['max_connections'] == 0) {
                                $rMaxConnections = "<button type='button' class='btn btn-dark text-white btn-xs waves-effect waves-light'>&infin;</button>";
                            } else {
                                $rMaxConnections = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . $rRow['max_connections'] . '</button>';
                            }
                            $rButtons = '<div class="btn-group">';
                            $rNotes = '';
                            if (0 >= strlen($rRow['reseller_notes'])) {
                            } else {
                                if (strlen($rNotes) == 0) {
                                } else {
                                    $rNotes .= "\n";
                                }
                                $rNotes .= $rRow['reseller_notes'];
                            }
                            if (0 < strlen($rNotes)) {
                                $rButtons .= '<button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . $rNotes . '"><i class="mdi mdi-note"></i></button>';
                            } else {
                                $rButtons .= '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-note"></i></button>';
                            }
                            $rButtons .= '<a href="line?id=' . $rRow['id'] . '"><button title="Edit" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>';
                            if (!$rPermissions['allow_download']) {
                            } else {
                                $rButtons .= "<button type=\"button\" title=\"Download Playlist\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"download('" . $rRow['username'] . "', '" . $rRow['password'] . "');\"><i class=\"mdi mdi-download\"></i></button>";
                            }
                            $rWhatsAppContact = !empty($rRow['contact']) ? addslashes($rRow['contact']) : '';
                            $rWhatsAppExp = $rRow['exp_date'] ? $rRow['exp_date'] : 'null';
                            $rButtons .= "<button type=\"button\" title=\"WhatsApp Renewal\" class=\"btn btn-success waves-effect waves-light btn-xs tooltip\" onClick=\"openWhatsApp('" . addslashes($rRow['username']) . "', '" . $rWhatsAppContact . "', " . $rWhatsAppExp . ");\"><i class=\"mdi mdi-whatsapp\"></i></button>";
                            if (!$rPermissions['reseller_client_connection_logs']) {
                            } else {
                                if (0 < $rRow['active_connections']) {
                                    $rButtons .= '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'kill_line');\"><i class=\"fas fa-hammer\"></i></button>";
                                } else {
                                    $rButtons .= '<button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="fas fa-hammer"></i></button>';
                                }
                            }
                            if (!$rRow['is_isplock']) {
                            } else {
                                $rButtons .= '<button title="Reset ISP Lock" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'reset_isp');\"><i class=\"mdi mdi-lock-reset\"></i></button>";
                            }
                            if ($rRow['enabled']) {
                                $rButtons .= '<button title="Disable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= '<button title="Enable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= '<button title="Delete" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            $rButtons .= '</div>';
                            if ($rRow['active_connections'] && $rRow['last_active']) {
                                $rLastActive = "<a href='stream_view?id=" . $rRow['stream_id'] . "'>" . $rRow['stream_display_name'] . "</a><br/><small class='text-secondary'>Online: " . CoreUtilities::secondsToTime(time() - $rRow['last_active']) . '</small>';
                            } else {
                                if ($rRow['last_active']) {
                                    $rLastActive = date($rSettings['date_format'], $rRow['last_active']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['last_active']) . '</small>';
                                } else {
                                    $rLastActive = 'Never';
                                }
                            }
                            if (in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . '</a>';
                            } else {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                            }
                            $rReturn['data'][] = array("<a href='line?id=" . $rRow['id'] . "'>" . $rRow['id'] . '</a>', "<a href='line?id=" . $rRow['id'] . "'>" . $rRow['username'] . '</a>', $rRow['password'], $rOwner, $rStatus, $rActive, $rTrial, $rActiveConnections, $rMaxConnections, $rExpDate, $rLastActive, $rButtons);
                        } else {
                            $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                        }
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'mags') {
        if ($rPermissions['create_mag']) {
            $rOrder = array('`lines`.`id`', '`lines`.`username`', '`mag_devices`.`mac`', '`mag_devices`.`stb_type`', '`users`.`username`', '`lines`.`enabled`', '`active_connections`', '`lines`.`is_trial`', '`lines`.`exp_date`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rWhere = $rWhereV = array();
            $rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
            if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
            } else {
                foreach (range(1, 6) as $rInt) {
                    $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                }
                $rWhere[] = '(`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `mag_devices`.`stb_type` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
            }
            if (0 >= strlen(CoreUtilities::$rRequest['filter'])) {
            } else {
                if (CoreUtilities::$rRequest['filter'] == 1) {
                    $rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
                } else {
                    if (CoreUtilities::$rRequest['filter'] == 2) {
                        $rWhere[] = '`lines`.`enabled` = 0';
                    } else {
                        if (CoreUtilities::$rRequest['filter'] == 3) {
                            $rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
                        } else {
                            if (CoreUtilities::$rRequest['filter'] != 4) {
                            } else {
                                $rWhere[] = '`lines`.`is_trial` = 1';
                            }
                        }
                    }
                }
            }
            if (0 >= strlen(CoreUtilities::$rRequest['reseller'])) {
            } else {
                $rWhere[] = '`lines`.`member_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['reseller'];
            }
            if (0 < count($rWhere)) {
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
            } else {
                $rWhereString = '';
            }
            if (!$rOrder[$rOrderRow]) {
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
            }
            $rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ';';
            $db->query($rCountQuery, ...$rWhereV);
            if ($db->num_rows() == 1) {
                $rReturn['recordsTotal'] = $db->get_row()['count'];
            } else {
                $rReturn['recordsTotal'] = 0;
            }
            $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
            if (0 >= $rReturn['recordsTotal']) {
            } else {
                $rQuery = 'SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`is_isplock`, `mag_devices`.`mac`, `mag_devices`.`stb_type`, `mag_devices`.`mag_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`reseller_notes`, `lines`.`max_connections`,  `lines`.`is_trial`, `users`.`username` AS `owner_name`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                $db->query($rQuery, ...$rWhereV);
                if (0 >= $db->num_rows()) {
                } else {
                    $rRows = $db->get_rows();
                    $rLineIDs = array();
                    foreach ($rRows as $rRow) {
                        if (!$rRow['id']) {
                        } else {
                            $rLineIDs[] = intval($rRow['id']);
                        }
                    }
                    if (0 >= count($rLineIDs)) {
                    } else {
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rConnectionCount = array();
                            $rConnectionMap = CoreUtilities::getUserConnections($rLineIDs, false);
                            foreach (array_keys($rConnectionMap) as $rUserID) {
                                $rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
                            }
                            unset($rConnectionMap);
                        }
                    }
                    foreach ($rRows as $rRow) {
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
                        }
                        if (!$rIsAPI) {
                            if (!$rRow['admin_enabled']) {
                                $rStatus = '<i class="text-danger fas fa-square"></i>';
                            } else {
                                if (!$rRow['enabled']) {
                                    $rStatus = '<i class="text-secondary fas fa-square"></i>';
                                } else {
                                    if ($rRow['exp_date'] && $rRow['exp_date'] < time()) {
                                        $rStatus = '<i class="text-warning far fa-square"></i>';
                                    } else {
                                        $rStatus = '<i class="text-success fas fa-square"></i>';
                                    }
                                }
                            }
                            if (0 < $rRow['active_connections']) {
                                $rActive = '<i class="text-success fas fa-square"></i>';
                            } else {
                                $rActive = '<i class="text-warning far fa-square"></i>';
                            }
                            if ($rRow['is_trial']) {
                                $rTrial = '<i class="text-warning fas fa-square"></i>';
                            } else {
                                $rTrial = '<i class="text-secondary far fa-square"></i>';
                            }
                            if ($rRow['exp_date']) {
                                if ($rRow['exp_date'] < time()) {
                                    $rExpDate = '<span class="expired">' . date($rSettings['date_format'], $rRow['exp_date']) . '<br/><small>' . date('H:i:s', $rRow['exp_date']) . '</small></span>';
                                } else {
                                    $rExpDate = date($rSettings['date_format'], $rRow['exp_date']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['exp_date']) . '</small>';
                                }
                            } else {
                                $rExpDate = '&infin;';
                            }
                            $rButtons = '<div class="btn-group">';
                            $rNotes = '';
                            if (0 >= strlen($rRow['reseller_notes'])) {
                            } else {
                                if (strlen($rNotes) == 0) {
                                } else {
                                    $rNotes .= "\n";
                                }
                                $rNotes .= $rRow['reseller_notes'];
                            }
                            if (0 < strlen($rNotes)) {
                                $rButtons .= '<button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . $rNotes . '"><i class="mdi mdi-note"></i></button>';
                            } else {
                                $rButtons .= '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-note"></i></button>';
                            }
                            $rButtons .= '<button title="MAG Event" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="message(' . $rRow['mag_id'] . ", '" . $rRow['mac'] . "');\"><i class=\"mdi mdi-message-alert\"></i></button>";
                            $rButtons .= '<a href="mag?id=' . $rRow['mag_id'] . '"><button title="Edit" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>';
                            if (!$rRow['is_isplock']) {
                            } else {
                                $rButtons .= '<button title="Reset ISP Lock" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'reset_isp');\"><i class=\"mdi mdi-lock-reset\"></i></button>";
                            }
                            if (!$rPermissions['create_line']) {
                            } else {
                                $rButtons .= '<button title="Convert to User Line" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'convert');\"><i class=\"fas fa-retweet\"></i></button>";
                            }
                            if (!$rPermissions['reseller_client_connection_logs']) {
                            } else {
                                if (0 < $rRow['active_connections']) {
                                    $rButtons .= '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'kill_line');\"><i class=\"fas fa-hammer\"></i></button>";
                                } else {
                                    $rButtons .= '<button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="fas fa-hammer"></i></button>';
                                }
                            }
                            if ($rRow['enabled'] == 1) {
                                $rButtons .= '<button title="Disable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= '<button title="Enable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= '<button title="Delete" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['mag_id'] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            $rButtons .= '</div>';
                            if (in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . '</a>';
                            } else {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                            }
                            $rReturn['data'][] = array("<a href='mag?id=" . $rRow['mag_id'] . "'>" . $rRow['mag_id'] . '</a>', $rRow['username'], "<a href='mag?id=" . $rRow['mag_id'] . "'>" . $rRow['mac'] . '</a>', $rRow['stb_type'], $rOwner, $rStatus, $rActive, $rTrial, $rExpDate, $rButtons);
                        } else {
                            $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                        }
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'enigmas') {
        if ($rPermissions['create_enigma']) {
            $rOrder = array('`lines`.`id`', '`lines`.`username`', '`enigma2_devices`.`mac`', '`enigma2_devices`.`public_ip`', '`users`.`username`', '`lines`.`enabled`', '`active_connections`', '`lines`.`is_trial`', '`lines`.`exp_date`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rWhere = $rWhereV = array();
            $rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
            if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
            } else {
                foreach (range(1, 6) as $rInt) {
                    $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                }
                $rWhere[] = '(`lines`.`username` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `enigma2_devices`.`public_ip` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
            }
            if (0 >= strlen(CoreUtilities::$rRequest['filter'])) {
            } else {
                if (CoreUtilities::$rRequest['filter'] == 1) {
                    $rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
                } else {
                    if (CoreUtilities::$rRequest['filter'] == 2) {
                        $rWhere[] = '`lines`.`enabled` = 0';
                    } else {
                        if (CoreUtilities::$rRequest['filter'] == 3) {
                            $rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
                        } else {
                            if (CoreUtilities::$rRequest['filter'] != 4) {
                            } else {
                                $rWhere[] = '`lines`.`is_trial` = 1';
                            }
                        }
                    }
                }
            }
            if (0 >= strlen(CoreUtilities::$rRequest['reseller'])) {
            } else {
                $rWhere[] = '`lines`.`member_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['reseller'];
            }
            if (0 < count($rWhere)) {
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
            } else {
                $rWhereString = '';
            }
            if (!$rOrder[$rOrderRow]) {
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
            }
            $rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ';';
            $db->query($rCountQuery, ...$rWhereV);
            if ($db->num_rows() == 1) {
                $rReturn['recordsTotal'] = $db->get_row()['count'];
            } else {
                $rReturn['recordsTotal'] = 0;
            }
            $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
            if (0 >= $rReturn['recordsTotal']) {
            } else {
                $rQuery = 'SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`is_isplock`, `enigma2_devices`.`mac`, `enigma2_devices`.`public_ip`, `enigma2_devices`.`device_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`reseller_notes`, `lines`.`max_connections`,  `lines`.`is_trial`, `users`.`username` AS `owner_name`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                $db->query($rQuery, ...$rWhereV);
                if (0 >= $db->num_rows()) {
                } else {
                    $rRows = $db->get_rows();
                    $rLineIDs = array();
                    foreach ($rRows as $rRow) {
                        if (!$rRow['id']) {
                        } else {
                            $rLineIDs[] = intval($rRow['id']);
                        }
                    }
                    if (0 >= count($rLineIDs)) {
                    } else {
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rConnectionCount = array();
                            $rConnectionMap = CoreUtilities::getUserConnections($rLineIDs, false);
                            foreach (array_keys($rConnectionMap) as $rUserID) {
                                $rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
                            }
                            unset($rConnectionMap);
                        }
                    }
                    foreach ($rRows as $rRow) {
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
                        }
                        if (!$rIsAPI) {
                            if (!$rRow['admin_enabled']) {
                                $rStatus = '<i class="text-danger fas fa-square"></i>';
                            } else {
                                if (!$rRow['enabled']) {
                                    $rStatus = '<i class="text-secondary fas fa-square"></i>';
                                } else {
                                    if ($rRow['exp_date'] && $rRow['exp_date'] < time()) {
                                        $rStatus = '<i class="text-warning far fa-square"></i>';
                                    } else {
                                        $rStatus = '<i class="text-success fas fa-square"></i>';
                                    }
                                }
                            }
                            if (0 < $rRow['active_connections']) {
                                $rActive = '<i class="text-success fas fa-square"></i>';
                            } else {
                                $rActive = '<i class="text-warning far fa-square"></i>';
                            }
                            if ($rRow['is_trial']) {
                                $rTrial = '<i class="text-warning fas fa-square"></i>';
                            } else {
                                $rTrial = '<i class="text-secondary far fa-square"></i>';
                            }
                            if ($rRow['exp_date']) {
                                if ($rRow['exp_date'] < time()) {
                                    $rExpDate = '<span class="expired">' . date($rSettings['date_format'], $rRow['exp_date']) . '<br/><small>' . date('H:i:s', $rRow['exp_date']) . '</small></span>';
                                } else {
                                    $rExpDate = date($rSettings['date_format'], $rRow['exp_date']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['exp_date']) . '</small>';
                                }
                            } else {
                                $rExpDate = '&infin;';
                            }
                            $rButtons = '<div class="btn-group">';
                            $rNotes = '';
                            if (0 >= strlen($rRow['reseller_notes'])) {
                            } else {
                                if (strlen($rNotes) == 0) {
                                } else {
                                    $rNotes .= "\n";
                                }
                                $rNotes .= $rRow['reseller_notes'];
                            }
                            if (0 < strlen($rNotes)) {
                                $rButtons .= '<button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . $rNotes . '"><i class="mdi mdi-note"></i></button>';
                            } else {
                                $rButtons .= '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-note"></i></button>';
                            }
                            $rButtons .= '<a href="enigma?id=' . $rRow['device_id'] . '"><button title="Edit" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>';
                            if (!$rRow['is_isplock']) {
                            } else {
                                $rButtons .= '<button title="Reset ISP Lock" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'reset_isp');\"><i class=\"mdi mdi-lock-reset\"></i></button>";
                            }
                            if (!$rPermissions['create_line']) {
                            } else {
                                $rButtons .= '<button title="Convert to User Line" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'convert');\"><i class=\"fas fa-retweet\"></i></button>";
                            }
                            if (!$rPermissions['reseller_client_connection_logs']) {
                            } else {
                                if (0 < $rRow['active_connections']) {
                                    $rButtons .= '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'kill_line');\"><i class=\"fas fa-hammer\"></i></button>";
                                } else {
                                    $rButtons .= '<button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="fas fa-hammer"></i></button>';
                                }
                            }
                            if ($rRow['enabled'] == 1) {
                                $rButtons .= '<button title="Disable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= '<button title="Enable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= '<button title="Delete" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['device_id'] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            $rButtons .= '</div>';
                            if (in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . '</a>';
                            } else {
                                $rOwner = "<a href='user?id=" . intval($rRow['member_id']) . "'>" . $rRow['owner_name'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                            }
                            $rReturn['data'][] = array("<a href='enigma?id=" . $rRow['device_id'] . "'>" . $rRow['device_id'] . '</a>', $rRow['username'], "<a href='enigma?id=" . $rRow['device_id'] . "'>" . $rRow['mac'] . '</a>', "<a onClick=\"whois('" . $rRow['public_ip'] . "');\" href='javascript: void(0);'>" . $rRow['public_ip'] . '</a>', $rOwner, $rStatus, $rActive, $rTrial, $rExpDate, $rButtons);
                        } else {
                            $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                        }
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'streams') {
        if ($rPermissions['can_view_vod']) {
            $rCategories = getCategories('live');
            $rOrder = array('`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rCreated = isset(CoreUtilities::$rRequest['created']);
            $rWhere = $rWhereV = array();
            if (0 < count($rPermissions['stream_ids'])) {
                $rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
                if ($rCreated) {
                    $rWhere[] = '`type` = 3';
                } else {
                    $rWhere[] = '`type` = 1';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 2) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['category'])) {
                } else {
                    $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
                    $rWhereV[] = CoreUtilities::$rRequest['category'];
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                if (0 < count($rWhere)) {
                    $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                } else {
                    $rWhereString = '';
                }
                $rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if ($db->num_rows() > 0) {
                        $rRows = $db->get_rows();
                        if (CoreUtilities::$rSettings['redis_handler']) {
                            $rConnectionCount = $rReports = array();
                            $db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
                            foreach ($db->get_rows() as $rRow) {
                                $rReports[] = $rRow['id'];
                            }
                            if (0 >= count($rReports)) {
                            } else {
                                foreach (CoreUtilities::getUserConnections($rReports, false) as $rUserID => $rConnections) {
                                    foreach ($rConnections as $rConnection) {
                                        $rConnectionCount[$rConnection['stream_id']]++;
                                    }
                                }
                            }
                        }
                        foreach ($rRows as $rRow) {
                            if (!CoreUtilities::$rSettings['redis_handler']) {
                            } else {
                                $rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
                            }
                            if (!$rIsAPI) {
                                $rCategoryIDs = json_decode($rRow['category_id'], true);
                                if (0 < strlen(CoreUtilities::$rRequest['category'])) {
                                    $rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
                                } else {
                                    $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
                                }
                                if (1 >= count($rCategoryIDs)) {
                                } else {
                                    $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
                                }
                                if (!(0 < $rRow['tv_archive_duration'] && 0 < $rRow['tv_archive_server_id'])) {
                                } else {
                                    $rRow['stream_display_name'] .= " <i class='text-danger mdi mdi-record'></i>";
                                }
                                if (0 < $rRow['clients']) {
                                    if ($rPermissions['reseller_client_connection_logs']) {
                                        $rButtons = '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'purge');\"><i class=\"mdi mdi-hammer\"></i></button>";
                                        $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . intval($rRow['id']) . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button></a>';
                                    } else {
                                        $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                        $rClients = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button>';
                                    }
                                } else {
                                    $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                    $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                                }
                                if (0 < strlen($rRow['stream_icon'])) {
                                    $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow['stream_icon'] . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . $rRow['stream_icon'] . "' /></a>";
                                } else {
                                    $rIcon = '';
                                }
                                $rReturn['data'][] = array($rRow['id'], $rIcon, $rRow['stream_display_name'], $rCategory, $rClients, $rButtons);
                            } else {
                                $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                            }
                        }
                    }
                }
                echo json_encode($rReturn);
                exit();
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'radios') {
        if ($rPermissions['can_view_vod']) {
            $rCategories = getCategories('radio');
            $rOrder = array('`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rCreated = isset(CoreUtilities::$rRequest['created']);
            $rWhere = $rWhereV = array();
            if (0 < count($rPermissions['stream_ids'])) {
                $rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
                $rWhere[] = '`type` = 4';
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 2) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['category'])) {
                } else {
                    $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
                    $rWhereV[] = CoreUtilities::$rRequest['category'];
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                if (0 < count($rWhere)) {
                    $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                } else {
                    $rWhereString = '';
                }
                $rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if (0 >= $db->num_rows()) {
                    } else {
                        $rRows = $db->get_rows();
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rConnectionCount = $rReports = array();
                            $db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
                            foreach ($db->get_rows() as $rRow) {
                                $rReports[] = $rRow['id'];
                            }
                            if (0 >= count($rReports)) {
                            } else {
                                foreach (CoreUtilities::getUserConnections($rReports, false) as $rUserID => $rConnections) {
                                    foreach ($rConnections as $rConnection) {
                                        $rConnectionCount[$rConnection['stream_id']]++;
                                    }
                                }
                            }
                        }
                        foreach ($rRows as $rRow) {
                            if (!CoreUtilities::$rSettings['redis_handler']) {
                            } else {
                                $rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
                            }
                            if (!$rIsAPI) {
                                $rCategoryIDs = json_decode($rRow['category_id'], true);
                                if (0 < strlen(CoreUtilities::$rRequest['category'])) {
                                    $rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
                                } else {
                                    $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
                                }
                                if (1 >= count($rCategoryIDs)) {
                                } else {
                                    $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
                                }
                                if (0 < $rRow['clients']) {
                                    if ($rPermissions['reseller_client_connection_logs']) {
                                        $rButtons = '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'purge');\"><i class=\"mdi mdi-hammer\"></i></button>";
                                        $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . intval($rRow['id']) . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button></a>';
                                    } else {
                                        $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                        $rClients = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button>';
                                    }
                                } else {
                                    $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                    $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                                }
                                if (0 < strlen($rRow['stream_icon'])) {
                                    $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow['stream_icon'] . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . $rRow['stream_icon'] . "' /></a>";
                                } else {
                                    $rIcon = '';
                                }
                                $rReturn['data'][] = array($rRow['id'], $rIcon, $rRow['stream_display_name'], $rCategory, $rClients, $rButtons);
                            } else {
                                $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                            }
                        }
                    }
                }
                echo json_encode($rReturn);
                exit();
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'movies') {
        if ($rPermissions['can_view_vod']) {
            $rCategories = getCategories('movie');
            $rOrder = array('`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rCreated = isset(CoreUtilities::$rRequest['created']);
            $rWhere = $rWhereV = array();
            if (0 < count($rPermissions['stream_ids'])) {
                $rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
                $rWhere[] = '`type` = 2';
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 2) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['category'])) {
                } else {
                    $rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
                    $rWhereV[] = CoreUtilities::$rRequest['category'];
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                if (0 < count($rWhere)) {
                    $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                } else {
                    $rWhereString = '';
                }
                $rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `movie_properties`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if (0 >= $db->num_rows()) {
                    } else {
                        $rRows = $db->get_rows();
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rConnectionCount = $rReports = array();
                            $db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
                            foreach ($db->get_rows() as $rRow) {
                                $rReports[] = $rRow['id'];
                            }
                            if (0 >= count($rReports)) {
                            } else {
                                foreach (CoreUtilities::getUserConnections($rReports, false) as $rUserID => $rConnections) {
                                    foreach ($rConnections as $rConnection) {
                                        $rConnectionCount[$rConnection['stream_id']]++;
                                    }
                                }
                            }
                        }
                        foreach ($rRows as $rRow) {
                            if (!CoreUtilities::$rSettings['redis_handler']) {
                            } else {
                                $rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
                            }
                            if (!$rIsAPI) {
                                $rCategoryIDs = json_decode($rRow['category_id'], true);
                                if (0 < strlen(CoreUtilities::$rRequest['category'])) {
                                    $rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
                                } else {
                                    $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
                                }
                                if (1 >= count($rCategoryIDs)) {
                                } else {
                                    $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
                                }
                                if (0 < $rRow['clients']) {
                                    if ($rPermissions['reseller_client_connection_logs']) {
                                        $rButtons = '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'purge');\"><i class=\"mdi mdi-hammer\"></i></button>";
                                        $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . intval($rRow['id']) . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button></a>';
                                    } else {
                                        $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                        $rClients = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button>';
                                    }
                                } else {
                                    $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                    $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                                }
                                $rProperties = json_decode($rRow['movie_properties'], true);
                                if (0 < strlen($rProperties['movie_image'])) {
                                    $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties['movie_image'] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rProperties['movie_image'] . "' /></a>";
                                } else {
                                    $rImage = '';
                                }
                                $rReturn['data'][] = array($rRow['id'], $rImage, $rRow['stream_display_name'], $rCategory, $rClients, $rButtons);
                            } else {
                                $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                            }
                        }
                    }
                }
                echo json_encode($rReturn);
                exit();
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'episodes') {
        if ($rPermissions['can_view_vod']) {
            $rCategories = getCategories('series');
            $rOrder = array('`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rCreated = isset(CoreUtilities::$rRequest['created']);
            $rWhere = $rWhereV = array();
            if (0 < count($rPermissions['stream_ids'])) {
                $rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
                $rWhere[] = '`type` = 5';
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 3) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`streams`.`id` LIKE ? OR `stream_display_name` LIKE ? OR `streams_series`.`title` LIKE ?)';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['category'])) {
                } else {
                    $rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
                    $rWhereV[] = CoreUtilities::$rRequest['category'];
                }
                if (0 >= strlen(CoreUtilities::$rRequest['series'])) {
                } else {
                    $rWhere[] = '`streams_series`.`id` = ?';
                    $rWhereV[] = CoreUtilities::$rRequest['series'];
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                if (0 < count($rWhere)) {
                    $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                } else {
                    $rWhereString = '';
                }
                $rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `streams`.`id`, `stream_icon`, `stream_display_name`, `movie_properties`, `streams_series`.`category_id`, `streams_series`.`title`, `streams_episodes`.`season_num`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if (0 >= $db->num_rows()) {
                    } else {
                        $rRows = $db->get_rows();
                        if (!CoreUtilities::$rSettings['redis_handler']) {
                        } else {
                            $rConnectionCount = $rReports = array();
                            $db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
                            foreach ($db->get_rows() as $rRow) {
                                $rReports[] = $rRow['id'];
                            }
                            if (0 >= count($rReports)) {
                            } else {
                                foreach (CoreUtilities::getUserConnections($rReports, false) as $rUserID => $rConnections) {
                                    foreach ($rConnections as $rConnection) {
                                        $rConnectionCount[$rConnection['stream_id']]++;
                                    }
                                }
                            }
                        }
                        foreach ($rRows as $rRow) {
                            if (!CoreUtilities::$rSettings['redis_handler']) {
                            } else {
                                $rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
                            }
                            if (!$rIsAPI) {
                                $rSeriesName = $rRow['title'] . ' - Season ' . $rRow['season_num'];
                                $rStreamName = '<b>' . $rRow['stream_display_name'] . "</b><br><span style='font-size:11px;'>" . $rSeriesName . '</span>';
                                $rCategoryIDs = json_decode($rRow['category_id'], true);
                                if (0 < strlen(CoreUtilities::$rRequest['category'])) {
                                    $rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
                                } else {
                                    $rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
                                }
                                if (1 >= count($rCategoryIDs)) {
                                } else {
                                    $rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
                                }
                                if (0 < $rRow['clients']) {
                                    if ($rPermissions['reseller_client_connection_logs']) {
                                        $rButtons = '<button title="Kill Connections" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'purge');\"><i class=\"mdi mdi-hammer\"></i></button>";
                                        $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . intval($rRow['id']) . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button></a>';
                                    } else {
                                        $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                        $rClients = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow['clients'] . '</button>';
                                    }
                                } else {
                                    $rButtons = '<button type="button" disabled class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-hammer"></i></button>';
                                    $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                                }
                                $rProperties = json_decode($rRow['movie_properties'], true);
                                if (0 < strlen($rProperties['movie_image'])) {
                                    $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties['movie_image'] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rProperties['movie_image'] . "' /></a>";
                                } else {
                                    $rImage = '';
                                }
                                $rReturn['data'][] = array($rRow['id'], $rImage, $rStreamName, $rCategory, $rClients, $rButtons);
                            } else {
                                $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                            }
                        }
                    }
                }
                echo json_encode($rReturn);
                exit();
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'line_activity') {
        if ($rPermissions['reseller_client_connection_logs']) {
            $rOrder = array('`username`', '`streams`.`stream_display_name`', '`lines_activity`.`user_agent`', '`lines_activity`.`isp`', '`lines_activity`.`user_ip`', '`lines_activity`.`date_start`', '`lines_activity`.`date_end`', '`lines_activity`.`date_end` - `lines_activity`.`date_start`', '`lines_activity`.`container`');
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rWhere = $rWhereV = array();
            $rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
            if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
            } else {
                foreach (range(1, 10) as $rInt) {
                    $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                }
                $rWhere[] = '(`lines_activity`.`user_agent` LIKE ? OR `lines_activity`.`user_ip` LIKE ? OR `lines_activity`.`container` LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_start`) LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_end`) LIKE ? OR `lines_activity`.`geoip_country_code` LIKE ? OR `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `streams`.`stream_display_name` LIKE ?)';
            }
            if (0 >= strlen(CoreUtilities::$rRequest['range'])) {
            } else {
                $rStartTime = substr(CoreUtilities::$rRequest['range'], 0, 10);
                $rEndTime = substr(CoreUtilities::$rRequest['range'], strlen(CoreUtilities::$rRequest['range']) - 10, 10);
                if ($rStartTime = strtotime($rStartTime . ' 00:00:00')) {
                } else {
                    $rStartTime = null;
                }
                if ($rEndTime = strtotime($rEndTime . ' 23:59:59')) {
                } else {
                    $rEndTime = null;
                }
                if (!($rStartTime && $rEndTime)) {
                } else {
                    $rWhere[] = '(`lines_activity`.`date_start` >= ? AND `lines_activity`.`date_end` <= ?)';
                    $rWhereV[] = $rStartTime;
                    $rWhereV[] = $rEndTime;
                }
            }
            if (0 >= strlen(CoreUtilities::$rRequest['stream'])) {
            } else {
                $rWhere[] = '`lines_activity`.`stream_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['stream'];
            }
            if (0 >= strlen(CoreUtilities::$rRequest['user'])) {
            } else {
                $rWhere[] = '`lines`.`member_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['user'];
            }
            if (0 >= strlen(CoreUtilities::$rRequest['line'])) {
            } else {
                $rWhere[] = '`lines_activity`.`user_id` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['line'];
            }
            if (0 < count($rWhere)) {
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
            } else {
                $rWhereString = '';
            }
            if (!$rOrder[$rOrderRow]) {
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
            }
            $rCountQuery = 'SELECT COUNT(*) AS `count` FROM `lines_activity` LEFT JOIN `lines` ON `lines_activity`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_activity`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_activity`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_activity`.`user_id` ' . $rWhereString . ';';
            $db->query($rCountQuery, ...$rWhereV);
            if ($db->num_rows() == 1) {
                $rReturn['recordsTotal'] = $db->get_row()['count'];
            } else {
                $rReturn['recordsTotal'] = 0;
            }
            $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
            if (0 >= $rReturn['recordsTotal']) {
            } else {
                $rQuery = 'SELECT `mag_devices`.`mag_id`, `enigma2_devices`.`device_id`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_activity`.`activity_id`, `lines_activity`.`container`, `lines_activity`.`isp`, `lines_activity`.`user_id`, `lines_activity`.`stream_id`, `streams`.`series_no`, `lines_activity`.`server_id`, `lines_activity`.`user_agent`, `lines_activity`.`user_ip`, `lines_activity`.`container`, `lines_activity`.`date_start`, `lines_activity`.`date_end`, `lines_activity`.`geoip_country_code`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username`, `streams`.`stream_display_name`, `streams`.`type`, `lines`.`is_restreamer` FROM `lines_activity` LEFT JOIN `lines` ON `lines_activity`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_activity`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_activity`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_activity`.`user_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                $db->query($rQuery, ...$rWhereV);
                if (0 >= $db->num_rows()) {
                } else {
                    foreach ($db->get_rows() as $rRow) {
                        if (!$rIsAPI) {
                            if ($rRow['is_mag']) {
                                $rUsername = "<a href='mag?id=" . $rRow['mag_id'] . "'>" . $rRow['username'] . '</a>';
                            } else {
                                if ($rRow['is_e2']) {
                                    $rUsername = "<a href='enigma?id=" . $rRow['device_id'] . "'>" . $rRow['username'] . '</a>';
                                } else {
                                    $rUsername = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['username'] . '</a>';
                                }
                            }
                            $rChannel = $rRow['stream_display_name'];
                            if (0 < strlen($rRow['geoip_country_code'])) {
                                $rGeoCountry = "<img loading='lazy' src='assets/images/countries/" . strtolower($rRow['geoip_country_code']) . ".png'></img> &nbsp;";
                            } else {
                                $rGeoCountry = '';
                            }
                            if ($rRow['user_ip']) {
                                $rIP = $rGeoCountry . "<a onClick=\"whois('" . $rRow['user_ip'] . "');\" href='javascript: void(0);'>" . $rRow['user_ip'] . '</a>';
                            } else {
                                $rIP = '';
                            }
                            if ($rRow['date_start']) {
                                $rStart = date($rSettings['datetime_format'], $rRow['date_start']);
                            } else {
                                $rStart = '';
                            }
                            if ($rRow['date_end']) {
                                $rStop = date($rSettings['datetime_format'], $rRow['date_end']);
                            } else {
                                $rStop = '';
                            }
                            $rPlayer = trim(explode('(', $rRow['user_agent'])[0]);
                            $rDuration = $rRow['date_end'] - $rRow['date_start'];
                            $rColour = 'success';
                            if (86400 <= $rDuration) {
                                $rDuration = sprintf('%02dd %02dh', $rDuration / 86400, ($rDuration / 3600) % 24);
                                $rColour = 'danger';
                            } else {
                                if (3600 <= $rDuration) {
                                    if (14400 < $rDuration) {
                                        $rColour = 'warning';
                                    } else {
                                        if (43200 >= $rDuration) {
                                        } else {
                                            $rColour = 'danger';
                                        }
                                    }
                                    $rDuration = sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60);
                                } else {
                                    $rDuration = sprintf('%02dm %02ds', ($rDuration / 60) % 60, $rDuration % 60);
                                }
                            }
                            $rDuration = "<button type='button' class='btn btn-" . $rColour . " btn-xs waves-effect waves-light btn-fixed'>" . $rDuration . '</button>';
                            $rReturn['data'][] = array($rUsername, $rChannel, $rPlayer, $rRow['isp'], $rIP, $rStart, $rStop, $rDuration, strtoupper($rRow['container']));
                        } else {
                            $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                        }
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        }
        exit();
    }
    if ($rType == 'live_connections') {
        if ($rPermissions['reseller_client_connection_logs']) {
            $rRows = array();
            if (CoreUtilities::$rSettings['redis_handler']) {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? false : true);
                $rReports = array();
                $rUserID = (0 < intval(CoreUtilities::$rRequest['user']) ? intval(CoreUtilities::$rRequest['user']) : null);
                $rStreamID = (0 < intval(CoreUtilities::$rRequest['stream_id']) ? intval(CoreUtilities::$rRequest['stream_id']) : null);
                if ($rUserID && in_array($rUserID, $rUserInfo['reports'])) {
                    $db->query('SELECT `id` FROM `lines` WHERE `member_id` = ?;', $rUserID);
                } else {
                    $db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
                }
                foreach ($db->get_rows() as $rRow) {
                    $rReports[] = $rRow['id'];
                }
                $rKeys = CoreUtilities::getUserConnections($rReports, false, true);
                if (!$rOrderDirection) {
                } else {
                    $rKeys = array_reverse($rKeys);
                }
                $rKeyCount = count($rKeys);
                foreach (CoreUtilities::$redis->mGet($rKeys) as $rRow) {
                    $rRow = igbinary_unserialize($rRow);
                    if (is_array($rRow)) {
                        if ($rFilterBefore) {
                        } else {
                            if (!($rStreamID && $rStreamID != $rRow['stream_id'])) {
                                if (in_array($rRow['user_id'], $rReports)) {
                                } else {
                                    $rKeyCount--;
                                }
                            } else {
                                $rKeyCount--;
                            }
                        }
                        $rRow['activity_id'] = $rRow['uuid'];
                        $rRow['identifier'] = ($rRow['user_id'] ?: $rRow['hmac_id'] . '_' . $rRow['hmac_identifier']);
                        $rRow['active_time'] = time() - $rRow['date_start'];
                        $rRow['server_name'] = (CoreUtilities::$rServers[$rRow['server_id']]['server_name'] ?: '');
                        $rRows[] = $rRow;
                    } else {
                        $rKeyCount--;
                    }
                }
                $rOrder = array('uuid', 'divergence', 'identifier', 'stream_display_name', 'user_agent', 'isp', 'user_ip', 'active_time', 'container', null);
                if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                    $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
                } else {
                    $rOrderRow = 0;
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    array_multisort(array_column($rRows, $rOrder[$rOrderRow]), ($rOrderDirection ? SORT_ASC : SORT_DESC), $rRows);
                }
                $rRows = array_slice($rRows, $rStart, $rLimit);
                $rUUIDs = $rStreamIDs = $rUserIDs = array();
                foreach ($rRows as $rRow) {
                    if (!$rRow['stream_id']) {
                    } else {
                        $rStreamIDs[] = intval($rRow['stream_id']);
                    }
                    if (!$rRow['user_id']) {
                    } else {
                        $rUserIDs[] = intval($rRow['user_id']);
                    }
                    if (!$rRow['uuid']) {
                    } else {
                        $rUUIDs[] = $rRow['uuid'];
                    }
                }
                $rStreamNames = $rDivergenceMap = $rSeriesMap = $rUserMap = array();
                if (0 >= count($rUserIDs)) {
                } else {
                    $db->query('SELECT `lines`.`id`, `lines`.`is_mag`, `lines`.`is_e2`, `lines`.`is_restreamer`, `lines`.`username`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`id` IN (' . implode(',', $rUserIDs) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        $rUserID = $rRow['id'];
                        unset($rRow['id']);
                        $rUserMap[$rUserID] = $rRow;
                    }
                }
                if (0 >= count($rStreamIDs)) {
                } else {
                    $db->query('SELECT `stream_id`, `series_id` FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        $rSeriesMap[$rRow['stream_id']] = $rRow['series_id'];
                    }
                    $db->query('SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');
                    foreach ($db->get_rows() as $rRow) {
                        $rStreamNames[$rRow['id']] = array($rRow['stream_display_name'], $rRow['type']);
                    }
                }
                if (0 >= count($rUUIDs)) {
                } else {
                    $db->query("SELECT `uuid`, `divergence` FROM `lines_divergence` WHERE `uuid` IN ('" . implode("','", $rUUIDs) . "');");
                    foreach ($db->get_rows() as $rRow) {
                        $rDivergenceMap[$rRow['uuid']] = $rRow['divergence'];
                    }
                }
                $i = 0;
                while ($i < count($rRows)) {
                    $rRows[$i]['divergence'] = ($rDivergenceMap[$rRows[$i]['uuid']] ?: 0);
                    $rRows[$i]['series_no'] = ($rSeriesMap[$rRows[$i]['stream_id']] ?: null);
                    $rRows[$i]['stream_display_name'] = ($rStreamNames[$rRows[$i]['stream_id']][0] ?: '');
                    $rRows[$i]['type'] = ($rStreamNames[$rRows[$i]['stream_id']][1] ?: 1);
                    $rRows[$i] = array_merge($rRows[$i], ($rUserMap[$rRows[$i]['user_id']] ?: array()));
                    $i++;
                }
                $rReturn['recordsTotal'] = $rKeyCount;
                $rReturn['recordsFiltered'] = ($rIsAPI ? ($rReturn['recordsTotal'] < $rLimit ? $rReturn['recordsTotal'] : $rLimit) : $rReturn['recordsTotal']);
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrder = array('`lines_live`.`activity_id`', '`lines_live`.`divergence`', '`username`', '`streams`.`stream_display_name`', '`lines_live`.`user_agent`', '`lines_live`.`isp`', '`lines_live`.`user_ip`', 'UNIX_TIMESTAMP() - `lines_live`.`date_start`', '`lines_live`.`container`', false);
                if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                    $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
                } else {
                    $rOrderRow = 0;
                }
                $rWhere = $rWhereV = array();
                $rWhere[] = '`hls_end` = 0';
                $rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 9) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`lines_live`.`user_agent` LIKE ? OR `lines_live`.`user_ip` LIKE ? OR `lines_live`.`container` LIKE ? OR FROM_UNIXTIME(`lines_live`.`date_start`) LIKE ? OR `lines_live`.`geoip_country_code` LIKE ? OR `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `streams`.`stream_display_name` LIKE ?)';
                }
                if (0 >= intval(CoreUtilities::$rRequest['stream'])) {
                } else {
                    $rWhere[] = '`lines_live`.`stream_id` = ?';
                    $rWhereV[] = CoreUtilities::$rRequest['stream'];
                }
                if (0 >= intval(CoreUtilities::$rRequest['user'])) {
                } else {
                    $rWhere[] = '`lines`.`member_id` = ?';
                    $rWhereV[] = CoreUtilities::$rRequest['user'];
                }
                if (0 >= intval(CoreUtilities::$rRequest['line'])) {
                } else {
                    $rWhere[] = '`lines_live`.`user_id` = ?';
                    $rWhereV[] = CoreUtilities::$rRequest['line'];
                }
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                $rCountQuery = 'SELECT COUNT(*) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `mag_devices`.`mag_id`, `enigma2_devices`.`device_id`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_live`.`activity_id`, `lines_live`.`divergence`, `lines_live`.`user_id`, `lines_live`.`stream_id`, `streams`.`series_no`, `lines`.`is_restreamer`, `lines_live`.`isp`, `lines_live`.`server_id`, `lines_live`.`user_agent`, `lines_live`.`user_ip`, `lines_live`.`container`, `lines_live`.`uuid`, `lines_live`.`date_start`, `lines_live`.`geoip_country_code`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username`, `streams`.`stream_display_name`, `streams`.`type` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if (0 >= $db->num_rows()) {
                    } else {
                        $rRows = $db->get_rows();
                    }
                }
            }
            if (0 >= count($rRows)) {
            } else {
                foreach ($rRows as $rRow) {
                    if (!$rIsAPI) {
                        if ($rRow['divergence'] <= 50) {
                            $rDivergence = '<i class="text-success fas fa-square tooltip" title="' . intval(100 - $rRow['divergence']) . '%"></i>';
                        } else {
                            if ($rRow['divergence'] <= 80) {
                                $rDivergence = '<i class="text-warning fas fa-square tooltip" title="' . intval(100 - $rRow['divergence']) . '%"></i>';
                            } else {
                                $rDivergence = '<i class="text-danger fas fa-square tooltip" title="' . intval(100 - $rRow['divergence']) . '%"></i>';
                            }
                        }
                        if ($rRow['is_mag']) {
                            $rUsername = "<a href='mag?id=" . $rRow['mag_id'] . "'>" . $rRow['username'] . '</a>';
                        } else {
                            if ($rRow['is_e2']) {
                                $rUsername = "<a href='enigma?id=" . $rRow['device_id'] . "'>" . $rRow['username'] . '</a>';
                            } else {
                                $rUsername = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['username'] . '</a>';
                            }
                        }
                        $rChannel = $rRow['stream_display_name'];
                        if (0 < strlen($rRow['geoip_country_code'])) {
                            $rGeoCountry = "<img loading='lazy' src='assets/images/countries/" . strtolower($rRow['geoip_country_code']) . ".png'></img> &nbsp;";
                        } else {
                            $rGeoCountry = '';
                        }
                        if ($rRow['user_ip']) {
                            $rIP = $rGeoCountry . "<a onClick=\"whois('" . $rRow['user_ip'] . "');\" href='javascript: void(0);'>" . $rRow['user_ip'] . '</a>';
                        } else {
                            $rIP = '';
                        }
                        $rPlayer = trim(explode('(', $rRow['user_agent'])[0]);
                        $rDuration = intval(time()) - intval($rRow['date_start']);
                        $rColour = 'success';
                        if (86400 <= $rDuration) {
                            $rDuration = sprintf('%02dd %02dh', $rDuration / 86400, ($rDuration / 3600) % 24);
                            $rColour = 'danger';
                        } else {
                            if (3600 <= $rDuration) {
                                if (14400 < $rDuration) {
                                    $rColour = 'warning';
                                } else {
                                    if (43200 >= $rDuration) {
                                    } else {
                                        $rColour = 'danger';
                                    }
                                }
                                $rDuration = sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60);
                            } else {
                                $rDuration = sprintf('%02dm %02ds', ($rDuration / 60) % 60, $rDuration % 60);
                            }
                        }
                        $rDuration = "<button type='button' class='btn btn-" . $rColour . " btn-xs waves-effect waves-light btn-fixed'>" . $rDuration . '</button>';
                        $rButtons = "<button title=\"Kill Connection\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow['uuid'] . "', 'kill');\"><i class=\"fas fa-hammer\"></i></button>";
                        $rReturn['data'][] = array($rRow['activity_id'], $rDivergence, $rUsername, $rChannel, $rPlayer, $rRow['isp'], $rIP, $rDuration, strtoupper($rRow['container']), $rButtons);
                    } else {
                        $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        } else {
            exit();
        }
    } else {
        if ($rType == 'reg_user_logs') {
            $rOrder = array('`users_logs`.`id`', '`users`.`username`', '`users_logs`.`log_id`', '`users_logs`.`type`, `users_logs`.`action`', '`users_logs`.`cost`', '`users_logs`.`credits_after`', '`users_logs`.`date`');
            if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
            } else {
                $rOrderRow = 0;
            }
            $rWhere = $rWhereV = array();
            $rWhere[] = '`users_logs`.`owner` IN (' . implode(',', $rUserInfo['reports']) . ')';
            if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
            } else {
                foreach (range(1, 3) as $rInt) {
                    $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                }
                $rWhere[] = '(`users`.`username` LIKE ? OR `users_logs`.`type` LIKE ? OR `users_logs`.`action` LIKE ?)';
            }
            if (0 >= strlen(CoreUtilities::$rRequest['range'])) {
            } else {
                $rStartTime = substr(CoreUtilities::$rRequest['range'], 0, 10);
                $rEndTime = substr(CoreUtilities::$rRequest['range'], strlen(CoreUtilities::$rRequest['range']) - 10, 10);
                if ($rStartTime = strtotime($rStartTime . ' 00:00:00')) {
                } else {
                    $rStartTime = null;
                }
                if ($rEndTime = strtotime($rEndTime . ' 23:59:59')) {
                } else {
                    $rEndTime = null;
                }
                if (!($rStartTime && $rEndTime)) {
                } else {
                    $rWhere[] = '(`users_logs`.`date` >= ? AND `users_logs`.`date` <= ?)';
                    $rWhereV[] = $rStartTime;
                    $rWhereV[] = $rEndTime;
                }
            }
            if (0 >= strlen(CoreUtilities::$rRequest['reseller'])) {
            } else {
                $rWhere[] = '`users_logs`.`owner` = ?';
                $rWhereV[] = CoreUtilities::$rRequest['reseller'];
            }
            if (0 < count($rWhere)) {
                $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
            } else {
                $rWhereString = '';
            }
            if (!$rOrder[$rOrderRow]) {
            } else {
                $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
            }
            $rCountQuery = 'SELECT COUNT(*) AS `count` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` ' . $rWhereString . ';';
            $db->query($rCountQuery, ...$rWhereV);
            if ($db->num_rows() == 1) {
                $rReturn['recordsTotal'] = $db->get_row()['count'];
            } else {
                $rReturn['recordsTotal'] = 0;
            }
            $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
            if (0 >= $rReturn['recordsTotal']) {
            } else {
                $rPackages = getPackages();
                $rQuery = 'SELECT `users`.`username`, `users_logs`.`id`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`credits_after`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                $db->query($rQuery, ...$rWhereV);
                if (0 >= $db->num_rows()) {
                } else {
                    foreach ($db->get_rows() as $rRow) {
                        if (!$rIsAPI) {
                            if (in_array($rRow['owner'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                $rOwner = "<a href='user?id=" . intval($rRow['owner']) . "'>" . $rRow['username'] . '</a>';
                            } else {
                                $rOwner = "<a href='user?id=" . intval($rRow['owner']) . "'>" . $rRow['username'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                            }
                            $rDevice = array('line' => 'User Line', 'mag' => 'MAG Device', 'enigma' => 'Enigma2 Device', 'user' => 'Reseller')[$rRow['type']];
                            switch ($rRow['action']) {
                                case 'new':
                                    if ($rRow['package_id']) {
                                        $rText = 'Created New ' . $rDevice . ' with Package: ' . $rPackages[$rRow['package_id']]['package_name'];
                                    } else {
                                        $rText = 'Created New ' . $rDevice;
                                    }
                                    break;
                                case 'extend':
                                    if ($rRow['package_id']) {
                                        $rText = 'Extended ' . $rDevice . ' with Package: ' . $rPackages[$rRow['package_id']]['package_name'];
                                    } else {
                                        $rText = 'Extended ' . $rDevice;
                                    }
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
                            $rLineInfo = null;
                            switch ($rRow['type']) {
                                case 'line':
                                    $rLine = getUser($rRow['log_id']);
                                    if (!$rLine) {
                                    } else {
                                        $rLineInfo = "<a href='line?id=" . $rRow['log_id'] . "'>" . $rLine['username'] . '</a>';
                                    }
                                    break;
                                case 'user':
                                    $rLine = getRegisteredUser($rRow['log_id']);
                                    if (!$rLine) {
                                    } else {
                                        $rLineInfo = "<a href='user?id=" . $rRow['log_id'] . "'>" . $rLine['username'] . '</a>';
                                    }
                                    break;
                                case 'mag':
                                    $rLine = getMag($rRow['log_id']);
                                    if (!$rLine) {
                                    } else {
                                        $rLineInfo = "<a href='mag?id=" . $rRow['log_id'] . "'>" . $rLine['mac'] . '</a>';
                                    }
                                    break;
                                case 'enigma':
                                    $rLine = getEnigma($rRow['log_id']);
                                    if (!$rLine) {
                                    } else {
                                        $rLineInfo = "<a href='enigma?id=" . $rRow['log_id'] . "'>" . $rLine['mac'] . '</a>';
                                    }
                                    break;
                            }
                            if ($rLineInfo) {
                            } else {
                                $rDeletedInfo = json_decode($rRow['deleted_info'], true);
                                if (is_array($rDeletedInfo)) {
                                    if (isset($rDeletedInfo['mac'])) {
                                        $rLineInfo = "<span class='text-secondary'>" . $rDeletedInfo['mac'] . '</span>';
                                    } else {
                                        $rLineInfo = "<span class='text-secondary'>" . $rDeletedInfo['username'] . '</span>';
                                    }
                                } else {
                                    $rLineInfo = "<span class='text-secondary'>DELETED</span>";
                                }
                            }
                            $rReturn['data'][] = array($rRow['id'], $rOwner, $rLineInfo, $rText, number_format($rRow['cost'], 0), number_format($rRow['credits_after'], 0), date($rSettings['datetime_format'], $rRow['date']));
                        } else {
                            unset($rRow['deleted_info']);
                            $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                        }
                    }
                }
            }
            echo json_encode($rReturn);
            exit();
        }
        if ($rType != 'reg_users') {
        } else {
            if ($rPermissions['create_sub_resellers']) {
                $rOrder = array('`users`.`id`', '`users`.`username`', '`r`.`username`', '`users`.`ip`', '`users`.`status`', '`users`.`credits`', '`user_count`', '`users`.`last_login`', false);
                if (isset(CoreUtilities::$rRequest['order']) && 0 < strlen(CoreUtilities::$rRequest['order'][0]['column'])) {
                    $rOrderRow = intval(CoreUtilities::$rRequest['order'][0]['column']);
                } else {
                    $rOrderRow = 0;
                }
                $rWhere = $rWhereV = array();
                $rWhere[] = '`users`.`owner_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
                if (0 >= strlen(CoreUtilities::$rRequest['search']['value'])) {
                } else {
                    foreach (range(1, 9) as $rInt) {
                        $rWhereV[] = '%' . CoreUtilities::$rRequest['search']['value'] . '%';
                    }
                    $rWhere[] = '(`users`.`id` LIKE ? OR `users`.`username` LIKE ? OR `users`.`notes` LIKE ? OR `r`.`username` LIKE ? OR FROM_UNIXTIME(`users`.`date_registered`) LIKE ? OR FROM_UNIXTIME(`users`.`last_login`) LIKE ? OR `users`.`email` LIKE ? OR `users`.`ip` LIKE ? OR `users_groups`.`group_name` LIKE ?)';
                }
                if (0 >= strlen(CoreUtilities::$rRequest['filter'])) {
                } else {
                    if (CoreUtilities::$rRequest['filter'] == 1) {
                        $rWhere[] = '`users`.`status` = 1';
                    } else {
                        if (CoreUtilities::$rRequest['filter'] != 2) {
                        } else {
                            $rWhere[] = '`users`.`status` = 0';
                        }
                    }
                }
                if (0 >= strlen(CoreUtilities::$rRequest['reseller'])) {
                } else {
                    $rWhere[] = '`users`.`owner_id` = ?';
                    $rWhereV[] = CoreUtilities::$rRequest['reseller'];
                }
                if (0 < count($rWhere)) {
                    $rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
                } else {
                    $rWhereString = '';
                }
                if (!$rOrder[$rOrderRow]) {
                } else {
                    $rOrderDirection = (strtolower(CoreUtilities::$rRequest['order'][0]['dir']) === 'desc' ? 'desc' : 'asc');
                    $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
                }
                $rCountQuery = 'SELECT COUNT(*) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` LEFT JOIN `users` AS `r` on `r`.`id` = `users`.`owner_id` ' . $rWhereString . ';';
                $db->query($rCountQuery, ...$rWhereV);
                if ($db->num_rows() == 1) {
                    $rReturn['recordsTotal'] = $db->get_row()['count'];
                } else {
                    $rReturn['recordsTotal'] = 0;
                }
                $rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
                if (0 >= $rReturn['recordsTotal']) {
                } else {
                    $rQuery = 'SELECT `users`.`id`, `users`.`status`, `users_groups`.`is_reseller`, `users`.`notes`, `users`.`owner_id`, `users`.`credits`, `users`.`username`, `users`.`email`, `users`.`ip`, FROM_UNIXTIME(`users`.`date_registered`) AS `date_registered`, FROM_UNIXTIME(`users`.`last_login`) AS `last_login`, `r`.`username` as `owner_username`, `users_groups`.`group_name`, `users`.`status`, (SELECT COUNT(`id`) FROM `lines` WHERE `member_id` = `users`.`id`) AS `user_count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` LEFT JOIN `users` AS `r` on `r`.`id` = `users`.`owner_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
                    $db->query($rQuery, ...$rWhereV);
                    if (0 >= $db->num_rows()) {
                    } else {
                        foreach ($db->get_rows() as $rRow) {
                            if (!$rIsAPI) {
                                if ($rRow['status'] == 1) {
                                    $rStatus = '<i class="text-success fas fa-square"></i>';
                                } else {
                                    $rStatus = '<i class="text-secondary fas fa-square"></i>';
                                }
                                if ($rRow['last_login']) {
                                } else {
                                    $rRow['last_login'] = 'NEVER';
                                }
                                $rButtons = '<div class="btn-group">';
                                if (0 < strlen($rRow['notes'])) {
                                    $rButtons .= '<button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="' . $rRow['notes'] . '"><i class="mdi mdi-note"></i></button>';
                                } else {
                                    $rButtons .= '<button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-note"></i></button>';
                                }
                                if (in_array($rRow['id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                    $rButtons .= '<button title="Adjust Credits" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="addCredits(' . $rRow['id'] . ", '" . addslashes($rRow['username']) . "', " . intval($rRow['credits']) . ');"><i class="mdi mdi-coin"></i></button>';
                                    $rUsername = "<a href='user?id=" . intval($rRow['id']) . "'>" . $rRow['username'] . '</a>';
                                } else {
                                    $rButtons .= '<button title="Adjust Credits" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="addCredits(' . $rRow['id'] . ", '" . addslashes($rRow['username']) . "', " . intval($rRow['credits']) . ', true);"><i class="mdi mdi-coin"></i></button>';
                                    $rUsername = "<a href='user?id=" . intval($rRow['id']) . "'>" . $rRow['username'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                                }
                                $rButtons .= '<a href="user?id=' . $rRow['id'] . '"><button title="Edit" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>';
                                if ($rRow['status'] == 1) {
                                    $rButtons .= '<button title="Disable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                                } else {
                                    $rButtons .= '<button title="Enable" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                                }
                                if (!$rPermissions['delete_users']) {
                                } else {
                                    $rButtons .= '<button title="Delete" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(' . $rRow['id'] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                                }
                                $rButtons .= '</div>';
                                if (0 < strlen($rRow['ip'])) {
                                    $rIP = "<a onClick=\"whois('" . $rRow['ip'] . "');\" href='javascript: void(0);'>" . $rRow['ip'] . '</a>';
                                } else {
                                    $rIP = '';
                                }
                                if ($rRow['is_reseller']) {
                                    $rCredits = '<button type="button" class="btn btn-info btn-xs waves-effect waves-light">' . number_format($rRow['credits'], 0) . '</button>';
                                } else {
                                    $rCredits = '<button type="button" class="btn btn-secondary btn-xs waves-effect waves-light">-</button>';
                                }
                                if (0 < $rRow['user_count']) {
                                    $rUserCount = '<button type="button" class="btn btn-info btn-xs waves-effect waves-light">' . number_format($rRow['user_count'], 0) . '</button>';
                                } else {
                                    $rUserCount = '<button type="button" class="btn btn-secondary btn-xs waves-effect waves-light">0</button>';
                                }
                                if (in_array($rRow['owner_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])))) {
                                    $rOwner = "<a href='user?id=" . intval($rRow['owner_id']) . "'>" . $rRow['owner_username'] . '</a>';
                                } else {
                                    $rOwner = "<a href='user?id=" . intval($rRow['owner_id']) . "'>" . $rRow['owner_username'] . "<br/><small class='text-pink'>(indirect)</small></a>";
                                }
                                $rReturn['data'][] = array("<a href='user?id=" . intval($rRow['id']) . "'>" . $rRow['id'] . '</a>', $rUsername, $rOwner, $rIP, $rStatus, $rCredits, $rUserCount, $rRow['last_login'], $rButtons);
                            } else {
                                $rReturn['data'][] = filterrow($rRow, CoreUtilities::$rRequest['show_columns'], CoreUtilities::$rRequest['hide_columns']);
                            }
                        }
                    }
                }
                echo json_encode($rReturn);
                exit();
            }
            exit();
        }
    }
} else {
    echo json_encode($rReturn);
    exit();
}
function filterRow($rRow, $rShow, $rHide) {
    if ($rShow || $rHide) {
        $rReturn = array();
        foreach (array_keys($rRow) as $rKey) {
            if ($rShow) {
                if (!in_array($rKey, $rShow)) {
                } else {
                    $rReturn[$rKey] = $rRow[$rKey];
                }
            } else {
                if (!$rHide) {
                } else {
                    if (in_array($rKey, $rHide)) {
                    } else {
                        $rReturn[$rKey] = $rRow[$rKey];
                    }
                }
            }
        }
        return $rReturn;
    } else {
        return $rRow;
    }
}

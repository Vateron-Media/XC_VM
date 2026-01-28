<?php
session_start();
session_write_close();
if (file_exists("../www/init.php")) {
    require_once "../www/init.php";
} else {
    require_once "../../../www/init.php";
}

if (!PHP_ERRORS) {
    if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        exit();
    }
}

$rReturn = ["draw" => (int) CoreUtilities::$rRequest["draw"], "recordsTotal" => 0, "recordsFiltered" => 0, "data" => []];
$rIsAPI = false;
if (isset(CoreUtilities::$rRequest["api_key"])) {
    $rReturn = ["status" => "STATUS_SUCCESS", "data" => []];
    $db->query("SELECT `id` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_admin` = 1 AND `status` = 1;", CoreUtilities::$rRequest["api_key"]);
    if ($db->num_rows() == 0) {
        echo json_encode(["status" => "STATUS_FAILURE", "error" => "Invalid API key."]);
        exit;
    }
    $rUserID = $db->get_row()["id"];
    $rIsAPI = true;
    require_once MAIN_HOME . "includes/admin.php";
    $rUserInfo = getRegisteredUser($rUserID);
    $rPermissions = getPermissions($rUserInfo["member_group_id"]);
    $rPermissions["advanced"] = json_decode($rPermissions["allowed_pages"], true);
    if (0 < strlen($rUserInfo["timezone"])) {
        date_default_timezone_set($rUserInfo["timezone"]);
    }
} elseif ($_SERVER["REMOTE_ADDR"] == "127.0.0.1" && isset(CoreUtilities::$rRequest["api_user_id"])) {
    $rIsAPI = true;
    require_once MAIN_HOME . "includes/admin.php";
    $rUserInfo = getRegisteredUser(CoreUtilities::$rRequest["api_user_id"]);
    $rPermissions = getPermissions($rUserInfo["member_group_id"]);
    $rPermissions["advanced"] = json_decode($rPermissions["allowed_pages"], true);
    if (0 < strlen($rUserInfo["timezone"])) {
        date_default_timezone_set($rUserInfo["timezone"]);
    }
} elseif (isset($_SESSION["hash"])) {
    include "functions.php";
} else {
    echo json_encode($rReturn);
    exit;
}

if ($rMobile) {
    CoreUtilities::$rSettings["modal_edit"] = false;
    CoreUtilities::$rSettings["group_buttons"] = false;
}
$rType = CoreUtilities::$rRequest["id"];

$rStart = (int) CoreUtilities::$rRequest["start"];
$rLimit = (int) CoreUtilities::$rRequest["length"];
if ((1000 < $rLimit || $rLimit <= 0) && !$rIsAPI) {
    $rLimit = 1000;
}
if (CoreUtilities::$rSettings["redis_handler"]) {
    CoreUtilities::connectRedis();
}

if ($rType == "lines") {
    if (!hasPermissions("adv", "users") && !hasPermissions("adv", "mass_edit_users")) {
        exit;
    }
    $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
    $rOrder = ["`lines`.`id`", "`lines`.`username`", "`lines`.`password`", "`lines`.`member_id`", "`lines`.`enabled` - `lines`.`admin_enabled`", "`active_connections` > 0", "`lines`.`is_trial`", "`lines`.`is_restreamer`", "`active_connections`", "`lines`.`max_connections`", "`lines`.`exp_date`", "`active_connections` " . $rOrderDirection . ", `last_activity`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "(`is_mag` + `is_e2`) = 0";
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 6) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`lines`.`username` LIKE ? OR `lines`.`password` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`max_connections` LIKE ? OR `lines`.`reseller_notes` LIKE ? OR `lines`.`admin_notes` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "`lines`.`enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "`lines`.`admin_enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`lines`.`is_trial` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 6) {
            $rWhere[] = "`lines`.`is_restreamer` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 7) {
            $rWhere[] = "`lines`.`is_stalker` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 8) {
            $rWhere[] = "(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` > UNIX_TIMESTAMP() AND `lines`.`exp_date` <= (UNIX_TIMESTAMP() + (86400*14)))";
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "`lines`.`member_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(`id`) AS `count` FROM `lines` " . $rWhereString . ";";
    if ($rOrder[$rOrderRow]) {
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `lines`.`id`, `lines`.`member_id`, `lines`.`last_activity`, `lines`.`last_activity_array`, `lines`.`username`, `lines`.`password`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`is_restreamer`, `lines`.`enabled`, `lines`.`admin_notes`, `lines`.`reseller_notes`, `lines`.`max_connections`, `lines`.`is_trial`, `lines`.`contact`, (SELECT COUNT(*) AS `active_connections` FROM `lines_live` WHERE `user_id` = `lines`.`id` AND `hls_end` = 0) AS `active_connections` FROM `lines` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();

            $rActivityIDs = $rLineInfo = $rLineIDs = [];
            foreach ($rRows as $rRow) {
                $rLineIDs[] = (int) $rRow["id"];
                $rLineInfo[(int) $rRow["id"]] = ["owner_name" => NULL, "stream_display_name" => NULL, "stream_id" => NULL, "last_active" => NULL];
                $rLastInfo = [];

                if (!empty($rRow['last_activity_array'])) {
                    $decoded = json_decode($rRow['last_activity_array'], true);
                    if (is_array($decoded)) {
                        $rLastInfo = $decoded;
                    }
                }

                if ($rLastInfo) {
                    $id = (int) $rRow['id'];
                    $rLineInfo[$id]['stream_id']   = $rLastInfo['stream_id'] ?? null;
                    $rLineInfo[$id]['last_active'] = $rLastInfo['date_end'] ?? null;
                } elseif (!empty($rRow['last_activity'])) {
                    $rActivityIDs[] = (int) $rRow['last_activity'];
                }
            }

            if (0 < count($rLineIDs)) {
                $db->query("SELECT `users`.`username`, `lines`.`id` FROM `users` LEFT JOIN `lines` ON `lines`.`member_id` = `users`.`id` WHERE `lines`.`id` IN (" . implode(",", $rLineIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rLineInfo[$rRow["id"]]["owner_name"] = $rRow["username"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rConnectionCount = CoreUtilities::getUserConnections($rLineIDs, true);
                    $rConnectionMap = CoreUtilities::getFirstConnection($rLineIDs);
                    $rStreamIDs = [];
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        if (!in_array($rConnection["stream_id"], $rStreamIDs)) {
                            $rStreamIDs[] = (int) $rConnection["stream_id"];
                        }
                    }
                    $rStreamMap = [];
                    if (0 < count($rStreamIDs)) {
                        $db->query("SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(",", $rStreamIDs) . ");");
                        foreach ($db->get_rows() as $rRow) {
                            $rStreamMap[$rRow["id"]] = $rRow["stream_display_name"];
                        }
                    }
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        $rLineInfo[$rUserID]["stream_display_name"] = $rStreamMap[$rConnection["stream_id"]];
                        $rLineInfo[$rUserID]["stream_id"] = $rConnection["stream_id"];
                        $rLineInfo[$rUserID]["last_active"] = $rConnection["date_start"];
                    }
                    unset($rConnectionMap);
                } else {
                    $db->query("SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`hls_end` = 0 AND `lines_live`.`user_id` IN (" . implode(",", $rLineIDs) . ");");
                    foreach ($db->get_rows() as $rRow) {
                        $rLineInfo[$rRow["user_id"]]["stream_display_name"] = $rRow["stream_display_name"];
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            if (0 < count($rActivityIDs)) {
                $db->query("SELECT `user_id`, `stream_id`, `date_end` AS `last_active` FROM `lines_activity` WHERE `activity_id` IN (" . implode(",", $rActivityIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    if (!isset($rLineInfo[$rRow["user_id"]]["stream_id"])) {
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            foreach ($rRows as $rRow) {
                $rRow = array_merge($rRow, $rLineInfo[$rRow["id"]]);

                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rRow["active_connections"] = isset($rConnectionCount[$rRow["id"]]) ? $rConnectionCount[$rRow["id"]] : 0;
                }
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (!$rRow["admin_enabled"]) {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"Banned\"></i>";
                    } elseif (!$rRow["enabled"]) {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Disabled\"></i>";
                    } elseif ($rRow["exp_date"] && $rRow["exp_date"] < time()) {
                        $rStatus = "<i class=\"text-warning far fa-square tooltip\" title=\"Expired\"></i>";
                    } else {
                        $rStatus = "<i class=\"text-success fas fa-square tooltip\" title=\"Active\"></i>";
                    }
                    if (0 < $rRow["active_connections"]) {
                        $rActive = "<i class=\"text-success fas fa-square\"></i>";
                    } else {
                        $rActive = "<i class=\"text-secondary far fa-square\"></i>";
                    }
                    if ($rRow["is_trial"]) {
                        $rTrial = "<i class=\"text-warning fas fa-square\"></i>";
                    } else {
                        $rTrial = "<i class=\"text-secondary far fa-square\"></i>";
                    }
                    if ($rRow["is_restreamer"]) {
                        $rRestreamer = "<i class=\"text-info fas fa-square\"></i>";
                    } else {
                        $rRestreamer = "<i class=\"text-secondary far fa-square\"></i>";
                    }
                    if ($rRow["exp_date"]) {
                        if ($rRow["exp_date"] < time()) {
                            $rExpDate = "<span class=\"expired\">" . date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small>" . date("H:i:s", $rRow["exp_date"]) . "</small></span>";
                        } else {
                            $rExpDate = date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["exp_date"]) . "</small>";
                        }
                    } else {
                        $rExpDate = "&infin;";
                    }

                    if (0 < $rRow["active_connections"]) {
                        $rActiveConnections = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["active_connections"] . "</button>";
                    } else {
                        $rActiveConnections = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (hasPermissions("adv", "live_connections") && 0 < $rRow["active_connections"]) {
                        $rActiveConnections = "<a href=\"live_connections?user_id=" . $rRow["id"] . "\">" . $rActiveConnections . "</a>";
                    }
                    if ($rRow["max_connections"] == 0) {
                        $rMaxConnections = "<button type='button' class='btn btn-dark text-white btn-xs waves-effect waves-light'>&infin;</button>";
                    } else {
                        $rMaxConnections = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . $rRow["max_connections"] . "</button>";
                    }
                    $rNotes = "";
                    if (!empty($rRow['admin_notes'])) {
                        $rNotes .= $rRow['admin_notes'];
                    }
                    if (!empty($rRow['reseller_notes'])) {
                        if ($rNotes !== '') {
                            $rNotes .= "\n";
                        }
                        $rNotes .= $rRow['reseller_notes'];
                    }

                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"line?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'line', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["username"])) . "')\" data-modal=\"true\"" : "") . ">Edit Line</a>";
                        }
                        if (hasPermissions("adv", "fingerprint") && 0 < $rRow["active_connections"]) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"modalFingerprint(" . $rRow["id"] . ", 'user');\">Fingerprint</a>";
                        }
                        $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"openDownload('" . $rRow["username"] . "', '" . $rRow["password"] . "');\">Download Playlist</a>";
                        $rWhatsAppContact = !empty($rRow["contact"]) ? addslashes($rRow["contact"]) : '';
                        $rWhatsAppExp = $rRow["exp_date"] ? $rRow["exp_date"] : 'null';
                        $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"openWhatsApp('" . addslashes($rRow["username"]) . "', '" . $rWhatsAppContact . "', " . $rWhatsAppExp . ");\"><i class=\"mdi mdi-whatsapp text-success\"></i> WhatsApp Renewal</a>";
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'kill');\">Kill Connections</a>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'ban');\">Ban Line</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'unban');\">Unban Line</a>";
                            }
                            if ($rRow["enabled"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'disable');\">Disable Line</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'enable');\">Enable Line</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'delete');\">Delete Line</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        } else {
                            $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<a href=\"line?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'line', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["username"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                        }
                        if (hasPermissions("adv", "fingerprint")) {
                            if (0 < $rRow["active_connections"]) {
                                $rButtons .= "<button title=\"Fingerprint\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"modalFingerprint(" . $rRow["id"] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            } else {
                                $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            }
                        }
                        $rButtons .= "<button type=\"button\" title=\"Download Playlist\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"openDownload('" . $rRow["username"] . "', '" . $rRow["password"] . "');\"><i class=\"mdi mdi-download\"></i></button>";
                        $rWhatsAppContact = !empty($rRow["contact"]) ? addslashes($rRow["contact"]) : '';
                        $rWhatsAppExp = $rRow["exp_date"] ? $rRow["exp_date"] : 'null';
                        $rButtons .= "<button type=\"button\" title=\"WhatsApp Renewal\" class=\"btn btn-success waves-effect waves-light btn-xs tooltip\" onClick=\"openWhatsApp('" . addslashes($rRow["username"]) . "', '" . $rWhatsAppContact . "', " . $rWhatsAppExp . ");\"><i class=\"mdi mdi-whatsapp\"></i></button>";
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<button title=\"Ban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Unban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
                            }
                            if ($rRow["enabled"]) {
                                $rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                        }
                        $rButtons .= "</div>";
                    }
                    if ($rRow["active_connections"] && $rRow["last_active"]) {
                        $rLastActive = "<a href='stream_view?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a><br/><small class='text-secondary'>Online: " . CoreUtilities::secondsToTime(time() - $rRow["last_active"]) . "</small>";
                    } elseif (!empty($rRow['last_active'])) {
                        $rLastActive = date($rSettings["date_format"], $rRow["last_active"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["last_active"]) . "</small>";
                    } else {
                        $rLastActive = "Never";
                    }
                    if (0 < $rRow["member_id"]) {
                        $rOwner = "<a href='user?id=" . $rRow["member_id"] . "'>" . $rRow["owner_name"] . "</a>";
                    } else {
                        $rOwner = $rRow['owner_name'] ?? '';
                    }
                    if (!isset(CoreUtilities::$rRequest["no_url"])) {
                        $rReturn["data"][] = ["<a href='line?id=" . $rRow["id"] . "'>" . $rRow["id"] . "</a>", "<a href='line?id=" . $rRow["id"] . "'>" . $rRow["username"] . "</a>", $rRow["password"], $rOwner, $rStatus, $rActive, $rTrial, $rRestreamer, $rActiveConnections, $rMaxConnections, $rExpDate, $rLastActive, $rButtons];
                    } else {
                        $rReturn["data"][] = [$rRow["id"], $rRow["username"], $rRow["password"], $rRow["owner_name"], $rStatus, $rActive, $rTrial, $rRestreamer, $rActiveConnections, $rMaxConnections, $rExpDate, $rLastActive, $rButtons];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "mags") {
    if (!hasPermissions("adv", "manage_mag")) {
        exit;
    }
    $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
    $rOrder = ["`lines`.`id`", "`lines`.`username`", "`mag_devices`.`mac`", "`mag_devices`.`stb_type`", "`lines`.`member_id`", "`lines`.`enabled`", "`active_connections` > 0", "`lines`.`is_trial`", "`lines`.`exp_date`", "`active_connections` " . $rOrderDirection . ", `last_activity`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 6) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `mag_devices`.`stb_type` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ? OR `lines`.`admin_notes` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "`lines`.`enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "`lines`.`admin_enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`lines`.`is_trial` = 1";
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "`lines`.`member_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `lines` RIGHT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`last_activity`, `lines`.`last_activity_array`, `mag_devices`.`mac`, `mag_devices`.`stb_type`, `mag_devices`.`mag_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`admin_notes`, `lines`.`reseller_notes`, `lines`.`max_connections`, `lines`.`is_trial`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` RIGHT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rActivityIDs = $rLineInfo = $rLineIDs = [];
            foreach ($rRows as $rRow) {
                if ($rRow["id"]) {
                    $rLineIDs[] = (int) $rRow["id"];
                    $rLineInfo[(int) $rRow["id"]] = ["owner_name" => NULL, "stream_display_name" => NULL, "stream_id" => NULL, "last_active" => NULL];
                }
                $rLastInfo = [];

                if (!empty($rRow['last_activity_array'])) {
                    $decoded = json_decode($rRow['last_activity_array'], true);
                    if (is_array($decoded)) {
                        $rLastInfo = $decoded;
                    }
                }

                if ($rLastInfo) {
                    $rLineInfo[(int) $rRow["id"]]["stream_id"] = $rLastInfo["stream_id"];
                    $rLineInfo[(int) $rRow["id"]]["last_active"] = $rLastInfo["date_end"];
                } elseif ($rRow["last_activity"]) {
                    $rActivityIDs[] = (int) $rRow["last_activity"];
                }
            }
            if (0 < count($rLineIDs)) {
                $db->query("SELECT `users`.`username`, `lines`.`id` FROM `users` LEFT JOIN `lines` ON `lines`.`member_id` = `users`.`id` WHERE `lines`.`id` IN (" . implode(",", $rLineIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rLineInfo[$rRow["id"]]["owner_name"] = $rRow["username"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rConnectionCount = CoreUtilities::getUserConnections($rLineIDs, true);
                    $rConnectionMap = CoreUtilities::getFirstConnection($rLineIDs);
                    $rStreamIDs = [];
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        if (!in_array($rConnection["stream_id"], $rStreamIDs)) {
                            $rStreamIDs[] = (int) $rConnection["stream_id"];
                        }
                    }
                    $rStreamMap = [];
                    if (0 < count($rStreamIDs)) {
                        $db->query("SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(",", $rStreamIDs) . ");");
                        foreach ($db->get_rows() as $rRow) {
                            $rStreamMap[$rRow["id"]] = $rRow["stream_display_name"];
                        }
                    }
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        $rLineInfo[$rUserID]["stream_display_name"] = $rStreamMap[$rConnection["stream_id"]];
                        $rLineInfo[$rUserID]["stream_id"] = $rConnection["stream_id"];
                        $rLineInfo[$rUserID]["last_active"] = $rConnection["date_start"];
                    }
                    unset($rConnectionMap);
                } else {
                    $db->query("SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`user_id` IN (" . implode(",", $rLineIDs) . ");");
                    foreach ($db->get_rows() as $rRow) {
                        $rLineInfo[$rRow["user_id"]]["stream_display_name"] = $rRow["stream_display_name"];
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            if (0 < count($rActivityIDs)) {
                $db->query("SELECT `user_id`, `stream_id`, `date_end` AS `last_active` FROM `lines_activity` WHERE `activity_id` IN (" . implode(",", $rActivityIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    if (!isset($rLineInfo[$rRow["user_id"]]["stream_id"])) {
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (isset($rLineInfo[$rRow["id"]])) {
                    $rRow = array_merge($rRow, $rLineInfo[$rRow["id"]]);
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rRow["active_connections"] = isset($rConnectionCount[$rRow["id"]]) ? $rConnectionCount[$rRow["id"]] : 0;
                }
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (!$rRow["id"]) {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"Damaged - Line Missing\"></i>";
                    } elseif (!$rRow["admin_enabled"]) {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"Banned\"></i>";
                    } elseif (!$rRow["enabled"]) {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Disabled\"></i>";
                    } elseif ($rRow["exp_date"] && $rRow["exp_date"] < time()) {
                        $rStatus = "<i class=\"text-warning far fa-square tooltip\" title=\"Expired\"></i>";
                    } else {
                        $rStatus = "<i class=\"text-success fas fa-square tooltip\" title=\"Active\"></i>";
                    }
                    if (0 < $rRow["active_connections"]) {
                        $rActive = "<i class=\"text-success fas fa-square\"></i>";
                    } else {
                        $rActive = "<i class=\"text-warning far fa-square\"></i>";
                    }
                    if ($rRow["is_trial"]) {
                        $rTrial = "<i class=\"text-warning fas fa-square\"></i>";
                    } else {
                        $rTrial = "<i class=\"text-secondary far fa-square\"></i>";
                    }
                    if ($rRow["exp_date"]) {
                        if ($rRow["exp_date"] < time()) {
                            $rExpDate = "<span class=\"expired\">" . date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small>" . date("H:i:s", $rRow["exp_date"]) . "</small></span>";
                        } else {
                            $rExpDate = date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["exp_date"]) . "</small>";
                        }
                    } else {
                        $rExpDate = "&infin;";
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        $rActiveConnections = "<a href=\"live_connections?user_id=" . $rRow["id"] . "\">" . $rRow["active_connections"] . "</a>";
                    } else {
                        $rActiveConnections = $rRow["active_connections"];
                    }
                    $rNotes = "";
                    if (!empty($rRow["admin_notes"])) {
                        $rNotes .= $rRow["admin_notes"];
                    }
                    if (!empty($rRow["reseller_notes"])) {
                        if (strlen($rNotes) != 0) {
                            $rNotes .= "\n";
                        }
                        $rNotes .= $rRow["reseller_notes"];
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if (hasPermissions("adv", "manage_events")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"message(" . $rRow["mag_id"] . ", '" . $rRow["mac"] . "');\">MAG Event</a>";
                        }
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'convert');\">Convert to Line</a>";
                        }
                        if (hasPermissions("adv", "fingerprint") && !empty($rRow["user_id"]) && 0 < $rRow["active_connections"]) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"modalFingerprint(" . $rRow["user_id"] . ", 'user');\">Fingerprint</a>";
                        }
                        if (hasPermissions("adv", "edit_mag")) {
                            $username = str_replace('"', '&quot;', str_replace("'", "\\'", $rRow['username'] ?? ''));
                            $rButtons .= "<a class=\"dropdown-item\" href=\"mag?id=" . $rRow['id'] . "\" " . (CoreUtilities::$rSettings['modal_edit'] ? "onClick=\"editModal(event, 'mag', " . (int) ($rRow['mag_id'] ?? 0) . ", '$username')\" data-modal=\"true\"" : "") . ">Edit Device</a>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'ban');\">Ban Device</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'unban');\">Unban Device</a>";
                            }
                            if ($rRow["enabled"] == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'disable');\">Disable Device</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'enable');\">Enable Device</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["mag_id"] . ", 'delete');\">Delete Device</a>";
                        }
                        $rButtons .= "</div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        } else {
                            $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        if (hasPermissions("adv", "manage_events")) {
                            $rButtons .= "<button title=\"MAG Event\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"message(" . $rRow["mag_id"] . ", '" . $rRow["mac"] . "');\"><i class=\"mdi mdi-message-alert\"></i></button>";
                        }
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<button title=\"Convert to User Line\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'convert');\"><i class=\"fas fa-retweet\"></i></button>";
                        }
                        if (hasPermissions("adv", "fingerprint")) {
                            if ($rRow["user_id"] && 0 < $rRow["active_connections"]) {
                                $rButtons .= "<button title=\"Fingerprint\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"modalFingerprint(" . $rRow["user_id"] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            } else {
                                $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            }
                        }
                        if (hasPermissions("adv", "edit_mag")) {
                            $rButtons .= "<a href=\"mag?id=" . $rRow["mag_id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'mag', " . (int) $rRow["mag_id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["mac"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<button title=\"Ban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Unban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
                            }
                            if ($rRow["enabled"] == 1) {
                                $rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["mag_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                        }
                        $rButtons .= "</div>";
                    }
                    if (0 < $rRow["member_id"]) {
                        $rOwner = "<a href='user?id=" . $rRow["member_id"] . "'>" . $rRow["owner_name"] . "</a>";
                    } else {
                        $rOwner = $rRow['owner_name'] ?? '';
                    }
                    if ($rRow["active_connections"] && $rRow["last_active"]) {
                        $rLastActive = "<a href='stream_view?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a><br/><small class='text-secondary'>Online: " . CoreUtilities::secondsToTime(time() - $rRow["last_active"]) . "</small>";
                    } elseif (!empty($rRow['last_active'])) {
                        $rLastActive = date($rSettings["date_format"], $rRow["last_active"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["last_active"]) . "</small>";
                    } else {
                        $rLastActive = "Never";
                    }
                    if (!isset(CoreUtilities::$rRequest["no_url"])) {
                        $rReturn["data"][] = ["<a href='mag?id=" . $rRow["mag_id"] . "'>" . $rRow["mag_id"] . "</a>", $rRow["username"], "<a href='mag?id=" . $rRow["mag_id"] . "'>" . $rRow["mac"] . "</a>", $rRow["stb_type"], $rOwner, $rStatus, $rActive, $rTrial, $rExpDate, $rLastActive, $rButtons];
                    } else {
                        $rReturn["data"][] = [$rRow["mag_id"], $rRow["username"], $rRow["mac"], $rRow["stb_type"], $rRow["owner_name"], $rStatus, $rActive, $rTrial, $rExpDate, $rLastActive, $rButtons];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "enigmas") {
    if (!hasPermissions("adv", "manage_e2")) {
        exit;
    }
    $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
    $rOrder = ["`lines`.`id`", "`lines`.`username`", "`enigma2_devices`.`mac`", "`enigma2_devices`.`public_ip`", "`lines`.`member_id`", "`lines`.`enabled`", "`active_connections` > 0", "`lines`.`is_trial`", "`lines`.`exp_date`", "`active_connections` " . $rOrderDirection . ", `last_activity`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 6) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`lines`.`username` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `enigma2_devices`.`public_ip` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ? OR `lines`.`admin_notes` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "`lines`.`enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "`lines`.`admin_enabled` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`lines`.`is_trial` = 1";
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "`lines`.`member_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `lines` RIGHT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`last_activity`, `lines`.`last_activity_array`, `enigma2_devices`.`mac`, `enigma2_devices`.`public_ip`, `enigma2_devices`.`device_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`admin_notes`, `lines`.`reseller_notes`, `lines`.`max_connections`, `lines`.`is_trial`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` RIGHT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rActivityIDs = $rLineInfo = $rLineIDs = [];
            foreach ($rRows as $rRow) {
                if ($rRow["id"]) {
                    $rLineIDs[] = (int) $rRow["id"];
                    $rLineInfo[(int) $rRow["id"]] = ["owner_name" => NULL, "stream_display_name" => NULL, "stream_id" => NULL, "last_active" => NULL];
                }
                $rLastInfo = [];

                if (!empty($rRow['last_activity_array'])) {
                    $decoded = json_decode($rRow['last_activity_array'], true);
                    if (is_array($decoded)) {
                        $rLastInfo = $decoded;
                    }
                }

                if ($rLastInfo) {
                    $rLineInfo[(int) $rRow["id"]]["stream_id"] = $rLastInfo["stream_id"];
                    $rLineInfo[(int) $rRow["id"]]["last_active"] = $rLastInfo["date_end"];
                } elseif ($rRow["last_activity"]) {
                    $rActivityIDs[] = (int) $rRow["last_activity"];
                }
            }
            if (0 < count($rLineIDs)) {
                $db->query("SELECT `users`.`username`, `lines`.`id` FROM `users` LEFT JOIN `lines` ON `lines`.`member_id` = `users`.`id` WHERE `lines`.`id` IN (" . implode(",", $rLineIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rLineInfo[$rRow["id"]]["owner_name"] = $rRow["username"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rConnectionCount = CoreUtilities::getUserConnections($rLineIDs, true);
                    $rConnectionMap = CoreUtilities::getFirstConnection($rLineIDs);
                    $rStreamIDs = [];
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        if (!in_array($rConnection["stream_id"], $rStreamIDs)) {
                            $rStreamIDs[] = (int) $rConnection["stream_id"];
                        }
                    }
                    $rStreamMap = [];
                    if (0 < count($rStreamIDs)) {
                        $db->query("SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(",", $rStreamIDs) . ");");
                        foreach ($db->get_rows() as $rRow) {
                            $rStreamMap[$rRow["id"]] = $rRow["stream_display_name"];
                        }
                    }
                    foreach ($rConnectionMap as $rUserID => $rConnection) {
                        $rLineInfo[$rUserID]["stream_display_name"] = $rStreamMap[$rConnection["stream_id"]];
                        $rLineInfo[$rUserID]["stream_id"] = $rConnection["stream_id"];
                        $rLineInfo[$rUserID]["last_active"] = $rConnection["date_start"];
                    }
                    unset($rConnectionMap);
                } else {
                    $db->query("SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`user_id` IN (" . implode(",", $rLineIDs) . ");");
                    foreach ($db->get_rows() as $rRow) {
                        $rLineInfo[$rRow["user_id"]]["stream_display_name"] = $rRow["stream_display_name"];
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            if (0 < count($rActivityIDs)) {
                $db->query("SELECT `user_id`, `stream_id`, `date_end` AS `last_active` FROM `lines_activity` WHERE `activity_id` IN (" . implode(",", $rActivityIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    if (!isset($rLineInfo[$rRow["user_id"]]["stream_id"])) {
                        $rLineInfo[$rRow["user_id"]]["stream_id"] = $rRow["stream_id"];
                        $rLineInfo[$rRow["user_id"]]["last_active"] = $rRow["last_active"];
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (isset($rLineInfo[$rRow["id"]])) {
                    $rRow = array_merge($rRow, $rLineInfo[$rRow["id"]]);
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rRow["active_connections"] = isset($rConnectionCount[$rRow["id"]]) ? $rConnectionCount[$rRow["id"]] : 0;
                }
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (!$rRow["id"]) {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"Damaged - Line Missing\"></i>";
                    } elseif (!$rRow["admin_enabled"]) {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"Banned\"></i>";
                    } elseif (!$rRow["enabled"]) {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Disabled\"></i>";
                    } elseif ($rRow["exp_date"] && $rRow["exp_date"] < time()) {
                        $rStatus = "<i class=\"text-warning far fa-square tooltip\" title=\"Expired\"></i>";
                    } else {
                        $rStatus = "<i class=\"text-success fas fa-square tooltip\" title=\"Active\"></i>";
                    }
                    if (0 < $rRow["active_connections"]) {
                        $rActive = "<i class=\"text-success fas fa-square\"></i>";
                    } else {
                        $rActive = "<i class=\"text-warning far fa-square\"></i>";
                    }
                    if ($rRow["is_trial"]) {
                        $rTrial = "<i class=\"text-warning fas fa-square\"></i>";
                    } else {
                        $rTrial = "<i class=\"text-secondary far fa-square\"></i>";
                    }
                    if ($rRow["exp_date"]) {
                        if ($rRow["exp_date"] < time()) {
                            $rExpDate = "<span class=\"expired\">" . date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small>" . date("H:i:s", $rRow["exp_date"]) . "</small></span>";
                        } else {
                            $rExpDate = date($rSettings["date_format"], $rRow["exp_date"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["exp_date"]) . "</small>";
                        }
                    } else {
                        $rExpDate = "&infin;";
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        $rActiveConnections = "<a href=\"live_connections?user_id=" . $rRow["id"] . "\">" . $rRow["active_connections"] . "</a>";
                    } else {
                        $rActiveConnections = $rRow["active_connections"];
                    }
                    $rNotes = "";
                    if (!empty($rRow["admin_notes"])) {
                        $rNotes .= $rRow["admin_notes"];
                    }
                    if (!empty($rRow["reseller_notes"])) {
                        if (strlen($rNotes) != 0) {
                            $rNotes .= "\n";
                        }
                        $rNotes .= $rRow["reseller_notes"];
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'convert');\">Convert to Line</a>";
                        }
                        if (hasPermissions("adv", "fingerprint") && $rRow["user_id"] && 0 < $rRow["active_connections"]) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"modalFingerprint(" . $rRow["user_id"] . ", 'user');\">Fingerprint</a>";
                        }
                        if (hasPermissions("adv", "edit_e2")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"enigma?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'enigma', " . (int) $rRow["device_id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["username"])) . "')\" data-modal=\"true\"" : "") . ">Edit Device</a>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'ban');\">Ban Device</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'unban');\">Unban Device</a>";
                            }
                            if ($rRow["enabled"] == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'disable');\">Disable Device</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'enable');\">Enable Device</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["device_id"] . ", 'delete');\">Delete Device</a>";
                        }
                        $rButtons .= "</div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if (0 < strlen($rNotes)) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rNotes . "\"><i class=\"mdi mdi-note\"></i></button>";
                        } else {
                            $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        if (hasPermissions("adv", "edit_user")) {
                            $rButtons .= "<button title=\"Convert to User Line\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'convert');\"><i class=\"fas fa-retweet\"></i></button>";
                        }
                        if (hasPermissions("adv", "fingerprint")) {
                            if ($rRow["user_id"] && 0 < $rRow["active_connections"]) {
                                $rButtons .= "<button title=\"Fingerprint\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"modalFingerprint(" . $rRow["user_id"] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            } else {
                                $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                            }
                        }
                        if (hasPermissions("adv", "edit_e2")) {
                            $rButtons .= "<a href=\"enigma?id=" . $rRow["device_id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'enigma', " . (int) $rRow["device_id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["mac"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                            if ($rRow["admin_enabled"]) {
                                $rButtons .= "<button title=\"Ban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'ban');\"><i class=\"mdi mdi-power\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Unban\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'unban');\"><i class=\"mdi mdi-power\"></i></button>";
                            }
                            if ($rRow["enabled"] == 1) {
                                $rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["device_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                        }
                        $rButtons .= "</div>";
                    }
                    if (0 < $rRow["member_id"]) {
                        $rOwner = "<a href='user?id=" . $rRow["member_id"] . "'>" . $rRow["owner_name"] . "</a>";
                    } else {
                        $rOwner = $rRow['owner_name'] ?? '';
                    }
                    if ($rRow["active_connections"] && $rRow["last_active"]) {
                        $rLastActive = "<a href='stream_view?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a><br/><small class='text-secondary'>Online: " . CoreUtilities::secondsToTime(time() - $rRow["last_active"]) . "</small>";
                    } elseif (!empty($rRow['last_active'])) {
                        $rLastActive = date($rSettings["date_format"], $rRow["last_active"]) . "<br/><small class='text-secondary'>" . date("H:i:s", $rRow["last_active"]) . "</small>";
                    } else {
                        $rLastActive = "Never";
                    }
                    if (!isset(CoreUtilities::$rRequest["no_url"])) {
                        $rReturn["data"][] = ["<a href='enigma?id=" . $rRow["device_id"] . "'>" . $rRow["device_id"] . "</a>", $rRow["username"], "<a href='enigma?id=" . $rRow["device_id"] . "'>" . $rRow["mac"] . "</a>", "<a onClick=\"whois('" . $rRow["public_ip"] . "');\" href='javascript: void(0);'>" . $rRow["public_ip"] . "</a>", $rOwner, $rStatus, $rActive, $rTrial, $rExpDate, $rLastActive, $rButtons];
                    } else {
                        $rReturn["data"][] = [$rRow["device_id"], $rRow["username"], $rRow["mac"], $rRow["public_ip"], $rRow["owner_name"], $rStatus, $rActive, $rTrial, $rExpDate, $rLastActive, $rButtons];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "streams") {
    if (!hasPermissions("adv", "streams") && !hasPermissions("adv", "mass_edit_streams")) {
        exit;
    }
    $rCategories = getCategories("live");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_icon`", "`streams`.`stream_display_name`", "`streams_servers`.`current_source`", "`clients`", "`streams_servers`.`stream_started`", false, false, false, "`streams_servers`.`bitrate`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rCreated = isset(CoreUtilities::$rRequest["created"]);
    $rWhere = $rWhereV = [];
    if ($rCreated) {
        $rWhere[] = "`streams`.`type` = 3";
    } else {
        $rWhere[] = "`streams`.`type` = 1";
    }
    if (isset(CoreUtilities::$rRequest["stream_id"])) {
        $rWhere[] = "`streams`.`id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } else {
        if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
            foreach (range(1, 4) as $rInt) {
                $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
            }
            $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
        }
        if (0 < (int) CoreUtilities::$rRequest["category"]) {
            $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
            $rWhereV[] = CoreUtilities::$rRequest["category"];
        } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
            $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
        }
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rWhere = ["`streams`.`id` IN (" . implode(",", array_map("intval", explode(",", CoreUtilities::$rRequest["refresh"]))) . ")"];
            $rStart = 0;
            $rLimit = 1000;
        }
        if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
            if (!$rCreated) {
                if (CoreUtilities::$rRequest["filter"] == 1) {
                    $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`stream_status` = 0)";
                } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                    $rWhere[] = "((`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 1))";
                } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                    $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0)";
                } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                    $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 2)";
                } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                    $rWhere[] = "`streams_servers`.`on_demand` = 1";
                } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                    $rWhere[] = "`streams`.`direct_source` = 1";
                } elseif (CoreUtilities::$rRequest["filter"] == 7) {
                    $rWhere[] = "`streams`.`tv_archive_server_id` > 0 AND `streams`.`tv_archive_duration` > 0";
                } elseif (CoreUtilities::$rRequest["filter"] == 8) {
                    if ($rSettings["streams_grouped"] == 1) {
                        $rWhere[] = "(SELECT COUNT(*) AS `count` FROM `streams_logs` WHERE `streams_logs`.`action` = 'STREAM_FAILED' AND `streams_logs`.`date` >= UNIX_TIMESTAMP()-86400 AND `streams_logs`.`stream_id` = `streams`.`id`) > 144";
                    } else {
                        $rWhere[] = "(SELECT COUNT(*) AS `count` FROM `streams_logs` WHERE `streams_logs`.`action` = 'STREAM_FAILED' AND `streams_logs`.`date` >= UNIX_TIMESTAMP()-86400 AND `streams_logs`.`stream_id` = `streams`.`id` AND `streams_logs`.`server_id` = `streams_servers`.`server_id`) > 144";
                    }
                } elseif (CoreUtilities::$rRequest["filter"] == 9) {
                    $rWhere[] = "LENGTH(`streams`.`channel_id`) > 0";
                } elseif (CoreUtilities::$rRequest["filter"] == 10) {
                    $rWhere[] = "(`streams`.`channel_id` IS NULL OR LENGTH(`streams`.`channel_id`) = 0)";
                } elseif (CoreUtilities::$rRequest["filter"] == 11) {
                    $rWhere[] = "`streams`.`adaptive_link` IS NOT NULL";
                } elseif (CoreUtilities::$rRequest["filter"] == 12) {
                    $rWhere[] = "`streams`.`title_sync` IS NOT NULL";
                } elseif (CoreUtilities::$rRequest["filter"] == 13) {
                    $rWhere[] = "`streams`.`transcode_profile_id` > 0";
                }
            } elseif (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "(`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND (REPLACE(`streams_servers`.`cchannel_rsources`, '\\\\/', '/') = REPLACE(`streams`.`stream_source`, '\\\\/', '/'))";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "(REPLACE(`streams_servers`.`cchannel_rsources`, '\\\\/', '/') <> REPLACE(`streams`.`stream_source`, '\\\\/', '/'))";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "`streams`.`transcode_profile_id` > 0";
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["audio"])) {
            if (CoreUtilities::$rRequest["audio"] == -1) {
                $rWhere[] = "`streams_servers`.`audio_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`audio_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["audio"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["video"])) {
            if (CoreUtilities::$rRequest["video"] == -1) {
                $rWhere[] = "`streams_servers`.`video_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`video_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["video"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["resolution"])) {
            $rWhere[] = "`streams_servers`.`resolution` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["resolution"] ?: NULL;
        }
        if (0 < (int) CoreUtilities::$rRequest["server"]) {
            $rWhere[] = "`streams_servers`.`server_id` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
        } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
            $rWhere[] = "`streams_servers`.`server_id` IS NULL";
        }
        if ($rOrder[$rOrderRow]) {
            $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
            $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
        }
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if (isset(CoreUtilities::$rRequest["single"])) {
        $rSettings["streams_grouped"] = 0;
    }
    if ($rSettings["streams_grouped"] == 1) {
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM (SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id`) t1;";
    } else {
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    }
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    if ($rIsAPI) {
        $rReturn["recordsFiltered"] = min($rReturn["recordsTotal"], $rLimit);
    } else {
        $rReturn["recordsFiltered"] = $rReturn["recordsTotal"];
    }
    if ($rReturn["recordsTotal"] > 0) {
        if ($rSettings["streams_grouped"] == 1) {
            $rQuery = "SELECT `streams`.`id`, `streams_servers`.`stream_id`, `streams`.`type`, `streams`.`stream_icon`, `streams`.`adaptive_link`, `streams`.`title_sync`, `streams_servers`.`cchannel_rsources`, `streams`.`stream_source`, `streams`.`stream_display_name`, `streams`.`tv_archive_duration`, `streams`.`tv_archive_server_id`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`cc_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams`.`epg_id`, `streams`.`channel_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . ", -`stream_started` DESC LIMIT " . $rStart . ", " . $rLimit . ";";
        } else {
            $rQuery = "SELECT `streams`.`id`, `streams`.`type`, `streams`.`stream_icon`, `streams`.`adaptive_link`, `streams`.`title_sync`, `streams_servers`.`cchannel_rsources`, `streams`.`stream_source`, `streams`.`stream_display_name`, `streams`.`tv_archive_duration`, `streams`.`tv_archive_server_id`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`cc_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams`.`epg_id`, `streams`.`channel_id`, `streams_servers`.`parent_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        }
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rEPGIDs = $rFails = $rFailsPS = $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
                if ($rRow["channel_id"] && !in_array("'" . $rRow["epg_id"] . "_" . $rRow["channel_id"] . "'", $rEPGIDs)) {
                    $rEPGIDs[] = "'" . $rRow["epg_id"] . "_" . str_replace("'", "\\'", $rRow["channel_id"]) . "'";
                }
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"]) {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
                    } else {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, false, false);
                    }
                }
            }
            if (!$rCreated) {
                $rTime = time();

                if (count($rStreamIDs) > 0) {
                    $rQuery = "SELECT `stream_id`, `server_id`, COUNT(*) AS `fails`, MAX(`date`) AS `last` FROM `streams_logs` WHERE `action` IN ('STREAM_FAILED', 'STREAM_START_FAIL') AND `date` >= (UNIX_TIMESTAMP()-" . intval(($rSettings['fails_per_time'] ?: 86400)) . ') AND `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ') GROUP BY `stream_id`, `server_id`;';
                    $db->query($rQuery);

                    if ($db->num_rows() > 0) {
                        foreach ($db->get_rows() as $rRow) {
                            $streamId = $rRow["stream_id"];
                            $serverId = intval($rRow["server_id"]);
                            $fails = intval($rRow["fails"]);
                            $lastDelta = $rTime - intval($rRow["last"]);

                            // Init arrays if not set
                            if (!isset($rFailsPS[$streamId])) {
                                $rFailsPS[$streamId] = [];
                            }
                            $rFailsPS[$streamId][$serverId] = [$fails, $lastDelta];

                            if (!isset($rFails[$streamId])) {
                                $rFails[$streamId] = [0, 0]; // [totalFails, maxLastDelta]
                            }

                            $rFails[$streamId][0] += $fails; // sum up fails

                            if ($rFails[$streamId][1] < $lastDelta) {
                                $rFails[$streamId][1] = $lastDelta; // save max lastDelta
                            }
                        }
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["clients"] = $rConnectionCount[$rRow["id"]] ?: 0;
                    } else {
                        $rRow["clients"] = count($rConnectionCount[$rRow["id"]][$rRow["server_id"]]) ?: 0;
                    }
                }
                if ($rIsAPI) {
                    unset($rRow["stream_source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    if (0 < $rRow['tv_archive_duration'] && 0 < $rRow['tv_archive_server_id']) {
                        $rRow['stream_display_name'] .= " &nbsp;<a href='archive?id=" . $rRow['id'] . "'><i class='text-danger mdi mdi-record'></i></a>";
                    }
                    $adaptiveLinks = json_decode($rRow['adaptive_link'] ?? '', true) ?: [];

                    if (is_array($adaptiveLinks) && count($adaptiveLinks) > 0) {
                        $rRow['stream_display_name'] .= " &nbsp;<a href='stream_view?id=" . $rRow['id'] . "'><i class='text-info mdi mdi-wifi-strength-3'></i></a>";
                    }
                    if ($rRow['title_sync']) {
                        $rRow['stream_display_name'] .= " &nbsp;<i class='text-info mdi mdi-sync tooltip' title='Title Sync'></i>";
                    }
                    $rStreamName = "<a href='stream_view?id=" . $rRow["id"] . "'><strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rCategory . "</span></a>";
                    if ($rRow["server_name"]) {
                        if (hasPermissions("adv", "servers")) {
                            $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                        } else {
                            $rServerName = $rRow["server_name"];
                        }
                        if ($rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button title=\"View All Servers\" onClick=\"viewSources('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', " . (int) $rRow["id"] . ");\" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                        if ($rServers[$rRow["server_id"]]["last_status"] != 1) {
                            $rServerName .= " &nbsp; <button title=\"Server Offline!<br/>Uptime cannot be confirmed.\" type='button' class='tooltip btn btn-danger btn-xs waves-effect waves-light'><i class='mdi mdi-alert'></i></button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    if (isset($rRow['parent_id']) && (int)$rRow['parent_id'] > 0) {
                        $rStreamSource = "<br/><span style='font-size:11px;'>loop: " . strtolower(CoreUtilities::$rServers[$rRow["parent_id"]]["server_name"]) . "</span>";
                    } else {
                        $rStreamSource = "<br/><span style='font-size:11px;'>" . strtolower(parse_url($rRow['current_source'] ?? '')['host'] ?? '') . "</span>";
                    }
                    $rServerName .= $rStreamSource;
                    if (0 < (int) $rRow["stream_started"]) {
                        $rSeconds = $rUptime = time() - (int) $rRow["stream_started"];
                    }
                    $rActualStatus = 0;
                    if ($rRow["server_id"]) {
                        if (!$rCreated) {
                            if ((int) $rRow["direct_source"] == 1) {
                                if ((int) $rRow["direct_proxy"] == 1) {
                                    if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                        $rActualStatus = 1;
                                    } else {
                                        $rActualStatus = 7;
                                    }
                                } else {
                                    $rActualStatus = 5;
                                }
                            } elseif ($rRow["monitor_pid"]) {
                                if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                    if ((int) $rRow["stream_status"] == 2) {
                                        $rActualStatus = 2;
                                    } else {
                                        $rActualStatus = 1;
                                    }
                                } elseif ($rRow["stream_status"] == 0) {
                                    $rActualStatus = 2;
                                } else {
                                    $rActualStatus = 3;
                                }
                            } elseif ((int) $rRow["on_demand"] == 1) {
                                $rActualStatus = 4;
                            } else {
                                $rActualStatus = 0;
                            }
                        } else {
                            if ($rRow["monitor_pid"]) {
                                if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                    if ((int) $rRow["stream_status"] == 2) {
                                        $rActualStatus = 2;
                                    } else {
                                        $rActualStatus = 1;
                                    }
                                } elseif ($rRow["stream_status"] == 0) {
                                    $rActualStatus = 2;
                                } else {
                                    $rActualStatus = 3;
                                }
                            } else {
                                $rActualStatus = 0;
                            }
                            if (count(json_decode($rRow["cchannel_rsources"], true)) != count(json_decode($rRow["stream_source"], true)) && !$rRow["parent_id"]) {
                                $rActualStatus = 6;
                            }
                        }
                    } elseif ((int) $rRow["direct_source"] == 1) {
                        $rActualStatus = 5;
                    } else {
                        $rActualStatus = -1;
                    }
                    if (!$rRow["server_id"]) {
                        $rRow["server_id"] = 0;
                    }
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["server_id"] = -1;
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        if (0 < $rRow["clients"]) {
                            $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . (int) $rRow["id"] . ", " . (int) $rRow["server_id"] . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button></a>";
                        } else {
                            $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                        }
                    } elseif (0 < $rRow["clients"]) {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button>";
                    } else {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (CoreUtilities::$rSettings["hide_failures"] && !$rCreated) {
                        $rBtnLength = "btn-fixed-xl";
                    } else {
                        $rBtnLength = "btn-fixed";
                    }
                    if ($rActualStatus == 1) {
                        if (86400 <= $rUptime) {
                            $rUptime = sprintf("%02dd %02dh %02dm", intdiv((int)$rUptime, 86400), intdiv((int)$rUptime, 3600) % 24, intdiv((int)$rUptime, 60) % 60);
                        } else {
                            $rUptime = sprintf("%02dh %02dm %02ds", intdiv((int)$rUptime, 3600), intdiv((int)$rUptime, 60) % 60, (int)$rUptime % 60);
                        }
                        $rUptime = "<button type='button' class='btn btn-success btn-xs waves-effect waves-light " . $rBtnLength . "'>" . $rUptime . "</button>";
                    } elseif ($rActualStatus == 3) {
                        $rUptime = "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light " . $rBtnLength . "'>DOWN</button>";
                    } elseif ($rActualStatus == 6) {
                        $rSources = json_decode($rRow["stream_source"], true);
                        $rLeft = count(array_diff($rSources, json_decode($rRow["cchannel_rsources"], true)));
                        $rPercent = (int) ((count($rSources) - $rLeft) / count($rSources) * 100);
                        $rUptime = "<button type='button' class='btn btn-primary btn-xs waves-effect waves-light btn-fixed-xl'>" . $rPercent . "% DONE</button>";
                    } else {
                        $rUptime = $rStatusArray[$rActualStatus];
                    }
                    if (in_array($rActualStatus, [1, 2, 3])) {
                        if ($rCreated) {
                            $rCCInfo = json_decode($rRow["cc_info"], true);
                            $rTrackInfo = $rRow["parent_id"] ? "Channel is looping from another server, real position cannot be determined." : "No information available.";
                            if ($rActualStatus == 1 && 0 < count($rCCInfo) && !$rRow["parent_id"]) {
                                $rSources = json_decode($rRow["stream_source"], true);
                                foreach ($rCCInfo as $rTrack) {
                                    if ($rTrack["start"] <= $rSeconds && $rSeconds < $rTrack["finish"]) {
                                        $rTrackInfo = pathinfo($rSources[$rTrack["position"]])["filename"] . "<br/><br/>Track # " . ($rTrack["position"] + 1) . " of " . count($rSources) . "<br/>";
                                        if ($rTrack["position"] < count($rSources) - 1) {
                                            $rTrackInfo .= "Next track in " . number_format(($rTrack["finish"] - $rSeconds) / 60, 0) . " minutes.";
                                        } else {
                                            $rTrackInfo .= "Looping in " . number_format(($rTrack["finish"] - $rSeconds) / 60, 0) . " minutes.";
                                        }
                                    }
                                }
                                $rUptime = "<button type='button' title='" . htmlspecialchars($rTrackInfo) . "' class='btn tooltip btn-success btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-check-circle'></i></button>" . $rUptime;
                            } else {
                                $rUptime = "<button type='button' title='" . htmlspecialchars($rTrackInfo) . "' class='btn tooltip btn-secondary btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-minus-circle'></i></button>" . $rUptime;
                            }
                        } else {
                            if (CoreUtilities::$rSettings["hide_failures"] && stripos($rUptime, "btn-fixed-xl") === false) {
                                $rUptime = str_replace("btn-fixed", "btn-fixed-xl", $rUptime);
                            }
                            if ($rSettings["streams_grouped"] == 1) {
                                $rFailRow = $rFails[$rRow['id']] ?? [];
                            } else {
                                $rFailRow = $rFailsPS[$rRow["id"]][$rRow["server_id"]];
                            }
                            if (!$rFailRow) {
                                $rFailRow = [0, 0];
                            }
                            if (!CoreUtilities::$rSettings["hide_failures"]) {
                                if (!isset($rFailRow) || $rFailRow[0] <= 2) {
                                    $rUptime = "<button onClick='showFailures(" . (int) $rRow["id"] . ", " . (!$rSettings["streams_grouped"] ? (int) $rRow["server_id"] : "0") . ")' type='button' title='" . $rFailRow[0] . " restarts' class='btn tooltip-left btn-success btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-check-circle'></i></button>" . $rUptime;
                                } elseif ($rFailRow[0] <= 4 || 21600 < $rFailRow[1]) {
                                    $rUptime = "<button onClick='showFailures(" . (int) $rRow["id"] . ", " . (!$rSettings["streams_grouped"] ? (int) $rRow["server_id"] : "0") . ")' type='button' title='" . $rFailRow[0] . " restarts' class='btn tooltip-left btn-info btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-minus-circle'></i></button>" . $rUptime;
                                } elseif ($rFailRow[0] <= 144 || 600 < $rFailRow[1]) {
                                    $rUptime = "<button onClick='showFailures(" . (int) $rRow["id"] . ", " . (!$rSettings["streams_grouped"] ? (int) $rRow["server_id"] : "0") . ")' type='button' title='" . $rFailRow[0] . " restarts' class='btn tooltip-left btn-warning btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-exclamation-circle'></i></button>" . $rUptime;
                                } else {
                                    $rUptime = "<button onClick='showFailures(" . (int) $rRow["id"] . ", " . (!$rSettings["streams_grouped"] ? (int) $rRow["server_id"] : "0") . ")' type='button' title='" . $rFailRow[0] . " restarts' class='btn tooltip-left btn-danger btn-xs waves-effect waves-light btn-fixed-xs'><i class='text-light fas fa-times-circle'></i></button>" . $rUptime;
                                }
                            }
                        }
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_stream")) {
                            if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\">Restart</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\">Kill Connections</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start</a>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                            }
                        } else {
                            if (hasPermissions("adv", "edit_stream")) {
                                if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                    $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop</a>\r\n\t\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\">Restart</a>\r\n\t\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\">Kill Connections</a>";
                                } else {
                                    $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start</a>";
                                }
                            }
                            if (hasPermissions("adv", "fingerprint") && !$rCreated && 0 < $rRow["clients"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"modalFingerprint(" . $rRow["id"] . ", 'stream');\">Fingerprint</a>";
                            }
                            if (hasPermissions("adv", "edit_stream")) {
                                if ($rRow["type"] == 3) {
                                    $rButtons .= "<a class=\"dropdown-item\" href=\"created_channel?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'created_channel', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>";
                                } else {
                                    $rButtons .= "<a class=\"dropdown-item\" href=\"stream?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'stream', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>";
                                }
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                            }
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_stream")) {
                            if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                $rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                $rStatus = "";
                            } else {
                                $rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                $rStatus = " disabled";
                            }
                            $rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-restart tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\"" . $rStatus . "><i class=\"mdi mdi-refresh\"></i></button>\r\n\t\t\t\t\t\t<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-restart tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\"" . $rStatus . "><i class=\"mdi mdi-hammer\"></i></button>";
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        } else {
                            if (!empty($rRow['notes'])) {
                                $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                            } else {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                            }
                            if (hasPermissions("adv", "edit_stream")) {
                                if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                    $rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                    $rStatus = "";
                                } else {
                                    $rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                    $rStatus = " disabled";
                                }
                                $rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-restart tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\"" . $rStatus . "><i class=\"mdi mdi-refresh\"></i></button>\r\n\t\t\t\t\t\t\t<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\"" . $rStatus . "><i class=\"mdi mdi-hammer\"></i></button>";
                            }
                            if (hasPermissions("adv", "fingerprint") && !$rCreated) {
                                if (0 < $rRow["clients"]) {
                                    $rButtons .= "<button title=\"Fingerprint\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"modalFingerprint(" . $rRow["id"] . ", 'stream');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                                } else {
                                    $rButtons .= "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                                }
                            }
                            if (hasPermissions("adv", "edit_stream")) {
                                if ($rRow["type"] == 3) {
                                    $rButtons .= "<a href=\"created_channel?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'created_channel', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                                } else {
                                    $rButtons .= "<a href=\"stream?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'stream', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                                }
                                $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        }
                        $rButtons .= "</div>";
                    }
                    $rStreamInfoText = "<table style='font-size: 10px;' class='table-data nowrap' align='center'><tbody><tr><td colspan='5'>No information available</td></tr></tbody></table>";
                    $rStreamInfo   = json_decode($rRow['stream_info'] ?? '', true) ?: [];
                    $rProgressInfo = json_decode($rRow['progress_info'] ?? '', true) ?: [];
                    if ($rActualStatus == 1) {
                        if (!isset($rStreamInfo["codecs"]["video"])) {
                            $rStreamInfo["codecs"]["video"] = ["width" => "?", "height" => "?", "codec_name" => "N/A", "r_frame_rate" => "--"];
                        }
                        if (!isset($rStreamInfo["codecs"]["audio"])) {
                            $rStreamInfo["codecs"]["audio"] = ["codec_name" => "N/A"];
                        }
                        if ($rRow["bitrate"] == 0) {
                            $rRow["bitrate"] = "?";
                        }
                        if (isset($rProgressInfo["speed"])) {
                            $rSpeed = floor($rProgressInfo["speed"] * 100) / 100 . "x";
                        } else {
                            $rSpeed = "1x";
                        }
                        $rFPS = NULL;
                        if (isset($rProgressInfo["fps"])) {
                            $rFPS = (int) $rProgressInfo["fps"];
                        } elseif (isset($rStreamInfo["codecs"]["video"]["r_frame_rate"])) {
                            $rFPS = (int) $rStreamInfo["codecs"]["video"]["r_frame_rate"];
                        }
                        if ($rFPS) {
                            if (1000 <= $rFPS) {
                                $rFPS = (int) ($rFPS / 1000);
                            }
                            $rFPS = $rFPS . " FPS";
                        } else {
                            $rFPS = "--";
                        }
                        $bitrate = is_numeric($rRow["bitrate"]) ? $rRow["bitrate"] : 0;
                        $rStreamInfoText = "<table class='table-data nowrap' align='center'><tbody><tr><td class='double'>" . number_format($bitrate, 0) . " Kbps</td><td class='text-success'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td class='text-success'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>";
                        if (!$rCreated) {
                            $rStreamInfoText .= "<td class='text-success'><i class='mdi mdi-play-speed' data-name='mdi-play-speed'></i></td>";
                        }
                        $rStreamInfoText .= "<td class='text-success'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td></tr><tr><td class='double'>" . $rStreamInfo["codecs"]["video"]["width"] . " x " . $rStreamInfo["codecs"]["video"]["height"] . "</td><td>" . $rStreamInfo["codecs"]["video"]["codec_name"] . "</td><td>" . $rStreamInfo["codecs"]["audio"]["codec_name"] . "</td>";
                        if (!$rCreated) {
                            $rStreamInfoText .= "<td>" . $rSpeed . "</td>";
                        }
                        $rStreamInfoText .= "<td>" . $rFPS . "</td></tr></tbody></table>";
                    }
                    if (hasPermissions("adv", "player")) {
                        if (((int) $rActualStatus == 1 || $rActualStatus == 4) && !$rRow["direct_proxy"]) {
                            if (empty($rStreamInfo["codecs"]["video"]["codec_name"]) || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "H264" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "N/A" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "HEVC" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "H265") {
                                $rPlayer = "<button title=\"Play\" type=\"button\" class=\"btn btn-info waves-effect waves-light btn-xs tooltip\" onClick=\"player(" . $rRow["id"] . ");\"><i class=\"mdi mdi-play\"></i></button>";
                            } else {
                                $rPlayer = "<button type=\"button\" class=\"btn btn-dark waves-effect waves-light btn-xs tooltip\" title=\"Incompatible Video Codec\"><i class=\"mdi mdi-play\"></i></button>";
                            }
                        } else {
                            $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                        }
                    } else {
                        $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                    }
                    if (file_exists(EPG_PATH . "stream_" . $rRow["id"])) {
                        $rEPG = "<button onClick=\"viewEPG(" . (int) $rRow["id"] . ");\" type='button' title='View EPG' class='tooltip btn btn-success btn-xs waves-effect waves-light'><i class='text-white fas fa-square'></i></button>";
                    } elseif ($rRow["channel_id"]) {
                        $rEPG = "<button type='button' class='btn btn-warning btn-xs waves-effect waves-light'><i class='text-white fas fa-square'></i></button>";
                    } else {
                        $rEPG = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'><i class='text-white fas fa-square'></i></button>";
                    }
                    if (0 < strlen($rRow["stream_icon"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . urlencode($rRow["stream_icon"]) . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . urlencode($rRow["stream_icon"]) . "' /></a>";
                    } else {
                        $rIcon = "";
                    }
                    $rID = $rRow["id"];
                    if (!$rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                        $rID .= "-" . $rRow["server_id"];
                    }
                    if ($rCreated) {
                        $rReturn["data"][] = ["<a href='stream_view?id=" . $rRow["id"] . "'>" . $rID . "</a>", $rIcon, $rStreamName, $rServerName, $rClients, $rUptime, $rButtons, $rPlayer, $rStreamInfoText];
                    } else {
                        $rReturn["data"][] = ["<a href='stream_view?id=" . $rRow["id"] . "'>" . $rID . "</a>", $rIcon, $rStreamName, $rServerName, $rClients, $rUptime, $rButtons, $rPlayer, $rEPG, $rStreamInfoText];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "radios") {
    if (!hasPermissions("adv", "radio") && !hasPermissions("adv", "mass_edit_radio")) {
        exit;
    }
    $rCategories = getCategories("radio");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_icon`", "`streams`.`stream_display_name`", "`server_name`", "`clients`", "`streams_servers`.`stream_started`", false, "`streams_servers`.`bitrate`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 4";
    if (isset(CoreUtilities::$rRequest["stream_id"])) {
        $rWhere[] = "`streams`.`id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } else {
        if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
            foreach (range(1, 4) as $rInt) {
                $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
            }
            $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
        }
        if (0 < (int) CoreUtilities::$rRequest["category"]) {
            $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
            $rWhereV[] = CoreUtilities::$rRequest["category"];
        } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
            $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
        }
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rWhere = ["`streams`.`id` IN (" . implode(",", array_map("intval", explode(",", CoreUtilities::$rRequest["refresh"]))) . ")"];
            $rStart = 0;
            $rLimit = 1000;
        }
        if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
            if (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`stream_status` = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "((`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 1))";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 2)";
            } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                $rWhere[] = "`streams_servers`.`on_demand` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                $rWhere[] = "`streams`.`direct_source` = 1";
            }
        }
        if (0 < (int) CoreUtilities::$rRequest["server"]) {
            $rWhere[] = "`streams_servers`.`server_id` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
        } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
            $rWhere[] = "`streams_servers`.`server_id` IS NULL";
        }
        if ($rOrder[$rOrderRow]) {
            $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
            $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
        }
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if (isset(CoreUtilities::$rRequest["single"])) {
        $rSettings["streams_grouped"] = 0;
    }
    if ($rSettings["streams_grouped"] == 1) {
        $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    } else {
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    }
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        if ($rSettings["streams_grouped"] == 1) {
            $rQuery = "SELECT `streams`.`id`, `streams`.`stream_icon`, `streams`.`movie_properties`, `streams_servers`.`to_analyze`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        } else {
            $rQuery = "SELECT `streams`.`id`, `streams`.`stream_icon`, `streams`.`type`, `streams_servers`.`cchannel_rsources`, `streams`.`stream_source`, `streams`.`stream_display_name`, `streams`.`tv_archive_duration`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams_servers`.`parent_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        }
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"]) {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
                    } else {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, false, false);
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["clients"] = $rConnectionCount[$rRow["id"]] ?: 0;
                    } else {
                        $rRow["clients"] = count($rConnectionCount[$rRow["id"]][$rRow["server_id"]]) ?: 0;
                    }
                }
                if ($rIsAPI) {
                    unset($rRow["stream_source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rStreamName = "<a href='stream_view?id=" . $rRow["id"] . "'><strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rCategory . "</span></a>";
                    if ($rRow["server_name"]) {
                        if (hasPermissions("adv", "servers")) {
                            $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                        } else {
                            $rServerName = $rRow["server_name"];
                        }
                        if ($rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button title=\"View All Servers\" onClick=\"viewSources('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', " . (int) $rRow["id"] . ");\" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                        if ($rServers[$rRow["server_id"]]["last_status"] != 1) {
                            $rServerName .= " &nbsp; <button title=\"Server Offline!<br/>Uptime cannot be confirmed.\" type='button' class='tooltip btn btn-danger btn-xs waves-effect waves-light'><i class='mdi mdi-alert'></i></button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    if (!$rSettings["streams_grouped"]) {
                        if (isset($rRow['parent_id']) && (int)$rRow['parent_id'] > 0) {
                            $rStreamSource = "<br/><span style='font-size:11px;'>loop: " . strtolower(CoreUtilities::$rServers[$rRow["parent_id"]]["server_name"]) . "</span>";
                        } else {
                            $rStreamSource = "<br/><span style='font-size:11px;'>" . strtolower(parse_url($rRow['current_source'] ?? '')['host'] ?? '') . "</span>";
                        }
                        $rServerName .= $rStreamSource;
                    }
                    $rUptime = 0;
                    $rActualStatus = 0;
                    if (0 < (int) $rRow["stream_started"]) {
                        $rUptime = time() - (int) $rRow["stream_started"];
                    }
                    if ($rRow["server_id"]) {
                        if ((int) $rRow["direct_source"] == 1) {
                            $rActualStatus = 5;
                        } elseif ($rRow["monitor_pid"]) {
                            if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                if ((int) $rRow["stream_status"] == 2) {
                                    $rActualStatus = 2;
                                } else {
                                    $rActualStatus = 1;
                                }
                            } elseif ($rRow["stream_status"] == 0) {
                                $rActualStatus = 2;
                            } else {
                                $rActualStatus = 3;
                            }
                        } elseif ((int) $rRow["on_demand"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 0;
                        }
                    } else {
                        $rActualStatus = -1;
                    }
                    if (!$rRow["server_id"]) {
                        $rRow["server_id"] = 0;
                    }
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["server_id"] = -1;
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        if (0 < $rRow["clients"]) {
                            $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . (int) $rRow["id"] . ", " . (int) $rRow["server_id"] . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button></a>";
                        } else {
                            $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                        }
                    } elseif (0 < $rRow["clients"]) {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button>";
                    } else {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if ($rActualStatus == 1) {
                        if (86400 <= $rUptime) {
                            $rUptime = sprintf("%02dd %02dh %02dm", intdiv((int)$rUptime, 86400), intdiv((int)$rUptime, 3600) % 24, intdiv((int)$rUptime, 60) % 60);
                        } else {
                            $rUptime = sprintf("%02dh %02dm %02ds", intdiv((int)$rUptime, 3600), intdiv((int)$rUptime, 60) % 60, (int)$rUptime % 60);
                        }
                        $rUptime = "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed-xl'>" . $rUptime . "</button>";
                    } elseif ($rActualStatus == 3) {
                        $rUptime = "<button type='button' class='btn btn-danger btn-xs waves-effect waves-light btn-fixed-xl'>DOWN</button>";
                    } else {
                        $rUptime = $rStatusArray[$rActualStatus];
                    }
                    $rUptime = str_replace("btn-fixed'", "btn-fixed-xl'", $rUptime);
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_radio")) {
                            if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\">Restart</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\">Kill Connections</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start</a>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                            }
                        } elseif (hasPermissions("adv", "edit_radio")) {
                            if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop</a>\r\n\t\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\">Restart</a>\r\n\t\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\">Kill Connections</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"radio?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'radio', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_radio")) {
                            if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                $rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                $rStatus = "";
                            } else {
                                $rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                $rStatus = " disabled";
                            }
                            $rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-restart tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\"" . $rStatus . "><i class=\"mdi mdi-refresh\"></i></button>";
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        } else {
                            if (!empty($rRow['notes'])) {
                                $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                            } else {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                            }
                            if (hasPermissions("adv", "edit_radio")) {
                                if ((int) $rActualStatus == 1 || (int) $rActualStatus == 2 || (int) $rActualStatus == 3 || $rRow["on_demand"] == 1 || $rActualStatus == 5) {
                                    $rButtons .= "<button title=\"Stop\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                    $rStatus = "";
                                } else {
                                    $rButtons .= "<button title=\"Start\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                    $rStatus = " disabled";
                                }
                                $rButtons .= "<button title=\"Restart\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-restart tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'restart');\"" . $rStatus . "><i class=\"mdi mdi-refresh\"></i></button>\r\n\t\t\t\t\t\t\t<button title=\"Kill Connections\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'purge');\"" . $rStatus . "><i class=\"mdi mdi-hammer\"></i></button>\r\n\t\t\t\t\t\t\t<a href=\"radio?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'radio', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>\r\n\t\t\t\t\t\t\t<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        }
                        $rButtons .= "</div>";
                    }
                    $rStreamInfoText = "<table style='font-size: 10px;' class='table-data nowrap' align='center'><tbody><tr><td colspan='5'>No information available</td></tr></tbody></table>";
                    $rStreamInfo   = json_decode($rRow['stream_info'] ?? '', true) ?: [];
                    $rProgressInfo = json_decode($rRow['progress_info'] ?? '', true) ?: [];
                    if ($rActualStatus == 1) {
                        if (!isset($rStreamInfo["codecs"]["video"])) {
                            $rStreamInfo["codecs"]["video"] = ["width" => "?", "height" => "?", "codec_name" => "N/A", "r_frame_rate" => "--"];
                        }
                        if (!isset($rStreamInfo["codecs"]["audio"])) {
                            $rStreamInfo["codecs"]["audio"] = ["codec_name" => "N/A"];
                        }
                        if ($rRow["bitrate"] == 0) {
                            $rRow["bitrate"] = "?";
                        }
                        if (isset($rProgressInfo["speed"])) {
                            $rSpeed = floor($rProgressInfo["speed"] * 100) / 100 . "x";
                        } else {
                            $rSpeed = "1x";
                        }
                        $rStreamInfoText = "<table class='table-data nowrap table-data-90' align='center'>\r\n                        <tbody>\r\n                            <tr>\r\n                                <td class='text-success'><i class='mdi mdi-video' data-name='mdi-video'></i></td>\r\n                                <td class='text-success'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>\r\n                                <td class='text-success'><i class='mdi mdi-play-speed' data-name='mdi-play-speed'></i></td>\r\n                            </tr>\r\n                            <tr>\r\n                                <td>" . $rRow["bitrate"] . " Kbps</td>\r\n                                <td>" . $rStreamInfo["codecs"]["audio"]["codec_name"] . "</td>\r\n                                <td>" . $rSpeed . "</td>\r\n                            </tr>\r\n                        </tbody>\r\n                    </table>";
                    }
                    if (0 < strlen($rRow["stream_icon"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow["stream_icon"] . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . $rRow["stream_icon"] . "' /></a>";
                    } else {
                        $rIcon = "";
                    }
                    $rID = $rRow["id"];
                    if (!$rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                        $rID .= "-" . $rRow["server_id"];
                    }
                    $rReturn["data"][] = ["<a href='stream_view?id=" . $rRow["id"] . "'>" . $rID . "</a>", $rIcon, $rStreamName, $rServerName, $rClients, $rUptime, $rButtons, $rStreamInfoText];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "movies") {
    if (!hasPermissions("adv", "movies") && !hasPermissions("adv", "mass_sedits_vod")) {
        exit;
    }
    $rCategories = getCategories("movie");
    $rOrder = ["`streams`.`id`", false, "`streams`.`stream_display_name`", "`server_name`", "`clients`", "`streams_servers`.`stream_started`", false, false, false, "`streams_servers`.`bitrate`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 2";
    $rDuplicates = false;
    if (isset(CoreUtilities::$rRequest["stream_id"])) {
        $rWhere[] = "`streams`.`id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } elseif (isset(CoreUtilities::$rRequest["source_id"])) {
        $rWhere[] = "MD5(`streams`.`stream_source`) = ?";
        $rWhereV[] = CoreUtilities::$rRequest["source_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } else {
        if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
            foreach (range(1, 4) as $rInt) {
                $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
            }
            $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
        }
        if (0 < (int) CoreUtilities::$rRequest["category"]) {
            $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
            $rWhereV[] = CoreUtilities::$rRequest["category"];
        } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
            $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
        }
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rWhere = ["`streams`.`id` IN (" . implode(",", array_map("intval", explode(",", CoreUtilities::$rRequest["refresh"]))) . ")"];
            $rStart = 0;
            $rLimit = 1000;
        }
        if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
            if (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 1 AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` = 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                $rWhere[] = "`streams`.`direct_source` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                $rWhere[] = "(`streams`.`movie_properties` IS NULL OR `streams`.`movie_properties` = '' OR `streams`.`movie_properties` = '[]' OR `streams`.`movie_properties` = '{}' OR `streams`.`movie_properties` LIKE '%tmdb_id\":\"\"%')";
            } elseif (CoreUtilities::$rRequest["filter"] == 7) {
                $rWhere[] = "`streams`.`id` IN (SELECT MIN(`id`) FROM `streams` WHERE `type` = 2 GROUP BY `stream_source` HAVING COUNT(`stream_source`) > 1)";
                $rDuplicates = true;
            } elseif (CoreUtilities::$rRequest["filter"] == 8) {
                $rWhere[] = "`streams`.`transcode_profile_id` > 0";
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["audio"])) {
            if (CoreUtilities::$rRequest["audio"] == -1) {
                $rWhere[] = "`streams_servers`.`audio_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`audio_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["audio"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["video"])) {
            if (CoreUtilities::$rRequest["video"] == -1) {
                $rWhere[] = "`streams_servers`.`video_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`video_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["video"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["resolution"])) {
            $rWhere[] = "`streams_servers`.`resolution` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["resolution"] ?: NULL;
        }
        if (0 < (int) CoreUtilities::$rRequest["server"]) {
            $rWhere[] = "`streams_servers`.`server_id` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
        } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
            $rWhere[] = "`streams_servers`.`server_id` IS NULL";
        }
        if ($rOrder[$rOrderRow]) {
            $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
            $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
        }
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if (isset(CoreUtilities::$rRequest["single"])) {
        $rSettings["streams_grouped"] = 0;
    } elseif (isset(CoreUtilities::$rRequest["grouped"])) {
        $rSettings["streams_grouped"] = 1;
    }
    if ($rSettings["streams_grouped"] == 1) {
        $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    } else {
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    }
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        if ($rSettings["streams_grouped"] == 1) {
            $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams`.`movie_properties`, `streams`.`year`, `streams_servers`.`to_analyze`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        } else {
            $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams`.`movie_properties`, `streams`.`year`, `streams_servers`.`to_analyze`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        }
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"]) {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
                    } else {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, false, false);
                    }
                }
                if ($rDuplicates) {
                    $rDuplicateCount = [];
                    $db->query("SELECT MD5(`stream_source`) AS `source`, COUNT(`stream_source`) AS `count` FROM `streams` WHERE `stream_source` IN (SELECT `stream_source` FROM `streams` WHERE `id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ")) GROUP BY `stream_source` HAVING COUNT(`stream_source`) > 1;");
                    foreach ($db->get_rows() as $rRow) {
                        $rDuplicateCount[$rRow["source"]] = $rRow["count"];
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["clients"] = $rConnectionCount[$rRow["id"]] ?: 0;
                    } else {
                        $rRow["clients"] = count($rConnectionCount[$rRow["id"]][$rRow["server_id"]]) ?: 0;
                    }
                }
                if ($rIsAPI) {
                    unset($rReturn["source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    $rProperties = json_decode($rRow["movie_properties"], true);
                    $rRatingText = "";
                    if ($rProperties["rating"]) {
                        $rStarRating = round($rProperties["rating"]) / 2;
                        $rFullStars = floor($rStarRating);
                        $rHalfStar = 0 < $rStarRating - $rFullStars;
                        $rEmpty = 5 - ($rFullStars + ($rHalfStar ? 1 : 0));
                        if (0 < $rFullStars) {
                            foreach (range(1, $rFullStars) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star'></i>";
                            }
                        }
                        if ($rHalfStar) {
                            $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                        }
                        if (0 < $rEmpty) {
                            foreach (range(1, $rEmpty) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                            }
                        }
                    }
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rYear = $rRow["year"] ? "<strong>" . $rRow["year"] . "</strong> &nbsp;" : "";
                    $rStreamName = "<a href='stream_view?id=" . $rRow["id"] . "'><strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rYear . $rRatingText . "<br/>" . $rCategory . "</span></a>";
                    if ($rRow["server_name"]) {
                        if (hasPermissions("adv", "servers")) {
                            $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                        } else {
                            $rServerName = $rRow["server_name"];
                        }
                        if ($rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button title=\"View All Servers\" onClick=\"viewSources('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', " . (int) $rRow["id"] . ");\" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                        if ($rServers[$rRow["server_id"]]["last_status"] != 1) {
                            $rServerName .= " &nbsp; <button title=\"Server Offline!<br/>Uptime cannot be confirmed.\" type='button' class='tooltip btn btn-danger btn-xs waves-effect waves-light'><i class='mdi mdi-alert'></i></button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    $rUptime = 0;
                    if ($rRow["server_id"]) {
                        $rActualStatus = 0;
                        if ((int) $rRow["direct_source"] == 1) {
                            if ((int) $rRow["direct_proxy"] == 1) {
                                $rActualStatus = 5;
                            } else {
                                $rActualStatus = 3;
                            }
                        } elseif (!is_null($rRow["pid"]) && 0 < $rRow["pid"]) {
                            if ($rRow["to_analyze"] == 1) {
                                $rActualStatus = 2;
                            } elseif ($rRow["stream_status"] == 1) {
                                $rActualStatus = 4;
                            } else {
                                $rActualStatus = 1;
                            }
                        } else {
                            $rActualStatus = 0;
                        }
                    } else {
                        $rActualStatus = -1;
                    }
                    if (!$rRow["server_id"]) {
                        $rRow["server_id"] = 0;
                    }
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["server_id"] = -1;
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        if (0 < $rRow["clients"]) {
                            $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . (int) $rRow["id"] . ", " . (int) $rRow["server_id"] . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button></a>";
                        } else {
                            $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                        }
                    } elseif (0 < $rRow["clients"]) {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button>";
                    } else {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_movie")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            } elseif ((int) $rActualStatus == 3) {
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop Encoding</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start Encoding</a>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                            }
                        } elseif (hasPermissions("adv", "edit_movie")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            } elseif ((int) $rActualStatus == 3) {
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop Encoding</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Start Encoding</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"movie?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'movie', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_movie")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
                            } elseif ((int) $rActualStatus == 3 || (int) $rActualStatus == 5) {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop\"><i class=\"mdi mdi-stop\"></i></button>";
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Start Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        } else {
                            if (!empty($rRow['notes'])) {
                                $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                            } else {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                            }
                            if (hasPermissions("adv", "edit_movie")) {
                                if ((int) $rActualStatus == 1) {
                                    $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
                                } elseif ((int) $rActualStatus == 3 || (int) $rActualStatus == 5) {
                                    $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop\"><i class=\"mdi mdi-stop\"></i></button>";
                                } elseif ((int) $rActualStatus == 2) {
                                    $rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                } else {
                                    $rButtons .= "<button title=\"Start Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                }
                                $rButtons .= "<a href=\"movie?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'movie', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>\r\n\t\t\t\t\t\t\t<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        }
                        $rButtons .= "</div>";
                    }
                    if ($rDuplicates) {
                        $rDupeCount = $rDuplicateCount[$rRow["source"]] - 1 ?: 0;
                        $rStreamInfoText = "<a href='javascript: void(0);' onClick=\"viewDuplicates('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', '" . $rRow["source"] . "');\">Duplicate of <strong>" . $rDupeCount . "</strong> other movie" . ($rDupeCount == 1 ? "" : "s") . "</a>";
                    } else {
                        $rStreamInfoText = "<table style='font-size: 10px;' class='table-data nowrap' align='center'><tbody><tr><td colspan='3'>No information available</td></tr></tbody></table>";
                        $rStreamInfo   = json_decode($rRow['stream_info'] ?? '', true) ?: [];
                        if ($rActualStatus == 1) {
                            if (!isset($rStreamInfo["codecs"]["video"])) {
                                $rStreamInfo["codecs"]["video"] = ["width" => "?", "height" => "?", "codec_name" => "N/A", "r_frame_rate" => "--"];
                            }
                            if (!isset($rStreamInfo["codecs"]["audio"])) {
                                $rStreamInfo["codecs"]["audio"] = ["codec_name" => "N/A"];
                            }
                            if ($rRow["bitrate"] == 0) {
                                $rRow["bitrate"] = "?";
                            }
                            $rDuration = empty($rStreamInfo["duration"]) ? "--" : substr($rStreamInfo["duration"], 0, 5);
                            $rStreamInfoText = "<table class='table-data nowrap table-data-120 text-center' align='center'>\r\n\t\t\t\t\t\t\t<tbody>\r\n\t\t\t\t\t\t\t\t<tr>\r\n\t\t\t\t\t\t\t\t\t<td class='double'>" . number_format($rRow["bitrate"], 0) . " Kbps</td>\r\n\t\t\t\t\t\t\t\t\t<td class='text-success'><i class='mdi mdi-video' data-name='mdi-video'></i></td>\r\n\t\t\t\t\t\t\t\t\t<td class='text-success'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>\r\n\t\t\t\t\t\t\t\t\t<td class='text-success'><i class='mdi mdi-clock' data-name='mdi-clock'></i></td>\r\n\t\t\t\t\t\t\t\t</tr>\r\n\t\t\t\t\t\t\t\t<tr>\r\n\t\t\t\t\t\t\t\t\t<td class='double'>" . $rStreamInfo["codecs"]["video"]["width"] . " x " . $rStreamInfo["codecs"]["video"]["height"] . "</td>\r\n\t\t\t\t\t\t\t\t\t<td>" . $rStreamInfo["codecs"]["video"]["codec_name"] . "</td>\r\n\t\t\t\t\t\t\t\t\t<td>" . $rStreamInfo["codecs"]["audio"]["codec_name"] . "</td>\r\n\t\t\t\t\t\t\t\t\t<td>" . $rDuration . "</td>\r\n\t\t\t\t\t\t\t\t</tr>\r\n\t\t\t\t\t\t\t</tbody>\r\n\t\t\t\t\t\t</table>";
                        }
                    }
                    if (hasPermissions("adv", "player")) {
                        if ((int) $rActualStatus == 1 || $rActualStatus == 3) {
                            $rPlayer = "<button title=\"Play\" type=\"button\" class=\"btn btn-info waves-effect waves-light btn-xs tooltip\" onClick=\"player(" . $rRow["id"] . ", '" . $rRow["target_container"] . "');\"><i class=\"mdi mdi-play\"></i></button>";
                        } else {
                            $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                        }
                    } else {
                        $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                    }
                    if (0 < strlen($rProperties["movie_image"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties["movie_image"] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rProperties["movie_image"] . "' /></a>";
                    } else {
                        $rImage = "";
                    }
                    if (isset($rProperties["kinopoisk_url"]) && 0 < strlen($rProperties["kinopoisk_url"])) {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-success btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-check-circle\"></i></button>";
                    } else {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-minus-circle\"></i></button>";
                    }
                    $rID = $rRow["id"];
                    if (!$rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                        $rID .= "-" . $rRow["server_id"];
                    }
                    $rReturn["data"][] = ["<a href='stream_view?id=" . $rRow["id"] . "'>" . $rID . "</a>", $rImage, $rStreamName, $rServerName, $rClients, $rVODStatusArray[$rActualStatus], $rTMDB, $rButtons, $rPlayer, $rStreamInfoText];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "episode_list") {
    if (!hasPermissions("adv", "import_episodes") && !hasPermissions("adv", "mass_delete")) {
        exit;
    }
    $rOrder = ["`streams`.`id`", false, "`streams`.`stream_display_name`", "`streams_servers`.`server_id`", "`streams_servers`.`stream_status`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 5";
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_servers`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
        $rWhere[] = "`streams_servers`.`server_id` IS NULL";
    }
    if (0 < strlen(CoreUtilities::$rRequest["series"])) {
        $rWhere[] = "`streams_episodes`.`series_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["series"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 5) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams_series`.`title` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 1 AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`stream_status` = 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`streams`.`direct_source` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 7) {
            $rWhere[] = "`streams`.`transcode_profile_id` > 0";
        }
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if (0 < $db->num_rows()) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams_servers`.`to_analyze`, `streams`.`movie_properties`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams_series`.`title`, `streams_series`.`seasons`, `streams_series`.`id` AS `sid`, `streams_episodes`.`season_num` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
            }
            foreach ($rRows as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rActualStatus = 0;
                    if ((int) $rRow["direct_source"] == 1) {
                        if ((int) $rRow["direct_proxy"] == 1) {
                            $rActualStatus = 5;
                        } else {
                            $rActualStatus = 3;
                        }
                    } elseif (!is_null($rRow["pid"]) && 0 < $rRow["pid"]) {
                        if ($rRow["to_analyze"] == 1) {
                            $rActualStatus = 2;
                        } elseif ($rRow["stream_status"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 1;
                        }
                    } else {
                        $rActualStatus = 0;
                    }
                    $rSeriesName = $rRow["title"] . " - Season " . $rRow["season_num"];
                    $rStreamName = "<strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rSeriesName . "</span>";
                    if ($rRow["server_name"]) {
                        $rServerName = $rRow["server_name"];
                        if (1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    $rImage = "";
                    $rProperties = json_decode($rRow["movie_properties"], true);
                    if (0 < strlen($rProperties["movie_image"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rImage = "<a href='javascript: void(0);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties["movie_image"] . "'><img loading='lazy' src='resize?maxh=32&maxw=64&url=" . $rProperties["movie_image"] . "' /></a>";
                    }
                    $rReturn["data"][] = [$rRow["id"], $rImage, $rStreamName, $rServerName, $rVODStatusArray[$rActualStatus]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "line_activity") {
    if (!hasPermissions("adv", "connection_logs")) {
        exit;
    }
    $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
    $rOrder = ["`username` " . $rOrderDirection . ", `lines_activity`.`hmac_identifier`", "`streams`.`stream_display_name`", "`server_name`", "`lines_activity`.`user_agent`", "`lines_activity`.`isp`", "`lines_activity`.`user_ip`", "`lines_activity`.`date_start`", "`lines_activity`.`activity_id`", "`lines_activity`.`date_end` - `lines_activity`.`date_start`", "`lines_activity`.`container`", "`lines`.`is_restreamer`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 7) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`lines_activity`.`hmac_identifier` LIKE ? OR `lines_activity`.`user_agent` LIKE ? OR `lines_activity`.`user_ip` LIKE ? OR `lines_activity`.`container` LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_start`) LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_end`) LIKE ? OR `lines_activity`.`geoip_country_code` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["range"])) {
        $rStartTime = substr(CoreUtilities::$rRequest["range"], 0, 10);
        $rEndTime = substr(CoreUtilities::$rRequest["range"], strlen(CoreUtilities::$rRequest["range"]) - 10, 10);
        if (!($rStartTime = strtotime($rStartTime . " 00:00:00"))) {
            $rStartTime = NULL;
        }
        if (!($rEndTime = strtotime($rEndTime . " 23:59:59"))) {
            $rEndTime = NULL;
        }
        if ($rStartTime && $rEndTime) {
            $rWhere[] = "(`lines_activity`.`date_start` >= ? AND `lines_activity`.`date_end` <= ?)";
            $rWhereV[] = $rStartTime;
            $rWhereV[] = $rEndTime;
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["stream"])) {
        $rWhere[] = "`lines_activity`.`stream_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["stream"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["user"])) {
        $rWhere[] = "`lines_activity`.`user_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["user"];
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "(`lines_activity`.`server_id` = ? OR `lines_activity`.`proxy_id` = ?)";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `lines_activity` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `lines`.`username`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_activity`.`activity_id`, `lines_activity`.`hmac_identifier`, `lines_activity`.`hmac_id`, `lines_activity`.`proxy_id`, `lines_activity`.`container`, `lines_activity`.`isp`, `lines_activity`.`user_id`, `lines_activity`.`stream_id`, `streams`.`series_no`, `lines_activity`.`server_id`, `lines_activity`.`user_agent`, `lines_activity`.`user_ip`, `lines_activity`.`container`, `lines_activity`.`date_start`, `lines_activity`.`date_end`, `lines_activity`.`geoip_country_code`, `streams`.`stream_display_name`, `streams`.`type`, (SELECT `server_name` FROM `servers` WHERE `id` = `lines_activity`.`server_id`) AS `server_name`, `lines`.`is_restreamer` FROM `lines_activity` LEFT JOIN `lines` ON `lines_activity`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_activity`.`stream_id` = `streams`.`id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rDeviceInfo = $rMagIDs = $rEnigmaIDs = [];
            foreach ($rRows as $rRow) {
                if ($rRow["is_mag"]) {
                    $rMagIDs[] = (int) $rRow["user_id"];
                }
                if ($rRow["is_e2"]) {
                    $rEnigmaIDs[] = (int) $rRow["user_id"];
                }
                if ($rRow["is_mag"] || $rRow["is_e2"]) {
                    $rDeviceInfo[(int) $rRow["user_id"]] = ["device_id" => NULL, "device_name" => NULL];
                }
            }
            if (0 < count($rMagIDs)) {
                $db->query("SELECT `user_id`, `mag_id`, `mac` FROM `mag_devices` WHERE `user_id` IN (" . implode(",", $rMagIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rDeviceInfo[(int) $rRow["user_id"]]["device_id"] = $rRow["mag_id"];
                    $rDeviceInfo[(int) $rRow["user_id"]]["device_name"] = $rRow["mac"];
                }
            }
            if (0 < count($rEnigmaIDs)) {
                $db->query("SELECT `user_id`, `device_id`, `mac` FROM `enigma2_devices` WHERE `user_id` IN (" . implode(",", $rEnigmaIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rDeviceInfo[(int) $rRow["user_id"]]["device_id"] = $rRow["device_id"];
                    $rDeviceInfo[(int) $rRow["user_id"]]["device_name"] = $rRow["mac"];
                }
            }
            foreach ($rRows as $rRow) {
                if (isset($rDeviceInfo[$rRow["user_id"]])) {
                    $rRow = array_merge($rRow, $rDeviceInfo[$rRow["user_id"]]);
                }
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if ($rRow["hmac_id"]) {
                        if (hasPermissions("adv", "add_hmac")) {
                            $rUsername = "<a href='hmac?id=" . $rRow["hmac_id"] . "'>HMAC - " . $rRow["hmac_identifier"] . "</a>";
                        } else {
                            $rUsername = "HMAC - " . $rRow["hmac_identifier"];
                        }
                    } elseif ($rRow["is_mag"]) {
                        if (hasPermissions("adv", "edit_mag")) {
                            $rUsername = "<a href='mag?id=" . $rRow["device_id"] . "'>" . $rRow["username"] . "<br/><strong>MAC: </strong> <span class='text-secondary'>" . $rRow["device_name"] . "</span></a>";
                        } else {
                            $rUsername = $rRow["username"];
                        }
                    } elseif ($rRow["is_e2"]) {
                        if (hasPermissions("adv", "edit_e2")) {
                            $rUsername = "<a href='enigma?id=" . $rRow["device_id"] . "'>" . $rRow["username"] . "<br/>" . $rRow["device_name"] . "</a>";
                        } else {
                            $rUsername = $rRow["username"];
                        }
                    } elseif (hasPermissions("adv", "users")) {
                        $rUsername = "<a href='line?id=" . $rRow["user_id"] . "'>" . $rRow["username"] . "</a>";
                    } else {
                        $rUsername = $rRow["username"];
                    }
                    $rPermission = ["1" => "streams", "2" => "movies", "3" => "streams", "4" => "radio", "5" => "series"];
                    $rURLs = ["1" => "stream_view", "2" => "stream_view", "3" => "stream_view", "4" => "stream_view"];
                    if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                        if ($rRow["type"] == 5) {
                            $rChannel = "<a href='serie?id=" . $rRow["series_no"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        } else {
                            $rChannel = "<a href='" . $rURLs[$rRow["type"]] . "?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        }
                    } else {
                        $rChannel = $rRow["stream_display_name"];
                    }
                    if (hasPermissions("adv", "servers")) {
                        $rServer = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                    } else {
                        $rServer = $rRow["server_name"];
                    }
                    if (0 < $rRow["proxy_id"] && isset($rProxyServers[$rRow["proxy_id"]])) {
                        $rServer .= "<br/><small>(via " . $rProxyServers[$rRow["proxy_id"]]["server_name"] . ")</small>";
                    }
                    if (0 < strlen($rRow["geoip_country_code"])) {
                        $rGeoCountry = "<img loading='lazy' src='assets/images/countries/" . strtolower($rRow["geoip_country_code"]) . ".png'></img> &nbsp;";
                    } else {
                        $rGeoCountry = "";
                    }
                    if ($rRow["user_ip"]) {
                        $rExplode = explode(":", $rRow["user_ip"]);
                        $rIP = $rGeoCountry . "<a onClick=\"whois('" . $rRow["user_ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["user_ip"]) . "</a>";
                    } else {
                        $rIP = "";
                    }
                    if ($rRow["date_start"]) {
                        $rStart = date($rSettings["datetime_format"], $rRow["date_start"]);
                    } else {
                        $rStart = "";
                    }
                    if ($rRow["date_end"]) {
                        $rStop = date($rSettings["datetime_format"], $rRow["date_end"]);
                    } else {
                        $rStop = "";
                    }
                    $rPlayer = trim(explode("(", $rRow["user_agent"])[0]);
                    $rDuration = $rRow["date_end"] - $rRow["date_start"];
                    $rColour = "success";
                    if (86400 <= $rDuration) {
                        $rDuration = sprintf("%02dd %02dh", $rDuration / 86400, $rDuration / 3600 % 24);
                        $rColour = "danger";
                    } elseif (3600 <= $rDuration) {
                        if (14400 < $rDuration) {
                            $rColour = "warning";
                        } elseif (43200 < $rDuration) {
                            $rColour = "danger";
                        }
                        $rDuration = sprintf("%02dh %02dm", $rDuration / 3600, $rDuration / 60 % 60);
                    } else {
                        $rDuration = sprintf("%02dm %02ds", $rDuration / 60 % 60, $rDuration % 60);
                    }
                    if ($rRow["is_restreamer"]) {
                        $rColour = "success";
                    }
                    $rDuration = "<button type='button' class='btn btn-" . $rColour . " btn-xs waves-effect waves-light btn-fixed'>" . $rDuration . "</button>";
                    if ($rRow["is_restreamer"] == 1) {
                        $rRestreamer = "<i class=\"text-info fas fa-square\"></i>";
                    } else {
                        $rRestreamer = "<i class=\"text-secondary fas fa-square\"></i>";
                    }
                    $rReturn["data"][] = [$rUsername, $rChannel, $rServer, $rPlayer, $rRow["isp"], $rIP, $rStart, $rStop, $rDuration, strtoupper($rRow["container"]), $rRestreamer];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "live_connections") {
    if (!hasPermissions("adv", "live_connections")) {
        exit;
    }
    $rRows = [];
    if (CoreUtilities::$rSettings["redis_handler"]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? false : true;
        $rFilterBefore = true;
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rStart = 0;
            $rLimit = 1000;
            $rKeys = explode(",", CoreUtilities::$rRequest["refresh"]);
        } else {
            $rServerID = 0 < (int) CoreUtilities::$rRequest["server_id"] ? (int) CoreUtilities::$rRequest["server_id"] : NULL;
            $rStreamID = 0 < (int) CoreUtilities::$rRequest["stream_id"] ? (int) CoreUtilities::$rRequest["stream_id"] : NULL;
            $rUserID = 0 < (int) CoreUtilities::$rRequest["user_id"] ? (int) CoreUtilities::$rRequest["user_id"] : NULL;
            if ($rUserID) {
                if ($rServerID || $rStreamID) {
                    $rKeys = CoreUtilities::$redis->zRevRangeByScore("LINE#" . $rUserID, "+inf", "-inf");
                    $rFilterBefore = false;
                } else {
                    if ($rOrderDirection) {
                        $rKeys = CoreUtilities::$redis->zRangeByScore("LINE#" . $rUserID, "-inf", "+inf", ["limit" => [$rStart, $rLimit]]);
                    } else {
                        $rKeys = CoreUtilities::$redis->zRevRangeByScore("LINE#" . $rUserID, "+inf", "-inf", ["limit" => [$rStart, $rLimit]]);
                    }
                    $rKeyCount = CoreUtilities::$redis->zCard("LINE#" . $rUserID);
                }
            } elseif ($rStreamID) {
                if ($rUserID || $rServerID) {
                    $rKeys = CoreUtilities::$redis->zRevRangeByScore("STREAM#" . $rStreamID, "+inf", "-inf");
                    $rFilterBefore = false;
                } else {
                    if ($rOrderDirection) {
                        $rKeys = CoreUtilities::$redis->zRangeByScore("STREAM#" . $rStreamID, "-inf", "+inf", ["limit" => [$rStart, $rLimit]]);
                    } else {
                        $rKeys = CoreUtilities::$redis->zRevRangeByScore("STREAM#" . $rStreamID, "+inf", "-inf", ["limit" => [$rStart, $rLimit]]);
                    }
                    $rKeyCount = CoreUtilities::$redis->zCard("STREAM#" . $rStreamID);
                }
            } elseif ($rServerID) {
                if ($rUserID || $rStreamID) {
                    $rKeys = CoreUtilities::$redis->zRevRangeByScore("SERVER#" . $rServerID, "+inf", "-inf");
                    $rFilterBefore = false;
                } else {
                    if ($rOrderDirection) {
                        $rKeys = CoreUtilities::$redis->zRangeByScore("SERVER#" . $rServerID, "-inf", "+inf", ["limit" => [$rStart, $rLimit]]);
                    } else {
                        $rKeys = CoreUtilities::$redis->zRevRangeByScore("SERVER#" . $rServerID, "+inf", "-inf", ["limit" => [$rStart, $rLimit]]);
                    }
                    $rKeyCount = CoreUtilities::$redis->zCard("SERVER#" . $rServerID);
                }
            } else {
                if ($rOrderDirection) {
                    $rKeys = CoreUtilities::$redis->zRangeByScore("LIVE", "-inf", "+inf", ["limit" => [$rStart, $rLimit]]);
                } else {
                    $rKeys = CoreUtilities::$redis->zRevRangeByScore("LIVE", "+inf", "-inf", ["limit" => [$rStart, $rLimit]]);
                }
                $rKeyCount = CoreUtilities::$redis->zCard("LIVE");
            }
        }
        if ($rOrderDirection && !$rFilterBefore) {
            $rKeys = array_reverse($rKeys);
        }
        if (!$rFilterBefore) {
            $rKeyCount = count($rKeys);
        }
        foreach (CoreUtilities::$redis->mGet($rKeys) as $rRow) {
            $rRow = igbinary_unserialize($rRow);
            if (!is_array($rRow)) {
                $rKeyCount--;
            } else {
                if (!$rFilterBefore) {
                    if ($rServerID && $rServerID != $rRow["server_id"]) {
                        $rKeyCount--;
                    } elseif ($rStreamID && $rStreamID != $rRow["stream_id"]) {
                        $rKeyCount--;
                    } elseif ($rUserID && $rUserID != $rRow["user_id"]) {
                        $rKeyCount--;
                    }
                }
                $rRow["activity_id"] = $rRow["uuid"];
                $rRow["identifier"] = $rRow["user_id"] ?: $rRow["hmac_id"] . "_" . $rRow["hmac_identifier"];
                $rRow["active_time"] = time() - $rRow["date_start"];
                $rRow["server_name"] = CoreUtilities::$rServers[$rRow["server_id"]]["server_name"] ?: "";
                $rRows[] = $rRow;
            }
        }
        if (!$rFilterBefore) {
            $rRows = array_slice($rRows, $rStart, $rLimit);
        }
        $rUUIDs = $rStreamIDs = $rUserIDs = [];
        foreach ($rRows as $rRow) {
            if ($rRow["stream_id"]) {
                $rStreamIDs[] = (int) $rRow["stream_id"];
            }
            if ($rRow["user_id"]) {
                $rUserIDs[] = (int) $rRow["user_id"];
            }
            if ($rRow["uuid"]) {
                $rUUIDs[] = $rRow["uuid"];
            }
        }
        $rStreamNames = $rDivergenceMap = $rSeriesMap = $rUserMap = [];
        if (0 < count($rUserIDs)) {
            $db->query("SELECT `lines`.`id`, `lines`.`is_mag`, `lines`.`is_e2`, `lines`.`is_restreamer`, `lines`.`username`, `mag_devices`.`mag_id`,`mag_devices`.`mac`, `enigma2_devices`.`device_id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`id` IN (" . implode(",", $rUserIDs) . ");");
            foreach ($db->get_rows() as $rRow) {
                $rUserID = $rRow["id"];
                unset($rRow["id"]);
                $rUserMap[$rUserID] = $rRow;
            }
        }
        if (0 < count($rStreamIDs)) {
            $db->query("SELECT `stream_id`, `series_id` FROM `streams_episodes` WHERE `stream_id` IN (" . implode(",", $rStreamIDs) . ");");
            foreach ($db->get_rows() as $rRow) {
                $rSeriesMap[$rRow["stream_id"]] = $rRow["series_id"];
            }
            $db->query("SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (" . implode(",", $rStreamIDs) . ");");
            foreach ($db->get_rows() as $rRow) {
                $rStreamNames[$rRow["id"]] = [$rRow["stream_display_name"], $rRow["type"]];
            }
        }
        if (0 < count($rUUIDs)) {
            $db->query("SELECT `uuid`, `divergence` FROM `lines_divergence` WHERE `uuid` IN ('" . implode("','", $rUUIDs) . "');");
            foreach ($db->get_rows() as $rRow) {
                $rDivergenceMap[$rRow["uuid"]] = $rRow["divergence"];
            }
        }
        for ($i = 0; $i < count($rRows); $i++) {
            $rRows[$i]["divergence"] = $rDivergenceMap[$rRows[$i]["uuid"]] ?: 0;
            $rRows[$i]["series_no"] = $rSeriesMap[$rRows[$i]["stream_id"]] ?: NULL;
            $rRows[$i]["stream_display_name"] = $rStreamNames[$rRows[$i]["stream_id"]][0] ?: "";
            $rRows[$i]["type"] = $rStreamNames[$rRows[$i]["stream_id"]][1] ?: 1;
            $rRows[$i] = array_merge($rRows[$i], $rUserMap[$rRows[$i]["user_id"]] ?: []);
        }
        $rReturn["recordsTotal"] = $rKeyCount;
        $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    } else {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrder = ["`lines_live`.`activity_id`", "`lines_live`.`divergence`", "`username` " . $rOrderDirection . ", `lines_live`.`hmac_identifier`", "`streams`.`stream_display_name`", "`server_name`", "`lines_live`.`user_agent`", "`lines_live`.`isp`", "`lines_live`.`user_ip`", "UNIX_TIMESTAMP() - `lines_live`.`date_start`", "`lines_live`.`container`", "`lines`.`is_restreamer`", false];
        if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
            $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
        } else {
            $rOrderRow = 0;
        }
        $rWhere = $rWhereV = [];
        $rWhere[] = "`hls_end` = 0";
        if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
            foreach (range(1, 10) as $rInt) {
                $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
            }
            $rWhere[] = "(`lines_live`.`hmac_identifier` LIKE ? OR `lines_live`.`user_agent` LIKE ? OR `lines_live`.`user_ip` LIKE ? OR `lines_live`.`container` LIKE ? OR FROM_UNIXTIME(`lines_live`.`date_start`) LIKE ? OR `lines_live`.`geoip_country_code` LIKE ? OR `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
        }
        if (0 < (int) CoreUtilities::$rRequest["server_id"]) {
            $rWhere[] = "(`lines_live`.`server_id` = ? OR `lines_live`.`proxy_id` = ?)";
            $rWhereV[] = CoreUtilities::$rRequest["server_id"];
            $rWhereV[] = CoreUtilities::$rRequest["server_id"];
        }
        if (0 < (int) CoreUtilities::$rRequest["stream_id"]) {
            $rWhere[] = "`lines_live`.`stream_id` = ?";
            $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
        }
        if (0 < (int) CoreUtilities::$rRequest["user_id"]) {
            $rWhere[] = "`lines_live`.`user_id` = ?";
            $rWhereV[] = CoreUtilities::$rRequest["user_id"];
        }
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rWhere = ["`lines_live`.`activity_id` IN (" . implode(",", array_map("intval", explode(",", CoreUtilities::$rRequest["refresh"]))) . ") AND `hls_end` = 0"];
            $rStart = 0;
            $rLimit = 1000;
        }
        if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
            if (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 AND `lines`.`is_restreamer` = 0 AND `lines`.`is_stalker` = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "`lines`.`is_mag` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "`lines`.`is_e2` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "`lines`.`is_trial` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                $rWhere[] = "`lines`.`is_restreamer` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                $rWhere[] = "`lines`.`is_stalker` = 1";
            }
        }
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
        if ($rOrder[$rOrderRow]) {
            $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
        }
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` " . $rWhereString . ";";
        $db->query($rCountQuery, ...$rWhereV);
        if ($db->num_rows() == 1) {
            $rReturn["recordsTotal"] = $db->get_row()["count"];
        } else {
            $rReturn["recordsTotal"] = 0;
        }
        $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
        if (0 < $rReturn["recordsTotal"]) {
            $rQuery = "SELECT `mag_devices`.`mag_id`, `mag_devices`.`mac`,`enigma2_devices`.`device_id`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_live`.`activity_id`, `lines_live`.`hmac_id`, `lines_live`.`hmac_identifier`, `lines_live`.`proxy_id`, `lines_live`.`divergence`, `lines_live`.`user_id`, `lines_live`.`stream_id`, `streams`.`series_no`, `lines`.`is_restreamer`, `lines_live`.`isp`, `lines_live`.`server_id`, `lines_live`.`user_agent`, `lines_live`.`user_ip`, `lines_live`.`container`, `lines_live`.`pid`, `lines_live`.`uuid`, `lines_live`.`date_start`, `lines_live`.`geoip_country_code`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username`, `streams`.`stream_display_name`, `streams`.`type`, (SELECT `server_name` FROM `servers` WHERE `id` = `lines_live`.`server_id`) AS `server_name` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
            $db->query($rQuery, ...$rWhereV);
            if (0 < $db->num_rows()) {
                $rRows = $db->get_rows();
            }
        }
    }
    if (0 < count($rRows)) {
        foreach ($rRows as $rRow) {
            if ($rIsAPI) {
                $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
            } else {
                if ($rRow["divergence"] <= 50) {
                    $rDivergence = "<i class=\"text-success fas fa-square tooltip\" title=\"" . (int) (100 - $rRow["divergence"]) . "%\"></i>";
                } elseif ($rRow["divergence"] <= 80) {
                    $rDivergence = "<i class=\"text-warning fas fa-square tooltip\" title=\"" . (int) (100 - $rRow["divergence"]) . "%\"></i>";
                } else {
                    $rDivergence = "<i class=\"text-danger fas fa-square tooltip\" title=\"" . (int) (100 - $rRow["divergence"]) . "%\"></i>";
                }
                if ($rRow["hmac_id"]) {
                    if (hasPermissions("adv", "add_hmac")) {
                        $rUsername = "<a href='hmac?id=" . $rRow["hmac_id"] . "'>HMAC - " . $rRow["hmac_identifier"] . "</a>";
                    } else {
                        $rUsername = "HMAC - " . $rRow["hmac_identifier"];
                    }
                } elseif ($rRow["is_mag"]) {
                    if (hasPermissions("adv", "edit_mag")) {
                        $rUsername = "<a href='mag?id=" . $rRow["mag_id"] . "'>" . $rRow["mac"] . "</a>";
                    } else {
                        $rUsername = $rRow["username"];
                    }
                } elseif ($rRow["is_e2"]) {
                    if (hasPermissions("adv", "edit_e2")) {
                        $rUsername = "<a href='enigma?id=" . $rRow["device_id"] . "'>" . $rRow["username"] . "</a>";
                    } else {
                        $rUsername = $rRow["username"];
                    }
                } elseif (hasPermissions("adv", "users")) {
                    $rUsername = "<a href='line?id=" . $rRow["user_id"] . "'>" . $rRow["username"] . "</a>";
                } else {
                    $rUsername = $rRow["username"];
                }
                $rPermission = ["1" => "streams", "2" => "movies", "3" => "streams", "4" => "radio", "5" => "series"];
                $rURLs = ["1" => "stream_view", "2" => "stream_view", "3" => "stream_view", "4" => "stream_view"];
                if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                    if ($rRow["type"] == 5) {
                        $rChannel = "<a href='serie?id=" . $rRow["series_no"] . "'>" . $rRow["stream_display_name"] . "</a>";
                    } else {
                        $rChannel = "<a href='" . $rURLs[$rRow["type"]] . "?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                    }
                } else {
                    $rChannel = $rRow["stream_display_name"];
                }
                if (hasPermissions("adv", "servers")) {
                    $rServer = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                } else {
                    $rServer = $rRow["server_name"];
                }
                if (0 < $rRow["proxy_id"] && isset($rProxyServers[$rRow["proxy_id"]])) {
                    $rServer .= "<br/><small>(via " . $rProxyServers[$rRow["proxy_id"]]["server_name"] . ")</small>";
                }
                if (0 < strlen($rRow["geoip_country_code"])) {
                    $rGeoCountry = "<img loading='lazy' src='assets/images/countries/" . strtolower($rRow["geoip_country_code"]) . ".png'></img> &nbsp;";
                } else {
                    $rGeoCountry = "";
                }
                if ($rRow["user_ip"]) {
                    $rExplode = explode(":", $rRow["user_ip"]);
                    $rIP = $rGeoCountry . "<a onClick=\"whois('" . $rRow["user_ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["user_ip"]) . "</a>";
                } else {
                    $rIP = "";
                }
                $rPlayer = trim(explode("(", $rRow["user_agent"])[0]);
                $rDuration = (int) time() - (int) $rRow["date_start"];
                $rColour = "success";
                if ($rRow["hls_end"]) {
                    $rDuration = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed'>CLOSED</button>";
                } else {
                    if (86400 <= $rDuration) {
                        $rDuration = sprintf("%02dd %02dh", $rDuration / 86400, $rDuration / 3600 % 24);
                        $rColour = "danger";
                    } elseif (3600 <= $rDuration) {
                        if (14400 < $rDuration) {
                            $rColour = "warning";
                        } elseif (43200 < $rDuration) {
                            $rColour = "danger";
                        }
                        $rDuration = sprintf("%02dh %02dm", $rDuration / 3600, $rDuration / 60 % 60);
                    } else {
                        $rDuration = sprintf("%02dm %02ds", $rDuration / 60 % 60, $rDuration % 60);
                    }
                    if ($rRow["is_restreamer"]) {
                        $rColour = "success";
                    }
                    $rDuration = "<button type='button' class='btn btn-" . $rColour . " btn-xs waves-effect waves-light btn-fixed'>" . $rDuration . "</button>";
                }
                if ($rRow["is_restreamer"] == 1) {
                    $rRestreamer = "<i class=\"text-info fas fa-square\"></i>";
                } else {
                    $rRestreamer = "<i class=\"text-secondary fas fa-square\"></i>";
                }
                $rButtons = "<div class=\"btn-group\">";
                if (isset(CoreUtilities::$rRequest["fingerprint"])) {
                    $rButtons .= "<button title=\"Kill Connection\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["uuid"] . "', 'kill', '" . $rRow["activity_id"] . "');\"><i class=\"fas fa-hammer\"></i></button>";
                } else {
                    $rButtons .= "<button title=\"Kill Connection\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["uuid"] . "', 'kill');\"><i class=\"fas fa-hammer\"></i></button>";
                    if (hasPermissions("adv", "fingerprint") && 0 < (int) $rRow["user_id"] && $rRow["type"] == 1) {
                        $rButtons .= "<button title=\"Fingerprint\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"modalFingerprint(" . $rRow["user_id"] . ", 'user');\"><i class=\"mdi mdi-fingerprint\"></i></button>";
                    }
                }
                $rButtons .= "</div>";
                $rReturn["data"][] = [$rRow["activity_id"], $rDivergence, $rUsername, $rChannel, $rServer, $rPlayer, $rRow["isp"], $rIP, $rDuration, strtoupper($rRow["container"]), $rRestreamer, $rButtons];
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "stream_list") {
    if (!hasPermissions("adv", "import_streams") && !hasPermissions("adv", "mass_delete")) {
        exit;
    }
    $rCategories = getCategories("live");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_icon`", "`streams`.`stream_display_name`", "`streams`.`category_id`", "`streams_servers`.`server_id`", "`streams_servers`.`stream_status`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (isset(CoreUtilities::$rRequest["include_channels"])) {
        $rWhere[] = "`streams`.`type` IN (1,3)";
    } elseif (isset(CoreUtilities::$rRequest["only_channels"])) {
        $rWhere[] = "`streams`.`type` = 3";
    } else {
        $rWhere[] = "`streams`.`type` = 1";
    }
    if (0 < (int) CoreUtilities::$rRequest["category"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category"];
    } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
        $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_servers`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
        $rWhere[] = "`streams_servers`.`server_id` IS NULL";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (!isset(CoreUtilities::$rRequest["only_channels"])) {
            if (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`stream_status` = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "((`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 1))";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 2)";
            } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                $rWhere[] = "`streams_servers`.`on_demand` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                $rWhere[] = "`streams`.`direct_source` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 7) {
                $rWhere[] = "`streams`.`tv_archive_server_id` > 0 AND `streams`.`tv_archive_duration` > 0";
            } elseif (CoreUtilities::$rRequest["filter"] == 8) {
                if ($rSettings["streams_grouped"] == 1) {
                    $rWhere[] = "(SELECT COUNT(*) AS `count` FROM `streams_logs` WHERE `streams_logs`.`action` = 'STREAM_FAILED' AND `streams_logs`.`date` >= UNIX_TIMESTAMP()-86400 AND `streams_logs`.`stream_id` = `streams`.`id`) > 144";
                } else {
                    $rWhere[] = "(SELECT COUNT(*) AS `count` FROM `streams_logs` WHERE `streams_logs`.`action` = 'STREAM_FAILED' AND `streams_logs`.`date` >= UNIX_TIMESTAMP()-86400 AND `streams_logs`.`stream_id` = `streams`.`id` AND `streams_logs`.`server_id` = `streams_servers`.`server_id`) > 144";
                }
            } elseif (CoreUtilities::$rRequest["filter"] == 9) {
                $rWhere[] = "LENGTH(`streams`.`channel_id`) > 0";
            } elseif (CoreUtilities::$rRequest["filter"] == 10) {
                $rWhere[] = "(`streams`.`channel_id` IS NULL OR LENGTH(`streams`.`channel_id`) = 0)";
            } elseif (CoreUtilities::$rRequest["filter"] == 11) {
                $rWhere[] = "`streams`.`adaptive_link` IS NOT NULL";
            } elseif (CoreUtilities::$rRequest["filter"] == 12) {
                $rWhere[] = "`streams`.`title_sync` IS NOT NULL";
            } elseif (CoreUtilities::$rRequest["filter"] == 13) {
                $rWhere[] = "`streams`.`transcode_profile_id` > 0";
            }
        } elseif (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0)";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "(`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND (REPLACE(`streams_servers`.`cchannel_rsources`, '\\\\/', '/') = REPLACE(`streams`.`stream_source`, '\\\\/', '/'))";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "(REPLACE(`streams_servers`.`cchannel_rsources`, '\\\\/', '/') <> REPLACE(`streams`.`stream_source`, '\\\\/', '/'))";
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM (SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id`) t1;";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams_servers`.`stream_id`, `streams`.`type`, `streams`.`stream_icon`, `streams`.`adaptive_link`, `streams`.`title_sync`, `streams_servers`.`cchannel_rsources`, `streams`.`stream_source`, `streams`.`stream_display_name`, `streams`.`tv_archive_duration`, `streams`.`tv_archive_server_id`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`cc_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams`.`epg_id`, `streams`.`channel_id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . ", -`stream_started` DESC LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
            }
            foreach ($rRows as $rRow) {
                if ($rIsAPI) {
                    unset($rRow["stream_source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rStreamName = $rRow["stream_display_name"];
                    if ($rRow["server_name"]) {
                        $rServerName = $rRow["server_name"];
                        if (1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    $rUptime = 0;
                    $rActualStatus = 0;
                    if (0 < (int) $rRow["stream_started"]) {
                        $rUptime = time() - (int) $rRow["stream_started"];
                    }
                    if ($rRow["server_id"]) {
                        if ((int) $rRow["direct_source"] == 1) {
                            if ((int) $rRow["direct_proxy"] == 1) {
                                $rActualStatus = 7;
                            } else {
                                $rActualStatus = 5;
                            }
                        } elseif ($rRow["monitor_pid"]) {
                            if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                if ((int) $rRow["stream_status"] == 2) {
                                    $rActualStatus = 2;
                                } else {
                                    $rActualStatus = 1;
                                }
                            } else {
                                $rActualStatus = 3;
                            }
                        } elseif ((int) $rRow["on_demand"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 0;
                        }
                    } else {
                        $rActualStatus = -1;
                    }
                    if (0 < strlen($rRow["stream_icon"])) {
                        $rIcon = "<img loading='lazy' src='resize?maxw=96&maxh=32&url=" . urlencode($rRow["stream_icon"]) . "' />";
                    } else {
                        $rIcon = "";
                    }
                    $rReturn["data"][] = [$rRow["id"], $rIcon, $rStreamName, $rCategory, $rServerName, $rStatusArray[$rActualStatus]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "movie_list") {
    if (!hasPermissions("adv", "import_movies") && !hasPermissions("adv", "mass_delete")) {
        exit;
    }
    $rCategories = getCategories("movie");
    $rOrder = ["`streams`.`id`", false, "`streams`.`stream_display_name`", "`streams`.`category_id`", "`streams_servers`.`server_id`", "`streams_servers`.`stream_status`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 2";
    if (0 < (int) CoreUtilities::$rRequest["category"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category"];
    } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
        $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_servers`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
        $rWhere[] = "`streams_servers`.`server_id` IS NULL";
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 1 AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` = 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 1)";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`streams`.`direct_source` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 6) {
            $rWhere[] = "(`streams`.`movie_properties` IS NULL OR `streams`.`movie_properties` = '' OR `streams`.`movie_properties` = '[]' OR `streams`.`movie_properties` = '{}' OR `streams`.`movie_properties` LIKE '%tmdb_id\":\"\"%')";
        } elseif (CoreUtilities::$rRequest["filter"] == 7) {
            $rWhere[] = "`streams`.`id` IN (SELECT MIN(`id`) FROM `streams` WHERE `type` = 2 GROUP BY `stream_source` HAVING COUNT(`stream_source`) > 1)";
            $rDuplicates = true;
        } elseif (CoreUtilities::$rRequest["filter"] == 8) {
            $rWhere[] = "`streams`.`transcode_profile_id` > 0";
        }
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams`.`movie_properties`, `streams`.`year`, `streams_servers`.`to_analyze`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
            }
            foreach ($rRows as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rActualStatus = 0;
                    if ((int) $rRow["direct_source"] == 1) {
                        if ((int) $rRow["direct_proxy"] == 1) {
                            $rActualStatus = 5;
                        } else {
                            $rActualStatus = 3;
                        }
                    } elseif (!is_null($rRow["pid"]) && 0 < $rRow["pid"]) {
                        if ($rRow["to_analyze"] == 1) {
                            $rActualStatus = 2;
                        } elseif ($rRow["stream_status"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 1;
                        }
                    } else {
                        $rActualStatus = 0;
                    }
                    if ($rRow["server_name"]) {
                        $rServerName = $rRow["server_name"];
                        if (1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rProperties = json_decode($rRow["movie_properties"], true);
                    $rRatingText = "";
                    if ($rProperties["rating"]) {
                        $rStarRating = round($rProperties["rating"]) / 2;
                        $rFullStars = floor($rStarRating);
                        $rHalfStar = 0 < $rStarRating - $rFullStars;
                        $rEmpty = 5 - ($rFullStars + ($rHalfStar ? 1 : 0));
                        if (0 < $rFullStars) {
                            foreach (range(1, $rFullStars) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star'></i>";
                            }
                        }
                        if ($rHalfStar) {
                            $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                        }
                        if (0 < $rEmpty) {
                            foreach (range(1, $rEmpty) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                            }
                        }
                    }
                    $rYear = $rRow["year"] ? "<strong>" . $rRow["year"] . "</strong> &nbsp;" : "";
                    $rStreamName = $rRow["stream_display_name"] . "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . "</span>";
                    if (0 < strlen($rProperties["movie_image"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rImage = "<a href='javascript: void(0);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties["movie_image"] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rProperties["movie_image"] . "' /></a>";
                    } else {
                        $rImage = "";
                    }
                    if (isset($rProperties["kinopoisk_url"]) && 0 < strlen($rProperties["kinopoisk_url"])) {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-success btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-check-circle\"></i></button>";
                    } else {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-minus-circle\"></i></button>";
                    }
                    $rReturn["data"][] = [$rRow["id"], $rImage, $rStreamName, $rCategory, $rServerName, $rVODStatusArray[$rActualStatus], $rTMDB];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "radio_list") {
    if (!hasPermissions("adv", "mass_delete")) {
        exit;
    }
    $rCategories = getCategories("radio");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_icon`", "`streams`.`stream_display_name`", "`streams`.`category_id`", "`streams_servers`.`server_id`", "`streams_servers`.`stream_status`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 4";
    if (0 < (int) CoreUtilities::$rRequest["category"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category"];
    } elseif ((int) CoreUtilities::$rRequest["category"] == -1) {
        $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_servers`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
        $rWhere[] = "`streams_servers`.`server_id` IS NULL";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "(`streams_servers`.`monitor_pid` > 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`stream_status` = 0)";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "((`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 1))";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NULL OR `streams_servers`.`monitor_pid` <= 0) AND `streams_servers`.`on_demand` = 0)";
        } elseif (CoreUtilities::$rRequest["filter"] == 4) {
            $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` = 2)";
        } elseif (CoreUtilities::$rRequest["filter"] == 5) {
            $rWhere[] = "`streams_servers`.`on_demand` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 6) {
            $rWhere[] = "`streams`.`direct_source` = 1";
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_icon`, `streams`.`movie_properties`, `streams_servers`.`to_analyze`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
            }
            foreach ($rRows as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rUptime = 0;
                    $rActualStatus = 0;
                    if (0 < (int) $rRow["stream_started"]) {
                        $rUptime = time() - (int) $rRow["stream_started"];
                    }
                    if ($rRow["server_id"]) {
                        if ((int) $rRow["direct_source"] == 1) {
                            $rActualStatus = 5;
                        } elseif ($rRow["monitor_pid"]) {
                            if ($rRow["pid"] && 0 < $rRow["pid"]) {
                                if ((int) $rRow["stream_status"] == 2) {
                                    $rActualStatus = 2;
                                } else {
                                    $rActualStatus = 1;
                                }
                            } else {
                                $rActualStatus = 3;
                            }
                        } elseif ((int) $rRow["on_demand"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 0;
                        }
                    } else {
                        $rActualStatus = -1;
                    }
                    if ($rRow["server_name"]) {
                        $rServerName = $rRow["server_name"];
                        if (1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    if (0 < strlen($rRow["stream_icon"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow["stream_icon"] . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . $rRow["stream_icon"] . "' /></a>";
                    } else {
                        $rIcon = "";
                    }
                    $rReturn["data"][] = [$rRow["id"], $rIcon, $rRow["stream_display_name"], $rCategory, $rServerName, $rStatusArray[$rActualStatus]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "series_list") {
    if (!hasPermissions("adv", "mass_delete")) {
        exit;
    }
    $rCategories = getCategories("series");
    $rOrder = ["`streams_series`.`id`", "`streams_series`.`cover`", "`streams_series`.`title`", "`streams_series`.`category_id`", "`latest_season`", "`episode_count`", false, "`streams_series`.`release_date`", "`streams_series`.`last_modified`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
        if (CoreUtilities::$rRequest["category"] == -1) {
            $rWhere[] = "(`streams_series`.`tmdb_id` = 0 OR `streams_series`.`tmdb_id` IS NULL)";
        } elseif (CoreUtilities::$rRequest["category"] == -2) {
            $rWhere[] = "(`streams`.`category_id` = '[]' OR `streams`.`category_id` IS NULL)";
        } else {
            $rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
            $rWhereV[] = CoreUtilities::$rRequest["category"];
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams_series`.`id` LIKE ? OR `streams_series`.`title` LIKE ? OR `streams_series`.`release_date` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_series` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams_series`.`id`, `streams_series`.`year`, `streams_series`.`rating`, `streams_series`.`cover`, `streams_series`.`title`, `streams_series`.`category_id`, `streams_series`.`tmdb_id`, `streams_series`.`release_date`, `streams_series`.`last_modified`, (SELECT MAX(`season_num`) FROM `streams_episodes` WHERE `series_id` = `streams_series`.`id`) AS `latest_season`, (SELECT COUNT(*) FROM `streams_episodes` WHERE `series_id` = `streams_series`.`id`) AS `episode_count` FROM `streams_series` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    if (0 < $rRow["latest_season"]) {
                        $rRow["latest_season"] = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["latest_season"] . "</button>";
                    } else {
                        $rRow["latest_season"] = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (0 < $rRow["episode_count"]) {
                        $rRow["episode_count"] = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["episode_count"] . "</button>";
                    } else {
                        $rRow["episode_count"] = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if ($rRow["last_modified"] == 0) {
                        $rRow["last_modified"] = "Never";
                    } else {
                        $rRow["last_modified"] = date($rSettings["datetime_format"], $rRow["last_modified"]);
                    }
                    if ($rRow["release_date"]) {
                        $rRow["release_date"] = date($rSettings["date_format"], strtotime($rRow["release_date"]));
                    }
                    if (0 < $rRow["tmdb_id"]) {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-success btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-check-circle\"></i></button>";
                    } else {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-minus-circle\"></i></button>";
                    }
                    if (0 < strlen($rRow["cover"])) {
                        $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow["cover"] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rRow["cover"] . "' /></a>";
                    } else {
                        $rImage = "";
                    }
                    $rRatingText = "";
                    if ($rRow["rating"]) {
                        $rStarRating = round($rRow["rating"]) / 2;
                        $rFullStars = floor($rStarRating);
                        $rHalfStar = 0 < $rStarRating - $rFullStars;
                        $rEmpty = 5 - ($rFullStars + ($rHalfStar ? 1 : 0));
                        if (0 < $rFullStars) {
                            foreach (range(1, $rFullStars) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star'></i>";
                            }
                        }
                        if ($rHalfStar) {
                            $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                        }
                        if (0 < $rEmpty) {
                            foreach (range(1, $rEmpty) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                            }
                        }
                    }
                    $rYear = $rRow["year"] ? "<strong>" . $rRow["year"] . "</strong> &nbsp;" : "";
                    $rTitle = "<strong>" . $rRow["title"] . "</strong><br><span style='font-size:11px;'>" . $rYear . $rRatingText . "</span></a>";
                    $rReturn["data"][] = [$rRow["id"], $rImage, $rTitle, $rCategory, $rRow["latest_season"], $rRow["episode_count"], $rTMDB, $rRow["release_date"], $rRow["last_modified"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "credits_log") {
    if (!hasPermissions("adv", "credits_log")) {
        exit;
    }
    $rOrder = ["`users_credits_logs`.`id`", "`owner_username`", "`target_username`", "`users_credits_logs`.`amount`", "`users_credits_logs`.`reason`", "`date`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 5) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`target`.`username` LIKE ? OR `owner`.`username` LIKE ? OR FROM_UNIXTIME(`date`) LIKE ? OR `users_credits_logs`.`amount` LIKE ? OR `users_credits_logs`.`reason` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["range"])) {
        $rStartTime = substr(CoreUtilities::$rRequest["range"], 0, 10);
        $rEndTime = substr(CoreUtilities::$rRequest["range"], strlen(CoreUtilities::$rRequest["range"]) - 10, 10);
        if (!($rStartTime = strtotime($rStartTime . " 00:00:00"))) {
            $rStartTime = NULL;
        }
        if (!($rEndTime = strtotime($rEndTime . " 23:59:59"))) {
            $rEndTime = NULL;
        }
        if ($rStartTime && $rEndTime) {
            $rWhere[] = "(`users_credits_logs`.`date` >= ? AND `users_credits_logs`.`date` <= ?)";
            $rWhereV[] = $rStartTime;
            $rWhereV[] = $rEndTime;
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "(`users_credits_logs`.`target_id` = ? OR `users_credits_logs`.`admin_id` = ?)";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `users_credits_logs` LEFT JOIN `users` AS `target` ON `target`.`id` = `users_credits_logs`.`target_id` LEFT JOIN `users` AS `owner` ON `owner`.`id` = `users_credits_logs`.`admin_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `users_credits_logs`.`id`, `users_credits_logs`.`target_id`, `users_credits_logs`.`admin_id`, `target`.`username` AS `target_username`, `owner`.`username` AS `owner_username`, `amount`, FROM_UNIXTIME(`date`) AS `date`, `users_credits_logs`.`reason` FROM `users_credits_logs` LEFT JOIN `users` AS `target` ON `target`.`id` = `users_credits_logs`.`target_id` LEFT JOIN `users` AS `owner` ON `owner`.`id` = `users_credits_logs`.`admin_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (hasPermissions("adv", "edit_reguser")) {
                        $rOwner = "<a href='user?id=" . $rRow["admin_id"] . "'>" . $rRow["owner_username"] . "</a>";
                        $rTarget = "<a href='user?id=" . $rRow["target_id"] . "'>" . $rRow["target_username"] . "</a>";
                    } else {
                        $rOwner = $rRow["owner_username"];
                        $rTarget = $rRow["target_username"];
                    }
                    $rReturn["data"][] = [$rRow["id"], $rOwner, $rTarget, number_format($rRow["amount"], 0), $rRow["reason"], $rRow["date"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "client_logs") {
    if (!hasPermissions("adv", "client_request_log")) {
        exit;
    }
    $rOrder = ["`lines_logs`.`id`", "`lines`.`username`", "`streams`.`stream_display_name`", "`lines_logs`.`client_status`", "`lines_logs`.`user_agent`", "`lines_logs`.`ip`", "`lines_logs`.`date`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 7) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`lines_logs`.`client_status` LIKE ? OR `lines_logs`.`query_string` LIKE ? OR FROM_UNIXTIME(`date`) LIKE ? OR `lines_logs`.`user_agent` LIKE ? OR `lines_logs`.`ip` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `lines`.`username` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["range"])) {
        $rStartTime = substr(CoreUtilities::$rRequest["range"], 0, 10);
        $rEndTime = substr(CoreUtilities::$rRequest["range"], strlen(CoreUtilities::$rRequest["range"]) - 10, 10);
        if (!($rStartTime = strtotime($rStartTime . " 00:00:00"))) {
            $rStartTime = NULL;
        }
        if (!($rEndTime = strtotime($rEndTime . " 23:59:59"))) {
            $rEndTime = NULL;
        }
        if ($rStartTime && $rEndTime) {
            $rWhere[] = "(`lines_logs`.`date` >= ? AND `lines_logs`.`date` <= ?)";
            $rWhereV[] = $rStartTime;
            $rWhereV[] = $rEndTime;
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        $rWhere[] = "`lines_logs`.`client_status` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["filter"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `lines_logs` LEFT JOIN `streams` ON `streams`.`id` = `lines_logs`.`stream_id` LEFT JOIN `lines` ON `lines`.`id` = `lines_logs`.`user_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `lines_logs`.`id`, `lines_logs`.`user_id`, `lines_logs`.`stream_id`, `streams`.`stream_display_name`, `streams`.`type`, `lines`.`username`, `lines_logs`.`client_status`, `lines_logs`.`query_string`, `lines_logs`.`user_agent`, `lines_logs`.`ip`, FROM_UNIXTIME(`lines_logs`.`date`) AS `date` FROM `lines_logs` LEFT JOIN `streams` ON `streams`.`id` = `lines_logs`.`stream_id` LEFT JOIN `lines` ON `lines`.`id` = `lines_logs`.`user_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (hasPermissions("adv", "edit_user")) {
                        $rUsername = "<a href='line?id=" . $rRow["user_id"] . "'>" . $rRow["username"] . "</a>";
                    } else {
                        $rUsername = $rRow["username"];
                    }
                    $rPermission = ["1" => "streams", "2" => "movies", "3" => "streams", "4" => "radio", "5" => "series"];
                    $rURLs = ["1" => "stream_view", "2" => "stream_view", "3" => "stream_view", "4" => "stream_view"];
                    if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                        if ($rRow["type"] == 5) {
                            $rChannel = "<a href='serie?id=" . $rRow["series_no"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        } else {
                            $rChannel = "<a href='" . $rURLs[$rRow["type"]] . "?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        }
                    } else {
                        $rChannel = $rRow["stream_display_name"];
                    }
                    $rExplode = explode(":", $rRow["ip"]);
                    $rIP = "<a onClick=\"whois('" . $rRow["ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["ip"]) . "</a>";
                    $rReturn["data"][] = [$rRow["id"], $rUsername, $rChannel, $rClientFilters[$rRow["client_status"]], $rRow["user_agent"], $rIP, $rRow["date"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "reg_user_logs") {
    if (!hasPermissions("adv", "reg_userlog")) {
        exit;
    }
    $rOrder = ["`users_logs`.`id`", "`users`.`username`", "`users_logs`.`log_id`", "`users_logs`.`type`, `users_logs`.`action`", "`users_logs`.`cost`", "`users_logs`.`credits_after`", "`users_logs`.`date`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`users`.`username` LIKE ? OR `users_logs`.`deleted_info` LIKE ? OR `users_logs`.`action` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["range"])) {
        $rStartTime = substr(CoreUtilities::$rRequest["range"], 0, 10);
        $rEndTime = substr(CoreUtilities::$rRequest["range"], strlen(CoreUtilities::$rRequest["range"]) - 10, 10);
        if (!($rStartTime = strtotime($rStartTime . " 00:00:00"))) {
            $rStartTime = NULL;
        }
        if (!($rEndTime = strtotime($rEndTime . " 23:59:59"))) {
            $rEndTime = NULL;
        }
        if ($rStartTime && $rEndTime) {
            $rWhere[] = "(`users_logs`.`date` >= ? AND `users_logs`.`date` <= ?)";
            $rWhereV[] = $rStartTime;
            $rWhereV[] = $rEndTime;
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "`users_logs`.`owner` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        $rWhere[] = "`users_logs`.`action` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["filter"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rPackages = getPackages();
        $rQuery = "SELECT `users`.`username`, `users_logs`.`id`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`credits_after`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (hasPermissions("adv", "edit_reguser")) {
                        $rOwner = "<a href='user?id=" . $rRow["owner"] . "'>" . $rRow["username"] . "</a>";
                    } else {
                        $rOwner = $rRow["username"];
                    }
                    $rDevice = ["line" => "User Line", "mag" => "MAG Device", "enigma" => "Enigma2 Device", "user" => "Reseller"][$rRow["type"]];
                    $rText = "";
                    switch ($rRow["action"]) {
                        case "new":
                            if ($rRow["package_id"]) {
                                $rText = "Created New " . $rDevice . " with Package: " . $rPackages[$rRow["package_id"]]["package_name"];
                            } else {
                                $rText = "Created New " . $rDevice;
                            }
                            break;
                        case "extend":
                            if ($rRow["package_id"]) {
                                $rText = "Extended " . $rDevice . " with Package: " . $rPackages[$rRow["package_id"]]["package_name"];
                            } else {
                                $rText = "Extended " . $rDevice;
                            }
                            break;
                        case "convert":
                            $rText = "Converted Device to User Line";
                            break;
                        case "edit":
                            $rText = "Edited " . $rDevice;
                            break;
                        case "enable":
                            $rText = "Enabled " . $rDevice;
                            break;
                        case "disable":
                            $rText = "Disabled " . $rDevice;
                            break;
                        case "delete":
                            $rText = "Deleted " . $rDevice;
                            break;
                        case "send_event":
                            $rText = "Sent Event to " . $rDevice;
                            break;
                        case "adjust_credits":
                            $rText = "Adjusted Credits by " . $rRow["cost"];
                            break;
                        case "connection":
                            $rText = "Additional Connection Added";
                            break;
                        default:
                            $rLineInfo = NULL;
                            switch ($rRow["type"]) {
                                case "line":
                                    $rLine = getUser($rRow["log_id"]);
                                    if ($rLine) {
                                        $rLineInfo = "<a href='line?id=" . $rRow["log_id"] . "'>" . $rLine["username"] . "</a>";
                                    }
                                    break;
                                case "user":
                                    $rLine = getRegisteredUser($rRow["log_id"]);
                                    if ($rLine) {
                                        $rLineInfo = "<a href='user?id=" . $rRow["log_id"] . "'>" . $rLine["username"] . "</a>";
                                    }
                                    break;
                                case "mag":
                                    $rLine = getMag($rRow["log_id"]);
                                    if ($rLine) {
                                        $rLineInfo = "<a href='mag?id=" . $rRow["log_id"] . "'>" . $rLine["mac"] . "</a>";
                                    }
                                    break;
                                case "enigma":
                                    $rLine = getEnigma($rRow["log_id"]);
                                    if ($rLine) {
                                        $rLineInfo = "<a href='enigma?id=" . $rRow["log_id"] . "'>" . $rLine["mac"] . "</a>";
                                    }
                                    break;
                                default:
                                    if (!$rLineInfo) {
                                        $rDeletedInfo = json_decode($rRow["deleted_info"], true);
                                        if (is_array($rDeletedInfo)) {
                                            if (isset($rDeletedInfo["mac"])) {
                                                $rLineInfo = "<span class='text-secondary'>" . $rDeletedInfo["mac"] . "</span>";
                                            } else {
                                                $rLineInfo = "<span class='text-secondary'>" . $rDeletedInfo["username"] . "</span>";
                                            }
                                        } else {
                                            $rLineInfo = "<span class='text-secondary'>DELETED</span>";
                                        }
                                    }
                                    $rReturn["data"][] = [$rRow["id"], $rOwner, $rLineInfo, $rText, number_format($rRow["cost"], 0), number_format($rRow["credits_after"], 0), date($rSettings["datetime_format"], $rRow["date"])];
                            }
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "stream_errors") {
    if (!hasPermissions("adv", "stream_errors")) {
        exit;
    }
    $rOrder = ["`streams_errors`.`id`", "`streams`.`stream_display_name`", "`servers`.`server_name`", "`streams_errors`.`error`", "`streams_errors`.`date`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`stream_display_name` LIKE ? OR `servers`.`server_name` LIKE ? OR FROM_UNIXTIME(`date`) LIKE ? OR `streams_errors`.`error` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["range"])) {
        $rStartTime = substr(CoreUtilities::$rRequest["range"], 0, 10);
        $rEndTime = substr(CoreUtilities::$rRequest["range"], strlen(CoreUtilities::$rRequest["range"]) - 10, 10);
        if (!($rStartTime = strtotime($rStartTime . " 00:00:00"))) {
            $rStartTime = NULL;
        }
        if (!($rEndTime = strtotime($rEndTime . " 23:59:59"))) {
            $rEndTime = NULL;
        }
        if ($rStartTime && $rEndTime) {
            $rWhere[] = "(`streams_errors`.`date` >= ? AND `streams_errors`.`date` <= ?)";
            $rWhereV[] = $rStartTime;
            $rWhereV[] = $rEndTime;
        }
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_errors`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_errors` LEFT JOIN `streams` ON `streams`.`id` = `streams_errors`.`stream_id` LEFT JOIN `servers` ON `servers`.`id` = `streams_errors`.`server_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams_errors`.`id`, `streams_errors`.`stream_id`, `streams`.`type`, `streams_errors`.`server_id`, `streams`.`stream_display_name`, `servers`.`server_name`, `streams_errors`.`error`, FROM_UNIXTIME(`streams_errors`.`date`) AS `date` FROM `streams_errors` LEFT JOIN `streams` ON `streams`.`id` = `streams_errors`.`stream_id` LEFT JOIN `servers` ON `servers`.`id` = `streams_errors`.`server_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rPermission = ["1" => "streams", "2" => "movies", "3" => "streams", "4" => "radio", "5" => "series"];
                    $rURLs = ["1" => "stream_view", "2" => "stream_view", "3" => "stream_view", "4" => "stream_view"];
                    if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                        if ($rRow["type"] == 5) {
                            $rChannel = "<a href='serie?id=" . $rRow["series_no"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        } else {
                            $rChannel = "<a href='" . $rURLs[$rRow["type"]] . "?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        }
                    } else {
                        $rChannel = $rRow["stream_display_name"];
                    }
                    $rReturn["data"][] = [$rRow["id"], $rChannel, $rRow["server_name"], $rRow["error"], $rRow["date"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "stream_unique") {
    if (!hasPermissions("adv", "fingerprint")) {
        exit;
    }
    $rCategories = getCategories("live");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false, "`active_count`", NULL];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 1";
    if (!CoreUtilities::$rSettings["redis_handler"]) {
        $rWhere[] = "(SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `lines_live`.`hls_end` = 0) > 0";
    }
    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`category_id`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `lines_live`.`hls_end` = 0) AS `active_count` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            if (CoreUtilities::$rSettings["redis_handler"]) {
                $rStreamIDs = [];
                foreach ($rRows as $rRow) {
                    $rStreamIDs[] = $rRow["id"];
                }
                if (0 < count($rStreamIDs)) {
                    $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
                }
            }
            foreach ($rRows as $rRow) {
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    $rRow["active_count"] = $rConnectionCount[$rRow["id"]] ?: 0;
                }
                if ($rRow["active_count"] == 0) {
                    $rReturn["recordsTotal"]--;
                } elseif ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rCategory, $rRow["active_count"], "<button type='button' class='btn btn-info waves-effect waves-light btn-xs' href='javascript:void(0);' onClick='selectFingerprint(" . $rRow["id"] . ")'><i class='mdi mdi-fingerprint'></i></button>"];
                }
            }
        }
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "reg_users") {
    if (!hasPermissions("adv", "mng_regusers")) {
        exit;
    }
    $rOrder = ["`users`.`id`", "`users`.`username`", "`users`.`owner_id`", "`users`.`ip`", "`users`.`status`", "`users`.`member_group_id`", "`users`.`credits`", false, false, false, false, "`users`.`last_login`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 7) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`users`.`id` LIKE ? OR `users`.`username` LIKE ? OR `users`.`notes` LIKE ? OR FROM_UNIXTIME(`users`.`date_registered`) LIKE ? OR FROM_UNIXTIME(`users`.`last_login`) LIKE ? OR `users`.`email` LIKE ? OR `users`.`ip` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == -1) {
            $rWhere[] = "`users`.`status` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == -2) {
            $rWhere[] = "`users`.`status` = 0";
        } else {
            $rWhere[] = "`users`.`member_group_id` = ?";
            $rWhereV[] = CoreUtilities::$rRequest["filter"];
        }
    }
    if (0 < strlen(CoreUtilities::$rRequest["reseller"])) {
        $rWhere[] = "`users`.`owner_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["reseller"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `users` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `users`.`member_group_id`, `users`.`id`, `users`.`status`, `users`.`notes`, `users`.`owner_id`, `users`.`credits`, `users`.`username`, `users`.`email`, `users`.`ip`, FROM_UNIXTIME(`users`.`date_registered`) AS `date_registered`, FROM_UNIXTIME(`users`.`last_login`) AS `last_login`, `users`.`status` FROM `users` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rUserInfo = $rOwnerInfo = $rUserIDs = $rOwnerIDs = [];
            $rRows = $db->get_rows();
            foreach ($rRows as $rRow) {
                $rUserIDs[] = $rRow["id"];
                if ($rRow["owner_id"]) {
                    $rOwnerIDs[] = $rRow["owner_id"];
                }
                $rUserInfo[$rRow["id"]] = ["is_reseller" => 0, "user_lines" => 0, "mag_lines" => 0, "e2_lines" => 0, "user_count" => 0, "group_name" => NULL];
            }
            if (0 < count($rUserIDs)) {
                $db->query("SELECT `users`.`id`, `users_groups`.`is_reseller`, `users_groups`.`group_name` FROM `users_groups` LEFT JOIN `users` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `users`.`id` IN (" . implode(",", $rUserIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rUserInfo[$rRow["id"]]["is_reseller"] = $rRow["is_reseller"];
                    $rUserInfo[$rRow["id"]]["group_name"] = $rRow["group_name"];
                }
                $db->query("SELECT `member_id`, COUNT(`id`) AS `user_lines` FROM `lines` WHERE `member_id` IN (" . implode(",", $rUserIDs) . ") AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0 GROUP BY `member_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rUserInfo[$rRow["member_id"]]["user_lines"] = $rRow["user_lines"];
                }
                $db->query("SELECT `member_id`, COUNT(`id`) AS `mag_lines` FROM `lines` WHERE `member_id` IN (" . implode(",", $rUserIDs) . ") AND `lines`.`is_mag` = 1 AND `lines`.`is_e2` = 0 GROUP BY `member_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rUserInfo[$rRow["member_id"]]["mag_lines"] = $rRow["mag_lines"];
                }
                $db->query("SELECT `member_id`, COUNT(`id`) AS `e2_lines` FROM `lines` WHERE `member_id` IN (" . implode(",", $rUserIDs) . ") AND `lines`.`is_mag` = 0 AND `lines`.`is_e2` = 1 GROUP BY `member_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rUserInfo[$rRow["member_id"]]["e2_lines"] = $rRow["e2_lines"];
                }
            }
            if (0 < count($rOwnerIDs)) {
                $db->query("SELECT `id`, `username` FROM `users` WHERE `id` IN (" . implode(",", $rOwnerIDs) . ");");
                foreach ($db->get_rows() as $rRow) {
                    $rOwnerInfo[$rRow["id"]] = $rRow["username"];
                }
            }
            foreach ($rRows as $rRow) {
                if (isset($rOwnerInfo[$rRow["owner_id"]])) {
                    $rRow["owner_username"] = $rOwnerInfo[$rRow["owner_id"]];
                } else {
                    $rRow["owner_username"] = "";
                }
                $rRow = array_merge($rRow, $rUserInfo[$rRow["id"]]);
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if ($rRow["status"] == 1) {
                        $rStatus = "<i class=\"text-success fas fa-square tooltip\" title=\"Active\"></i>";
                    } else {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Disabled\"></i>";
                    }
                    if (!$rRow["last_login"]) {
                        $rRow["last_login"] = "NEVER";
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if (hasPermissions("adv", "edit_reguser")) {
                            if ($rRow["is_reseller"]) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"addCredits(" . $rRow["id"] . ");\">Adjust Credits</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"user?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'user', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["username"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>";
                            if ($rRow["status"] == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'disable');\">Disable</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'enable');\">Enable</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'delete');\">Delete</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        } else {
                            $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        if (hasPermissions("adv", "edit_reguser")) {
                            if ($rRow["is_reseller"]) {
                                $rButtons .= "<button title=\"Adjust Credits\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"addCredits(" . $rRow["id"] . ");\"><i class=\"mdi mdi-coin\"></i></button>";
                            } else {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-coin\"></i></button>";
                            }
                            $rButtons .= "<a href=\"user?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'user', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["username"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>";
                            if ($rRow["status"] == 1) {
                                $rButtons .= "<button title=\"Disable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'disable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Enable\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'enable');\"><i class=\"mdi mdi-lock\"></i></button>";
                            }
                            $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                        }
                        $rButtons .= "</div>";
                    }
                    if (0 < strlen($rRow["ip"])) {
                        $rExplode = explode(":", $rRow["ip"]);
                        $rIP = "<a onClick=\"whois('" . $rRow["ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["ip"]) . "</a>";
                    } else {
                        $rIP = "";
                    }
                    if ($rRow["is_reseller"]) {
                        $rCredits = "<button type=\"button\" class=\"btn btn-purple btn-xs waves-effect waves-light\">" . number_format($rRow["credits"], 0) . "</button>";
                    } else {
                        $rCredits = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light\">-</button>";
                    }
                    if (0 < $rRow["user_count"]) {
                        $rUserCount = "<a href=\"users?owner=" . (int) $rRow["id"] . "\"><button type=\"button\" class=\"btn btn-pink btn-xs waves-effect waves-light\">" . number_format($rRow["user_count"], 0) . "</button></a>";
                    } else {
                        $rUserCount = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light\">0</button>";
                    }
                    if (0 < $rRow["user_lines"]) {
                        $rLineCount = "<a href=\"lines?owner=" . (int) $rRow["id"] . "\"><button type=\"button\" class=\"btn btn-info btn-xs waves-effect waves-light\">" . number_format($rRow["user_lines"], 0) . "</button></a>";
                    } else {
                        $rLineCount = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light\">0</button>";
                    }
                    if (0 < $rRow["mag_lines"]) {
                        $rMagCount = "<a href=\"mags?owner=" . (int) $rRow["id"] . "\"><button type=\"button\" class=\"btn btn-info btn-xs waves-effect waves-light\">" . number_format($rRow["mag_lines"], 0) . "</button></a>";
                    } else {
                        $rMagCount = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light\">0</button>";
                    }
                    if (0 < $rRow["e2_lines"]) {
                        $rE2Count = "<a href=\"enigmas?owner=" . (int) $rRow["id"] . "\"><button type=\"button\" class=\"btn btn-info btn-xs waves-effect waves-light\">" . number_format($rRow["e2_lines"], 0) . "</button></a>";
                    } else {
                        $rE2Count = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light\">0</button>";
                    }
                    if (!isset(CoreUtilities::$rRequest["no_url"])) {
                        $rReturn["data"][] = ["<a href='user?id=" . (int) $rRow["id"] . "'>" . $rRow["id"] . "</a>", "<a href='user?id=" . (int) $rRow["id"] . "'>" . $rRow["username"] . "</a>", "<a href='user?id=" . (int) $rRow["owner_id"] . "'>" . $rRow["owner_username"] . "</a>", $rIP, $rStatus, "<a href=\"users?filter=" . (int) $rRow["member_group_id"] . "\"><button type=\"button\" class=\"btn btn-dark btn-fixed btn-xs waves-effect waves-light\">" . $rRow["group_name"] . "</button></a>", $rCredits, $rUserCount, $rLineCount, $rMagCount, $rE2Count, $rRow["last_login"], $rButtons];
                    } else {
                        $rReturn["data"][] = [$rRow["id"], $rRow["username"], $rRow["owner_username"], $rIP, $rStatus, "<button type=\"button\" class=\"btn btn-dark btn-fixed btn-xs waves-effect waves-light\">" . $rRow["group_name"] . "</button>", $rCredits, $rUserCount, $rLineCount, $rMagCount, $rE2Count, $rRow["last_login"], $rButtons];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "asns") {
    if (!hasPermissions("adv", "block_isps")) {
        exit;
    }
    $rOrder = ["`blocked_asns`.`asn`", "`blocked_asns`.`isp`", "`blocked_asns`.`domain`", "`blocked_asns`.`country`", "`blocked_asns`.`num_ips`", "`blocked_asns`.`type`", "`blocked_asns`.`blocked`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 5) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`blocked_asns`.`asn` LIKE ? OR `blocked_asns`.`isp` LIKE ? OR `blocked_asns`.`domain` LIKE ? OR `blocked_asns`.`country` LIKE ? OR `blocked_asns`.`type` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        $rWhere[] = "`blocked_asns`.`blocked` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["filter"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["type"])) {
        $rWhere[] = "`blocked_asns`.`type` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["type"];
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `blocked_asns` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `blocked_asns`.`id`, `blocked_asns`.`asn`, `blocked_asns`.`isp`, `blocked_asns`.`domain`, `blocked_asns`.`country`, `blocked_asns`.`num_ips`, `blocked_asns`.`type`, `blocked_asns`.`blocked` FROM `blocked_asns` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<div class=\"btn-group\">";
                    if ($rRow["blocked"]) {
                        $rButtons .= "<button type=\"button\" class=\"btn btn-success waves-effect waves-light btn-xs\" onClick=\"api(" . $rRow["id"] . ", 'allow');\"><i class=\"mdi mdi-check\"></i></button>";
                    } else {
                        $rButtons .= "<button type=\"button\" class=\"btn btn-danger waves-effect waves-light btn-xs\" onClick=\"api(" . $rRow["id"] . ", 'block');\"><i class=\"mdi mdi-cancel\"></i></button>";
                    }
                    $rButtons .= "</div>";
                    if ($rRow["blocked"]) {
                        $rStatus = "<button type=\"button\" class=\"btn btn-danger btn-xs waves-effect waves-light btn-fixed\">BLOCKED</button>";
                    } else {
                        $rStatus = "<button type=\"button\" class=\"btn btn-success btn-xs waves-effect waves-light btn-fixed\">ALLOWED</button>";
                    }
                    $rType = strtoupper($rRow["type"]);
                    $rReturn["data"][] = [$rRow["asn"], $rRow["isp"], $rRow["domain"], "<img loading=\"lazy\" src=\"assets/images/countries/" . strtolower($rRow["country"]) . ".png\">", number_format($rRow["num_ips"], 0), $rType, $rStatus, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "series") {
    if (!hasPermissions("adv", "series") && !hasPermissions("adv", "mass_sedits")) {
        exit;
    }
    $rCategories = getCategories("series");
    $rOrder = ["`streams_series`.`id`", "`streams_series`.`cover`", "`streams_series`.`title`", "`streams_series`.`category_id`", "`latest_season`", "`episode_count`", false, "`streams_series`.`release_date`", "`streams_series`.`last_modified`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams_series`.`id` LIKE ? OR `streams_series`.`title` LIKE ? OR `streams_series`.`release_date` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
        if (CoreUtilities::$rRequest["category"] == -1) {
            $rWhere[] = "(`streams_series`.`tmdb_id` = 0 OR `streams_series`.`tmdb_id` IS NULL)";
        } elseif (CoreUtilities::$rRequest["category"] == -2) {
            $rWhere[] = "(`streams_series`.`category_id` = '[]' OR `streams_series`.`category_id` IS NULL)";
        } else {
            $rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
            $rWhereV[] = CoreUtilities::$rRequest["category"];
        }
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection . ", `streams_series`.`id` ASC";
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_series` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams_series`.`id`, `streams_series`.`year`, `streams_series`.`rating`, `streams_series`.`cover`, `streams_series`.`title`, `streams_series`.`category_id`, `streams_series`.`tmdb_id`, `streams_series`.`release_date`, `streams_series`.`last_modified`, (SELECT MAX(`season_num`) FROM `streams_episodes` WHERE `series_id` = `streams_series`.`id`) AS `latest_season`, (SELECT COUNT(*) FROM `streams_episodes` WHERE `series_id` = `streams_series`.`id`) AS `episode_count` FROM `streams_series` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if (hasPermissions("adv", "add_episode")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"episode?sid=" . $rRow["id"] . "\">Add Episode(s)</a>";
                        }
                        if (hasPermissions("adv", "episodes")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"episodes?series=" . $rRow["id"] . "\">View Episodes</a>";
                        }
                        if (hasPermissions("adv", "edit_series")) {
                            $rButtons .= "<a class=\"dropdown-item\" href=\"serie?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'serie', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["title"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>\r\n\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", 'delete');\">Delete</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if (hasPermissions("adv", "add_episode")) {
                            $rButtons .= "<a href=\"episode?sid=" . $rRow["id"] . "\"><button title=\"Add Episode(s)\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-plus-circle-outline\"></i></button></a>";
                        }
                        if (hasPermissions("adv", "episodes")) {
                            $rButtons .= "<a href=\"episodes?series=" . $rRow["id"] . "\"><button title=\"View Episodes\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-eye\"></i></button></a>";
                        }
                        if (hasPermissions("adv", "edit_series")) {
                            $rButtons .= "<a href=\"serie?id=" . $rRow["id"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'serie', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["title"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>\r\n\t\t\t\t\t\t<button type=\"button\" title=\"Delete\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                        }
                        $rButtons .= "</div>";
                    }
                    if (0 < $rRow["latest_season"]) {
                        $rRow["latest_season"] = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["latest_season"] . "</button>";
                    } else {
                        $rRow["latest_season"] = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (0 < $rRow["episode_count"]) {
                        if (hasPermissions("adv", "episodes")) {
                            $rRow["episode_count"] = "<a href='episodes?series=" . $rRow["id"] . "'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["episode_count"] . "</button></a>";
                        } else {
                            $rRow["episode_count"] = "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rRow["episode_count"] . "</button>";
                        }
                    } else {
                        $rRow["episode_count"] = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if ($rRow["last_modified"] == 0) {
                        $rRow["last_modified"] = "Never";
                    } else {
                        $rRow["last_modified"] = date($rSettings["datetime_format"], $rRow["last_modified"]);
                    }
                    if ($rRow["release_date"]) {
                        $rRow["release_date"] = date($rSettings["date_format"], strtotime($rRow["release_date"]));
                    }
                    if (0 < $rRow["tmdb_id"]) {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-success btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-check-circle\"></i></button>";
                    } else {
                        $rTMDB = "<button type=\"button\" class=\"btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xs\"><i class=\"text-light fas fa-minus-circle\"></i></button>";
                    }
                    if (0 < strlen($rRow["cover"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow["cover"] . "'><img loading='lazy' src='resize?maxh=58&maxw=32&url=" . $rRow["cover"] . "' /></a>";
                    } else {
                        $rImage = "";
                    }
                    if (hasPermissions("adv", "episodes")) {
                        $rID = "<a href='serie?id=" . (int) $rRow["id"] . "'>" . $rRow["id"] . "</a>";
                        $rTitle = "<a href='serie?id=" . (int) $rRow["id"] . "'><strong>" . $rRow["title"] . "</strong></a>";
                    } else {
                        $rID = $rRow["id"];
                        $rTitle = "<strong>" . $rRow["title"] . "</strong>";
                    }
                    $rRatingText = "";
                    if ($rRow["rating"]) {
                        $rStarRating = round($rRow["rating"]) / 2;
                        $rFullStars = floor($rStarRating);
                        $rHalfStar = 0 < $rStarRating - $rFullStars;
                        $rEmpty = 5 - ($rFullStars + ($rHalfStar ? 1 : 0));
                        if (0 < $rFullStars) {
                            foreach (range(1, $rFullStars) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star'></i>";
                            }
                        }
                        if ($rHalfStar) {
                            $rRatingText .= "<i class='mdi mdi-star-half'></i>";
                        }
                        if (0 < $rEmpty) {
                            foreach (range(1, $rEmpty) as $i) {
                                $rRatingText .= "<i class='mdi mdi-star-outline'></i>";
                            }
                        }
                    }
                    $rYear = $rRow["year"] ? "<strong>" . $rRow["year"] . "</strong> &nbsp;" : "";
                    $rTitle .= "<br><span style='font-size:11px;'>" . $rYear . $rRatingText . "</span></a>";
                    $rReturn["data"][] = [$rID, $rImage, $rTitle, $rCategory, $rRow["latest_season"], $rRow["episode_count"], $rTMDB, $rRow["release_date"], $rRow["last_modified"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "episodes") {
    if (!hasPermissions("adv", "episodes") && !hasPermissions("adv", "mass_sedits")) {
        exit;
    }
    $rOrder = ["`streams`.`id`", false, "`streams`.`stream_display_name`", "`server_name`", "`clients`", "`streams_servers`.`stream_started`", false, false, "`streams_servers`.`bitrate`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 5";
    $rDuplicates = false;
    if (isset(CoreUtilities::$rRequest["stream_id"])) {
        $rWhere[] = "`streams`.`id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } elseif (isset(CoreUtilities::$rRequest["source_id"])) {
        $rWhere[] = "MD5(`streams`.`stream_source`) = ?";
        $rWhereV[] = CoreUtilities::$rRequest["source_id"];
        $rOrderBy = "ORDER BY `streams_servers`.`server_stream_id` ASC";
    } else {
        if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
            foreach (range(1, 5) as $rInt) {
                $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
            }
            $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams_series`.`title` LIKE ? OR `streams`.`notes` LIKE ? OR `streams_servers`.`current_source` LIKE ?)";
        }
        if (0 < strlen(CoreUtilities::$rRequest["series"])) {
            $rWhere[] = "`streams_series`.`id` = ?";
            $rWhereV[] = CoreUtilities::$rRequest["series"];
        }
        if (isset(CoreUtilities::$rRequest["refresh"])) {
            $rWhere = ["`streams`.`id` IN (" . implode(",", array_map("intval", explode(",", CoreUtilities::$rRequest["refresh"]))) . ")"];
            $rStart = 0;
            $rLimit = 1000;
        }
        if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
            if (CoreUtilities::$rRequest["filter"] == 1) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 0 AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 2) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0 AND `streams_servers`.`to_analyze` = 1 AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 3) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND `streams_servers`.`stream_status` = 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 4) {
                $rWhere[] = "(`streams`.`direct_source` = 0 AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 1)";
            } elseif (CoreUtilities::$rRequest["filter"] == 5) {
                $rWhere[] = "`streams`.`direct_source` = 1";
            } elseif (CoreUtilities::$rRequest["filter"] == 6) {
                $rWhere[] = "`streams`.`id` IN (SELECT MIN(`id`) FROM `streams` WHERE `type` = 5 GROUP BY `stream_source` HAVING COUNT(`stream_source`) > 1)";
                $rDuplicates = true;
            } elseif (CoreUtilities::$rRequest["filter"] == 7) {
                $rWhere[] = "`streams`.`transcode_profile_id` > 0";
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["audio"])) {
            if (CoreUtilities::$rRequest["audio"] == -1) {
                $rWhere[] = "`streams_servers`.`audio_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`audio_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["audio"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["video"])) {
            if (CoreUtilities::$rRequest["video"] == -1) {
                $rWhere[] = "`streams_servers`.`video_codec` IS NULL";
            } else {
                $rWhere[] = "`streams_servers`.`video_codec` = ?";
                $rWhereV[] = CoreUtilities::$rRequest["video"];
            }
        }
        if (0 < strlen(CoreUtilities::$rRequest["resolution"])) {
            $rWhere[] = "`streams_servers`.`resolution` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["resolution"] ?: NULL;
        }
        if (0 < (int) CoreUtilities::$rRequest["server"]) {
            $rWhere[] = "`streams_servers`.`server_id` = ?";
            $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
        } elseif ((int) CoreUtilities::$rRequest["server"] == -1) {
            $rWhere[] = "`streams_servers`.`server_id` IS NULL";
        }
        if ($rOrder[$rOrderRow]) {
            $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
            $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
        }
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    if (isset(CoreUtilities::$rRequest["single"])) {
        $rSettings["streams_grouped"] = 0;
    } elseif (isset(CoreUtilities::$rRequest["grouped"])) {
        $rSettings["streams_grouped"] = 1;
    }
    $rReturn["recordsTotal"] = 0;
    if ($rSettings["streams_grouped"] == 1) {
        $rCountQuery = "SELECT COUNT(DISTINCT(`streams`.`id`)) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . ";";
    } else {
        $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . ";";
    }
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        if ($rSettings["streams_grouped"] == 1) {
            $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams_servers`.`to_analyze`, `streams`.`movie_properties`,  `streams`.`updated`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams_series`.`title`, `streams_series`.`seasons`, `streams_series`.`id` AS `sid`, `streams_episodes`.`season_num` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . " GROUP BY `streams`.`id` " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        } else {
            $rQuery = "SELECT `streams`.`id`, MD5(`streams`.`stream_source`) AS `source`, `streams_servers`.`to_analyze`, `streams`.`movie_properties`,  `streams`.`updated`, `streams`.`target_container`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`notes`, `streams`.`direct_source`, `streams`.`direct_proxy`, `streams_servers`.`pid`, `streams_servers`.`monitor_pid`, `streams_servers`.`stream_status`, `streams_servers`.`stream_started`, `streams_servers`.`stream_info`, `streams_servers`.`current_source`, `streams_servers`.`bitrate`, `streams_servers`.`progress_info`, `streams_servers`.`on_demand`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name`, (SELECT COUNT(*) FROM `lines_live` WHERE `lines_live`.`server_id` = `streams_servers`.`server_id` AND `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0) AS `clients`, `streams_series`.`title`, `streams_series`.`seasons`, `streams_series`.`id` AS `sid`, `streams_episodes`.`season_num` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        }
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rServerCount = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, COUNT(`server_stream_id`) AS `count` FROM `streams_servers` WHERE `stream_id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ") GROUP BY `stream_id`;");
                foreach ($db->get_rows() as $rRow) {
                    $rServerCount[$rRow["stream_id"]] = $rRow["count"];
                }
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"]) {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, true, true);
                    } else {
                        $rConnectionCount = CoreUtilities::getStreamConnections($rStreamIDs, false, false);
                    }
                }
                if ($rDuplicates) {
                    $rDuplicateCount = [];
                    $db->query("SELECT MD5(`stream_source`) AS `source`, COUNT(`stream_source`) AS `count` FROM `streams` WHERE `stream_source` IN (SELECT `stream_source` FROM `streams` WHERE `id` IN (" . implode(",", array_map("intval", $rStreamIDs)) . ")) GROUP BY `stream_source` HAVING COUNT(`stream_source`) > 1;");
                    foreach ($db->get_rows() as $rRow) {
                        $rDuplicateCount[$rRow["source"]] = $rRow["count"];
                    }
                }
            }
            foreach ($rRows as $rRow) {
                if (CoreUtilities::$rSettings["redis_handler"]) {
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["clients"] = $rConnectionCount[$rRow["id"]] ?: 0;
                    } else {
                        $rRow["clients"] = count($rConnectionCount[$rRow["id"]][$rRow["server_id"]]) ?: 0;
                    }
                }
                if ($rIsAPI) {
                    unset($rReturn["source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rSeriesName = $rRow["title"] . " - Season " . $rRow["season_num"];
                    $rStreamName = "<strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rSeriesName . "</span>";
                    if ($rRow["server_name"]) {
                        if (hasPermissions("adv", "servers")) {
                            $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                        } else {
                            $rServerName = $rRow["server_name"];
                        }
                        if ($rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                            $rServerName .= " &nbsp; <button title=\"View All Servers\" onClick=\"viewSources('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', " . (int) $rRow["id"] . ");\" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ " . ($rServerCount[$rRow["id"]] - 1) . "</button>";
                        }
                        if ($rServers[$rRow["server_id"]]["last_status"] != 1) {
                            $rServerName .= " &nbsp; <button title=\"Server Offline!<br/>Uptime cannot be confirmed.\" type='button' class='tooltip btn btn-danger btn-xs waves-effect waves-light'><i class='mdi mdi-alert'></i></button>";
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    if (!$rSettings["streams_grouped"]) {
                        $rStreamSource = "<br/><span style='font-size:11px;'>" . parse_url($rRow["current_source"])["host"] . "</span>";
                        $rServerName .= $rStreamSource;
                    }
                    $rUptime = 0;
                    $rActualStatus = 0;
                    if ((int) $rRow["direct_source"] == 1) {
                        if ((int) $rRow["direct_proxy"] == 1) {
                            $rActualStatus = 5;
                        } else {
                            $rActualStatus = 3;
                        }
                    } elseif (!is_null($rRow["pid"]) && 0 < $rRow["pid"]) {
                        if ($rRow["to_analyze"] == 1) {
                            $rActualStatus = 2;
                        } elseif ($rRow["stream_status"] == 1) {
                            $rActualStatus = 4;
                        } else {
                            $rActualStatus = 1;
                        }
                    } else {
                        $rActualStatus = 0;
                    }
                    if (!$rRow["server_id"]) {
                        $rRow["server_id"] = 0;
                    }
                    if ($rSettings["streams_grouped"] == 1) {
                        $rRow["server_id"] = -1;
                    }
                    if (hasPermissions("adv", "live_connections")) {
                        if (0 < $rRow["clients"]) {
                            $rClients = "<a href='javascript: void(0);' onClick='viewLiveConnections(" . (int) $rRow["id"] . ", " . (int) $rRow["server_id"] . ");'><button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button></a>";
                        } else {
                            $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                        }
                    } elseif (0 < $rRow["clients"]) {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>" . number_format($rRow["clients"], 0) . "</button>";
                    } else {
                        $rClients = "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                    }
                    if (CoreUtilities::$rSettings["group_buttons"]) {
                        $rButtons = "";
                        if (!empty($rRow['notes'])) {
                            $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                        }
                        $rButtons .= "<div class=\"btn-group dropdown\"><a href=\"javascript: void(0);\" class=\"table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm\" data-toggle=\"dropdown\" aria-expanded=\"false\"><i class=\"mdi mdi-menu\"></i></a><div class=\"dropdown-menu dropdown-menu-right\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_episode")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            } elseif ((int) $rActualStatus == 3) {
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop Encoding</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                            }
                        } elseif (hasPermissions("adv", "edit_episode")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            } elseif ((int) $rActualStatus == 3) {
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\">Stop Encoding</a>";
                            } else {
                                $rButtons .= "<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\">Encode</a>";
                            }
                            $rButtons .= "<a class=\"dropdown-item\" href=\"episode?id=" . $rRow["id"] . "&sid=" . $rRow["sid"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'episode', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . ">Edit</a>\r\n\t\t\t\t\t\t\t<a class=\"dropdown-item\" href=\"javascript:void(0);\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\">Delete</a>";
                        }
                        $rButtons .= "</div></div>";
                    } else {
                        $rButtons = "<div class=\"btn-group\">";
                        if ((isset(CoreUtilities::$rRequest["single"]) || isset(CoreUtilities::$rRequest["simple"])) && hasPermissions("adv", "edit_episode")) {
                            if ((int) $rActualStatus == 1) {
                                $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
                            } elseif ((int) $rActualStatus == 3) {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop\"><i class=\"mdi mdi-stop\"></i></button>";
                            } elseif ((int) $rActualStatus == 2) {
                                $rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                            } else {
                                $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                            }
                            if (isset(CoreUtilities::$rRequest["single"])) {
                                $rButtons .= "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        } else {
                            if (!empty($rRow['notes'])) {
                                $rButtons .= "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" title=\"" . $rRow["notes"] . "\"><i class=\"mdi mdi-note\"></i></button>";
                            } else {
                                $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-note\"></i></button>";
                            }
                            if (hasPermissions("adv", "edit_episode")) {
                                if ((int) $rActualStatus == 1) {
                                    $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-refresh\"></i></button>";
                                } elseif ((int) $rActualStatus == 3) {
                                    $rButtons .= "<button disabled type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop\"><i class=\"mdi mdi-stop\"></i></button>";
                                } elseif ((int) $rActualStatus == 2) {
                                    $rButtons .= "<button title=\"Stop Encoding\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-stop tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                                } else {
                                    $rButtons .= "<button title=\"Encode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs api-start tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'start');\"><i class=\"mdi mdi-play\"></i></button>";
                                }
                                $rButtons .= "<a href=\"episode?id=" . $rRow["id"] . "&sid=" . $rRow["sid"] . "\" " . (CoreUtilities::$rSettings["modal_edit"] ? "onClick=\"editModal(event, 'episode', " . (int) $rRow["id"] . ", '" . str_replace("\"", "&quot;", str_replace("'", "\\'", $rRow["stream_display_name"])) . "')\" data-modal=\"true\"" : "") . "><button title=\"Edit\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-pencil\"></i></button></a>\r\n\t\t\t\t\t\t\t<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", " . $rRow["server_id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                            }
                        }
                        $rButtons .= "</div>";
                    }
                    if ($rDuplicates) {
                        $rDupeCount = $rDuplicateCount[$rRow["source"]] - 1 ?: 0;
                        $rStreamInfoText = "<a href='javascript: void(0);' onClick=\"viewDuplicates('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', '" . $rRow["source"] . "');\">Duplicate of <strong>" . $rDupeCount . "</strong> other episode" . ($rDupeCount == 1 ? "" : "s") . "</a>";
                    } else {
                        $rStreamInfoText = "<table style='font-size: 10px;' class='table-data nowrap' align='center'><tbody><tr><td colspan='3'>No information available</td></tr></tbody></table>";
                        $rStreamInfo   = json_decode($rRow['stream_info'] ?? '', true) ?: [];
                        if ($rActualStatus == 1) {
                            if (!isset($rStreamInfo["codecs"]["video"])) {
                                $rStreamInfo["codecs"]["video"] = ["width" => "?", "height" => "?", "codec_name" => "N/A", "r_frame_rate" => "--"];
                            }
                            if (!isset($rStreamInfo["codecs"]["audio"])) {
                                $rStreamInfo["codecs"]["audio"] = ["codec_name" => "N/A"];
                            }
                            if ($rRow["bitrate"] == 0) {
                                $rRow["bitrate"] = "?";
                            }
                            $rStreamInfoText = "<table class='table-data nowrap table-data-120' align='center'>\r\n\t\t\t\t\t\t\t<tbody>\r\n\t\t\t\t\t\t\t\t<tr>\r\n\t\t\t\t\t\t\t\t\t<td class='double'>" . number_format($rRow["bitrate"], 0) . " Kbps</td>\r\n\t\t\t\t\t\t\t\t\t<td class='text-success'><i class='mdi mdi-video' data-name='mdi-video'></i></td>\r\n\t\t\t\t\t\t\t\t\t<td class='text-success'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>\r\n\t\t\t\t\t\t\t\t</tr>\r\n\t\t\t\t\t\t\t\t<tr>\r\n\t\t\t\t\t\t\t\t\t<td class='double'>" . $rStreamInfo["codecs"]["video"]["width"] . " x " . $rStreamInfo["codecs"]["video"]["height"] . "</td>\r\n\t\t\t\t\t\t\t\t\t<td>" . $rStreamInfo["codecs"]["video"]["codec_name"] . "</td>\r\n\t\t\t\t\t\t\t\t\t<td>" . $rStreamInfo["codecs"]["audio"]["codec_name"] . "</td>\r\n\t\t\t\t\t\t\t\t</tr>\r\n\t\t\t\t\t\t\t</tbody>\r\n\t\t\t\t\t\t</table>";
                        }
                    }
                    if (hasPermissions("adv", "player")) {
                        if ((int) $rActualStatus == 1 || $rActualStatus == 3) {
                            if (empty($rStreamInfo["codecs"]["video"]["codec_name"]) || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "H264" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "N/A" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "HEVC" || strtoupper($rStreamInfo["codecs"]["video"]["codec_name"]) == "H265") {
                                $rPlayer = "<button title=\"Play\" type=\"button\" class=\"btn btn-info waves-effect waves-light btn-xs tooltip\" onClick=\"player(" . $rRow["id"] . ", '" . $rRow["target_container"] . "');\"><i class=\"mdi mdi-play\"></i></button>";
                            } else {
                                $rPlayer = "<button type=\"button\" class=\"btn btn-dark waves-effect waves-light btn-xs tooltip\" title=\"Incompatible Video Codec\"><i class=\"mdi mdi-play\"></i></button>";
                            }
                        } else {
                            $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                        }
                    } else {
                        $rPlayer = "<button type=\"button\" disabled class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-play\"></i></button>";
                    }
                    $rImage = "";
                    $rProperties = json_decode($rRow["movie_properties"], true);
                    if (0 < strlen($rProperties["movie_image"]) && CoreUtilities::$rSettings["show_images"]) {
                        $rImage = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rProperties["movie_image"] . "'><img loading='lazy' src='resize?maxh=32&maxw=64&url=" . $rProperties["movie_image"] . "' /></a>";
                    }
                    $rID = $rRow["id"];
                    if (!$rSettings["streams_grouped"] && 1 < $rServerCount[$rRow["id"]]) {
                        $rID .= "-" . $rRow["server_id"];
                    }
                    $rModded = $rRow["updated"];
                    $rReturn["data"][] = ["<a href='stream_view?id=" . (int) $rRow["id"] . "'>" . $rID . "</a>", $rImage, "<a href='stream_view?id=" . (int) $rRow["id"] . "'>" . $rStreamName . "</a>", $rServerName, $rClients, $rVODStatusArray[$rActualStatus], $rButtons, $rPlayer, $rModded, $rStreamInfoText];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "backups") {
    if (!hasPermissions("adv", "database")) {
        exit;
    }
    $rBackups = array_reverse(getBackups());
    $rRemoteBackups = [];
    if (0 < strlen($rSettings["dropbox_token"])) {
        foreach (array_reverse(getRemoteBackups()) as $rBackup) {
            $rRemoteBackups[$rBackup["name"]] = $rBackup;
        }
    }
    $rReturn = ["draw" => (int) CoreUtilities::$rRequest["draw"], "recordsTotal" => count($rBackups), "recordsFiltered" => count($rBackups), "data" => []];
    $rLocalFiles = [];
    foreach ($rBackups as $rBackup) {
        $rButtons = "<div class=\"btn-group\"><button type=\"button\" title=\"Restore Backup\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rBackup["filename"] . "', 'restore');\"><i class=\"mdi mdi-folder-upload\"></i></button>\r\n        <button type=\"button\" title=\"Delete Backup\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rBackup["filename"] . "', 'delete');\"><i class=\"mdi mdi-close\"></i></button></div>";
        $rLocal = "<i class='text-success fas fa-square'></i>";
        if (isset($rRemoteBackups[$rBackup["filename"]])) {
            $rRemote = "<i class='text-success fas fa-square'></i>";
            unset($rRemoteBackups[$rBackup["filename"]]);
        } elseif (file_exists(MAIN_HOME . "backups/" . $rBackup["filename"] . ".error")) {
            $rRemote = "<i title='" . htmlspecialchars(file_get_contents(MAIN_HOME . "backups/" . $rBackup["filename"] . ".error")) . "' class='text-danger fas fa-square tooltip'></i>";
        } elseif (file_exists(MAIN_HOME . "backups/" . $rBackup["filename"] . ".uploading") && time() - filemtime(MAIN_HOME . "backups/" . $rBackup["filename"] . ".uploading") < 600) {
            $rRemote = "<i title='Uploading...' class='text-warning fas fa-square tooltip'></i>";
        } else {
            $rRemote = "<i class='text-secondary fas fa-square'></i>";
        }
        $rLocalFiles[] = $rBackup["filename"];
        $rReturn["data"][] = [date($rSettings["datetime_format"], strtotime($rBackup["date"])), $rBackup["filename"], ceil($rBackup["filesize"] / 1024 / 1024) . " MB", $rLocal, $rRemote, $rButtons];
    }
    foreach ($rRemoteBackups as $rBackup) {
        $rButtons = "<div class=\"btn-group\"><button type=\"button\" title=\"Restore Backup\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rBackup["name"] . "', 'restore');\"><i class=\"mdi mdi-folder-upload\"></i></button>\r\n        <button type=\"button\" title=\"Delete Backup\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rBackup["name"] . "', 'delete');\"><i class=\"mdi mdi-close\"></i></button></div>";
        if (in_array($rBackup["name"], $rLocalFiles)) {
            $rLocal = "<i class='text-success fas fa-square'></i>";
        } else {
            $rLocal = "<i class='text-secondary fas fa-square'></i>";
        }
        $rRemote = "<i class='text-success fas fa-square'></i>";
        $rReturn["data"][] = [date($rSettings["datetime_format"], $rBackup["time"]), $rBackup["name"], ceil($rBackup["size"] / 1024 / 1024) . " MB", $rLocal, $rRemote, $rButtons];
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "watch_output") {
    if (!hasPermissions("adv", "folder_watch_output")) {
        exit;
    }
    $rOrder = ["`watch_logs`.`id`", "`watch_logs`.`type`", "`watch_logs`.`server_id`", "`watch_logs`.`filename`", "`watch_logs`.`status`", "`watch_logs`.`dateadded`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`watch_logs`.`id` LIKE ? OR `watch_logs`.`filename` LIKE ? OR `watch_logs`.`dateadded` LIKE ?)";
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`watch_logs`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["type"])) {
        $rWhere[] = "`watch_logs`.`type` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["type"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["status"])) {
        $rWhere[] = "`watch_logs`.`status` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["status"];
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `watch_logs` LEFT JOIN `servers` ON `servers`.`id` = `watch_logs`.`server_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `watch_logs`.`id`, `watch_logs`.`type`, `watch_logs`.`server_id`, `servers`.`server_name`, `watch_logs`.`filename`, `watch_logs`.`status`, `watch_logs`.`stream_id`, `watch_logs`.`dateadded` FROM `watch_logs` LEFT JOIN `servers` ON `servers`.`id` = `watch_logs`.`server_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<div class=\"btn-group\">";
                    if (0 < $rRow["stream_id"]) {
                        if ($rRow["type"] == 1) {
                            if (hasPermissions("adv", "edit_movie")) {
                                $rButtons = "<a href=\"stream_view?id=" . $rRow["stream_id"] . "\"><button title=\"View Movie\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-eye\"></i></button></a>";
                            }
                        } elseif (hasPermissions("adv", "edit_episode")) {
                            $rButtons = "<a href=\"stream_view?id=" . $rRow["stream_id"] . "\"><button title=\"View Episode\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-eye\"></i></button></a>";
                        }
                    }
                    if (1 < $rRow["status"] && $rRow["type"] == 1) {
                        $rButtons .= "<a href=\"movie.php?path=" . urlencode("s:" . $rRow["server_id"] . ":" . $rRow["filename"]) . "\"><button type=\"button\" title=\"Manual Match\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-plus\"></i></button></a>";
                    }
                    $rButtons .= "<button type=\"button\" title=\"Delete\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                    $rButtons .= "</div>";
                    if (hasPermissions("adv", "servers")) {
                        $rServer = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                    } else {
                        $rServer = $rRow["server_name"];
                    }
                    $rReturn["data"][] = [$rRow["id"], ["1" => "Movies", "2" => "Series"][$rRow["type"]], $rServer, $rRow["filename"], $rWatchStatusArray[$rRow["status"]], $rRow["dateadded"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "mysql_syslog") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "panel_logs")) {
        exit;
    }
    $rOrder = ["`mysql_syslog`.`date`", "`servers`.`server_name`", "`mysql_syslog`.`type`", "`mysql_syslog`.`error`", "`mysql_syslog`.`ip`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`mysql_syslog`.`ip` LIKE ? OR `mysql_syslog`.`type` LIKE ? OR `mysql_syslog`.`error` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `mysql_syslog` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rBlocked = [];
        $db->query("SELECT `ip` FROM `blocked_ips`;");
        foreach ($db->get_rows() as $rRow) {
            $rBlocked[] = $rRow["ip"];
        }
        $rQuery = "SELECT `mysql_syslog`.`id`, `mysql_syslog`.`server_id`, `servers`.`server_name`, `mysql_syslog`.`type`, `mysql_syslog`.`error`, `mysql_syslog`.`ip`, `mysql_syslog`.`date` FROM `mysql_syslog` LEFT JOIN `servers` ON `servers`.`id` = `mysql_syslog`.`server_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if ($rRow["ip"] == "127.0.0.1") {
                        $rRow["ip"] = "localhost";
                    }
                    if (0 < strlen($rRow["ip"]) && $rRow["ip"] != "localhost") {
                        if (!in_array($rRow["ip"], $rBlocked)) {
                            $rButtons = "<button title=\"Block IP\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["ip"] . "', 'block');\"><i class=\"fas fa-hammer\"></i></button>";
                        } else {
                            $rButtons = "<button title=\"IP Already Blocked\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"fas fa-hammer\"></i></button>";
                        }
                        $rExplode = explode(":", $rRow["ip"]);
                        $rIP = "<a onClick=\"whois('" . $rRow["ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["ip"]) . "</a>";
                    } else {
                        $rButtons = "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\" disabled><i class=\"fas fa-hammer\"></i></button>";
                        $rIP = "localhost";
                    }
                    $rReturn["data"][] = [date($rSettings["datetime_format"], $rRow["date"]), "<a href='server_view?id=" . (int) $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>", $rRow["type"], $rRow["error"], $rIP, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "panel_logs") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "panel_logs")) {
        exit;
    }
    $rOrder = ["`panel_logs`.`date`", "`servers`.`server_name`", "`panel_logs`.`type`", "`panel_logs`.`log_message`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`panel_logs`.`log_message` LIKE ? OR `panel_logs`.`log_extra` LIKE ? OR `panel_logs`.`type` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `panel_logs` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `panel_logs`.`id`, `panel_logs`.`date`, `panel_logs`.`server_id`, `servers`.`server_name`, `panel_logs`.`type`, `panel_logs`.`log_message`, `panel_logs`.`log_extra`, `panel_logs`.`line` FROM `panel_logs` LEFT JOIN `servers` ON `servers`.`id` = `panel_logs`.`server_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rReturn["data"][] = [date($rSettings["datetime_format"], $rRow["date"]), "<a href='server_view?id=" . (int) $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>", strtoupper($rRow["type"]), $rRow["log_message"] . ($rRow["log_extra"] ? "<br/>" . $rRow["log_extra"] : ""), $rRow["line"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "login_logs") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "login_logs")) {
        exit;
    }
    $rOrder = ["`login_logs`.`date`", "`login_logs`.`type`", "`login_logs`.`status`", "`users`.`username`", "`access_codes`.`code`", "`login_logs`.`login_ip`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`login_logs`.`login_ip` LIKE ? OR `login_logs`.`status` LIKE ? OR `users`.`username` LIKE ? OR `access_codes`.`code` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `login_logs` LEFT JOIN `users` ON `users`.`id` = `login_logs`.`user_id` LEFT JOIN `access_codes` ON `access_codes`.`id` = `login_logs`.`access_code` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rBlocked = [];
        $db->query("SELECT `ip` FROM `blocked_ips`;");
        foreach ($db->get_rows() as $rRow) {
            $rBlocked[] = $rRow["ip"];
        }
        $rQuery = "SELECT `login_logs`.`id`, `login_logs`.`type`, `login_logs`.`access_code`, `access_codes`.`code`, `login_logs`.`user_id`, `users`.`username`, `login_logs`.`status`, `login_logs`.`login_ip`, `login_logs`.`date` FROM `login_logs` LEFT JOIN `users` ON `users`.`id` = `login_logs`.`user_id` LEFT JOIN `access_codes` ON `access_codes`.`id` = `login_logs`.`access_code` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (0 < strlen($rRow["login_ip"])) {
                        if (!in_array($rRow["login_ip"], $rBlocked)) {
                            $rButtons = "<button title=\"Block IP\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["login_ip"] . "', 'block');\"><i class=\"fas fa-hammer\"></i></button>";
                        } else {
                            $rButtons = "<button title=\"IP Already Blocked\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"fas fa-hammer\"></i></button>";
                        }
                        $rExplode = explode(":", $rRow["ip"]);
                        $rIP = "<a onClick=\"whois('" . $rRow["ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["ip"]) . "</a>";
                    } else {
                        $rButtons = "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\" disabled><i class=\"fas fa-hammer\"></i></button>";
                        $rIP = "";
                    }
                    $rReturn["data"][] = [date($rSettings["datetime_format"], $rRow["date"]), $rRow["type"], $rRow["status"], "<a href=\"user?id=" . $rRow["user_id"] . "\">" . $rRow["username"] . "</a>", $rRow["code"], $rIP, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "queue") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "movies") && !hasPermissions("adv", "episodes") && !hasPermissions("adv", "series")) {
        exit;
    }
    $rOrder = ["`queue`.`id`", "`streams`.`stream_display_name`", "`servers`.`server_name`", "`queue`.`pid`", "`queue`.`added`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`stream_display_name` LIKE ? OR `servers`.`server_name` LIKE ? OR `streams`.`id` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `queue` LEFT JOIN `servers` ON `servers`.`id` = `queue`.`server_id` LEFT JOIN `streams` ON `streams`.`id` = `queue`.`stream_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `queue`.*, `servers`.`server_name`, `streams`.`type`, `streams`.`stream_display_name` FROM `queue` LEFT JOIN `servers` ON `servers`.`id` = `queue`.`server_id` LEFT JOIN `streams` ON `streams`.`id` = `queue`.`stream_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rPosition = $rStart + 1;
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rRow["position"] = $rPosition;
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (hasPermissions("adv", "servers")) {
                        $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                    } else {
                        $rServerName = $rRow["server_name"];
                    }
                    $rPermission = ["2" => "movies", "5" => "series"];
                    if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                        $rStream = "<a href='stream_view?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                    } else {
                        $rStream = $rRow["stream_display_name"];
                    }
                    if (0 < $rRow["pid"]) {
                        $rStatus = "<i class=\"text-info fas fa-square tooltip\" title=\"In Progress\"></i>";
                        $rButtons = "<button title=\"Stop\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["id"] . "', 'stop');\"><i class=\"mdi mdi-stop\"></i></button>";
                    } else {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Queued...\"></i>";
                        $rButtons = "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["id"] . "', 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                    }
                    $rReturn["data"][] = [$rPosition, $rStream, $rServerName, $rStatus, date($rSettings["datetime_format"], $rRow["added"]), $rButtons];
                    $rPosition++;
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "restream_logs") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "restream_logs")) {
        exit;
    }
    $rOrder = ["`detect_restream_logs`.`id`", "`lines`.`username`", "`streams`.`stream_display_name`", "`detect_restream_logs`.`ip`", "`detect_restream_logs`.`time`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`detect_restream_logs`.`ip` LIKE ? OR `lines`.`username` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `detect_restream_logs` LEFT JOIN `lines` ON `lines`.`id` = `detect_restream_logs`.`user_id` LEFT JOIN `streams` ON `streams`.`id` = `detect_restream_logs`.`stream_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rBlocked = [];
        $db->query("SELECT `ip` FROM `blocked_ips`;");
        foreach ($db->get_rows() as $rRow) {
            $rBlocked[] = $rRow["ip"];
        }
        $rQuery = "SELECT `detect_restream_logs`.`id`, `detect_restream_logs`.`user_id`, `detect_restream_logs`.`stream_id`, `detect_restream_logs`.`ip`, `detect_restream_logs`.`time`, `lines`.`username`, `streams`.`stream_display_name`, `streams`.`type` FROM `detect_restream_logs` LEFT JOIN `lines` ON `lines`.`id` = `detect_restream_logs`.`user_id` LEFT JOIN `streams` ON `streams`.`id` = `detect_restream_logs`.`stream_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if (0 < strlen($rRow["ip"])) {
                        if (!in_array($rRow["ip"], $rBlocked)) {
                            $rButtons = "<button title=\"Block IP\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api('" . $rRow["ip"] . "', 'block');\"><i class=\"fas fa-hammer\"></i></button>";
                        } else {
                            $rButtons = "<button title=\"IP Already Blocked\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"fas fa-hammer\"></i></button>";
                        }
                        $rExplode = explode(":", $rRow["ip"]);
                        $rIP = "<a onClick=\"whois('" . $rRow["ip"] . "');\" href='javascript: void(0);'>" . (1 < count($rExplode) ? implode(":", array_slice($rExplode, 0, 4)) . ":<br/>" . implode(":", array_slice($rExplode, 4, 8)) : $rRow["ip"]) . "</a>";
                    } else {
                        $rButtons = "<button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\" disabled><i class=\"fas fa-hammer\"></i></button>";
                        $rIP = "";
                    }
                    $rPermission = ["1" => "streams", "2" => "movies", "3" => "streams", "4" => "radio", "5" => "series"];
                    $rURLs = ["1" => "stream_view", "2" => "stream_view", "3" => "stream_view", "4" => "stream_view"];
                    if (hasPermissions("adv", $rPermission[$rRow["type"]])) {
                        if ($rRow["type"] == 5) {
                            $rStream = "<a href='serie?id=" . $rRow["series_no"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        } else {
                            $rStream = "<a href='" . $rURLs[$rRow["type"]] . "?id=" . $rRow["stream_id"] . "'>" . $rRow["stream_display_name"] . "</a>";
                        }
                    } else {
                        $rStream = $rRow["stream_display_name"];
                    }
                    if (hasPermissions("adv", "edit_user")) {
                        $rLine = "<a href=\"line?id=" . $rRow["user_id"] . "\">" . $rRow["username"] . "</a>";
                    } else {
                        $rLine = $rRow["username"];
                    }
                    $rReturn["data"][] = [$rRow["id"], $rLine, $rStream, $rIP, date($rSettings["datetime_format"], $rRow["date"]), $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "mag_events") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "manage_events")) {
        exit;
    }
    $rOrder = ["`mag_events`.`send_time`", "`mag_devices`.`mac`", "`mag_events`.`event`", "`mag_events`.`msg`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`mag_devices`.`mac` LIKE ? OR `mag_events`.`event` LIKE ? OR `mag_events`.`msg` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `mag_events` LEFT JOIN `mag_devices` ON `mag_devices`.`mag_id` = `mag_events`.`mag_device_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `mag_events`.`id`, `mag_events`.`send_time`, `mag_devices`.`mac`, `mag_events`.`event`, `mag_events`.`msg`, `mag_events`.`mag_device_id` FROM `mag_events` LEFT JOIN `mag_devices` ON `mag_devices`.`mag_id` = `mag_events`.`mag_device_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<button title=\"Delete\" type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\" onClick=\"api(" . $rRow["id"] . ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>";
                    $rReturn["data"][] = [date($rSettings["datetime_format"], $rRow["send_time"]), $rRow["mac"], $rRow["event"], $rRow["msg"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "bouquets_streams") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "bouquets")) {
        exit;
    }
    $rCategories = getCategories("live");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false, false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "(`type` = 1 OR `type` = 3)";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`category_id` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category_id"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category_id"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rButtons = "<div class=\"btn-group\"><button data-id=\"" . $rRow["id"] . "\" data-type=\"stream\" type=\"button\" style=\"display: none;\" class=\"btn-remove btn btn-warning waves-effect waves-warning btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'stream');\"><i class=\"mdi mdi-minus\"></i></button>\r\n                <button data-id=\"" . $rRow["id"] . "\" data-type=\"stream\" type=\"button\" style=\"display: none;\" class=\"btn-add btn btn-success waves-effect waves-success btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'stream');\"><i class=\"mdi mdi-plus\"></i></button></div>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rCategory, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "bouquets_vod") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "bouquets")) {
        exit;
    }
    $rCategories = getCategories("movie");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false, false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`type` = 2";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`category_id` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category_id"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category_id"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rButtons = "<div class=\"btn-group\"><button data-id=\"" . $rRow["id"] . "\" data-type=\"movies\" type=\"button\" style=\"display: none;\" class=\"btn-remove btn btn-warning waves-effect waves-warning btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'movies');\"><i class=\"mdi mdi-minus\"></i></button>\r\n                <button data-id=\"" . $rRow["id"] . "\" data-type=\"movies\" type=\"button\" style=\"display: none;\" class=\"btn-add btn btn-success waves-effect waves-success btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'movies');\"><i class=\"mdi mdi-plus\"></i></button></div>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rCategory, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "bouquets_series") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "bouquets")) {
        exit;
    }
    $rCategories = getCategories("series");
    $rOrder = ["`streams_series`.`id`", "`streams_series`.`title`", false, false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams_series`.`id` LIKE ? OR `streams_series`.`title` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_series` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams_series`.`id`, `streams_series`.`title`, `streams_series`.`category_id` FROM `streams_series` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category_id"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category_id"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rButtons = "<div class=\"btn-group\"><button data-id=\"" . $rRow["id"] . "\" data-type=\"series\" type=\"button\" style=\"display: none;\" class=\"btn-remove btn btn-warning waves-effect waves-warning btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'series');\"><i class=\"mdi mdi-minus\"></i></button>\r\n                <button data-id=\"" . $rRow["id"] . "\" data-type=\"series\" type=\"button\" style=\"display: none;\" class=\"btn-add btn btn-success waves-effect waves-success btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'series');\"><i class=\"mdi mdi-plus\"></i></button></div>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["title"], $rCategory, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "bouquets_radios") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "bouquets")) {
        exit;
    }
    $rCategories = getCategories("radio");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false, false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`type` = 4";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`category_id` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category_id"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category_id"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rButtons = "<div class=\"btn-group\"><button data-id=\"" . $rRow["id"] . "\" data-type=\"radios\" type=\"button\" style=\"display: none;\" class=\"btn-remove btn btn-warning waves-effect waves-warning btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'radios');\"><i class=\"mdi mdi-minus\"></i></button>\r\n                <button data-id=\"" . $rRow["id"] . "\" data-type=\"radios\" type=\"button\" style=\"display: none;\" class=\"btn-add btn btn-success waves-effect waves-success btn-xs\" onClick=\"toggleBouquet(" . $rRow["id"] . ", 'radios');\"><i class=\"mdi mdi-plus\"></i></button></div>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rCategory, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "streams_short") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "categories")) {
        exit;
    }
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "(`type` = 1 OR `type` = 3)";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<a href=\"stream_view?id=" . $rRow["id"] . "\"><button type=\"button\" title=\"View Stream\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-play\"></i></button></a>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "movies_short") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "categories")) {
        exit;
    }
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`type` = 2";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<a href=\"stream_view?id=" . $rRow["id"] . "\"><button type=\"button\" title=\"View Movie\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-play\"></i></button></a>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "radios_short") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "categories")) {
        exit;
    }
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`type` = 4";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name` FROM `streams` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<a href=\"stream_view?id=" . $rRow["id"] . "\"><button type=\"button\" title=\"View Station\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-play\"></i></button></a>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "series_short") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "categories")) {
        exit;
    }
    $rOrder = ["`streams_series`.`id`", "`streams_series`.`title`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < (int) CoreUtilities::$rRequest["category_id"]) {
        $rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category_id"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams_series`.`id` LIKE ? OR `streams_series`.`title` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_series` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams_series`.`id`, `streams_series`.`title` FROM `streams_series` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<a href=\"series?id=" . $rRow["id"] . "\"><button type=\"button\" title=\"Edit Series\" class=\"btn btn-light waves-effect waves-light btn-xs tooltip\"><i class=\"mdi mdi-play\"></i></button></a>";
                    $rReturn["data"][] = [$rRow["id"], $rRow["title"], $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "vod_selection") {
    if (!$rPermissions["is_admin"] || !hasPermissions("adv", "create_channel")) {
        exit;
    }
    $rCategories = getCategories("movie");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_display_name`", "`streams_series`.`title`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`stream_source` LIKE ?";
    $rWhereV[] = "%s:" . (int) CoreUtilities::$rRequest["server_id"] . ":%";
    if (isset(CoreUtilities::$rRequest["category_id"]) && 0 < strlen(CoreUtilities::$rRequest["category_id"])) {
        $rSplit = explode(":", CoreUtilities::$rRequest["category_id"]);
        if ((int) $rSplit[0] == 0) {
            $rWhere[] = "(`streams`.`type` = 2 AND JSON_CONTAINS(`streams`.`category_id`, ?, '\$'))";
            $rWhereV[] = $rSplit[1];
        } else {
            $rWhere[] = "(`streams`.`type` = 5 AND `streams`.`series_no` = ?)";
            $rWhereV[] = $rSplit[1];
        }
    } else {
        $rWhere[] = "(`streams`.`type` = 2 OR `streams`.`type` = 5)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 3) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `streams_series`.`title` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams`.`series_no` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `streams`.`id`, `streams`.`stream_display_name`, `streams`.`category_id`, `streams_series`.`title` FROM `streams` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams`.`series_no` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rButtons = "<div class=\"btn-group\"><button data-id=\"" . $rRow["id"] . "\" data-type=\"vod\" type=\"button\" style=\"display: none;\" class=\"btn-remove btn btn-light waves-effect waves-light btn-xs\" onClick=\"toggleSelection(" . $rRow["id"] . ");\"><i class=\"mdi mdi-minus\"></i></button>\r\n                <button data-id=\"" . $rRow["id"] . "\" data-type=\"vod\" type=\"button\" style=\"display: none;\" class=\"btn-add btn btn-light waves-effect waves-light btn-xs\" onClick=\"toggleSelection(" . $rRow["id"] . ");\"><i class=\"mdi mdi-plus\"></i></button></div>";
                    if (0 < strlen($rRow["title"])) {
                        $rCategory = $rRow["title"];
                    } else {
                        $rCategoryIDs = json_decode($rRow["category_id"], true);
                        if (0 < strlen($rSplit[1])) {
                            $rCategory = $rCategories[(int) $rSplit[1]]["category_name"] ?: "No Category";
                        } else {
                            $rCategory = $rCategoryIDs[0] ?? null;
                            $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                        }
                        if (1 < count($rCategoryIDs)) {
                            $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                        }
                    }
                    $rReturn["data"][] = [$rRow["id"], $rRow["stream_display_name"], $rCategory, $rButtons];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "provider_streams") {
    $rOrder = ["`providers`.`name`", "`providers_streams`.`stream_icon`", "`providers_streams`.`stream_display_name`", false];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`providers`.`enabled` = 1 AND `providers`.`status` = 1";
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 4) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`providers`.`name` LIKE ? OR `providers`.`ip` LIKE ? OR `providers_streams`.`stream_display_name` LIKE ? OR `providers_streams`.`stream_id` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["type"])) {
        $rWhere[] = "`providers_streams`.`type` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["type"];
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `providers_streams` LEFT JOIN `providers` ON `providers`.`id` = `providers_streams`.`provider_id` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `providers`.`id`,`providers_streams`.`type`,`providers`.`username`, `providers`.`password`, `providers`.`ssl`, `providers`.`legacy`, `providers`.`hls`, `providers`.`ip`, `providers`.`port`, `providers`.`name`, `providers`.`data`, `providers_streams`.`stream_id`, `providers_streams`.`category_array`, `providers_streams`.`stream_display_name`, `providers_streams`.`stream_icon`,`providers_streams`.`channel_id` FROM `providers_streams` LEFT JOIN `providers` ON `providers`.`id` = `providers_streams`.`provider_id` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    if ($rRow["type"] == "live") {
                        $rStreamURL = ($rRow["ssl"] ? "https" : "http") . "://" . $rRow["ip"] . ":" . $rRow["port"] . "/live/" . $rRow["username"] . "/" . $rRow["password"] . "/" . $rRow["stream_id"] . ($rRow["hls"] ? ".m3u8" : ($rRow["legacy"] ? ".ts" : ""));
                        $rButtons = "<a href=\"javascript: void(0);\" onClick=\"addStream('" . str_replace("'", "\\'", $rStreamURL) . "');\"><button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-check\"></i></button></a>";
                    } else {
                        $rStreamURL = ($rRow["ssl"] ? "https" : "http") . "://" . $rRow["ip"] . ":" . $rRow["port"] . "/movie/" . $rRow["username"] . "/" . $rRow["password"] . "/" . $rRow["stream_id"] . "." . $rRow["channel_id"];
                        $rButtons = "<a href=\"javascript: void(0);\" onClick=\"addStream('" . str_replace("'", "\\'", $rRow["stream_display_name"]) . "', '" . str_replace("'", "\\'", $rStreamURL) . "');\"><button type=\"button\" class=\"btn btn-light waves-effect waves-light btn-xs\"><i class=\"mdi mdi-check\"></i></button></a>";
                    }
                    if (0 < strlen($rRow["stream_icon"]) && $rRow["type"] == "live") {
                        $rIcon = "<img loading='lazy' src='" . $rRow["stream_icon"] . "' height='32px' />";
                    } else {
                        $rIcon = "";
                    }
                    $rProviderData = json_decode($rRow["data"], true);
                    $rExpires = $rProviderData["exp_date"] ?: "Never";
                    $rMaxConnections = $rProviderData["max_connections"] ?: "&infin;";
                    $rProvider = "<span class='tooltip' title='Expires: " . $rExpires . "<br/>Connections: " . $rProviderData["active_connections"] . " / " . $rMaxConnections . "'>" . $rRow["name"] . "</span>";
                    if ($rRow["type"] == "live") {
                        $rReturn["data"][] = [$rIcon, $rRow["stream_display_name"], $rProvider, $rButtons];
                    } else {
                        $rReturn["data"][] = [$rRow["stream_display_name"], $rProvider, $rButtons];
                    }
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "parent_servers") {
    if (!hasPermissions("adv", "servers")) {
        exit;
    }
    if (!isset(CoreUtilities::$rServers[CoreUtilities::$rRequest["proxy_id"]]) || count(CoreUtilities::$rServers[CoreUtilities::$rRequest["proxy_id"]]["parent_id"]) == 0) {
        echo json_encode($rReturn);
        exit;
    }
    $rOrder = ["`id`", "`server_name`", "`server_ip`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`server_type` = 0";
    $rWhere[] = "`id` IN (" . implode(",", array_map("intval", CoreUtilities::$rServers[CoreUtilities::$rRequest["proxy_id"]]["parent_id"])) . ")";
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 2) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`server_name` LIKE ? OR `server_ip` LIKE ?)";
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `servers` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `id`, `server_name`, `server_ip` FROM `servers` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rReturn["data"][] = ["<a href='server_view?id=" . (int) $rRow["id"] . "'>" . $rRow["id"] . "</a>", "<a href='server_view?id=" . (int) $rRow["id"] . "'>" . $rRow["server_name"] . "</a>", $rRow["server_ip"]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "failures_modal") {
    if (!hasPermissions("adv", "streams")) {
        exit;
    }
    $rLimit = 10;
    $rOrderBy = "ORDER BY `date` DESC";
    $rWhere = $rWhereV = [];
    $rWhere[] = "`stream_id` = ?";
    $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
    if (isset(CoreUtilities::$rRequest["server_id"]) && 0 < (int) CoreUtilities::$rRequest["server_id"]) {
        $rWhere[] = "`server_id` = ?";
        $rWhereV[] = CoreUtilities::$rRequest["server_id"];
    }
    $rWhere[] = "`date` >= UNIX_TIMESTAMP()-" . (int) ($rSettings["fails_per_time"] ?: 86400);
    $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_logs` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `server_id`, `action`, `source`, `date` FROM `streams_logs` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rStreamSource = "";
                    if (!empty($rRow["source"])) {
                        $rStreamSource = strtolower(parse_url($rRow["source"])["host"]);
                    }
                    $rReturn["data"][] = ["<a href='server_view?id=" . (int) $rRow["server_id"] . "'>" . CoreUtilities::$rServers[$rRow["server_id"]]["server_name"] . "</a>", $rStreamSource, $rFailureStatusArray[$rRow["action"]], date(CoreUtilities::$rSettings["datetime_format"], $rRow["date"])];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "epg_modal") {
    $rLimit = 10;
    $rEPG = CoreUtilities::getEPG(CoreUtilities::$rRequest["stream_id"], time(), time() + 604800);
    if ($rEPG && $rLimit < count($rEPG)) {
        $rEPG = array_slice($rEPG, 0, $rLimit);
    }
    $rReturn["recordsTotal"] = count($rEPG) ?: 0;
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        foreach ($rEPG as $rRow) {
            if ($rIsAPI) {
                $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
            } else {
                $rReturn["data"][] = [date("H:i:s", $rRow["start"]), $rRow["title"], $rRow["description"]];
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "stream_logs") {
    $rOrder = ["`date`", "`action`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`stream_id` = ?";
    $rWhereV[] = CoreUtilities::$rRequest["stream_id"];
    $rWhere[] = "`date` >= (UNIX_TIMESTAMP()-86400)";
    $rOrderBy = "ORDER BY `date` DESC";
    $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams_logs` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `date`, `action` FROM `streams_logs` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            foreach ($db->get_rows() as $rRow) {
                if ($rIsAPI) {
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rReturn["data"][] = [date("H:i:s", $rRow["date"]), $rStreamLogsArray[$rRow["action"]]];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
} elseif ($rType == "ondemand") {
    if (!hasPermissions("adv", "streams")) {
        exit;
    }
    $rCategories = getCategories("live");
    $rOrder = ["`streams`.`id`", "`streams`.`stream_icon`", "`streams`.`stream_display_name`", "`streams_servers`.`server_id`", "`ondemand_check`.`status`", "`ondemand_check`.`response`", "`ondemand_check`.`resolution`", "`ondemand_check`.`date`"];
    if (isset(CoreUtilities::$rRequest["order"]) && 0 < strlen(CoreUtilities::$rRequest["order"][0]["column"])) {
        $rOrderRow = (int) CoreUtilities::$rRequest["order"][0]["column"];
    } else {
        $rOrderRow = 0;
    }
    $rWhere = $rWhereV = [];
    $rWhere[] = "`streams`.`type` = 1";
    $rWhere[] = "`streams`.`direct_source` = 0";
    $rWhere[] = "`streams_servers`.`on_demand` = 1";
    if (0 < strlen(CoreUtilities::$rRequest["search"]["value"])) {
        foreach (range(1, 6) as $rInt) {
            $rWhereV[] = "%" . CoreUtilities::$rRequest["search"]["value"] . "%";
        }
        $rWhere[] = "(`streams`.`id` LIKE ? OR `streams`.`stream_display_name` LIKE ? OR `ondemand_check`.`fps` LIKE ? OR `ondemand_check`.`resolution` LIKE ? OR `ondemand_check`.`video_codec` LIKE ? OR `ondemand_check`.`audio_codec` LIKE ?)";
    }
    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
        $rWhere[] = "JSON_CONTAINS(`streams`.`category_id`, ?, '\$')";
        $rWhereV[] = CoreUtilities::$rRequest["category"];
    }
    if (0 < strlen(CoreUtilities::$rRequest["filter"])) {
        if (CoreUtilities::$rRequest["filter"] == 1) {
            $rWhere[] = "`ondemand_check`.`status` = 1";
        } elseif (CoreUtilities::$rRequest["filter"] == 2) {
            $rWhere[] = "`ondemand_check`.`status` = 0";
        } elseif (CoreUtilities::$rRequest["filter"] == 3) {
            $rWhere[] = "`ondemand_check`.`status` IS NULL";
        }
    }
    if (0 < (int) CoreUtilities::$rRequest["server"]) {
        $rWhere[] = "`streams_servers`.`server_id` = ?";
        $rWhereV[] = (int) CoreUtilities::$rRequest["server"];
    }
    if ($rOrder[$rOrderRow]) {
        $rOrderDirection = strtolower(CoreUtilities::$rRequest["order"][0]["dir"]) === "desc" ? "desc" : "asc";
        $rOrderBy = "ORDER BY " . $rOrder[$rOrderRow] . " " . $rOrderDirection;
    }
    if (0 < count($rWhere)) {
        $rWhereString = "WHERE " . implode(" AND ", $rWhere);
    } else {
        $rWhereString = "";
    }
    $rCountQuery = "SELECT COUNT(*) AS `count` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `ondemand_check` ON `ondemand_check`.`id` = `streams_servers`.`ondemand_check` " . $rWhereString . ";";
    $db->query($rCountQuery, ...$rWhereV);
    if ($db->num_rows() == 1) {
        $rReturn["recordsTotal"] = $db->get_row()["count"];
    } else {
        $rReturn["recordsTotal"] = 0;
    }
    $rReturn["recordsFiltered"] = ($rIsAPI ? ($rReturn["recordsTotal"] < $rLimit ? $rReturn["recordsTotal"] : $rLimit) : $rReturn["recordsTotal"]);
    if (0 < $rReturn["recordsTotal"]) {
        $rQuery = "SELECT `ondemand_check`.`status` AS `ondemand_status`, `ondemand_check`.`date` AS `ondemand_date`, `ondemand_check`.`errors`, `ondemand_check`.`response`, `ondemand_check`.`resolution`, `ondemand_check`.`fps`, `ondemand_check`.`video_codec`, `ondemand_check`.`audio_codec`, `streams`.`id`, `streams`.`type`, `streams`.`stream_icon`, `streams`.`stream_source`, `streams`.`stream_display_name`, `streams_servers`.`server_id`, `streams`.`llod`, `streams`.`category_id`, (SELECT `server_name` FROM `servers` WHERE `id` = `streams_servers`.`server_id`) AS `server_name` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` LEFT JOIN `ondemand_check` ON `ondemand_check`.`id` = `streams_servers`.`ondemand_check` " . $rWhereString . " " . $rOrderBy . " LIMIT " . $rStart . ", " . $rLimit . ";";
        $db->query($rQuery, ...$rWhereV);
        if (0 < $db->num_rows()) {
            $rRows = $db->get_rows();
            $rUpChecks = $rDownChecks = $rStreamIDs = [];
            foreach ($rRows as $rRow) {
                $rStreamIDs[] = (int) $rRow["id"];
            }
            if (0 < count($rStreamIDs)) {
                $db->query("SELECT `stream_id`, `server_id`, COUNT(*) AS `count` FROM `ondemand_check` WHERE `stream_id` IN (" . implode(",", $rStreamIDs) . ") AND `status` = 1 GROUP BY CONCAT(`stream_id`, '_', `server_id`);");
                foreach ($db->get_rows() as $rRow) {
                    $rUpChecks[(int) $rRow["server_id"]][$rRow["stream_id"]] = $rRow["count"];
                }
                $db->query("SELECT `stream_id`, `server_id`, COUNT(*) AS `count` FROM `ondemand_check` WHERE `stream_id` IN (" . implode(",", $rStreamIDs) . ") AND `status` = 0 GROUP BY CONCAT(`stream_id`, '_', `server_id`);");
                foreach ($db->get_rows() as $rRow) {
                    $rDownChecks[(int) $rRow["server_id"]][$rRow["stream_id"]] = $rRow["count"];
                }
            }
            foreach ($rRows as $rRow) {
                if ($rIsAPI) {
                    unset($rRow["stream_source"]);
                    $rReturn["data"][] = filterrow($rRow, CoreUtilities::$rRequest["show_columns"], CoreUtilities::$rRequest["hide_columns"]);
                } else {
                    $rServerID = (int) $rRow["server_id"];
                    $rCategoryIDs = json_decode($rRow["category_id"], true);
                    if (0 < strlen(CoreUtilities::$rRequest["category"])) {
                        $rCategory = $rCategories[(int) CoreUtilities::$rRequest["category"]]["category_name"] ?: "No Category";
                    } else {
                        $rCategory = $rCategoryIDs[0] ?? null;
                        $rCategory = $rCategories[$rCategory]['category_name'] ?? "No Category";
                    }
                    if (1 < count($rCategoryIDs)) {
                        $rCategory .= " (+" . (count($rCategoryIDs) - 1) . " others)";
                    }
                    $rStreamName = "<a href='stream_view?id=" . $rRow["id"] . "'><strong>" . $rRow["stream_display_name"] . "</strong><br><span style='font-size:11px;'>" . $rCategory . "</span></a>";
                    if ($rRow["server_name"]) {
                        if (hasPermissions("adv", "servers")) {
                            $rServerName = "<a href='server_view?id=" . $rRow["server_id"] . "'>" . $rRow["server_name"] . "</a>";
                        } else {
                            $rServerName = $rRow["server_name"];
                        }
                    } else {
                        $rServerName = "No Server Selected";
                    }
                    if (!empty($rRow["stream_icon"])) {
                        $rIcon = "<a href='javascript: void(0);' onClick='openImage(this);' data-src='resize?maxw=512&maxh=512&url=" . $rRow["stream_icon"] . "'><img loading='lazy' src='resize?maxw=96&maxh=32&url=" . $rRow["stream_icon"] . "' /></a>";
                    } else {
                        $rIcon = "";
                    }
                    if (is_null($rRow["ondemand_status"])) {
                        $rStatus = "<i class=\"text-secondary fas fa-square tooltip\" title=\"Not Scanned\"></i>";
                    } elseif ($rRow["ondemand_status"] == 1) {
                        $rStatus = "<i class=\"text-success fas fa-square tooltip\" title=\"Ready\"></i>";
                    } else {
                        $rStatus = "<i class=\"text-danger fas fa-square tooltip\" title=\"" . (!empty($rRow["errors"]) ? "<strong>Latest Error:</strong><br/>" . str_replace("\"", "\\\"", $rRow["errors"]) : "Down") . "\"></i>";
                    }
                    $rChecks = "<button type=\"button\" class=\"btn btn-dark bg-animate btn-xs waves-effect waves-light no-border\">" . ($rUpChecks[$rServerID][$rRow["id"]] ?: 0) . " <i class=\"mdi mdi-arrow-up-thick\"></i> &nbsp; " . ($rDownChecks[$rServerID][$rRow["id"]] ?: 0) . " <i class=\"mdi mdi-arrow-down-thick\"></i></button>";
                    $rLastCheck = "Never";
                    $rTimeTaken = "<button type='button' class='btn btn-light btn-xs waves-effect waves-light'>--</button>";
                    $rStreamInfoText = "<table style='font-size: 10px;' class='table-data nowrap' align='center'><tbody><tr><td colspan='3'>No information available</td></tr></tbody></table>";
                    if (!is_null($rRow["ondemand_status"])) {
                        if (0 < $rRow["ondemand_date"]) {
                            $rLastCheck = date($rSettings["date_format"], $rRow["ondemand_date"]) . "<br/>" . date("H:i:s", $rRow["ondemand_date"]);
                        }
                        if (0 < $rRow["response"]) {
                            $rTimeTaken = "<button type='button' class='btn btn-light btn-xs waves-effect waves-light'>" . number_format($rRow["response"], 0) . " ms</button>";
                        }
                        if ($rRow["fps"] || $rRow["video_codec"] || $rRow["audio_codec"] || $rRow["resolution"]) {
                            $rStreamInfoText = "<table class='table-data nowrap table-data-120 text-center' align='center'>\r\n                            <tbody>\r\n                                <tr>\r\n                                    <td class='text-success'><i class='mdi mdi-image-size-select-large' data-name='mdi-image-size-select-large'></i></td>\r\n                                    <td class='text-success'><i class='mdi mdi-video' data-name='mdi-video'></i></td>\r\n                                    <td class='text-success'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>\r\n                                    <td class='text-success'><i class='mdi mdi-clock' data-name='mdi-clock'></i></td>\r\n                                </tr>\r\n                                <tr>\r\n                                    <td>" . ($rRow["resolution"] ? $rRow["resolution"] . "p" : "N/A") . "</td>\r\n                                    <td>" . (str_replace("mpeg2video", "mpeg2", $rRow["video_codec"]) ?: "N/A") . "</td>\r\n                                    <td>" . ($rRow["audio_codec"] ?: "N/A") . "</td>\r\n                                    <td>" . ($rRow["fps"] . " FPS" ?: "N/A") . "</td>\r\n                                </tr>\r\n                            </tbody>\r\n                        </table>";
                        }
                    }
                    $rReturn["data"][] = ["<a href='stream_view?id=" . $rRow["id"] . "'>" . $rRow["id"] . "</a>", $rIcon, $rStreamName, $rServerName, $rStatus . " &nbsp; " . $rChecks, $rTimeTaken, $rStreamInfoText, $rLastCheck];
                }
            }
        }
    }
    echo json_encode($rReturn);
    exit;
}
function filterRow($rRow, $rShow, $rHide) {
    if (!$rShow && !$rHide) {
        return $rRow;
    }
    $rReturn = [];
    foreach (array_keys($rRow) as $rKey) {
        if ($rShow) {
            if (in_array($rKey, $rShow)) {
                $rReturn[$rKey] = $rRow[$rKey];
            }
        } elseif ($rHide && !in_array($rKey, $rHide)) {
            $rReturn[$rKey] = $rRow[$rKey];
        }
    }
    return $rReturn;
}

<?php
require str_replace('\\', '/', dirname($argv[0])) . '/../../includes/admin.php';
set_time_limit(0);
ini_set('memory_limit', -1);
if (!$argc) {
    exit(0);
}

$rTableList = array('reg_users', 'users', 'enigma2_devices', 'mag_devices', 'user_output', 'streaming_servers', 'series', 'series_episodes', 'streams', 'streams_sys', 'streams_options', 'stream_categories', 'bouquets', 'member_groups', 'packages', 'rtmp_ips', 'epg', 'blocked_ips', 'blocked_user_agents', 'isp_addon', 'tickets', 'tickets_replies', 'transcoding_profiles', 'watch_folders', 'categories', 'epg_sources', 'members', 'blocked_isps', 'groups', 'servers', 'stream_servers');
$rMigrateOptions = (json_decode(file_get_contents(TMP_PATH . '.migration.options'), true) ?: array());
if (count($rMigrateOptions) == 0) {
    $rMigrateOptions = $rTableList;
}
file_put_contents(TMP_PATH . '.migration.pid', getmypid());
file_put_contents(TMP_PATH . '.migration.status', 1);
$odb = new Database($_INFO['username'], $_INFO['password'], "xc_vm_migrate", $_INFO['hostname'], $_INFO['port'], true);
if (!$odb->connected) {
    echo 'Failed to connect to migration database, or database is empty!' . "\n";
    file_put_contents(TMP_PATH . '.migration.status', 3);
    exit();
}
echo 'Connected to migration database.' . "\n";
$odb->query("SHOW TABLES LIKE 'access_codes';");
if ($odb->num_rows() > 0) {
    echo "\n" . "Can't migrate from XC_VM to XC_VM! Use the restore functions instead." . "\n\n";
    exit();
}

$rItemCount = 0;
foreach ($rTableList as $rTable) {
    $odb->query('SHOW TABLES LIKE ?;', $rTable);
    if (0 >= $odb->num_rows()) {
    } else {
        $odb->query('SELECT COUNT(*) AS `count` FROM `' . $rTable . '`;');
        $rItemCount += (intval($odb->get_row()['count']) ?: 0);
    }
}
if ($rItemCount == 0) {
    echo "\n" . "Couldn't find anything to migrate in the `xc_vm_migrate` database. Please ensure you restore your backup to that database specifically." . "\n\n";
    exit();
}
echo "\n" . 'Migrating database to XC_VM...' . "\n\n";
echo 'Remapping bouquets.' . "\n";
$rSeriesMap = $rBouquetMap = array();
$odb->query('SELECT `id`, `type` FROM `streams`;');
$rStreams = $odb->get_rows();
foreach ($rStreams as $rStream) {
    $rBouquetMap[intval($rStream['id'])] = intval($rStream['type']);
}
$odb->query('SELECT `id` FROM `series`;');
$rSeries = $odb->get_rows();
foreach ($rSeries as $rSeriesArr) {
    $rSeriesMap[] = intval($rSeriesArr['id']);
}
if (in_array('reg_users', $rMigrateOptions)) {
    $odb->query('SELECT COUNT(*) AS `count` FROM `reg_users`;');
    $rCount = $odb->get_row()['count'];
    if ($rCount > 0) {
        $db->query('TRUNCATE `users`;');
        echo 'Adding ' . number_format($rCount, 0) . ' users.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }
        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `reg_users` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult = verifyPostTable('users', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (in_array('members', $rMigrateOptions)) {
    $odb->query('SELECT COUNT(*) AS `count` FROM `members`;');
    $rCount = $odb->get_row()['count'];
    if ($rCount > 0) {
        $db->query('TRUNCATE `users`;');
        echo 'Adding ' . number_format($rCount, 0) . ' users.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `members` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult = verifyPostTable('users', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `users`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('blocked_ips', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `blocked_ips`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `blocked_ips`;');
        echo 'Blocking ' . number_format(count($rResults), 0) . ' IP addresses.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('blocked_ips', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `blocked_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('blocked_user_agents', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `blocked_user_agents`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `blocked_uas`;');
        echo 'Blocking ' . number_format(count($rResults), 0) . ' user-agents.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('blocked_uas', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `blocked_uas`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('isp_addon', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `isp_addon`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `blocked_isps`;');
        echo 'Blocking ' . number_format(count($rResults), 0) . " ISP's." . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('blocked_isps', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `blocked_isps`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('blocked_isps', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `blocked_isps`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `blocked_isps`;');
        echo 'Blocking ' . number_format(count($rResults), 0) . " ISP's." . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('blocked_isps', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `blocked_isps`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('bouquets', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `bouquets`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `bouquets`;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' bouquets.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rChannels = json_decode($rResult['bouquet_channels'], true);
                $rResult['bouquet_radios'] = array();
                $rResult['bouquet_movies'] = $rResult['bouquet_radios'];
                $rResult['bouquet_channels'] = $rResult['bouquet_movies'];
                foreach ($rChannels as $rStreamID) {
                    if (!isset($rBouquetMap[intval($rStreamID)])) {
                    } else {
                        $rType = array(1 => 'channels', 2 => 'movies', 3 => 'channels', 4 => 'radio')[$rBouquetMap[intval($rStreamID)]];
                        if (!$rType) {
                        } else {
                            $rResult['bouquet_' . $rType][] = intval($rStreamID);
                        }
                    }
                }
                $rSeries = json_decode($rResult['bouquet_series'], true);
                $rResult['bouquet_series'] = array();
                foreach ($rSeries as $rSeriesID) {
                    if (!in_array(intval($rSeriesID), $rSeriesMap)) {
                    } else {
                        $rResult['bouquet_series'][] = intval($rSeriesID);
                    }
                }
                foreach (array('channels', 'movies', 'radios', 'series') as $rType) {
                    if ($rResult['bouquet_' . $rType]) {
                    } else {
                        $rResult['bouquet_' . $rType] = '[]';
                    }
                }
                $rResult = verifyPostTable('bouquets', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('enigma2_devices', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `enigma2_devices`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `enigma2_devices`;');
        echo 'Authorising ' . number_format($rCount, 0) . ' enigma devices.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `enigma2_devices` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult['lock_device'] = 1;
                    $rResult = verifyPostTable('enigma2_devices', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `enigma2_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('mag_devices', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `mag_devices`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `mag_devices`;');
        echo 'Authorising ' . number_format($rCount, 0) . ' MAG devices.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `mag_devices` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult['mac'] = base64_decode($rResult['mac']);
                    $rResult['lock_device'] = 1;
                    if (0 >= $rResult['user_id']) {
                    } else {
                        $rResult = verifyPostTable('mag_devices', $rResult);
                        $rPrepare = prepareArray($rResult);
                        $rQuery = 'INSERT INTO `mag_devices`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                        $db->query($rQuery, ...$rPrepare['data']);
                    }
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('epg', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `epg`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `epg`;');
        echo 'Processing ' . number_format(count($rResults), 0) . ' EPG URLs.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('epg', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `epg`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('epg_sources', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `epg_sources`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `epg`;');
        echo 'Processing ' . number_format(count($rResults), 0) . ' EPG URLs.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('epg', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `epg`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('member_groups', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `member_groups` WHERE `can_delete` = 1;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('DELETE FROM `users_groups` WHERE `can_delete` = 1;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' user groups.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult['can_view_vod'] = $rResult['reset_stb_data'];
                $rResult['allow_restrictions'] = 1;
                $rResult['allow_change_username'] = 1;
                $rResult['allow_change_password'] = 1;
                $rResult['minimum_username_length'] = 8;
                $rResult['minimum_password_length'] = 8;
                $rResult = verifyPostTable('users_groups', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('groups', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `groups` WHERE `can_delete` = 1;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('DELETE FROM `users_groups` WHERE `can_delete` = 1;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' user groups.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult['can_view_vod'] = $rResult['reset_stb_data'];
                $rResult['allow_restrictions'] = 1;
                $rResult['allow_change_username'] = 1;
                $rResult['allow_change_password'] = 1;
                $rResult['minimum_username_length'] = 8;
                $rResult['minimum_password_length'] = 8;
                $rResult = verifyPostTable('users_groups', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('groups', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `groups` WHERE `can_delete` = 1;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('DELETE FROM `users_groups` WHERE `can_delete` = 1;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' user groups.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult['can_view_vod'] = $rResult['reset_stb_data'];
                $rResult['allow_restrictions'] = 1;
                $rResult['allow_change_username'] = 1;
                $rResult['allow_change_password'] = 1;
                $rResult['minimum_username_length'] = 8;
                $rResult['minimum_password_length'] = 8;
                $rResult = verifyPostTable('users_groups', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `users_groups`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('packages', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `packages`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `users_packages`;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' user packages.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                if ($rResult['can_gen_mag']) {
                    $rResult['is_mag'] = 1;
                } else {
                    $rResult['is_mag'] = 0;
                }
                if ($rResult['can_gen_e2']) {
                    $rResult['is_e2'] = 1;
                } else {
                    $rResult['is_e2'] = 0;
                }
                if ($rResult['only_mag'] || $rResult['only_e2']) {
                    $rResult['is_line'] = 0;
                } else {
                    $rResult['is_line'] = 1;
                }
                $rResult['lock_device'] = 1;
                $rResult['check_compatible'] = 1;
                if (count(json_decode($rResult['output_formats'], true)) != 0) {
                } else {
                    $rResult['output_formats'] = '[1,2,3]';
                }
                $rResult = verifyPostTable('users_packages', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `users_packages`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('rtmp_ips', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `rtmp_ips`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `rtmp_ips`;');
        echo 'Authorising ' . number_format(count($rResults), 0) . ' RTMP IPs.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('rtmp_ips', $rResult);
                $rResult['push'] = 1;
                $rResult['pull'] = 1;
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `rtmp_ips`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('series', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `series`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams_series`;');
        echo 'Adding ' . number_format($rCount, 0) . ' TV series.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `series` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult['category_id'] = '[' . intval($rResult['category_id']) . ']';
                    $rResult['release_date'] = $rResult['releaseDate'];
                    if ($rResult['tmdb_id'] != 0) {
                    } else {
                        $rResult['tmdb_id'] = null;
                    }
                    $rResult = verifyPostTable('streams_series', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT IGNORE INTO `streams_series`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('series_episodes', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `series_episodes`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams_episodes`;');
        echo 'Adding ' . number_format($rCount, 0) . ' episodes.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `series_episodes` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult['episode_num'] = $rResult['sort'];
                    $rResult = verifyPostTable('streams_episodes', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `streams_episodes`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('streaming_servers', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `streaming_servers`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $rMain = false;
        $db->query('TRUNCATE `servers`;');
        echo 'Moving ' . number_format(count($rResults), 0) . ' servers.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult['server_type'] = 0;
                $rResult['parent_id'] = null;
                $rResult['http_broadcast_port'] = 80;
                $rResult['https_broadcast_port'] = 443;
                $rResult['rtmp_port'] = 8880;
                $rResult['total_services'] = 4;
                $rResult['http_ports_add'] = null;
                $rResult['https_ports_add'] = null;
                if ($rResult['can_delete'] == 0 && !$rMain) {
                    $rResult['is_main'] = 1;
                    $rMain = true;
                } else {
                    $rResult['is_main'] = 0;
                }
                $rResult = verifyPostTable('servers', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('servers', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `servers` ORDER BY `id` ASC;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $rMain = false;
        $db->query('TRUNCATE `servers`;');
        echo 'Moving ' . number_format(count($rResults), 0) . ' servers.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult['server_type'] = 0;
                $rResult['parent_id'] = null;
                $rResult['http_broadcast_port'] = 80;
                $rResult['https_broadcast_port'] = 443;
                $rResult['rtmp_port'] = 8880;
                $rResult['total_services'] = 4;
                $rResult['http_ports_add'] = null;
                $rResult['https_ports_add'] = null;
                if (!$rMain) {
                    $rResult['is_main'] = 1;
                    $rMain = true;
                } else {
                    $rResult['is_main'] = 0;
                }
                $rResult = verifyPostTable('servers', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
$rCreatedOptions = array();
if (!in_array('streams', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `streams`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams`;');
        echo 'Adding ' . number_format($rCount, 0) . ' streams.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `streams` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    try {
                        $rExternal = json_decode($rResult['external_push'], true);
                        if ($rExternal) {
                        } else {
                            $rResult['external_push'] = '{}';
                        }
                        $rResult['category_id'] = '[' . intval($rResult['category_id']) . ']';
                        $rResult['movie_properties'] = $rResult['movie_propeties'];
                        if (!$rResult['target_container']) {
                        } else {
                            list($rResult['target_container']) = json_decode($rResult['target_container'], true);
                        }
                        $rCreatedOptions[$rResult['id']] = $rResult['cchannel_rsources'];
                        $rResult = verifyPostTable('streams', $rResult);
                        $rPrepare = prepareArray($rResult);
                        $rQuery = 'INSERT INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                        $db->query($rQuery, ...$rPrepare['data']);
                    } catch (Exception $e) {
                        echo 'Error: ' . $e . "\n";
                    }
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('streams_options', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `streams_options`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams_options`;');
        echo 'Attributing ' . number_format($rCount, 0) . ' options to streams.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `streams_options` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rResult = verifyPostTable('streams_options', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `streams_options`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('streams_sys', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `streams_sys`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams_servers`;');
        echo 'Allocating ' . number_format($rCount, 0) . ' streams to servers.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `streams_sys` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                if (0 >= count($rResults)) {
                } else {
                    foreach ($rResults as $rResult) {
                        if ($rResult['parent_id'] && $rResult['parent_id'] != 0) {
                        } else {
                            $rResult['parent_id'] = null;
                        }
                        if (!isset($rCreatedOptions[$rResult['stream_id']])) {
                        } else {
                            $rResult['cchannel_rsources'] = $rCreatedOptions[$rResult['stream_id']];
                        }
                        $rResult['custom_ffmpeg'] = '';
                        $rResult['stream_status'] = 0;
                        $rResult['stream_started'] = null;
                        $rResult['monitor_pid'] = null;
                        if ($rResult['pid'] > 0) {
                        } else {
                            $rResult['pid'] = null;
                        }
                        $rResult = verifyPostTable('streams_servers', $rResult);
                        $rPrepare = prepareArray($rResult);
                        $rQuery = 'INSERT INTO `streams_servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                        $db->query($rQuery, ...$rPrepare['data']);
                    }
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('stream_servers', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `stream_servers`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `streams_servers`;');
        echo 'Allocating ' . number_format($rCount, 0) . ' streams to servers.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `stream_servers` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                if (0 >= count($rResults)) {
                } else {
                    foreach ($rResults as $rResult) {
                        if ($rResult['parent_id'] && $rResult['parent_id'] != 0) {
                        } else {
                            $rResult['parent_id'] = null;
                        }
                        $rResult['stream_status'] = 0;
                        $rResult['stream_started'] = null;
                        $rResult['monitor_pid'] = null;
                        if ($rResult['pid'] > 0) {
                        } else {
                            $rResult['pid'] = null;
                        }
                        $rResult = verifyPostTable('streams_servers', $rResult);
                        $rPrepare = prepareArray($rResult);
                        $rQuery = 'INSERT INTO `streams_servers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                        $db->query($rQuery, ...$rPrepare['data']);
                    }
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('stream_categories', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `stream_categories`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `streams_categories`;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' categories.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('streams_categories', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('categories', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `categories`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `streams_categories`;');
        echo 'Creating ' . number_format(count($rResults), 0) . ' categories.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('streams_categories', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('tickets', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `tickets`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `tickets`;');
        echo 'Posting ' . number_format(count($rResults), 0) . ' tickets.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('tickets', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `tickets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('tickets_replies', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `tickets_replies`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `tickets_replies`;');
        echo 'Posting ' . number_format(count($rResults), 0) . ' replies.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('tickets_replies', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `tickets_replies`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('transcoding_profiles', $rMigrateOptions)) {
} else {
    $odb->query('SELECT * FROM `transcoding_profiles`;');
    $rResults = $odb->get_rows();
    if (0 >= count($rResults)) {
    } else {
        $db->query('TRUNCATE `profiles`;');
        echo 'Generating ' . number_format(count($rResults), 0) . ' transcoding profiles.' . "\n";
        foreach ($rResults as $rResult) {
            try {
                $rResult = verifyPostTable('profiles', $rResult);
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `profiles`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
$rOutput = array();
if (!in_array('user_output', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `user_output`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        echo 'Attributing ' . number_format($rCount, 0) . ' output options to lines.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `user_output` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    $rOutput[$rResult['user_id']][] = $rResult['access_output_id'];
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
    }
}
if (!in_array('users', $rMigrateOptions)) {
} else {
    $odb->query('SELECT COUNT(*) AS `count` FROM `users`;');
    $rCount = $odb->get_row()['count'];
    if (0 >= $rCount) {
    } else {
        $db->query('TRUNCATE `lines`;');
        echo 'Adding ' . number_format($rCount, 0) . ' lines.' . "\n";
        $rSteps = [];
        $stepSize = 1000;
        for ($i = 0; $i < $rCount; $i += $stepSize) {
            $rSteps[] = $i;
        }
        if (empty($rSteps)) {
            $rSteps = [0];
        }

        foreach ($rSteps as $rStep) {
            try {
                $odb->query('SELECT * FROM `users` LIMIT ' . $rStep . ', 1000;');
                $rResults = $odb->get_rows();
                foreach ($rResults as $rResult) {
                    if (!empty($rResult['isp_desc'])) {
                    } else {
                        $rResult['isp_desc'] = null;
                    }
                    if (!isset($rOutput[$rResult['id']])) {
                    } else {
                        $rResult['allowed_outputs'] = '[' . implode(',', $rOutput[$rResult['id']]) . ']';
                    }
                    if (!isset($rResult['output'])) {
                    } else {
                        $rResult['allowed_outputs'] = $rResult['output'];
                    }
                    $rResult['bouquet'] = '[' . implode(',', array_map('intval', json_decode($rResult['bouquet'], true))) . ']';
                    $rResult = verifyPostTable('lines', $rResult);
                    $rPrepare = prepareArray($rResult);
                    $rQuery = 'INSERT INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                    $db->query($rQuery, ...$rPrepare['data']);
                }
            } catch (Exception $e) {
                echo 'Error: ' . $e . "\n";
            }
        }
        try {
        } catch (Exception $e) {
            echo 'Error: ' . $e . "\n";
        }
    }
}
if (!in_array('watch_folders', $rMigrateOptions)) {
} else {
    $odb->query("SHOW TABLES LIKE 'watch_folders';");
    if (0 >= $odb->num_rows()) {
    } else {
        $odb->query('SELECT COUNT(*) AS `count` FROM `watch_folders`;');
        $rCount = $odb->get_row()['count'];
        if (0 >= $rCount) {
        } else {
            $db->query('TRUNCATE `watch_folders`;');
            echo 'Adding ' . number_format($rCount, 0) . ' folders to watch.' . "\n";
            $odb->query('SELECT * FROM `watch_folders`;');
            $rResults = $odb->get_rows();
            foreach ($rResults as $rResult) {
                $rResult = verifyPostTable('watch_folders', $rResult);
                $rResult['bouquets'] = '[' . implode(',', array_map('intval', json_decode($rResult['bouquets'], true))) . ']';
                $rResult['fb_bouquets'] = '[' . implode(',', array_map('intval', json_decode($rResult['fb_bouquets'], true))) . ']';
                $rPrepare = prepareArray($rResult);
                $rQuery = 'INSERT INTO `watch_folders`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
                $db->query($rQuery, ...$rPrepare['data']);
            }
        }
    }
}
try {
    $odb->query('SELECT * FROM `settings` LIMIT 1;');
    $rSettings = $odb->get_row();
    $db->query('UPDATE `settings` SET `server_name` = ?, `default_timezone` = ?;', $rSettings['server_name'], $rSettings['default_timezone']);
} catch (Exception $e) {
    echo 'Error: ' . $e . "\n";
}
try {
    $odb->query("SHOW TABLES LIKE 'admin_settings';");
    if (0 >= $odb->num_rows()) {
    } else {
        $rAdminSettings = array();
        $odb->query('SELECT * FROM `admin_settings`;');
        foreach ($odb->get_rows() as $rRow) {
            $rAdminSettings[$rRow['type']] = $rRow['value'];
        }
        if (!(0 < strlen($rAdminSettings['recaptcha_v2_secret_key']) && 0 < strlen($rAdminSettings['recaptcha_v2_site_key']))) {
        } else {
            $db->query('UPDATE `settings` SET `recaptcha_v2_secret_key` = ?, `recaptcha_v2_site_key` = ?;', $rAdminSettings['recaptcha_v2_secret_key'], $rAdminSettings['recaptcha_v2_site_key']);
        }
    }
} catch (Exception $e) {
    echo 'Error: ' . $e . "\n";
}
echo "\n" . 'Migration has been completed!' . "\n\n" . 'Your settings have been reset to the XC_VM default, please take some time to review the settings page and make the desired changes.' . "\n";
file_put_contents(TMP_PATH . '.migration.status', 2);
if (is_object($odb)) {
    $odb->close_mysql();
}

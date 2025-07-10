<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'root') {
    set_time_limit(0);
    if ($argc) {
        require str_replace('\\', '/', dirname($argv[0])) . '/../includes/admin.php';
        cli_set_process_title('XC_VM[MysqlErrors]');
        $rIdentifier = CRONS_TMP_PATH . md5(CoreUtilities::generateUniqueCode() . __FILE__);
        CoreUtilities::checkCron($rIdentifier);
        $rIgnoreErrors = array('innodb: page_cleaner', 'aborted connection', 'got an error reading communication packets', 'got packets out of order', 'got timeout reading communication packets');
        if (0 >= CoreUtilities::$rSettings['mysql_sleep_kill']) {
        } else {
            $db->query("SELECT `id` FROM `INFORMATION_SCHEMA`.`PROCESSLIST` WHERE `COMMAND` = 'Sleep' AND `TIME` > ?;", intval(CoreUtilities::$rSettings['mysql_sleep_kill']));
            foreach ($db->get_rows() as $rRow) {
                $db->query('KILL ?;', $rRow['id']);
            }
        }
        $db->query('SELECT MAX(`date`) AS `date` FROM `mysql_syslog`;');
        $rMaxTime = intval($db->get_row()['date']);
        $rMaxAttempts = 10;
        $rAttempts = array();
        $db->query("SELECT `mysql_syslog`.`ip`, COUNT(`mysql_syslog`.`id`) AS `count`, `blocked_ips`.`id` AS `block_id` FROM `mysql_syslog` LEFT JOIN `blocked_ips` ON `blocked_ips`.`ip` = `mysql_syslog`.`ip` WHERE `type` = 'AUTH' AND `mysql_syslog`.`date` > UNIX_TIMESTAMP() - 86400 GROUP BY `mysql_syslog`.`ip`;");
        foreach ($db->get_rows() as $rRow) {
            $rAttempts[$rRow['ip']] = $rRow['count'];
            if ($rMaxAttempts >= $rRow['count'] || $rRow['block_id']) {
            } else {
                if (in_array($rRow['ip'], CoreUtilities::getAllowedIPs())) {
                } else {
                    echo 'Blocking IP ' . $rRow['ip'] . "\n";
                    API::blockIP(array('ip' => $rRow['ip'], 'notes' => 'MYSQL BRUTEFORCE ATTACK'));
                }
            }
        }
        exec('sudo tail -n 1000 /var/log/syslog | grep mysqld', $rOutput, $rRetVal);
        foreach ($rOutput as $rError) {
            $rStrip = trim(explode(']:', explode('mysqld[', $rError)[1])[1]);
            $rTime = strtotime(substr($rStrip, 0, 19));
            if ($rMaxTime >= $rTime) {
            } else {
                if (!(empty($rStrip) || inArray($rIgnoreErrors, $rStrip))) {
                    if (stripos($rStrip, '[Note]') !== false) {
                        $rNote = trim(explode('[Note]', $rStrip)[1]);
                        $rType = 'NOTICE';
                    } else {
                        if (stripos($rStrip, '[Warning]') !== false) {
                            $rNote = trim(explode('[Warning]', $rStrip)[1]);
                            $rType = 'WARNING';
                        } else {
                            if (stripos($rStrip, '[Error]') === false) {
                            } else {
                                $rNote = trim(explode('[Error]', $rStrip)[1]);
                                $rType = 'ERROR';
                            }
                        }
                    }
                    if (!$rNote) {
                    } else {
                        $rUsername = null;
                        $rHost = null;
                        $rDatabase = null;
                        if (stripos($rNote, 'access denied for user') === false) {
                        } else {
                            $rUsername = trim(explode("'", explode("user '", $rNote)[1])[0]);
                            $rHost = trim(explode("'", explode("user '", $rNote)[1])[2]);
                            $rType = 'AUTH';
                        }
                        if (stripos($rNote, 'user:') === false) {
                        } else {
                            $rUsername = trim(explode("'", explode("user: '", $rNote)[1])[0]);
                            $rHost = trim(explode("'", explode("host: '", $rNote)[1])[0]);
                            $rDatabase = trim(explode("'", explode("db: '", $rNote)[1])[0]);
                            $rType = 'ABORTED';
                        }
                        $db->query('INSERT INTO `mysql_syslog`(`type`,`error`,`username`,`ip`,`database`,`date`) VALUES(?,?,?,?,?,?)', $rType, $rNote, $rUsername, $rHost, $rDatabase, $rTime);
                    }
                }
            }
        }
        @unlink($rIdentifier);
    } else {
        exit(0);
    }
} else {
    exit('Please run as root!' . "\n");
}
function inArray($needles, $haystack) {
    foreach ($needles as $needle) {
        if (!stristr($haystack, $needle)) {
        } else {
            return true;
        }
    }
    return false;
}

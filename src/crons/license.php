<?php
if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
    if ($argc) {
        if (isrunning()) {
            $rConfig = parse_ini_string(file_get_contents('/home/xc_vm/config/config.ini'));
            if ((!isset($rConfig['is_lb']) || !$rConfig['is_lb'])) {
                $rPort = (intval(explode(';', explode(' ', trim(explode('listen ', file_get_contents('/home/xc_vm/bin/nginx/conf/ports/http.conf'))[1]))[0])[0]) ?: 80);
            }

            require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
            $rUpdate = json_decode(str_replace('<', '&lt;', str_replace('>', '&gt;', file_get_contents('https://update.xc_vm.com/update.json', false, stream_context_create(array('http' => array('timeout' => 5)))))), true);
            if (is_array($rUpdate) && $rUpdate['version'] && (0 < version_compare($rUpdate['version'], XC_VM_VERSION) || version_compare($rUpdate['version'], XC_VM_VERSION) == 0 && intval(XC_VM_REVISION) < intval($rUpdate['revision']))) {
                echo 'Update is available!' . "\n";
                $updatedChanges = array();
                foreach ($rUpdate['changelog'] as $rItem) {
                    if (!($rItem['version'] == XC_VM_VERSION && intval($rItem['revision']) <= intval(XC_VM_REVISION))) {
                        $updatedChanges[] = $rItem;
                    } else {
                        break;
                    }
                }
                $rUpdate['changelog'] = $updatedChanges;
                // $db->query('UPDATE `settings` SET `update_data` = ?;', json_encode($rUpdate));
            } else {
                $db->query('UPDATE `settings` SET `update_data` = NULL;');
            }
        }
    } else {
        exit(0);
    }
} else {
    exit('Please run as XC_VM!' . "\n");
}
function isrunning() {
    $rNginx = 0;
    exec('ps -fp $(pgrep -u xc_vm)', $rOutput, $rReturnVar);
    foreach ($rOutput as $rProcess) {
        $rSplit = explode(' ', preg_replace('!\\s+!', ' ', trim($rProcess)));
        if ($rSplit[8] == 'nginx:' && $rSplit[9] == 'master') {
            $rNginx++;
        }
    }
    return 0 < $rNginx;
}

<?php

require_once __DIR__ . '/../CronTrait.php';

class UpdateCronJob implements CommandInterface {
    use CronTrait;

    public function getName(): string {
        return 'cron:update';
    }

    public function getDescription(): string {
        return 'Cron: check for XC_VM updates';
    }

    public function execute(array $rArgs): int {
        if (!$this->assertRunAsXcVm()) {
            return 1;
        }

        if (!$this->isRunning()) {
            return 1;
        }

        global $db, $gitRelease;

        $rConfig = parse_ini_string(file_get_contents('/home/xc_vm/config/config.ini'));
        if (!isset($rConfig['is_lb']) || !$rConfig['is_lb']) {
            $rPort = (intval(explode(';', explode(' ', trim(explode('listen ', file_get_contents('/home/xc_vm/bin/nginx/conf/ports/http.conf'))[1]))[0])[0]) ?: 80);
        }

        $rUpdate = $gitRelease->getUpdate(XC_VM_VERSION);

        if (is_array($rUpdate) && $rUpdate['version'] && (0 < version_compare($rUpdate['version'], XC_VM_VERSION) || version_compare($rUpdate['version'], XC_VM_VERSION) == 0)) {
            echo 'Update is available!' . "\n";
            $updatedChanges = array();
            foreach (array_reverse($rUpdate['changelog']) as $rItem) {
                if (!($rItem['version'] == XC_VM_VERSION)) {
                    $updatedChanges[] = $rItem;
                } else {
                    break;
                }
            }
            $rUpdate['changelog'] = $updatedChanges;
            $db->query('UPDATE `settings` SET `update_data` = ?;', json_encode($rUpdate));
        } else {
            $db->query('UPDATE `settings` SET `update_data` = NULL;');
        }

        return 0;
    }

    private function isRunning(): bool {
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
}

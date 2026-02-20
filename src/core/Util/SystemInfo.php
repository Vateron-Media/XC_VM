<?php

/**
 * XC_VM — System Information
 *
 * Collects server hardware/OS metrics: CPU, memory, disk, network,
 * GPU, I/O, audio/video devices.
 *
 * ---------------------------------------------------------------
 * What it replaces (CoreUtilities):
 * ---------------------------------------------------------------
 *   getStats()             — aggregate server stats
 *   getTotalCPU()          — total CPU usage via ps
 *   getMemory()            — memory info from /proc/meminfo
 *   getUptime()            — uptime from /proc/uptime
 *   getNetworkInterfaces() — list of network interfaces
 *   getNetwork()           — network I/O from cached file
 *   getVideoDevices()      — V4L2 video devices
 *   getAudioDevices()      — ALSA audio devices
 *   getIO()                — iostat JSON
 *   getGPUInfo()           — nvidia-smi GPU data
 *
 * @see CoreUtilities::getStats()
 */

class SystemInfo {

    /**
     * Aggregate server statistics.
     *
     * Collects CPU, memory, disk, network, GPU, I/O, audio/video devices.
     * Requires CoreUtilities::$rServers[SERVER_ID] for network interface selection.
     *
     * @return array<string, mixed>
     */
    public static function getStats() {
        $rJSON = array();
        $rJSON['cpu'] = round(self::getTotalCPU(), 2);
        $rJSON['cpu_cores'] = intval(shell_exec('cat /proc/cpuinfo | grep "^processor" | wc -l'));
        $rJSON['cpu_avg'] = round((sys_getloadavg()[0] * 100) / (($rJSON['cpu_cores'] ?: 1)), 2);
        $rJSON['cpu_name'] = trim(shell_exec("cat /proc/cpuinfo | grep 'model name' | uniq | awk -F: '{print \$2}'"));
        if ($rJSON['cpu_avg'] > 100) {
            $rJSON['cpu_avg'] = 100;
        }
        $rMemInfo = self::getMemory();
        $rJSON['total_mem'] = $rMemInfo['total'];
        $rJSON['total_mem_free'] = $rMemInfo['free'];
        $rJSON['total_mem_used'] = $rMemInfo['used'];
        $rJSON['total_mem_used_percent'] = round(($rJSON['total_mem_used'] / $rJSON['total_mem']) * 100, 2);
        $rJSON['total_disk_space'] = disk_total_space(MAIN_HOME);
        $rJSON['free_disk_space'] = disk_free_space(MAIN_HOME);
        $rJSON['kernel'] = trim(shell_exec('uname -r'));
        $rJSON['uptime'] = self::getUptime();
        $rJSON['total_running_streams'] = (int) trim(shell_exec('ps ax | grep -v grep | grep -c ffmpeg'));
        $rJSON['bytes_sent'] = 0;
        $rJSON['bytes_sent_total'] = 0;
        $rJSON['bytes_received'] = 0;
        $rJSON['bytes_received_total'] = 0;
        $rJSON['network_speed'] = 0;
        $rJSON['interfaces'] = self::getNetworkInterfaces();
        $rJSON['network_speed'] = 0;
        if ($rJSON['cpu'] > 100) {
            $rJSON['cpu'] = 100;
        }
        if ($rJSON['total_mem'] < $rJSON['total_mem_used']) {
            $rJSON['total_mem_used'] = $rJSON['total_mem'];
        }
        if ($rJSON['total_mem_used_percent'] > 100) {
            $rJSON['total_mem_used_percent'] = 100;
        }

        // Network interface selection: delegate to CoreUtilities::$rServers for backward compat
        $rNetworkInterface = null;
        if (defined('SERVER_ID') && isset(CoreUtilities::$rServers[SERVER_ID]['network_interface'])) {
            $rNetworkInterface = CoreUtilities::$rServers[SERVER_ID]['network_interface'] == 'auto'
                ? null
                : CoreUtilities::$rServers[SERVER_ID]['network_interface'];
        }
        $rJSON['network_info'] = self::getNetwork($rNetworkInterface);

        foreach ($rJSON['network_info'] as $rInterface => $rData) {
            if (file_exists('/sys/class/net/' . $rInterface . '/speed')) {
                $NetSpeed = intval(file_get_contents('/sys/class/net/' . $rInterface . '/speed'));
                if (0 < $NetSpeed && $rJSON['network_speed'] == 0) {
                    $rJSON['network_speed'] = $NetSpeed;
                }
            }
            $rJSON['bytes_sent_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
            $rJSON['bytes_received_total'] = (intval(trim(file_get_contents('/sys/class/net/' . $rInterface . '/statistics/tx_bytes'))) ?: 0);
            $rJSON['bytes_sent'] += $rData['out_bytes'];
            $rJSON['bytes_received'] += $rData['in_bytes'];
        }
        $rJSON['audio_devices'] = array();
        $rJSON['video_devices'] = $rJSON['audio_devices'];
        $rJSON['gpu_info'] = $rJSON['video_devices'];
        $rJSON['iostat_info'] = $rJSON['gpu_info'];
        if (shell_exec('which iostat')) {
            $rJSON['iostat_info'] = self::getIO();
        }
        if (shell_exec('which nvidia-smi')) {
            $rJSON['gpu_info'] = self::getGPUInfo();
        }
        if (shell_exec('which v4l2-ctl')) {
            $rJSON['video_devices'] = self::getVideoDevices();
        }
        if (shell_exec('which arecord')) {
            $rJSON['audio_devices'] = self::getAudioDevices();
        }
        list($rJSON['cpu_load_average']) = sys_getloadavg();
        return $rJSON;
    }

    /**
     * Get total CPU usage as percentage (sum of all processes / core count).
     *
     * @return float CPU usage percentage
     */
    public static function getTotalCPU() {
        $rTotalLoad = 0;
        exec('ps -Ao pid,pcpu', $processes);
        foreach ($processes as $process) {
            $cols = explode(' ', preg_replace('!\\s+!', ' ', trim($process)));
            if (count($cols) >= 2 && is_numeric($cols[1])) {
                $rTotalLoad += floatval($cols[1]);
            }
        }

        $cpuCores = 1;
        $coreCount = intval(shell_exec("grep -P '^processor' /proc/cpuinfo|wc -l"));
        if ($coreCount > 0) {
            $cpuCores = $coreCount;
        }

        if ($cpuCores <= 0) {
            $cpuCores = 1;
        }

        return $rTotalLoad / $cpuCores;
    }

    /**
     * Get memory info from /proc/meminfo.
     *
     * @return array{total: int, free: int, used: int} Memory in kB
     */
    public static function getMemory() {
        try {
            $rFree = explode("\n", file_get_contents('/proc/meminfo'));
            $rMemory = array();

            foreach ($rFree as $rLine) {
                if (empty($rLine)) continue;

                $rParts = preg_split('/\s+/', trim($rLine));
                if (count($rParts) >= 2) {
                    $rKey = rtrim($rParts[0], ':');
                    $rValue = intval($rParts[1]);
                    $rMemory[$rKey] = $rValue;
                }
            }

            if (isset($rMemory['MemTotal'], $rMemory['MemAvailable'])) {
                return array(
                    'total' => $rMemory['MemTotal'],
                    'free'  => $rMemory['MemAvailable'],
                    'used'  => $rMemory['MemTotal'] - $rMemory['MemAvailable']
                );
            }

            return array('total' => 0, 'free' => 0, 'used' => 0);
        } catch (Exception $e) {
            return array('total' => 0, 'free' => 0, 'used' => 0);
        }
    }

    /**
     * Get system uptime as human-readable string.
     *
     * @return string e.g., "5d 3h 12m 4s"
     */
    public static function getUptime() {
        if (!(file_exists('/proc/uptime') && is_readable('/proc/uptime'))) {
            return '';
        }
        $tmp = explode(' ', file_get_contents('/proc/uptime'));
        return TimeUtils::secondsToTime(intval($tmp[0]));
    }

    /**
     * List network interfaces (excluding lo and bond*).
     *
     * @return string[]
     */
    public static function getNetworkInterfaces() {
        $rReturn = array();
        exec('ls /sys/class/net/', $rOutput, $rReturnVar);
        foreach ($rOutput as $rInterface) {
            $rInterface = trim(rtrim($rInterface, ':'));
            if (!($rInterface != 'lo' && substr($rInterface, 0, 4) != 'bond')) {
            } else {
                $rReturn[] = $rInterface;
            }
        }
        return $rReturn;
    }

    /**
     * Read cached network I/O statistics.
     *
     * @param string|null $rInterface  Specific interface name, or null for all
     * @return array<string, array{in_bytes: int, in_packets: int, in_errors: int, out_bytes: int, out_packets: int, out_errors: int}>
     */
    public static function getNetwork($rInterface = null) {
        $rReturn = array();
        if (file_exists(LOGS_TMP_PATH . 'network')) {
            $rNetwork = json_decode(file_get_contents(LOGS_TMP_PATH . 'network'), true);
            foreach ($rNetwork as $rLine) {
                if (!($rInterface && $rLine[0] != $rInterface) && !($rLine[0] == 'lo' || !$rInterface && substr($rLine[0], 0, 4) == 'bond')) {
                    $rReturn[$rLine[0]] = array('in_bytes' => intval($rLine[1] / 2), 'in_packets' => $rLine[2], 'in_errors' => $rLine[3], 'out_bytes' => intval($rLine[4] / 2), 'out_packets' => $rLine[5], 'out_errors' => $rLine[6]);
                }
            }
        }
        return $rReturn;
    }

    /**
     * List V4L2 video capture devices.
     *
     * @return array<int, array{name: string, video_device: string}>
     */
    public static function getVideoDevices() {
        $rReturn = array();
        $rID = 0;
        try {
            $rDevices = array_values(array_filter(explode("\n", shell_exec('v4l2-ctl --list-devices'))));
            if (is_array($rDevices)) {
                foreach ($rDevices as $rKey => $rValue) {
                    if ($rKey % 2 == 0) {
                        $rReturn[$rID]['name'] = $rValue;
                        list(, $rReturn[$rID]['video_device']) = explode('/dev/', $rDevices[$rKey + 1]);
                        $rID++;
                    }
                }
            }
        } catch (Exception $e) {
        }
        return $rReturn;
    }

    /**
     * List ALSA audio recording devices.
     *
     * @return string[]
     */
    public static function getAudioDevices() {
        try {
            return array_filter(explode("\n", shell_exec('arecord -L | grep "hw:CARD="')));
        } catch (Exception $e) {
            return array();
        }
    }

    /**
     * Get I/O statistics via iostat (JSON mode).
     *
     * @return array
     */
    public static function getIO() {
        exec('iostat -o JSON -m', $rOutput, $rReturnVar);
        $rOutput = implode('', $rOutput);
        $rJSON = json_decode($rOutput, true);
        if (isset($rJSON['sysstat'])) {
            return $rJSON['sysstat']['hosts'][0]['statistics'][0];
        }
        return array();
    }

    /**
     * Get GPU information via nvidia-smi (XML mode).
     *
     * @return array
     */
    public static function getGPUInfo() {
        exec('nvidia-smi -x -q', $rOutput, $rReturnVar);
        $rOutput = implode('', $rOutput);
        if (stripos($rOutput, '<?xml') === false) {
        } else {
            $rJSON = json_decode(json_encode(simplexml_load_string($rOutput)), true);
            if (!isset($rJSON['driver_version'])) {
            } else {
                $rGPU = array('attached_gpus' => $rJSON['attached_gpus'], 'driver_version' => $rJSON['driver_version'], 'cuda_version' => $rJSON['cuda_version'], 'gpus' => array());
                if (!isset($rJSON['gpu']['board_id'])) {
                } else {
                    $rJSON['gpu'] = array($rJSON['gpu']);
                }
                foreach ($rJSON['gpu'] as $rInstance) {
                    $rArray = array('name' => $rInstance['product_name'], 'power_readings' => $rInstance['power_readings'], 'utilisation' => $rInstance['utilization'], 'memory_usage' => $rInstance['fb_memory_usage'], 'fan_speed' => $rInstance['fan_speed'], 'temperature' => $rInstance['temperature'], 'clocks' => $rInstance['clocks'], 'uuid' => $rInstance['uuid'], 'id' => intval($rInstance['pci']['pci_device']), 'processes' => array());
                    foreach ($rInstance['processes']['process_info'] as $rProcess) {
                        $rArray['processes'][] = array('pid' => intval($rProcess['pid']), 'memory' => $rProcess['used_memory']);
                    }
                    $rGPU['gpus'][] = $rArray;
                }
                return $rGPU;
            }
        }
        return array();
    }
}

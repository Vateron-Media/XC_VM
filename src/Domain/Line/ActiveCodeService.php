<?php

namespace XcVm\Domain\Line;

use XcVm\Core\Config\DomainResolver;
use XcVm\Domain\User\UserRepository;

/**
 * ActiveCodeService — Native Smart Activation Codes System
 *
 * Implements delayed activation (stock mode), automated subscriber account pairing,
 * collision-free cryptographic code generation, transactional credit management,
 * mass edits, and scratch-card export.
 *
 * @package XC_VM_Domain_Line
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ActiveCodeService {
    use \XcVm\Infrastructure\Database\DatabaseAware;

    /**
     * Generate unique collision-free code string.
     */
    public static function generateCodeString(int $length = 10, string $type = 'alphanumeric'): string {
        $db = self::db();
        $chars = ($type === 'numeric')
            ? '0123456789'
            : '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; // Exclude visually ambiguous characters (0, O, 1, I)

        $charsLen = strlen($chars);
        do {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, $charsLen - 1)];
            }
            $db->query('SELECT `id` FROM `activation_codes` WHERE `activation_code` = ? LIMIT 1;', $code);
        } while ($db->num_rows() > 0);

        return $code;
    }

    /**
     * Generate unique batch name.
     */
    public static function generateBatchName(): string {
        return 'BATCH-' . date('Ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
    }

    /**
     * Generate single or bulk active codes with transaction safety.
     *
     * @param array $data Input form parameters
     * @param array $user Authenticated user
     * @param bool  $isAdmin Is administrator
     * @return array Result array with status, message, count, and codes
     */
    public static function generateCodes(array $data, array $user, bool $isAdmin): array {
        $db = self::db();

        $qty = max(1, min(500, intval($data['num_codes'] ?? 1)));
        $length = max(6, min(24, intval($data['code_length'] ?? 10)));
        $format = ($data['code_format'] ?? 'alphanumeric') === 'numeric' ? 'numeric' : 'alphanumeric';

        $packageId = intval($data['package_id'] ?? 0);
        $package = PackageService::getById($packageId);
        if (!$package) {
            return ['status' => 'ERROR', 'message' => 'Invalid package selected.'];
        }

        // Calculate credit cost per code
        $isTrial = !empty($package['is_trial']) || !empty($data['is_trial']);
        if ($isTrial) {
            $costPerCode = floatval($package['trial_credits'] ?? 0);
        } else {
            // Check for reseller custom package override
            $override = json_decode($user['override_packages'] ?? '', true) ?: [];
            if (isset($override[$packageId]['official_credits']) && strlen((string)$override[$packageId]['official_credits']) > 0) {
                $costPerCode = floatval($override[$packageId]['official_credits']);
            } else {
                $costPerCode = floatval($package['official_credits'] ?? 0);
            }
        }

        $totalCost = $qty * $costPerCode;

        // Balance check for non-admin
        if (!$isAdmin) {
            $currentCredits = floatval($user['credits'] ?? 0);
            if ($totalCost > $currentCredits) {
                return [
                    'status' => 'INSUFFICIENT_CREDITS',
                    'message' => "Insufficient balance. Required: {$totalCost} credits, Available: {$currentCredits} credits."
                ];
            }
        }

        // Target owner for codes
        $targetOwnerId = $user['id'];
        if ($isAdmin && !empty($data['created_by'])) {
            $targetOwnerId = intval($data['created_by']);
        }

        $batchName = trim($data['batch_name'] ?? '');
        if (empty($batchName)) {
            $batchName = self::generateBatchName();
        }

        // Bouquets determination
        if (!empty($data['bouquets_selected']) && is_array($data['bouquets_selected'])) {
            $selectedBouquets = array_map('intval', $data['bouquets_selected']);
        } else {
            $selectedBouquets = json_decode((string)($package['bouquets'] ?? '[]'), true) ?: [];
        }
        $bouquetsJson = '[' . implode(',', array_map('intval', $selectedBouquets)) . ']';

        $dnsBase = trim($data['dns_base'] ?? '') ?: null;
        $forcedCountry = trim($data['forced_country'] ?? '') ?: ($package['forced_country'] ?? null);
        $maxConnections = intval($data['max_connections'] ?? ($package['max_connections'] ?: 1));
        $isAdult = !empty($data['is_adult']) ? 1 : 0;
        $outputFormats = $package['output_formats'] ?? '[]';

        $generatedCodes = [];

        $db->beginTransaction();
        try {
            // 1. Deduct reseller credits if non-admin
            if (!$isAdmin && $totalCost > 0) {
                $newCredits = floatval($user['credits']) - $totalCost;
                $db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $newCredits, $user['id']);

                // Audit logging
                $db->query(
                    "INSERT INTO `users_credits_logs` (`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES (?, ?, ?, ?, ?);",
                    $user['id'],
                    $user['id'],
                    -$totalCost,
                    time(),
                    "Generated {$qty} active codes for package: {$package['package_name']} (Batch: {$batchName})"
                );

                $db->query(
                    "INSERT INTO `users_logs` (`owner`, `type`, `action`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES (?, 'active_code', 'generate', ?, ?, ?, ?, ?);",
                    $user['id'],
                    $packageId,
                    $totalCost,
                    $newCredits,
                    time(),
                    json_encode(['qty' => $qty, 'batch_name' => $batchName, 'package' => $package['package_name']])
                );
            }

            // 2. Generate subscriber lines & activation codes
            for ($i = 0; $i < $qty; $i++) {
                $code = self::generateCodeString($length, $format);

                // Auto-create companion line with frozen countdown (exp_date = NULL)
                $lineUsername = 'ac_' . strtolower(substr(bin2hex(random_bytes(5)), 0, 9));
                $linePassword = substr(bin2hex(random_bytes(6)), 0, 10);

                // Ensure username collision-free
                while (UserRepository::getLineByUsername($lineUsername)) {
                    $lineUsername = 'ac_' . strtolower(substr(bin2hex(random_bytes(5)), 0, 9));
                }

                $db->query(
                    "INSERT INTO `lines` (
                        `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`,
                        `bouquet`, `allowed_outputs`, `max_connections`, `is_restreamer`, `is_trial`,
                        `is_mag`, `is_e2`, `forced_country`, `package_id`, `is_activecode`, `created_at`,
                        `reseller_notes`
                    ) VALUES (?, ?, ?, NULL, 1, 1, ?, ?, ?, 0, ?, 0, 0, ?, ?, 1, ?, ?);",
                    $targetOwnerId,
                    $lineUsername,
                    $linePassword,
                    $bouquetsJson,
                    $outputFormats,
                    $maxConnections,
                    $isTrial ? 1 : 0,
                    $forcedCountry,
                    $packageId,
                    time(),
                    "Active Code: {$code} (Batch: {$batchName})"
                );

                $lineId = (int)$db->last_insert_id();

                // Insert into activation_codes table
                $db->query(
                    "INSERT INTO `activation_codes` (
                        `activation_code`, `batch_name`, `subscriber_id`, `status`, `created_by`,
                        `package_id`, `bouquets`, `is_adult`, `is_trial`, `purchase_cost`,
                        `dns_base`, `forced_country`, `max_connections`, `created_at`
                    ) VALUES (?, ?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);",
                    $code,
                    $batchName,
                    $lineId,
                    $targetOwnerId,
                    $packageId,
                    $bouquetsJson,
                    $isAdult,
                    $isTrial ? 1 : 0,
                    $costPerCode,
                    $dnsBase,
                    $forcedCountry,
                    $maxConnections,
                    time()
                );

                $generatedCodes[] = [
                    'code' => $code,
                    'line_id' => $lineId,
                    'username' => $lineUsername,
                    'password' => $linePassword,
                    'batch_name' => $batchName,
                ];
            }

            $db->commit();

            return [
                'status' => 'SUCCESS',
                'message' => "Successfully generated {$qty} active codes.",
                'batch_name' => $batchName,
                'qty' => $qty,
                'total_cost' => $totalCost,
                'codes' => $generatedCodes,
            ];
        } catch (\Throwable $e) {
            $db->rollback();
            return [
                'status' => 'ERROR',
                'message' => 'Failed to generate codes: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Activate a code or verify an existing active code.
     * Starts the subscription timer countdown on first access (Stock Mode -> Active).
     *
     * @param string $code Activation code
     * @param array $deviceInfo Client device details (mac, device_id, ip, user_agent)
     * @return array Result with status, line info, M3U playlists, XC credentials
     */
    public static function activateCode(string $code, array $deviceInfo = []): array {
        $db = self::db();
        $cleanCode = strtoupper(trim($code));

        $codeRow = self::getByCode($cleanCode);
        if (!$codeRow) {
            return ['status' => 'INVALID_CODE', 'message' => 'Invalid or unknown activation code.'];
        }

        // Revoked or disabled
        if ($codeRow['status'] == 0) {
            return ['status' => 'DISABLED', 'message' => 'This activation code has been suspended or revoked.'];
        }

        $line = UserRepository::getLineById($codeRow['subscriber_id']);
        if (!$line) {
            return ['status' => 'LINE_NOT_FOUND', 'message' => 'Underlying subscription line not found.'];
        }

        $package = PackageService::getById($codeRow['package_id']);
        $now = time();

        // ─── First-Time Activation (Countdown starts now) ───
        if ($codeRow['status'] == 1 || empty($codeRow['activated_at'])) {
            $duration = intval($codeRow['is_trial'] ? ($package['trial_duration'] ?? 1) : ($package['official_duration'] ?? 1));
            $unit = (string)($codeRow['is_trial'] ? ($package['trial_duration_in'] ?? 'days') : ($package['official_duration_in'] ?? 'months'));

            if (!in_array($unit, ['hours', 'days', 'months', 'years'], true)) {
                $unit = 'months';
            }

            $expDate = strtotime("+{$duration} {$unit}", $now);
            $mac = !empty($deviceInfo['mac']) ? trim($deviceInfo['mac']) : null;
            $deviceId = !empty($deviceInfo['device_id']) ? trim($deviceInfo['device_id']) : null;
            $clientIp = $deviceInfo['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);

            // Update activation_codes
            $db->query(
                "UPDATE `activation_codes` SET
                    `status` = 2,
                    `activated_at` = ?,
                    `mac` = COALESCE(?, `mac`),
                    `device_id` = COALESCE(?, `device_id`)
                WHERE `id` = ?;",
                $now,
                $mac,
                $deviceId,
                $codeRow['id']
            );

            // Update companion line
            $db->query(
                "UPDATE `lines` SET
                    `exp_date` = ?,
                    `last_ip` = ?,
                    `last_activity` = ?
                WHERE `id` = ?;",
                $expDate,
                $clientIp,
                $now,
                $line['id']
            );

            $line['exp_date'] = $expDate;
            $codeRow['status'] = 2;
            $codeRow['activated_at'] = $now;
        } else {
            // Already activated: check if expired
            if (!empty($line['exp_date']) && $line['exp_date'] < $now) {
                return [
                    'status' => 'EXPIRED',
                    'message' => 'Subscription has expired.',
                    'exp_date' => $line['exp_date'],
                    'code' => $cleanCode,
                ];
            }

            // Check device lock if enforced
            if (!empty($codeRow['mac']) && !empty($deviceInfo['mac']) && strcasecmp($codeRow['mac'], $deviceInfo['mac']) !== 0) {
                return ['status' => 'DEVICE_MISMATCH', 'message' => 'Code is locked to another hardware device.'];
            }
        }

        // Resolve Portal and M3U URLs
        $portalHost = DomainResolver::resolve(SERVER_ID);
        if (!empty($codeRow['dns_base'])) {
            $portalHost = rtrim($codeRow['dns_base'], '/');
        }
        $portalParsed = parse_url($portalHost);
        $serverDomain = $portalParsed['host'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $serverPort = $portalParsed['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80);

        $m3uHls = "{$portalHost}/get.php?username={$line['username']}&password={$line['password']}&type=m3u_plus&output=hls";
        $m3uTs  = "{$portalHost}/get.php?username={$line['username']}&password={$line['password']}&type=m3u_plus&output=ts";

        $webPlayerUrl = null;
        $db->query("SELECT `code` FROM `access_codes` WHERE `type` = 6 AND `enabled` = 1 LIMIT 1;");
        if ($db->num_rows() > 0) {
            $wpRow = $db->get_row();
            $webPlayerUrl = "{$portalHost}/{$wpRow['code']}/";
        }

        return [
            'status' => 'SUCCESS',
            'code' => $cleanCode,
            'is_new_activation' => ($codeRow['status'] == 2 && ($codeRow['activated_at'] >= ($now - 5))),
            'package_name' => $package['package_name'] ?? 'Premium IPTV',
            'exp_date' => (int)$line['exp_date'],
            'exp_date_formatted' => date('Y-m-d H:i:s', (int)$line['exp_date']),
            'max_connections' => (int)($line['max_connections'] ?? 1),
            'line' => $line,
            'web_player_url' => $webPlayerUrl,
            'credentials' => [
                'server'   => $serverDomain,
                'port'     => $serverPort,
                'host'     => $portalHost,
                'username' => $line['username'],
                'password' => $line['password'],
            ],
            'playlists' => [
                'm3u_hls' => $m3uHls,
                'm3u_ts'  => $m3uTs,
            ],
            'device' => [
                'mac'       => $codeRow['mac'],
                'device_id' => $codeRow['device_id'],
            ],
            'code_details' => $codeRow,
        ];
    }

    /**
     * Look up activation code record by code string.
     */
    public static function getByCode(string $code): ?array {
        $db = self::db();
        $db->query('SELECT * FROM `activation_codes` WHERE `activation_code` = ? LIMIT 1;', strtoupper(trim($code)));
        return $db->num_rows() > 0 ? $db->get_row() : null;
    }

    /**
     * Look up activation code record by ID.
     */
    public static function getById(int $id): ?array {
        $db = self::db();
        $db->query('SELECT * FROM `activation_codes` WHERE `id` = ? LIMIT 1;', $id);
        return $db->num_rows() > 0 ? $db->get_row() : null;
    }

    /**
     * Distinct resellers who have created activation codes (reseller filter).
     */
    public static function getResellersWithCodes(): array {
        $db = self::db();
        return $db->fetchAll(
            'SELECT DISTINCT `users`.`id`, `users`.`username`
             FROM `activation_codes`
             INNER JOIN `users` ON `users`.`id` = `activation_codes`.`created_by`
             ORDER BY `users`.`username` ASC;'
        );
    }

    /**
     * Recent distinct batch names (batch filter). Pass a list of creator ids to
     * scope it (reseller view); empty = all batches (admin view).
     */
    public static function getRecentBatchNames(array $createdBy = [], int $limit = 100): array {
        $db = self::db();
        $where = '`batch_name` IS NOT NULL';
        if (!empty($createdBy)) {
            $where = '`created_by` IN (' . implode(',', array_map('intval', $createdBy)) . ') AND ' . $where;
        }
        return $db->fetchAll(
            'SELECT DISTINCT `batch_name` FROM `activation_codes`
             WHERE ' . $where . '
             ORDER BY `created_at` DESC LIMIT ' . (int) $limit . ';'
        );
    }

    /**
     * All resellers with their credit balance, for the creator-assignment
     * dropdown on the admin generate-codes wizard.
     */
    public static function getResellersForAssignment(): array {
        $db = self::db();
        return $db->fetchAll('SELECT `id`, `username`, `credits` FROM `users` ORDER BY `username` ASC;') ?: [];
    }

    /**
     * Multi-action / Mass edit engine.
     */
    public static function massAction(string $action, array $codeIds, array $user, bool $isAdmin, array $extra = []): array {
        $db = self::db();
        if (empty($codeIds)) {
            return ['status' => 'ERROR', 'message' => 'No codes selected.'];
        }

        $cleanIds = array_map('intval', $codeIds);
        $idList = implode(',', $cleanIds);

        // Security check: restrict non-admins to their report tree
        $whereScope = '';
        if (!$isAdmin) {
            $allowedReports = (array)($user['reports'] ?? [$user['id']]);
            $reportsList = implode(',', array_map('intval', $allowedReports));
            $whereScope = " AND `created_by` IN ({$reportsList})";
        }

        // Fetch targets
        $codes = $db->fetchAll("SELECT * FROM `activation_codes` WHERE `id` IN ({$idList}) {$whereScope};");
        if (empty($codes)) {
            return ['status' => 'ERROR', 'message' => 'No accessible codes found for mass action.'];
        }

        $targetIds = array_column($codes, 'id');
        $targetIdList = implode(',', $targetIds);
        $subscriberIds = array_filter(array_column($codes, 'subscriber_id'));
        $subIdList = !empty($subscriberIds) ? implode(',', $subscriberIds) : '0';

        switch ($action) {
            case 'mass_enable':
                // Set status: 1 if never activated, 2 if activated
                $db->query("UPDATE `activation_codes` SET `status` = IF(`activated_at` IS NULL, 1, 2) WHERE `id` IN ({$targetIdList});");
                $db->query("UPDATE `lines` SET `enabled` = 1, `admin_enabled` = 1 WHERE `id` IN ({$subIdList});");
                return ['status' => 'SUCCESS', 'message' => count($targetIds) . ' codes successfully enabled.'];

            case 'mass_disable':
                $db->query("UPDATE `activation_codes` SET `status` = 0 WHERE `id` IN ({$targetIdList});");
                $db->query("UPDATE `lines` SET `enabled` = 0 WHERE `id` IN ({$subIdList});");
                return ['status' => 'SUCCESS', 'message' => count($targetIds) . ' codes suspended.'];

            case 'mass_extend':
                $days = max(1, intval($extra['days'] ?? 30));
                $seconds = $days * 86400;
                $db->query(
                    "UPDATE `lines` SET `exp_date` = IF(`exp_date` IS NOT NULL AND `exp_date` > UNIX_TIMESTAMP(), `exp_date` + ?, UNIX_TIMESTAMP() + ?) WHERE `id` IN ({$subIdList}) AND `exp_date` IS NOT NULL;",
                    $seconds,
                    $seconds
                );
                return ['status' => 'SUCCESS', 'message' => "Extended expiration of selected active codes by {$days} days."];

            case 'mass_reset_device':
                $db->query("UPDATE `activation_codes` SET `mac` = NULL, `device_id` = NULL WHERE `id` IN ({$targetIdList});");
                return ['status' => 'SUCCESS', 'message' => 'Hardware/Device lock reset on selected codes.'];

            case 'mass_change_package':
                $newPackageId = intval($extra['package_id'] ?? 0);
                $newPackage = PackageService::getById($newPackageId);
                if (!$newPackage) {
                    return ['status' => 'ERROR', 'message' => 'Invalid package.'];
                }
                $newBouquets = $newPackage['bouquets'];
                $db->query("UPDATE `activation_codes` SET `package_id` = ?, `bouquets` = ? WHERE `id` IN ({$targetIdList});", $newPackageId, $newBouquets);
                $db->query("UPDATE `lines` SET `package_id` = ?, `bouquet` = ? WHERE `id` IN ({$subIdList});", $newPackageId, $newBouquets);
                return ['status' => 'SUCCESS', 'message' => 'Updated package on selected codes.'];

            case 'mass_delete':
                $refund = !empty($extra['refund_credits']) || !empty($extra['refund']);
                $totalRefunded = 0;

                $db->beginTransaction();
                try {
                    if ($refund && !$isAdmin) {
                        // Calculate refund only on stock/unactivated codes (status = 1)
                        foreach ($codes as $c) {
                            if ($c['status'] == 1 && $c['purchase_cost'] > 0 && $c['created_by'] == $user['id']) {
                                $totalRefunded += floatval($c['purchase_cost']);
                            }
                        }

                        if ($totalRefunded > 0) {
                            $db->query("UPDATE `users` SET `credits` = `credits` + ? WHERE `id` = ?;", $totalRefunded, $user['id']);
                            $db->query(
                                "INSERT INTO `users_credits_logs` (`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES (?, ?, ?, ?, ?);",
                                $user['id'],
                                $user['id'],
                                $totalRefunded,
                                time(),
                                "Refund for deleted unused active codes (" . count($targetIds) . " codes)"
                            );
                        }
                    }

                    $db->query("DELETE FROM `activation_codes` WHERE `id` IN ({$targetIdList});");
                    $db->query("DELETE FROM `lines` WHERE `id` IN ({$subIdList});");
                    $db->commit();

                    $msg = count($targetIds) . ' codes deleted successfully.';
                    if ($totalRefunded > 0) {
                        $msg .= " Refunded {$totalRefunded} credits for unused stock.";
                    }
                    return ['status' => 'SUCCESS', 'message' => $msg];
                } catch (\Throwable $e) {
                    $db->rollback();
                    return ['status' => 'ERROR', 'message' => 'Failed to delete codes: ' . $e->getMessage()];
                }

            default:
                return ['status' => 'ERROR', 'message' => 'Unknown mass action.'];
        }
    }

    /**
     * Get grouped summary metrics by batch.
     */
    public static function getBatchSummary(array $user, bool $isAdmin, ?string $batchName = null): array {
        $db = self::db();
        $where = [];
        $params = [];

        if (!$isAdmin) {
            $allowedReports = (array)($user['reports'] ?? [$user['id']]);
            $where[] = "`created_by` IN (" . implode(',', array_map('intval', $allowedReports)) . ")";
        }

        if ($batchName) {
            $where[] = "`batch_name` = ?";
            $params[] = $batchName;
        }

        $whereClause = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

        $sql = "SELECT 
                    `batch_name`,
                    `created_by`,
                    `package_id`,
                    `is_trial`,
                    MIN(`created_at`) as `created_at`,
                    COUNT(*) as `total_codes`,
                    SUM(CASE WHEN `status` = 1 THEN 1 ELSE 0 END) as `stock_count`,
                    SUM(CASE WHEN `status` = 2 THEN 1 ELSE 0 END) as `active_count`,
                    SUM(CASE WHEN `status` = 0 THEN 1 ELSE 0 END) as `disabled_count`
                FROM `activation_codes`
                {$whereClause}
                GROUP BY `batch_name`, `created_by`, `package_id`, `is_trial`
                ORDER BY `created_at` DESC;";

        $rows = $db->fetchAll($sql, ...$params);
        $packages = [];
        $resellers = [];

        foreach ($rows as &$row) {
            $pkgId = $row['package_id'];
            if (!isset($packages[$pkgId])) {
                $p = PackageService::getById($pkgId);
                $packages[$pkgId] = $p['package_name'] ?? 'Custom Package';
            }
            $row['package_name'] = $packages[$pkgId];

            $resellerId = $row['created_by'];
            if (!isset($resellers[$resellerId])) {
                $u = UserRepository::getUserById($resellerId);
                $resellers[$resellerId] = $u['username'] ?? "User #{$resellerId}";
            }
            $row['creator_name'] = $resellers[$resellerId];
            $row['ready_codes'] = $row['stock_count'];
            $row['active_codes'] = $row['active_count'];
        }

        return $rows;
    }

    /**
     * Export scratch-card formatted text for printing.
     */
    public static function exportBatchTxt(string $batchName, array $user, bool $isAdmin): string {
        $db = self::db();
        $where = ["`batch_name` = ?"];
        $params = [$batchName];

        if (!$isAdmin) {
            $allowedReports = (array)($user['reports'] ?? [$user['id']]);
            $where[] = "`created_by` IN (" . implode(',', array_map('intval', $allowedReports)) . ")";
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);
        $codes = $db->fetchAll("SELECT * FROM `activation_codes` {$whereClause} ORDER BY `id` ASC;", ...$params);

        if (empty($codes)) {
            return "No codes found for batch {$batchName}.\n";
        }

        $first = $codes[0];
        $pkg = PackageService::getById($first['package_id']);
        $pkgName = $pkg['package_name'] ?? 'IPTV Subscription';
        $portalUrl = DomainResolver::resolve(SERVER_ID);
        if (!empty($first['dns_base'])) {
            $portalUrl = rtrim($first['dns_base'], '/');
        }

        $out = "========================================================================\n";
        $out .= "                   ACTIVATION CODES VOUCHER BATCH                      \n";
        $out .= "========================================================================\n";
        $out .= "Batch Name : {$batchName}\n";
        $out .= "Package    : {$pkgName}\n";
        $out .= "Generated  : " . date('Y-m-d H:i:s', (int)$first['created_at']) . "\n";
        $out .= "Total Vouchers: " . count($codes) . "\n";
        $out .= "Activation Portal: {$portalUrl}/portal\n";
        $out .= "========================================================================\n\n";

        $i = 1;
        foreach ($codes as $c) {
            $statusText = ($c['status'] == 1) ? 'READY / UNUSED' : (($c['status'] == 2) ? 'ACTIVE' : 'REVOKED');
            $out .= "+----------------------------------------------------------------------+\n";
            $out .= sprintf("| CARD #%03d  |  CODE: %-25s | %-16s |\n", $i++, $c['activation_code'], $statusText);
            $out .= sprintf("| Portal: %-42s  Max Conn: %-2d |\n", "{$portalUrl}/portal", (int)$c['max_connections']);
            $out .= "+----------------------------------------------------------------------+\n\n";
        }

        return $out;
    }
}

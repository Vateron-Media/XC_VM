<?php

namespace XcVm\Domain\Line;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
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
        $forcedCountry = array_key_exists('forced_country', $data)
            ? (trim((string)$data['forced_country']) ?: null)
            : (trim((string)($package['forced_country'] ?? '')) ?: null);
        $maxConnections = intval($data['max_connections'] ?? ($package['max_connections'] ?: 1));
        $isAdult = !empty($data['is_adult']) ? 1 : 0;
        $outputFormats = $package['output_formats'] ?? '[]';

        $customDataJson = null;
        if (!empty($data['category_template_id']) && intval($data['category_template_id']) > 0) {
            $tplId = intval($data['category_template_id']);
            $customDataObj = \XcVm\Domain\Stream\CategoryTemplateService::buildCustomData($tplId);
            $customDataJson = json_encode($customDataObj, JSON_UNESCAPED_UNICODE);
        } elseif (!empty($data['custom_data'])) {
            $customDataJson = is_array($data['custom_data']) ? json_encode($data['custom_data'], JSON_UNESCAPED_UNICODE) : (string)$data['custom_data'];
        }

        $customUsername = trim((string)($data['streaming_username'] ?? $data['username'] ?? ''));
        $customPassword = trim((string)($data['streaming_password'] ?? $data['password'] ?? ''));

        if ($qty === 1 && $customUsername !== '') {
            if (strlen($customUsername) < 3) {
                return ['status' => 'ERROR', 'message' => 'Streaming username must be at least 3 characters.'];
            }
            if (!preg_match('/^[a-zA-Z0-9_\-\.]+$/', $customUsername)) {
                return ['status' => 'ERROR', 'message' => 'Streaming username contains invalid characters. Use letters, numbers, dots, hyphens, or underscores.'];
            }
            if (UserRepository::getLineByUsername($customUsername)) {
                return ['status' => 'ERROR', 'message' => "The streaming username '{$customUsername}' already exists. Please choose a different username."];
            }
        }

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

                if ($qty === 1 && $customUsername !== '') {
                    $lineUsername = $customUsername;
                    $linePassword = ($customPassword !== '') ? $customPassword : substr(bin2hex(random_bytes(6)), 0, 10);
                } else {
                    // Auto-create companion line with frozen countdown (exp_date = NULL)
                    $lineUsername = 'ac_' . strtolower(substr(bin2hex(random_bytes(5)), 0, 9));
                    $linePassword = substr(bin2hex(random_bytes(6)), 0, 10);

                    // Ensure username collision-free
                    while (UserRepository::getLineByUsername($lineUsername)) {
                        $lineUsername = 'ac_' . strtolower(substr(bin2hex(random_bytes(5)), 0, 9));
                    }
                }

                $insertResult = $db->query(
                    "INSERT INTO `lines` (
                        `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`,
                        `bouquet`, `allowed_outputs`, `max_connections`, `is_restreamer`, `is_trial`,
                        `is_mag`, `is_e2`, `forced_country`, `package_id`, `is_activecode`, `created_at`,
                        `reseller_notes`, `custom_data`
                    ) VALUES (?, ?, ?, NULL, 1, 1, ?, ?, ?, 0, ?, 0, 0, ?, ?, 1, ?, ?, ?);",
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
                    "Active Code: {$code} (Batch: {$batchName})",
                    $customDataJson
                );

                $lineId = (int)$db->last_insert_id();
                if (!$insertResult || $lineId <= 0) {
                    $lastErr = (isset($db->lastError) && $db->lastError) ? $db->lastError : 'Database error';
                    throw new \RuntimeException("Failed to create subscriber line for code {$code}: {$lastErr}");
                }

                // Insert into activation_codes table
                $acInsertResult = $db->query(
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

                $acId = (int)$db->last_insert_id();
                if (!$acInsertResult || $acId <= 0) {
                    $lastErr = (isset($db->lastError) && $db->lastError) ? $db->lastError : 'Database error';
                    throw new \RuntimeException("Failed to register activation code {$code}: {$lastErr}");
                }

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

        // Extract and sanitize hardware/device identifiers
        $mac = !empty($deviceInfo['mac']) ? trim($deviceInfo['mac']) : null;
        $explicitDeviceId = !empty($deviceInfo['device_id']) ? trim($deviceInfo['device_id']) : null;
        $clientIp = $deviceInfo['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
        $userAgent = $deviceInfo['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

        // Fallback: If neither MAC nor Device ID was provided, derive an ephemeral identifier for logging/session
        $deviceId = $explicitDeviceId;
        if (empty($mac) && empty($deviceId)) {
            $fingerprint = ($clientIp ?? '') . '|' . $userAgent . '|' . $cleanCode;
            $deviceId = 'DEV-' . strtoupper(substr(hash('sha256', $fingerprint), 0, 12));
        }

        // ─── First-Time Activation (Countdown starts now) ───
        if ($codeRow['status'] == 1 || empty($codeRow['activated_at'])) {
            $duration = intval($codeRow['is_trial'] ? ($package['trial_duration'] ?? 1) : ($package['official_duration'] ?? 1));
            $unit = (string)($codeRow['is_trial'] ? ($package['trial_duration_in'] ?? 'days') : ($package['official_duration_in'] ?? 'months'));

            if (!in_array($unit, ['hours', 'days', 'months', 'years'], true)) {
                $unit = 'months';
            }

            $expDate = strtotime("+{$duration} {$unit}", $now);

            // Update activation_codes with bound device credentials (only explicit hardware IDs are permanently locked)
            $db->query(
                "UPDATE `activation_codes` SET
                    `status` = 2,
                    `activated_at` = ?,
                    `mac` = COALESCE(?, `mac`),
                    `device_id` = COALESCE(?, `device_id`)
                WHERE `id` = ?;",
                $now,
                $mac,
                $explicitDeviceId,
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
            if (!empty($mac)) $codeRow['mac'] = $mac;
            if (!empty($explicitDeviceId)) $codeRow['device_id'] = $explicitDeviceId;
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

            // Hardware & Device binding upon login:
            // If code was not bound to a hardware device yet, bind it now to the current device credentials
            $boundUpdated = false;
            $updateFields = [];
            $updateParams = [];

            if (empty($codeRow['mac']) && !empty($mac)) {
                $updateFields[] = "`mac` = ?";
                $updateParams[] = $mac;
                $codeRow['mac'] = $mac;
                $boundUpdated = true;
            }

            // If device_id is empty OR was a synthetic web fingerprint ('DEV-...'), allow explicit device_id binding
            $storedIsSynthetic = empty($codeRow['device_id']) || str_starts_with($codeRow['device_id'], 'DEV-');
            if ($storedIsSynthetic && !empty($explicitDeviceId)) {
                $updateFields[] = "`device_id` = ?";
                $updateParams[] = $explicitDeviceId;
                $codeRow['device_id'] = $explicitDeviceId;
                $boundUpdated = true;
            }

            if ($boundUpdated && !empty($updateFields)) {
                $updateParams[] = $codeRow['id'];
                $db->query(
                    "UPDATE `activation_codes` SET " . implode(', ', $updateFields) . " WHERE `id` = ?;",
                    ...$updateParams
                );
            }

            // Check device lock if enforced (ignore synthetic web fingerprints)
            if (!empty($codeRow['mac']) && !empty($mac) && strcasecmp(trim($codeRow['mac']), trim($mac)) !== 0) {
                return ['status' => 'DEVICE_MISMATCH', 'message' => 'Code is locked to another hardware device (MAC: ' . htmlspecialchars($codeRow['mac']) . ').'];
            }
            if (!empty($codeRow['device_id']) && !str_starts_with($codeRow['device_id'], 'DEV-') && !empty($explicitDeviceId) && !str_starts_with($explicitDeviceId, 'DEV-') && strcasecmp(trim($codeRow['device_id']), trim($explicitDeviceId)) !== 0) {
                return ['status' => 'DEVICE_MISMATCH', 'message' => 'Code is locked to another hardware device.'];
            }
        }

        // Resolve Portal and M3U URLs (dynamically respecting http / https protocol)
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https')
            || (isset($_SERVER['SERVER_PORT']) && in_array((int)$_SERVER['SERVER_PORT'], [443, 3434], true))
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
        $currentScheme = $isHttps ? 'https' : 'http';

        if (!empty($codeRow['dns_base'])) {
            $portalHost = rtrim($codeRow['dns_base'], '/');
            if (!preg_match('#^https?://#i', $portalHost)) {
                $portalHost = "{$currentScheme}://{$portalHost}";
            }
        } elseif (!empty($_SERVER['HTTP_HOST'])) {
            $portalHost = "{$currentScheme}://{$_SERVER['HTTP_HOST']}";
        } else {
            $portalHost = rtrim(DomainResolver::resolve(defined('SERVER_ID') ? constant('SERVER_ID') : 1, $isHttps), '/');
        }
        $portalParsed = parse_url($portalHost);
        $serverDomain = $portalParsed['host'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
        $serverPort = $portalParsed['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : ($isHttps ? 443 : 80));

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
                'mac'       => $codeRow['mac'] ?: ($mac ?? ''),
                'device_id' => $codeRow['device_id'] ?: ($deviceId ?? ''),
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
            case 'enable':
            case 'mass_enable':
                // Set status: 1 if never activated, 2 if activated
                $db->query("UPDATE `activation_codes` SET `status` = IF(`activated_at` IS NULL, 1, 2) WHERE `id` IN ({$targetIdList});");
                $db->query("UPDATE `lines` SET `enabled` = 1, `admin_enabled` = 1 WHERE `id` IN ({$subIdList});");
                return ['status' => 'SUCCESS', 'message' => count($targetIds) . ' codes successfully enabled.'];

            case 'disable':
            case 'mass_disable':
                $db->query("UPDATE `activation_codes` SET `status` = 0 WHERE `id` IN ({$targetIdList});");
                $db->query("UPDATE `lines` SET `enabled` = 0 WHERE `id` IN ({$subIdList});");
                return ['status' => 'SUCCESS', 'message' => count($targetIds) . ' codes suspended.'];

            case 'extend':
            case 'mass_extend':
                $days = max(1, intval($extra['days'] ?? 30));
                $seconds = $days * 86400;
                $db->query(
                    "UPDATE `lines` SET `exp_date` = IF(`exp_date` IS NOT NULL AND `exp_date` > UNIX_TIMESTAMP(), `exp_date` + ?, UNIX_TIMESTAMP() + ?) WHERE `id` IN ({$subIdList}) AND `exp_date` IS NOT NULL;",
                    $seconds,
                    $seconds
                );
                return ['status' => 'SUCCESS', 'message' => "Extended expiration of selected active codes by {$days} days."];

            case 'reset_device':
            case 'mass_reset_device':
                $db->query("UPDATE `activation_codes` SET `mac` = NULL, `device_id` = NULL WHERE `id` IN ({$targetIdList});");
                return ['status' => 'SUCCESS', 'message' => 'Hardware/Device lock reset on selected codes.'];

            case 'change_package':
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

            case 'delete':
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

                    $msg = count($targetIds) . ' code(s) deleted successfully.';
                    if ($totalRefunded > 0) {
                        $msg .= " Refunded {$totalRefunded} credits for unused stock.";
                    }
                    return ['status' => 'SUCCESS', 'message' => $msg];
                } catch (\Throwable $e) {
                    $db->rollback();
                    return ['status' => 'ERROR', 'message' => 'Failed to delete codes: ' . $e->getMessage()];
                }

            default:
                return ['status' => 'ERROR', 'message' => 'Unknown action.'];
        }
    }

    /**
     * Delete a single active code with optional refund.
     */
    public static function deleteCode(int $codeId, array $user, bool $isAdmin, bool $refund = true): array
    {
        return self::massAction('delete', [$codeId], $user, $isAdmin, ['refund_credits' => $refund]);
    }

    /**
     * Update an existing active code voucher and its companion subscriber line.
     */
    public static function updateCode(int $codeId, array $data, array $user, bool $isAdmin): array
    {
        $db = self::db();
        if ($codeId <= 0) {
            return ['status' => 'ERROR', 'message' => 'Invalid code ID.'];
        }

        // Scope check: restrict non-admins to their report hierarchy
        $whereScope = '';
        if (!$isAdmin) {
            $allowedReports = (array)($user['reports'] ?? [$user['id']]);
            $reportsList = implode(',', array_map('intval', $allowedReports));
            $whereScope = " AND `created_by` IN ({$reportsList})";
        }

        $code = $db->fetchOne("SELECT * FROM `activation_codes` WHERE `id` = ? {$whereScope} LIMIT 1;", $codeId);
        if (!$code) {
            return ['status' => 'ERROR', 'message' => 'Activation code not found or access denied.'];
        }

        // 1. Activation code string validation
        $newCode = trim((string)($data['activation_code'] ?? $code['activation_code']));
        if (empty($newCode)) {
            return ['status' => 'ERROR', 'message' => 'Activation code cannot be empty.'];
        }

        // Check uniqueness if changed
        if (strcasecmp($newCode, (string)$code['activation_code']) !== 0) {
            $exists = $db->fetchOne("SELECT `id` FROM `activation_codes` WHERE `activation_code` = ? AND `id` != ? LIMIT 1;", $newCode, $codeId);
            if ($exists) {
                return ['status' => 'ERROR', 'message' => 'Activation code "' . $newCode . '" is already taken. Please choose another.'];
            }
        }

        // 2. Package update
        $packageId = isset($data['package_id']) ? (int)$data['package_id'] : (int)$code['package_id'];
        $bouquets = $code['bouquets'];
        if ($packageId > 0 && $packageId !== (int)$code['package_id']) {
            $pkg = PackageService::getById($packageId);
            if (!$pkg) {
                return ['status' => 'ERROR', 'message' => 'Selected package does not exist.'];
            }
            $bouquets = $pkg['bouquets'] ?? $code['bouquets'];
        }

        // 3. Status
        $status = isset($data['status']) ? (int)$data['status'] : (int)$code['status'];
        if (!in_array($status, [0, 1, 2], true)) {
            $status = (int)$code['status'];
        }

        // 4. Device lock / MAC & Device ID
        $mac = isset($data['mac']) ? trim((string)$data['mac']) : (string)$code['mac'];
        $mac = (strlen($mac) > 0 && $mac !== 'None') ? $mac : null;

        $deviceId = isset($data['device_id']) ? trim((string)$data['device_id']) : (string)$code['device_id'];
        $deviceId = (strlen($deviceId) > 0 && $deviceId !== 'None') ? $deviceId : null;

        // 5. Batch name
        $batchName = isset($data['batch_name']) ? trim((string)$data['batch_name']) : (string)$code['batch_name'];
        $batchName = strlen($batchName) > 0 ? $batchName : null;

        // 6. Max connections
        $maxConn = isset($data['max_connections']) ? max(1, (int)$data['max_connections']) : max(1, (int)$code['max_connections']);

        // 7. Expiration date
        $subId = (int)$code['subscriber_id'];
        $expDate = null;
        $hasExpDateInput = false;
        if (isset($data['exp_date'])) {
            $expInput = trim((string)$data['exp_date']);
            if (!empty($expInput)) {
                $hasExpDateInput = true;
                $parsed = is_numeric($expInput) ? (int)$expInput : strtotime($expInput);
                if ($parsed !== false && $parsed > 0) {
                    $expDate = $parsed;
                }
            }
        }

        $db->beginTransaction();
        try {
            // Update activation_codes table
            $db->query(
                "UPDATE `activation_codes` SET
                    `activation_code` = ?,
                    `batch_name` = ?,
                    `package_id` = ?,
                    `bouquets` = ?,
                    `status` = ?,
                    `mac` = ?,
                    `device_id` = ?,
                    `max_connections` = ?
                 WHERE `id` = ?;",
                $newCode,
                $batchName,
                $packageId,
                $bouquets,
                $status,
                $mac,
                $deviceId,
                $maxConn,
                $codeId
            );

            // If companion subscriber line exists, synchronize line details
            if ($subId > 0) {
                $lineUpdates = [];
                $lineParams = [];

                // Sync line username with code if it was matching or prefixed with ac_
                if (strcasecmp($newCode, (string)$code['activation_code']) !== 0) {
                    $line = $db->fetchOne("SELECT `username` FROM `lines` WHERE `id` = ? LIMIT 1;", $subId);
                    if ($line && (strcasecmp((string)$line['username'], (string)$code['activation_code']) === 0 || str_starts_with((string)$line['username'], 'ac_'))) {
                        $lineUpdates[] = "`username` = ?";
                        $lineParams[] = $newCode;
                    }
                }

                // Streaming password
                if (!empty($data['password'])) {
                    $lineUpdates[] = "`password` = ?";
                    $lineParams[] = trim((string)$data['password']);
                }

                // Expiration date
                if ($hasExpDateInput && $expDate !== null) {
                    $lineUpdates[] = "`exp_date` = ?";
                    $lineParams[] = $expDate;
                }

                // Max connections
                $lineUpdates[] = "`max_connections` = ?";
                $lineParams[] = $maxConn;

                // Package & bouquet
                if ($packageId > 0) {
                    $lineUpdates[] = "`package_id` = ?";
                    $lineParams[] = $packageId;
                    $lineUpdates[] = "`bouquet` = ?";
                    $lineParams[] = $bouquets;
                }

                // Enabled flag based on status
                if ($status === 0) {
                    $lineUpdates[] = "`enabled` = 0";
                } elseif ($status === 1 || $status === 2) {
                    $lineUpdates[] = "`enabled` = 1";
                }

                if (!empty($lineUpdates)) {
                    $lineParams[] = $subId;
                    $db->query("UPDATE `lines` SET " . implode(', ', $lineUpdates) . " WHERE `id` = ?;", ...$lineParams);
                }

                LineService::updateLinesSignal([$subId]);
            }

            $db->commit();
            return ['status' => 'SUCCESS', 'message' => 'Active code updated successfully.'];
        } catch (\Throwable $e) {
            $db->rollback();
            return ['status' => 'ERROR', 'message' => 'Failed to update code: ' . $e->getMessage()];
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
        $portalUrl = DomainResolver::resolve(defined('SERVER_ID') ? constant('SERVER_ID') : 1);
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

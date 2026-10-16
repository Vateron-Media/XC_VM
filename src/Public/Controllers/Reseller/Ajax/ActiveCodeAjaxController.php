<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\PackageService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Reseller-ajax controller for activation codes.
 *
 * Actions: active_code_details, active_codes_mass, active_codes_batch_action, active_codes_export_txt, generate_active_codes.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ActiveCodeAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Handle Active Code Details AJAX (modal view)
	 */
	public function details(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$codeId = intval(RequestManager::get('id') ?? 0);
		if (!$codeId) {
			echo json_encode(['result' => false, 'message' => 'Missing code ID.']);
			exit();
		}

		$allowedReports = (array) ($rUserInfo['reports'] ?? [$rUserInfo['id']]);
		$code = $db->fetchOne(
			"SELECT `activation_codes`.*, `lines`.`username` as `sub_username`, `lines`.`password` as `sub_password`,
			        `lines`.`exp_date` as `sub_exp_date`, `lines`.`max_connections` as `line_max_conn`
			 FROM `activation_codes`
			 LEFT JOIN `lines` ON `lines`.`id` = `activation_codes`.`subscriber_id`
			 WHERE `activation_codes`.`id` = ? AND `activation_codes`.`created_by` IN (" . implode(',', array_map('intval', $allowedReports)) . ") LIMIT 1;",
			$codeId
		);

		if (!$code) {
			http_response_code(404);
			exit();
		}

		$package = PackageService::getById((int) $code['package_id']);

		$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
			|| (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https')
			|| (isset($_SERVER['SERVER_PORT']) && in_array((int) $_SERVER['SERVER_PORT'], [443, 3434], true))
			|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
			|| (isset($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on');
		$currentScheme = $isHttps ? 'https' : 'http';

		if (!empty($code['dns_base'])) {
			$portalUrl = rtrim($code['dns_base'], '/');
			if (!preg_match('#^https?://#i', $portalUrl)) {
				$portalUrl = "{$currentScheme}://{$portalUrl}";
			}
		} elseif (!empty($_SERVER['HTTP_HOST'])) {
			$portalUrl = "{$currentScheme}://{$_SERVER['HTTP_HOST']}";
		} else {
			$portalUrl = rtrim(DomainResolver::resolve(SERVER_ID, $isHttps), '/');
		}
		$portalParsed = parse_url($portalUrl);

		$m3uHls = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=hls";
		$m3uTs  = "{$portalUrl}/get.php?username={$code['sub_username']}&password={$code['sub_password']}&type=m3u_plus&output=ts";

		$portalCode = null;
		$db->query("SELECT `code` FROM `access_codes` WHERE `type` = 7 AND `enabled` = 1 LIMIT 1;");
		if ($db->num_rows() > 0) {
			$portalCode = $db->get_row()['code'];
		}

		$playerCode = null;
		$db->query("SELECT `code` FROM `access_codes` WHERE `type` = 6 AND `enabled` = 1 LIMIT 1;");
		if ($db->num_rows() > 0) {
			$playerCode = $db->get_row()['code'];
		}

		$subscriberPortalUrl = $portalCode ? "{$portalUrl}/{$portalCode}/" : "{$portalUrl}/portal";
		$directActivateUrl   = "{$subscriberPortalUrl}?code=" . urlencode((string) $code['activation_code']);
		$webPlayerUrl        = $playerCode ? "{$portalUrl}/{$playerCode}/" : null;

		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by the required active_code_details.php view
		$d = [
			'id' => (int) $code['id'],
			'code' => $code['activation_code'],
			'batch_name' => $code['batch_name'],
			'status' => (int) $code['status'],
			'status_text' => ($code['status'] == 1) ? 'Ready (Stock)' : (($code['status'] == 2) ? 'Active' : 'Disabled'),
			'package_id' => (int) $code['package_id'],
			'package_name' => $package['package_name'] ?? 'Custom Package',
			'is_trial' => (bool) $code['is_trial'],
			'max_connections' => (int) ($code['line_max_conn'] ?: $code['max_connections']),
			'exp_date' => $code['sub_exp_date'] ? date('Y-m-d H:i:s', (int) $code['sub_exp_date']) : 'Frozen (Stock)',
			'exp_date_input' => $code['sub_exp_date'] ? date('Y-m-d\TH:i', (int) $code['sub_exp_date']) : '',
			'has_line' => !empty($code['subscriber_id']),
			'activated_at' => $code['activated_at'] ? date('Y-m-d H:i:s', (int) $code['activated_at']) : 'Never',
			'created_at' => $code['created_at'] ? date('Y-m-d H:i:s', (int) $code['created_at']) : '-',
			'mac' => $code['mac'] ?: 'None',
			'raw_mac' => $code['mac'] ?? '',
			'device_id' => $code['device_id'] ?: 'None',
			'raw_device_id' => $code['device_id'] ?? '',
			'username' => $code['sub_username'],
			'password' => $code['sub_password'],
			'server' => $portalParsed['host'] ?? 'localhost',
			'port' => $portalParsed['port'] ?? (isset($_SERVER['SERVER_PORT']) ? (int) $_SERVER['SERVER_PORT'] : 80),
			'portal_url' => $portalUrl,
			'activation_portal_url' => $subscriberPortalUrl,
			'direct_activate_url' => $directActivateUrl,
			'web_player_url' => $webPlayerUrl,
			'm3u_hls' => $m3uHls,
			'm3u_ts' => $m3uTs,
		];

		// Reuse the shared voucher-details fragment (same body as admin), rendered
		// server-side and injected by the reseller list. $language lets it translate.
		// phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by the required active_code_details.php view
		$language = Translator::class;
		header('Content-Type: text/html; charset=utf-8');
		require MAIN_HOME . 'Public/Views/admin/active_code_details.php';
		exit();
	}

	/**
	 * Handle Active Codes Mass Actions AJAX
	 */
	public function mass(): void {
		[$rUserInfo] = $this->begin();
		$subAction = trim(RequestManager::get('sub_action') ?? '');
		$ids = json_decode(RequestManager::get('ids') ?? '[]', true) ?: [];
		$extra = [
			'days' => intval(RequestManager::get('days') ?? 30),
			'package_id' => intval(RequestManager::get('package_id') ?? 0),
			'refund_credits' => !empty(RequestManager::get('refund_credits')),
		];

		$res = ActiveCodeService::massAction($subAction, $ids, $rUserInfo, false, $extra);
		echo json_encode([
			'result' => ($res['status'] === 'SUCCESS'),
			'message' => $res['message'] ?? 'Action processed.'
		]);
		exit();
	}

	/**
	 * Handle Batch Action AJAX (Enable, Disable, Delete)
	 */
	public function batchAction(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$batchName = trim(RequestManager::get('batch_name') ?? '');
		$subAction = trim(RequestManager::get('sub_action') ?? '');
		$refund = !empty(RequestManager::get('refund_credits'));

		if (empty($batchName)) {
			echo json_encode(['result' => false, 'message' => 'Missing batch name.']);
			exit();
		}

		$allowedReports = (array) ($rUserInfo['reports'] ?? [$rUserInfo['id']]);
		$codes = $db->fetchAll(
			"SELECT `id` FROM `activation_codes` WHERE `batch_name` = ? AND `created_by` IN (" . implode(',', array_map('intval', $allowedReports)) . ");",
			$batchName
		);

		if (empty($codes)) {
			echo json_encode(['result' => false, 'message' => 'No codes found for this batch.']);
			exit();
		}

		$ids = array_column($codes, 'id');
		$res = ActiveCodeService::massAction($subAction, $ids, $rUserInfo, false, ['refund_credits' => $refund]);

		echo json_encode([
			'result' => ($res['status'] === 'SUCCESS'),
			'message' => $res['message'] ?? 'Batch action processed.'
		]);
		exit();
	}

	/**
	 * Handle Export Scratch Cards TXT
	 */
	public function exportTxt(): void {
		[$rUserInfo] = $this->begin();
		$batchName = trim(RequestManager::get('batch_name') ?? '');
		if (empty($batchName)) {
			exit('Invalid batch name');
		}

		$content = ActiveCodeService::exportBatchTxt($batchName, $rUserInfo, false);
		$filename = preg_replace('/[^A-Za-z0-9_\-]/', '_', $batchName) . '_vouchers.txt';

		header('Content-Type: text/plain; charset=utf-8');
		header('Content-Disposition: attachment; filename="' . $filename . '"');
		header('Content-Length: ' . strlen($content));
		echo $content;
		exit();
	}

	/**
	 * Handle AJAX Code Generation
	 */
	public function generate(): void {
		[$rUserInfo] = $this->begin();
		$data = RequestManager::getAll();
		$res = ActiveCodeService::generateCodes($data, $rUserInfo, false);

		echo json_encode([
			'result' => ($res['status'] === 'SUCCESS'),
			'message' => $res['message'] ?? '',
			'batch_name' => $res['batch_name'] ?? null,
			'qty' => $res['qty'] ?? 0,
			'codes' => $res['codes'] ?? []
		]);
		exit();
	}
}

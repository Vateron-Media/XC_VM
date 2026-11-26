<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * ResellerCategoryTemplateController — Category Template Editor for Resellers.
 *
 * @renders Views/reseller/category_template.php
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ResellerCategoryTemplateController extends BaseResellerController {
	public function index() {
		$this->requirePermission();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			// Templates are created from the list page's dialog (the create action
			// returns the new id); the "Create Category Template" menu entry lands
			// here, so open that dialog instead of just showing the list.
			header('Location: category_templates?create=1');
			exit;
		}

		$template = CategoryTemplateService::getTemplateById($id);
		if (!$template) {
			header('Location: category_templates');
			exit;
		}

		$user = $GLOBALS['rUserInfo'] ?? [];
		$userId = (int) ($user['id'] ?? 0);
		$isOwner = ((int) $template['owner_id'] === $userId);
		$isSystem = ((int) $template['is_system'] === 1);

		$subUsers = \XcVm\Domain\User\UserRepository::getSubUsers($userId);
		$subResellerIds = !empty($subUsers) ? array_map('intval', array_keys($subUsers)) : [];
		$isSubReseller = in_array((int) $template['owner_id'], $subResellerIds, true);

		$canEdit = ($isOwner || $isSubReseller) && !$isSystem;
		$customerLineId = (int) RequestManager::get('customer_line_id', 0);
		$returnUrl = 'category_templates';
		if ($customerLineId > 0) {
			$line = UserRepository::getLineById($customerLineId);
			$customData = CategoryTemplateService::parseCustomData($line['custom_data'] ?? null);
			if (!$line
				|| !Authorization::check('line', $customerLineId)
				|| (int) ($customData['template_id'] ?? 0) !== $id
				|| !$isOwner
				|| $isSystem
				|| !empty($template['is_shared'])
				|| (int) $template['subscriber_count'] !== 1
			) {
				header('Location: category_templates');
				exit;
			}

			if (!empty($line['is_mag']) || !empty($line['is_e2'])) {
				$db = DatabaseFactory::get();
				$table = !empty($line['is_mag']) ? 'mag_devices' : 'enigma2_devices';
				$idColumn = !empty($line['is_mag']) ? 'mag_id' : 'device_id';
				$db->query("SELECT `{$idColumn}` FROM `{$table}` WHERE `user_id` = ? LIMIT 1", $customerLineId);
				$deviceId = (int) ($db->get_col() ?: 0);
				$returnUrl = $deviceId > 0 ? (!empty($line['is_mag']) ? 'mag?id=' : 'enigma?id=') . $deviceId : (!empty($line['is_mag']) ? 'mags' : 'enigmas');
			} else {
				$returnUrl = 'line?id=' . $customerLineId;
			}
		}

		$this->setTitle('Category Template: ' . $template['name']);

		$categories = CategoryTemplateService::getEditorCategories($id);

		$this->render('category_template', [
			'template'      => $template,
			'categories'    => $categories,
			'isAdmin'       => false,
			'isOwner'       => $isOwner,
			'isSubReseller' => $isSubReseller,
			'canEdit'        => $canEdit,
			'currentUser'    => $user,
			'customerLineId' => $customerLineId,
			'returnUrl'      => $returnUrl,
		]);
	}
}

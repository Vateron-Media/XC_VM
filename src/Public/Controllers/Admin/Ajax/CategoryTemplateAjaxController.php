<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Audit\AdminAudit;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;
use XcVm\Domain\User\UserRepository;

/**
 * CategoryTemplateAjaxController — Ajax controller for Category Templates.
 *
 * Handles API actions for both Admin and Reseller panels:
 * - category_template_create
 * - category_template_save
 * - category_template_delete
 * - category_template_clone
 * - category_template_apply_all
 * - category_template_toggle_system
 * - category_template_get
 *
 * @package XC_VM_Public_Controllers_Admin_Ajax
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CategoryTemplateAjaxController extends BaseAjaxController {
	/**
	 * Get the authenticated user and admin status.
	 *
	 * @return array{user: array, isAdmin: bool}
	 */
	private function getAuthContext(): array {
		$user = $GLOBALS['rUserInfo'] ?? ($GLOBALS['rAdminUserInfo'] ?? null);
		if (!$user) {
			$this->fail(['message' => 'Unauthenticated']);
		}
		// The page's own permission: its actions (create, save, delete, apply
		// to every stream) ran for any signed-in group.
		if (!PageAuthorization::checkPermissions('category_templates')) {
			$this->fail(['message' => 'No permission']);
		}

		$isAdmin = (int) ($user['is_admin'] ?? 0) === 1 || (int) ($user['member_group_id'] ?? 0) === 1;
		return ['user' => $user, 'isAdmin' => $isAdmin];
	}

	/**
	 * Action: category_template_create
	 */
	public function create(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$name = trim((string) RequestManager::get('name', ''));
		if ($name === '') {
			$this->fail(['message' => 'Template name is required.']);
		}

		$isShared = !empty(RequestManager::get('is_shared'));
		$isSystem = $isAdmin && !empty(RequestManager::get('is_system'));

		$res = CategoryTemplateService::createTemplate((int) $user['id'], $name, $isSystem, $isShared);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok([
			'id'      => $res['id'],
			'message' => $res['message']
		]);
	}

	/**
	 * Action: category_template_save
	 */
	public function save(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$rawBody = file_get_contents('php://input');
		$data = json_decode($rawBody, true);

		if (!is_array($data)) {
			$data = RequestManager::getAll();
			if (isset($data['categories']) && is_string($data['categories'])) {
				$data['categories'] = json_decode($data['categories'], true) ?: [];
			}
		}

		$id = (int) ($data['id'] ?? RequestManager::get('id', 0));
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$customerLineId = (int) ($data['customer_line_id'] ?? 0);
		$customerLine = null;
		if ($customerLineId > 0) {
			$customerLine = UserRepository::getLineById($customerLineId);
			$customData = CategoryTemplateService::parseCustomData($customerLine['custom_data'] ?? null);
			$template = CategoryTemplateService::getTemplateById($id);
			if (!$customerLine
				|| !Authorization::check('line', $customerLineId)
				|| (int) ($customData['template_id'] ?? 0) !== $id
				|| !$template
				|| (int) $template['owner_id'] !== (int) $customerLine['member_id']
				|| !empty($template['is_system'])
				|| !empty($template['is_shared'])
				|| (int) $template['subscriber_count'] !== 1
			) {
				$this->fail(['message' => 'This customer template is no longer safe to edit.']);
			}
			$data['is_shared'] = 0;
		}

		$res = CategoryTemplateService::saveTemplate($id, $data, $user, $isAdmin);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		if ($customerLine) {
			AdminAudit::record('customer_category_order_save', true, [
				'line_id' => $customerLineId,
				'customer_type' => !empty($customerLine['is_mag']) ? 'mag' : (!empty($customerLine['is_e2']) ? 'enigma' : 'line'),
				'previous_template_id' => $id,
				'new_template_id' => $id,
			]);
		}

		$this->ok([
			'message'      => $res['message'],
			'synced_lines' => $res['synced_lines'] ?? 0
		]);
	}

	/** Prepare a private template for one authorized customer line. */
	public function customer(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();
		if ($isAdmin) {
			$this->fail(['message' => 'Use the reseller profile for customer-specific templates.']);
		}

		$lineId = (int) RequestManager::get('line_id', 0);
		if ($lineId <= 0 || !Authorization::check('line', $lineId)) {
			$this->fail(['message' => 'Customer not found.']);
		}

		$result = CategoryTemplateService::prepareCustomerTemplate($lineId, $user);
		if (!$result['success']) {
			$this->fail(['message' => $result['message']]);
		}

		$this->ok([
			'id' => $result['id'],
			'url' => 'category_template?id=' . (int) $result['id'] . '&customer_line_id=' . $lineId,
			'message' => $result['message'],
		]);
	}

	/**
	 * Action: category_template_delete
	 */
	public function delete(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$res = CategoryTemplateService::deleteTemplate($id, $user, $isAdmin);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok(['message' => $res['message']]);
	}

	/**
	 * Action: category_template_clone
	 */
	public function clone(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$res = CategoryTemplateService::cloneTemplate($id, $user, $isAdmin);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok([
			'id'      => $res['id'],
			'message' => $res['message']
		]);
	}

	/**
	 * Action: category_template_toggle_system
	 */
	public function toggleSystem(): never {
		$this->requireXhr();
		['isAdmin' => $isAdmin] = $this->getAuthContext();

		if (!$isAdmin) {
			$this->fail(['message' => 'Access denied. Super Admin only.']);
		}

		$id = (int) RequestManager::get('id', 0);
		$isSystem = !empty(RequestManager::get('is_system'));

		$res = CategoryTemplateService::toggleSystem($id, $isSystem, true);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok(['message' => $res['message']]);
	}

	/**
	 * Action: category_template_apply_all
	 */
	public function applyAll(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$targetResellerId = RequestManager::has('target_reseller_id') ? (int) RequestManager::get('target_reseller_id') : null;

		$res = CategoryTemplateService::applyToAll($id, $user, $isAdmin, $targetResellerId);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok([
			'message'       => $res['message'],
			'updated_count' => $res['updated_count']
		]);
	}

	/**
	 * Action: category_template_get
	 * Returns template metadata and pre-built custom_data JSON for client forms.
	 */
	public function get(): never {
		$this->requireXhr();
		['user' => $user, 'isAdmin' => $isAdmin] = $this->getAuthContext();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$template = CategoryTemplateService::getTemplateById($id);
		if (!$template) {
			$this->fail(['message' => 'Template not found.']);
		}

		if (!CategoryTemplateService::canAccessTemplate($template, $user, $isAdmin)) {
			$this->fail(['message' => 'You do not have permission to view this template.']);
		}

		$customData = CategoryTemplateService::buildCustomData($id);
		$items = CategoryTemplateService::getTemplateItems($id);

		$this->ok([
			'template'    => $template,
			'custom_data' => $customData,
			'items'       => $items
		]);
	}
}

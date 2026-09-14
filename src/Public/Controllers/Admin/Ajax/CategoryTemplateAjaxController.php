<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryTemplateService;

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

		$res = CategoryTemplateService::saveTemplate($id, $data, $user, $isAdmin);
		if (!$res['success']) {
			$this->fail(['message' => $res['message']]);
		}

		$this->ok([
			'message'      => $res['message'],
			'synced_lines' => $res['synced_lines'] ?? 0
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
		['user' => $user] = $this->getAuthContext();

		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$res = CategoryTemplateService::cloneTemplate($id, (int) $user['id']);
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
		$id = (int) RequestManager::get('id', 0);
		if ($id <= 0) {
			$this->fail(['message' => 'Invalid template ID.']);
		}

		$template = CategoryTemplateService::getTemplateById($id);
		if (!$template) {
			$this->fail(['message' => 'Template not found.']);
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

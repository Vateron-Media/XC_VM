<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleManager;

/**
 * ModulesController — admin module management page.
 *
 * @renders Views/admin/modules.php
 *
 * @package XC_VM_Public_Controllers_Admin
 */
class ModulesController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $manager = new ModuleManager(container: ServiceContainer::getInstance());
        $flash = null;

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
                && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

            try {
                $action = (string) $this->input('module_action', '');
                $name = (string) $this->input('module_name', '');

                switch ($action) {
                    case 'install':
                        $manager->installModule($name);
                        $flash = ['type' => 'success', 'message' => 'Module installed: ' . $name];
                        break;

                    case 'uninstall':
                        $manager->uninstallModule($name);
                        $flash = ['type' => 'warning', 'message' => 'Module uninstalled: ' . $name];
                        break;

                    case 'delete':
                        $manager->deleteModule($name);
                        $flash = ['type' => 'warning', 'message' => 'Module deleted: ' . $name];
                        break;

                    case 'enable':
                        $manager->setEnabled($name, true);
                        $flash = ['type' => 'success', 'message' => 'Module enabled: ' . $name];
                        break;

                    case 'disable':
                        $manager->setEnabled($name, false);
                        $flash = ['type' => 'warning', 'message' => 'Module disabled: ' . $name];
                        break;

                    case 'update':
                        $manager->updateModuleFromSource($name);
                        $flash = ['type' => 'success', 'message' => 'Module updated: ' . $name];
                        break;

                    case 'platform_install':
                        $slug = trim((string) $this->input('module_slug', ''));
                        $key  = (string) (SettingsManager::getAll()['platform_api_key'] ?? '');

                        if ($key === '') {
                            throw new \RuntimeException('Set the platform API key before installing from the store.');
                        }
                        if ($slug === '') {
                            throw new \RuntimeException('Module slug is required.');
                        }

                        // Always install the latest approved version (resolved by the
                        // extension from the platform).
                        $manager->downloadFromPlatform($slug, '', $key);
                        $flash = ['type' => 'success', 'message' => 'Module installed from store (latest): ' . $slug];
                        break;

                    case 'platform_rollback':
                        $key = (string) (SettingsManager::getAll()['platform_api_key'] ?? '');
                        if ($key === '') {
                            throw new \RuntimeException('Set the platform API key before rolling back.');
                        }
                        $manager->rollbackFromPlatform($name, $key);
                        $flash = ['type' => 'success', 'message' => 'Module rolled back to previous version: ' . $name];
                        break;

                    case 'renew_license':
                        $key = (string) (SettingsManager::getAll()['platform_api_key'] ?? '');
                        if ($key === '') {
                            throw new \RuntimeException('Set the platform API key before renewing the license.');
                        }
                        if ($manager->renewModuleLicense($name, $key)) {
                            $flash = ['type' => 'success', 'message' => 'License renewed for: ' . $name];
                        } else {
                            $flash = ['type' => 'warning', 'message' => 'License not renewed for ' . $name . ' (platform licensing off, not entitled, or no server data).'];
                        }
                        break;

                    case 'upload_install':
                        if (!isset($_FILES['module_zip']) || !is_array($_FILES['module_zip'])) {
                            throw new \RuntimeException('Zip file was not uploaded.');
                        }

                        if ((int) ($_FILES['module_zip']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                            throw new \RuntimeException('Upload failed.');
                        }

                        $tmp = (string) ($_FILES['module_zip']['tmp_name'] ?? '');
                        if ($tmp === '' || !is_uploaded_file($tmp)) {
                            throw new \RuntimeException('Uploaded file is invalid.');
                        }

                        $installedName = $manager->uploadAndInstall($tmp);
                        $flash = ['type' => 'success', 'message' => 'Module uploaded and installed: ' . $installedName];
                        break;
                }
            } catch (\Throwable $e) {
                $flash = ['type' => 'danger', 'message' => $e->getMessage()];
            }

            if ($isAjax) {
                header('Content-Type: application/json');
                exit(json_encode($flash ?: ['type' => 'info', 'message' => 'No action taken']));
            }
        }

        $modules = $manager->listModules();

        $this->setTitle('Modules');
        $this->render('modules', [
            'modules' => $modules,
            'moduleFlash' => $flash,
        ]);
    }
}

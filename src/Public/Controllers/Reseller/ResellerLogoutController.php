<?php
/**
 * ResellerLogoutController — Destroys reseller session and redirects to login.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerLogoutController extends BaseResellerController
{
    public function index()
    {
        SessionManager::clearContext('reseller');
        $this->redirect('./login');
    }
}

<?php
/**
 * ResellerResizeController — Image resize proxy for reseller panel.
 *
 * Migrated from reseller/resize.php.
 * Resizes remote/local images and caches the result as PNG.
 * Returns 1x1 transparent PNG on failure.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerResizeController extends BaseResellerController
{
    public function index()
    {
        session_write_close();

        if (!isset($GLOBALS['rUserInfo']) || !$GLOBALS['rUserInfo']['id']) {
            exit();
        }

        ImageResizeService::serve(['cacheDir' => IMAGES_PATH . 'admin/']);
    }
}

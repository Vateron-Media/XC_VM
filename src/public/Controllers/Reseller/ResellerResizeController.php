<?php
/**
 * ResellerResizeController — Image resize proxy for reseller panel.
 *
 * Migrated from reseller/resize.php.
 * Resizes remote/local images and caches the result as PNG.
 * Returns 1x1 transparent PNG on failure.
 */
class ResellerResizeController extends BaseResellerController
{
    public function index()
    {
        session_write_close();

        if (!isset($GLOBALS['rUserInfo']) || !$GLOBALS['rUserInfo']['id']) {
            exit();
        }

        require MAIN_HOME . 'infrastructure/legacy/reseller_resize_body.php';
    }
}

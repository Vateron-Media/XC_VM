<?php
/**
 * ProviderEditController — add/edit stream provider (Phase 6.3 — Group H).
 *
 * Route: GET /admin/provider → index()
 */
class ProviderEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rProvider = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rProvider = function_exists('getStreamProvider') ? getStreamProvider($id) : null;
            if (!$rProvider) {
                exit();
            }
        }

        $this->setTitle('Stream Provider');
        $this->render('provider', compact('rProvider'));
    }
}

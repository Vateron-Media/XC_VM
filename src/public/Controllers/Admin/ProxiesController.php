<?php
/**
 * ProxiesController — Proxy Servers listing (Phase 6.3 — Group N).
 */
class ProxiesController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        CoreUtilities::$rServers = CoreUtilities::getServers(true);

        $this->setTitle('Proxy Servers');
        $this->render('proxies');
    }
}

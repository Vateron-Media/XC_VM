<?php
/**
 * ProxiesController — Proxy Servers listing.
 */
class ProxiesController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        ServerRepository::getAll(true);

        $this->setTitle('Proxy Servers');
        $this->render('proxies');
    }
}

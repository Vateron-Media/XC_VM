<?php
/**
 * OndemandController — On-Demand сканер (Phase 6.3 — Group B).
 */
class OndemandController extends BaseAdminController
{
    public function index()
    {
        $this->setTitle('On-Demand Scanner');
        $this->render('ondemand');
    }
}

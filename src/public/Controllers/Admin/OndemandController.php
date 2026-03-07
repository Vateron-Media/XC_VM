<?php
/**
 * OndemandController — On-Demand сканер.
 */
class OndemandController extends BaseAdminController
{
    public function index()
    {
        $this->setTitle('On-Demand Scanner');
        $this->render('ondemand');
    }
}

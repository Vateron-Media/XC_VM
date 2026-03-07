<?php
/**
 * MassDeleteController — Mass Delete.
 */
class MassDeleteController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $this->setTitle('Mass Delete');
        $this->render('mass_delete');
    }
}

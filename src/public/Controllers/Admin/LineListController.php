<?php
/**
 * LineListController — список линий.
 */
class LineListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('lines');
    }
}

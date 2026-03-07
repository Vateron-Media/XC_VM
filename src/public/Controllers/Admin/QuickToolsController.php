<?php
/**
 * QuickToolsController — Quick Tools.
 */
class QuickToolsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Quick Tools');
        $this->render('quick_tools');
    }
}

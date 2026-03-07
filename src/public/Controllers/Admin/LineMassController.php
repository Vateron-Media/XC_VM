<?php
/**
 * LineMassController — массовое редактирование линий.
 */
class LineMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line_mass');
    }
}

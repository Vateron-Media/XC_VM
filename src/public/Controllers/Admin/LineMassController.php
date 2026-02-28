<?php
/**
 * LineMassController — массовое редактирование линий (Phase 6.3 — Group C).
 */
class LineMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line_mass');
    }
}

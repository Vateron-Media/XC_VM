<?php
/**
 * RadioListController — список радиостанций (Phase 6.3 — Group A).
 */
class RadioListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = getCategories('radio');

        $this->setTitle('Radio Stations');
        $this->render('radios', compact('rCategories'));
    }
}

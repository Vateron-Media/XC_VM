<?php
/**
 * RadioListController — список радиостанций.
 */
class RadioListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = CategoryService::getAllByType('radio');

        $this->setTitle('Radio Stations');
        $this->render('radios', compact('rCategories'));
    }
}

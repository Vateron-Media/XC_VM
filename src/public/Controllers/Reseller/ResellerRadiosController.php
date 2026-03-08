<?php
/**
 * ResellerRadiosController — Radio stations listing (read-only).
 */
class ResellerRadiosController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Radio Stations');
        $this->render('radios', [
            'categories' => CategoryService::getAllByType('radio'),
        ]);
    }
}

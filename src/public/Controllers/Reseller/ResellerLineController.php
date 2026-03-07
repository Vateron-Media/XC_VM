<?php
/**
 * ResellerLineController — Line edit/create.
 */
class ResellerLineController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line');
    }
}

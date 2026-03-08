<?php
/**
 * ResellerLinesController — Lines listing.
 */
class ResellerLinesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Lines');
        $this->render('lines');
    }
}

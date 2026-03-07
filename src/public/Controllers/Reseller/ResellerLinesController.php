<?php
/**
 * ResellerLinesController — Lines listing.
 */
class ResellerLinesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('lines');
    }
}

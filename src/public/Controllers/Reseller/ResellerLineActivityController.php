<?php
/**
 * ResellerLineActivityController — Line activity log.
 */
class ResellerLineActivityController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('line_activity');
    }
}

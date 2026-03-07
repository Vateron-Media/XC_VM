<?php
/**
 * ResellerEpgViewController — EPG preview.
 */
class ResellerEpgViewController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('epg_view');
    }
}

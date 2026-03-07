<?php
/**
 * ResellerEpisodesController — Episodes listing (read-only).
 */
class ResellerEpisodesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('episodes');
    }
}

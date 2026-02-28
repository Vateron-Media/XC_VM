<?php
/**
 * ResellerMoviesController — Movies listing (read-only) (Phase 6.4 — Reseller).
 */
class ResellerMoviesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('movies');
    }
}

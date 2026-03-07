<?php
/**
 * ResellerMoviesController — Movies listing (read-only).
 */
class ResellerMoviesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('movies');
    }
}

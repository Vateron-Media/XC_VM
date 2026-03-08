<?php
/**
 * ResellerMoviesController — Movies listing (read-only).
 */
class ResellerMoviesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Movies');
        $this->render('movies', [
            'categories' => CategoryService::getAllByType('movie'),
        ]);
    }
}

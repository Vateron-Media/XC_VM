<?php
/**
 * SeriesListController — список сериалов.
 */
class SeriesListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = CategoryService::getAllByType('series');

        $this->setTitle('TV Series');
        $this->render('series', compact('rCategories'));
    }
}

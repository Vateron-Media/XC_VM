<?php
/**
 * SeriesMassController — массовое редактирование сериалов.
 */
class SeriesMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = CategoryService::getAllByType('series');

        $this->setTitle('Mass Edit Series');
        $this->render('series_mass', compact('rCategories'));
    }
}

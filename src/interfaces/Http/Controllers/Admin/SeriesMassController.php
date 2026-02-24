<?php
/**
 * SeriesMassController — массовое редактирование сериалов (Phase 6.3 — Group B).
 */
class SeriesMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = getCategories('series');

        $this->setTitle('Mass Edit Series');
        $this->render('series_mass', compact('rCategories'));
    }
}

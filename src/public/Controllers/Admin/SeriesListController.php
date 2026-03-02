<?php
/**
 * SeriesListController — список сериалов (Phase 6.3 — Group B).
 */
class SeriesListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = getCategories('series');

        $this->setTitle('TV Series');
        $this->render('series', compact('rCategories'));
    }
}

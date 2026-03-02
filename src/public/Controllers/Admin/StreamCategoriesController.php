<?php
/**
 * StreamCategoriesController — список категорий стримов (Phase 6.3 — Group A).
 */
class StreamCategoriesController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = [1 => getCategories(), 2 => getCategories('movie'), 3 => getCategories('series'), 4 => getCategories('radio')];
        $rMainCategories = [1 => [], 2 => [], 3 => [], 4 => []];

        foreach ([1, 2, 3, 4] as $rID) {
            foreach ($rCategories[$rID] as $rCategoryData) {
                $rMainCategories[$rID][] = $rCategoryData;
            }
        }

        $this->setTitle('Stream Categories');
        $this->render('stream_categories', compact('rCategories', 'rMainCategories'));
    }
}

<?php
/**
 * StreamCategoriesController — список категорий стримов.
 */
class StreamCategoriesController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCategories = [1 => CategoryService::getAllByType(), 2 => CategoryService::getAllByType('movie'), 3 => CategoryService::getAllByType('series'), 4 => CategoryService::getAllByType('radio')];
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

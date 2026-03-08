<?php
/**
 * ResellerEpisodesController — Episodes listing (read-only).
 */
class ResellerEpisodesController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Episodes');
        $this->render('episodes', [
            'seriesList' => SeriesService::getList(),
            'categories' => CategoryService::getAllByType('series'),
        ]);
    }
}

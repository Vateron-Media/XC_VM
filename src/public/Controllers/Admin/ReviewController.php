<?php
/**
 * ReviewController — Review imported streams/movies.
 * Very complex data-prep: M3U import processing, category matching, stream/movie API calls.
 * Data-prep is ~160 lines; delegated to legacy file via $__viewMode.
 */
class ReviewController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rType = isset(RequestManager::getAll()['type']) ? intval(RequestManager::getAll()['type']) : 1;
        $rCategorySet = [];
        $rLogoSet = [];

        $this->setTitle('Review');
        $this->render('review', compact('rType', 'rCategorySet', 'rLogoSet'));
    }
}

<?php
/**
 * ResellerStreamsController — Streams listing (read-only).
 */
class ResellerStreamsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Streams');
        $this->render('streams', [
            'categories' => CategoryService::getAllByType('live'),
        ]);
    }
}

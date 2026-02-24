<?php
/**
 * ReviewController — Review imported streams/movies (Phase 6.3 — Group N).
 * Very complex data-prep: M3U import processing, category matching, stream/movie API calls.
 * Data-prep is ~160 lines; delegated to legacy file via $__viewMode.
 */
class ReviewController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Review');
        $this->render('review');
    }
}

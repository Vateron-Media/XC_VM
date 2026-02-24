<?php
/**
 * StreamErrorsController — ошибки стримов (Phase 6.3 — Group A).
 */
class StreamErrorsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Stream Errors');
        $this->render('stream_errors');
    }
}

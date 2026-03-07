<?php
/**
 * StreamErrorsController — ошибки стримов.
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

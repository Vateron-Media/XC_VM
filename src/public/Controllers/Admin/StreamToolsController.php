<?php
/**
 * StreamToolsController — инструменты стримов (Phase 6.3 — Group A).
 */
class StreamToolsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Stream Tools');
        $this->render('stream_tools');
    }
}

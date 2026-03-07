<?php
/**
 * StreamToolsController — инструменты стримов.
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

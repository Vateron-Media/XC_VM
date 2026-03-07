<?php
/**
 * QueueController — Encoding Queue.
 */
class QueueController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Encoding Queue');
        $this->render('queue');
    }
}

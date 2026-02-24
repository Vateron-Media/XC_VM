<?php
/**
 * QueueController — Encoding Queue (Phase 6.3 — Group M).
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

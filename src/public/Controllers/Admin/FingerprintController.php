<?php
/**
 * FingerprintController — Fingerprint Stream (Phase 6.3 — Group N).
 */
class FingerprintController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Fingerprint Stream');
        $this->render('fingerprint');
    }
}

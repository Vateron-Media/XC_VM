<?php
/**
 * FingerprintController — Fingerprint Stream.
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

<?php
/**
 * ResellerEnigmasController — Enigma devices listing (Phase 6.4 — Reseller).
 */
class ResellerEnigmasController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('enigmas');
    }
}

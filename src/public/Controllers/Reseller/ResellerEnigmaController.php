<?php
/**
 * ResellerEnigmaController — Enigma device edit/create (Phase 6.4 — Reseller).
 */
class ResellerEnigmaController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('enigma');
    }
}

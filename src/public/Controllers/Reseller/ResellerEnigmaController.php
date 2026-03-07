<?php
/**
 * ResellerEnigmaController — Enigma device edit/create.
 */
class ResellerEnigmaController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('enigma');
    }
}

<?php
/**
 * ResellerEnigmasController — Enigma devices listing.
 */
class ResellerEnigmasController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('enigmas');
    }
}

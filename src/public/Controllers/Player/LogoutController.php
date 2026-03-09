<?php

class LogoutController extends BasePlayerController
{
    public function index()
    {
        destroySession('player');
        header('Location: login');
        exit();
    }
}

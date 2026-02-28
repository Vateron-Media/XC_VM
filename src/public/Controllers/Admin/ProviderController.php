<?php

class ProviderController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Stream Providers');

        $this->render('providers', [
            'providers' => getStreamProviders(),
        ]);
    }
}

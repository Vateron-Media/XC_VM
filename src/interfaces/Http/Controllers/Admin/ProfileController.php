<?php

class ProfileController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Transcoding Profiles');

        $this->render('profiles', [
            'profiles' => StreamConfigRepository::getTranscodeProfiles(),
        ]);
    }
}

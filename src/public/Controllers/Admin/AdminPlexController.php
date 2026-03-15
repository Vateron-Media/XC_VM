<?php
/**
 * AdminPlexController — Plex Sync listing (admin wrapper).
 */
class AdminPlexController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rPlexServers = PlexRepository::getPlexServers();
        if (!is_array($rPlexServers)) {
            $rPlexServers = [];
        }

        $this->setTitle('Plex Sync');
        $this->render('plex', compact('rPlexServers'));
    }
}

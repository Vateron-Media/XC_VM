<?php
/**
 * PlexController — Plex Sync listing.
 */
class PlexController extends BaseAdminController
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

<?php
/**
 * PlexController — Plex Sync listing (Phase 6.3 — Group L).
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

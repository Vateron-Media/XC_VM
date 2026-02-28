<?php
/**
 * WatchOutputController — Watch Folder Logs (Phase 6.3 — Group L).
 */
class WatchOutputController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rServers = ServerRepository::getStreamingSimple($rPermissions);
        if (!is_array($rServers)) {
            $rServers = [];
        }

        $this->setTitle('Watch Folder Logs');
        $this->render('watch_output', compact('rServers'));
    }
}

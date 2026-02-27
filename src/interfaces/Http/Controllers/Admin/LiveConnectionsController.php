<?php
/**
 * LiveConnectionsController — активные подключения (Phase 6.3 — Group A).
 */
class LiveConnectionsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rSearchUser = null;
        $rSearchStream = null;

        if (isset(CoreUtilities::$rRequest['user_id'])) {
            $rSearchUser = UserRepository::getLineById(CoreUtilities::$rRequest['user_id']);
        }

        if (isset(CoreUtilities::$rRequest['stream_id'])) {
            $rSearchStream = StreamRepository::getById(CoreUtilities::$rRequest['stream_id']);
        }

        $this->setTitle('Live Connections');
        $this->render('live_connections', compact('rSearchUser', 'rSearchStream'));
    }
}

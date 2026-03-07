<?php
/**
 * LiveConnectionsController — активные подключения.
 */
class LiveConnectionsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rSearchUser = null;
        $rSearchStream = null;

        if (isset(RequestManager::getAll()['user_id'])) {
            $rSearchUser = UserRepository::getLineById(RequestManager::getAll()['user_id']);
        }

        if (isset(RequestManager::getAll()['stream_id'])) {
            $rSearchStream = StreamRepository::getById(RequestManager::getAll()['stream_id']);
        }

        $this->setTitle('Live Connections');
        $this->render('live_connections', compact('rSearchUser', 'rSearchStream'));
    }
}

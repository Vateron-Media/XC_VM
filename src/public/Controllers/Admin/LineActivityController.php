<?php
/**
 * LineActivityController — логи активности линий.
 */
class LineActivityController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $data = [];

        if (isset(RequestManager::getAll()['user_id'])) {
            $rSearchUser = UserRepository::getLineById(RequestManager::getAll()['user_id']);
            if ($rSearchUser) {
                $data['rSearchUser'] = $rSearchUser;
            }
        }

        if (isset(RequestManager::getAll()['stream_id'])) {
            $rSearchStream = StreamRepository::getById(RequestManager::getAll()['stream_id']);
            if ($rSearchStream) {
                $data['rSearchStream'] = $rSearchStream;
            }
        }

        $this->render('line_activity', $data);
    }
}

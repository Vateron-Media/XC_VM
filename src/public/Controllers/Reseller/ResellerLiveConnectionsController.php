<?php
/**
 * ResellerLiveConnectionsController — Live connections.
 */
class ResellerLiveConnectionsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Live Connections');

        $rRequest = RequestManager::getAll();
        $data = [
            'redisEnabled' => (bool) SettingsManager::getAll()['redis_handler'],
        ];

        if (isset($rRequest['line'])) {
            if (Authorization::check('line', $rRequest['line'])) {
                $data['rSearchLine'] = UserRepository::getLineById($rRequest['line']);
            } else {
                exit();
            }
        }

        if (isset($rRequest['stream'])) {
            $data['rSearchStream'] = StreamRepository::getById($rRequest['stream']);
        }

        $this->render('live_connections', $data);
    }
}

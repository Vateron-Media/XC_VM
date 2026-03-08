<?php
/**
 * ResellerLineActivityController — Line activity log.
 */
class ResellerLineActivityController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Activity Logs');

        $rRequest = RequestManager::getAll();
        $data = [];

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

        $this->render('line_activity', $data);
    }
}

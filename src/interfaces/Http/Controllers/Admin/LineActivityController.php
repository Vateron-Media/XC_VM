<?php
/**
 * LineActivityController — логи активности линий (Phase 6.3 — Group C).
 */
class LineActivityController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $data = [];

        if (isset(CoreUtilities::$rRequest['user_id'])) {
            $rSearchUser = UserRepository::getLineById(CoreUtilities::$rRequest['user_id']);
            if ($rSearchUser) {
                $data['rSearchUser'] = $rSearchUser;
            }
        }

        if (isset(CoreUtilities::$rRequest['stream_id'])) {
            $rSearchStream = StreamRepository::getById(CoreUtilities::$rRequest['stream_id']);
            if ($rSearchStream) {
                $data['rSearchStream'] = $rSearchStream;
            }
        }

        $this->render('line_activity', $data);
    }
}

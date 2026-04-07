<?php
/**
 * ResellerLineActivityController — Line activity log.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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

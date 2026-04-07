<?php

/**
 * PlayerLogoutController — player logout controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerLogoutController extends BasePlayerController
{
    public function index()
    {
        destroySession('player');
        header('Location: login');
        exit();
    }
}

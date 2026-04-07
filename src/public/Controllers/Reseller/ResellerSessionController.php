<?php

/**
 * ResellerSessionController — JSON-эндпоинт проверки сессии.
 *
 * Используется JS-функцией pingSession() (из footer) для keep-alive
 * проверки: GET /reseller/session → {"result": true}.
 *
 * Если сессия истекла или невалидна, bootstrap (session.php)
 * перенаправит на login ДО того, как мы дойдём сюда.
 * Поэтому если контроллер выполняется — сессия активна.
 *
 * @see src/reseller/session.php  (legacy endpoint, nginx direct access)
 * @see src/public/Views/layouts/reseller/footer.php  (pingSession JS)
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerSessionController extends BaseResellerController
{
    public function index()
    {
        echo json_encode(['result' => true]);
        exit;
    }
}

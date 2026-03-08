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
 */
class ResellerSessionController extends BaseResellerController
{
    public function index()
    {
        echo json_encode(['result' => true]);
        exit;
    }
}

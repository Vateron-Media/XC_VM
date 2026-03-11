<?php

/**
 * PlayerEmbedController — Встроенный плеер для просмотра потоков.
 *
 * Плеер открывается из admin UI (stream_view, live_connections и т.д.)
 * в popup/iframe. Имеет собственный HTML-документ (не layout header/footer).
 */
class PlayerEmbedController extends BaseAdminController
{
	public function index()
	{
		$this->requirePermission();
		@chdir(MAIN_HOME . 'public/Views/admin/');
		require MAIN_HOME . 'public/Views/admin/player.php';
	}
}

<?php
/**
 * Settings view (Phase 6.3 — Group F).
 *
 * Делегирует рендеринг HTML-body в legacy-файл admin/settings.php,
 * пропуская его data-prep и layout-обёртки через флаг $__settingsViewMode.
 */
$__settingsViewMode = true;
include MAIN_HOME . 'admin/settings.php';

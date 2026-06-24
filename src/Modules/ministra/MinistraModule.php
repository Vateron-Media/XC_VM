<?php

namespace XcVm\Module\Ministra;
use XcVm\Core\Boundary\BoundaryInterface;
use XcVm\Core\Module\BaseModule;


/**
 * Ministra (Stalker Portal) Module
 *
 * Модуль MAG/Ministra-портала для STB-устройств.
 * Обрабатывает HTTP-запросы от MAG-приставок через portal.php.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Обработчики:
 *     - PortalHandler    — диспетчер switch/case для type+action запросов
 *                          (pre-init, stb, itv, vod, series, epg, radio,
 *                           tv_archive, watchdog, audioclub, account_info,
 *                           handshake)
 *
 *   Хелперы:
 *     - PortalHelpers    — вспомогательные функции портала
 *                          (getDevice, updateCache, getEPG, getItems,
 *                           getMovies, getSeries, getStreams, getStations,
 *                           getSeriesItems, sort*, getHeaders, shutdown)
 *
 *   Точка входа:
 *     - ministra/portal.php — тонкая обёртка: init/auth → PortalHandler
 *
 * @see PortalHandler
 * @see PortalHelpers
 *
 * @package XC_VM_Module_Ministra
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MinistraModule extends BaseModule implements BoundaryInterface {

    public function getName(): string {
        return 'ministra';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function getEntryPoint(): string {
        return 'ministra/portal.php';
    }

    public function isIsolated(): bool {
        return true;
    }
}

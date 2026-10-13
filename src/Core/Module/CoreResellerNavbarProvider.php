<?php

namespace XcVm\Core\Module;

use XcVm\Core\Module\Contract\ResellerNavbarProviderInterface;

/**
 * CoreResellerNavbarProvider — registers all built-in reseller navigation items.
 *
 * Called once at the start of ModuleLoader::bootAll() before any module
 * registers its own items, mirroring CoreNavbarProvider for the admin tree.
 * Migrated from the formerly hardcoded $xmMenuSections array in
 * Public/Views/layouts/reseller/header.php. Modules inject additional items
 * via the same ResellerNavbarRegistry::add() API.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class CoreResellerNavbarProvider implements ResellerNavbarProviderInterface {
	/**
	 * ResellerNavbarProviderInterface implementation — delegates to register().
	 */
	public function registerResellerNavbar(ResellerNavbarRegistry $registry): void {
		self::register();
	}

	/**
	 * Register all core reseller navigation items with the ResellerNavbarRegistry.
	 */
	public static function register(): void {
		self::_dashboard();
		self::_clients();
		self::_content();
		self::_categoryTemplates();
		self::_ticketsAndLogs();
	}

	// ── Dashboard ─────────────────────────────────────────────────

	private static function _dashboard(): void {
		ResellerNavbarRegistry::add((new NavbarItem('dashboard'))
			->url('dashboard')->label('dashboard')
			->icon('ti tabler-smart-home')->order(100));
	}

	// ── Clients (Lines / MAG / Enigma / Active Codes / Sub-Resellers) ──

	/**
	 * Register the "Clients" section: Lines, MAG devices, Enigma devices,
	 * Active Codes and Sub-Resellers, each gated on the create_* permission
	 * that used to guard the whole hardcoded group.
	 */
	private static function _clients(): void {
		// Lines
		ResellerNavbarRegistry::add((new NavbarItem('user_lines'))
			->url('#')->label('user_lines')
			->icon('ti tabler-device-desktop')->permissions(['create_line'])->order(200));
		ResellerNavbarRegistry::add((new NavbarItem('user_lines.add'))
			->parent('user_lines')->url('line')
			->label('add_line')->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('user_lines.trial'))
			->parent('user_lines')->url('line?trial=1')
			->label('generate_trial')->permissions(['can_generate_trials'])->order(20));
		ResellerNavbarRegistry::add((new NavbarItem('user_lines.manage'))
			->parent('user_lines')->url('lines')
			->label('manage_lines')->order(30));

		// MAG devices
		ResellerNavbarRegistry::add((new NavbarItem('mag_devices'))
			->url('#')->label('mag_devices')
			->icon('ti tabler-device-tv')->permissions(['create_mag'])->order(210));
		ResellerNavbarRegistry::add((new NavbarItem('mag_devices.add'))
			->parent('mag_devices')->url('mag')
			->label('add_mag')->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('mag_devices.trial'))
			->parent('mag_devices')->url('mag?trial=1')
			->label('generate_trial')->permissions(['can_generate_trials'])->order(20));
		ResellerNavbarRegistry::add((new NavbarItem('mag_devices.manage'))
			->parent('mag_devices')->url('mags')
			->label('manage_mag_devices')->order(30));

		// Enigma devices
		ResellerNavbarRegistry::add((new NavbarItem('enigma_devices'))
			->url('#')->label('enigma_devices')
			->icon('ti tabler-cpu')->permissions(['create_enigma'])->order(220));
		ResellerNavbarRegistry::add((new NavbarItem('enigma_devices.add'))
			->parent('enigma_devices')->url('enigma')
			->label('add_enigma')->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('enigma_devices.trial'))
			->parent('enigma_devices')->url('enigma?trial=1')
			->label('generate_trial')->permissions(['can_generate_trials'])->order(20));
		ResellerNavbarRegistry::add((new NavbarItem('enigma_devices.manage'))
			->parent('enigma_devices')->url('enigmas')
			->label('manage_enigma_devices')->order(30));

		// Active codes (reuses create_line, matching the current gate)
		ResellerNavbarRegistry::add((new NavbarItem('active_codes'))
			->url('#')->label('active_codes')
			->icon('ti tabler-key')->permissions(['create_line'])->settingDisabled('hide_active_codes')->order(230));
		ResellerNavbarRegistry::add((new NavbarItem('active_codes.add'))
			->parent('active_codes')->url('active_code')
			->label('generate_codes')->settingDisabled('hide_active_codes')->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('active_codes.manage'))
			->parent('active_codes')->url('active_codes')
			->label('manage_codes')->settingDisabled('hide_active_codes')->order(20));
		ResellerNavbarRegistry::add((new NavbarItem('active_codes.batch'))
			->parent('active_codes')->url('active_codes_batch')
			->label('batch_manager')->settingDisabled('hide_active_codes')->order(30));

		// Sub-resellers
		ResellerNavbarRegistry::add((new NavbarItem('sub_resellers'))
			->url('#')->label('sub_resellers')
			->icon('ti tabler-users')->permissions(['create_sub_resellers'])->order(240));
		ResellerNavbarRegistry::add((new NavbarItem('sub_resellers.add'))
			->parent('sub_resellers')->url('user')
			->label('add_user')->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('sub_resellers.manage'))
			->parent('sub_resellers')->url('users')
			->label('manage_users')->order(20));
	}

	// ── Content (flat top-level entries, grouped by view-level section) ──

	/**
	 * Register Content navigation items as independent top-level entries
	 * (no shared parent — the "content" caption is a view-level grouping,
	 * matching how admin registers standalone items like management.tickets).
	 * Each item carries can_view_vod directly, reproducing the current
	 * section-level gate without a separate section-visibility mechanism.
	 */
	private static function _content(): void {
		ResellerNavbarRegistry::add((new NavbarItem('streams'))
			->url('streams')->label('streams')
			->icon('ti tabler-player-play')->permissions(['can_view_vod'])->order(300));
		ResellerNavbarRegistry::add((new NavbarItem('created_channels'))
			->url('created_channels')->label('created_channels')
			->icon('ti tabler-playlist-add')->permissions(['can_view_vod'])->order(310));
		ResellerNavbarRegistry::add((new NavbarItem('movies'))
			->url('movies')->label('movies')
			->icon('ti tabler-movie')->permissions(['can_view_vod'])->order(320));
		ResellerNavbarRegistry::add((new NavbarItem('episodes'))
			->url('episodes')->label('episodes')
			->icon('ti tabler-device-tv-old')->permissions(['can_view_vod'])->order(330));
		ResellerNavbarRegistry::add((new NavbarItem('radios'))
			->url('radios')->label('radios')
			->icon('ti tabler-broadcast')->permissions(['can_view_vod'])->order(340));
		ResellerNavbarRegistry::add((new NavbarItem('tv_guide'))
			->url('epg_view')->label('tv_guide')
			->icon('ti tabler-calendar-time')->permissions(['can_view_vod'])
			->desktopOnly()->order(350));
	}

	// ── Category Templates ─────────────────────────────────────────

	private static function _categoryTemplates(): void {
		ResellerNavbarRegistry::add((new NavbarItem('category_templates'))
			->url('category_templates')->label('category_templates')
			->icon('ti tabler-layout-grid')->order(400));
	}

	// ── Tickets & Logs ────────────────────────────────────────────

	private static function _ticketsAndLogs(): void {
		ResellerNavbarRegistry::add((new NavbarItem('tickets'))
			->url('tickets')->label('tickets')
			->icon('ti tabler-ticket')->order(500));

		ResellerNavbarRegistry::add((new NavbarItem('logs'))
			->url('#')->label('logs')
			->icon('ti tabler-clipboard-list')->order(510));
		ResellerNavbarRegistry::add((new NavbarItem('logs.live_connections'))
			->parent('logs')->url('live_connections')
			->label('live_connections')->permissions(['reseller_client_connection_logs'])->order(10));
		ResellerNavbarRegistry::add((new NavbarItem('logs.activity_logs'))
			->parent('logs')->url('line_activity')
			->label('activity_logs')->permissions(['reseller_client_connection_logs'])->order(20));
		ResellerNavbarRegistry::add((new NavbarItem('logs.user_logs'))
			->parent('logs')->url('user_logs')
			->label('user_logs')->order(30));
	}
}

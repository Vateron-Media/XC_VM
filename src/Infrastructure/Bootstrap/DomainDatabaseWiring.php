<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Epg\EPG;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Line\LineRepository;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Server\ServerService;
use XcVm\Domain\Server\SettingsService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ChannelService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\Stream\PlaylistGenerator;
use XcVm\Domain\Stream\ProfileService;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Domain\Stream\RadioService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\TicketRepository;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Domain\Vod\EpisodeService;
use XcVm\Domain\Vod\MovieService;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Domain\Vod\TMDbService;

/**
 * DomainDatabaseWiring — inject the request-scoped $db into every domain class.
 *
 * Domain classes use the static setDb() / db() pattern: setDb() stores the
 * injected instance; db() returns it (throwing if it was never set). This is
 * the single source of truth for that wiring, shared by every bootstrap path
 * (XC_Bootstrap and the lightweight WebApiBootstrap) so the list cannot drift.
 *
 * Must run after the DatabaseHandler is created and before any code calls a
 * domain service — in particular LegacyInitializer::initCore(), which invokes
 * ServerRepository::getAll() via exportGlobals().
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DomainDatabaseWiring {
	/**
	 * Wire the injected $db instance into every domain service class.
	 *
	 * @param object $db DatabaseHandler instance.
	 * @return void
	 */
	public static function wire(object $db): void {
		// Every domain service that uses the static setDb()/db() pattern, kept as a
		// single flat list so the wiring cannot drift between bootstrap paths.
		//
		// Each entry is guarded by class_exists() below: the LB build strips whole
		// MAIN-only domains (Domain/User, Domain/Device, Domain/Auth, …), so those
		// classes are simply absent on load-balancer nodes. Wiring must skip a
		// missing class instead of fataling during boot — a load-balancer only
		// needs the streaming/server slice and never touches the MAIN-only ones.
		$services = [
			// Bouquet
			BouquetService::class,
			// Device (MAIN-only — absent on LB)
			EnigmaService::class,
			MagService::class,
			// Epg
			EPG::class,
			EpgService::class,
			// Line
			LineRepository::class,
			LineService::class,
			PackageService::class,
			// Security
			BlocklistService::class,
			// Server
			ServerRepository::class,
			ServerService::class,
			SettingsService::class,
			// Stream
			CategoryService::class,
			ChannelService::class,
			ConnectionTracker::class,
			PlaylistGenerator::class,
			ProfileService::class,
			ProviderService::class,
			RadioService::class,
			StreamConfigRepository::class,
			StreamProcess::class,
			StreamRepository::class,
			StreamService::class,
			// User (MAIN-only — absent on LB)
			GroupService::class,
			ResellerAPI::class,
			TicketRepository::class,
			UserRepository::class,
			UserService::class,
			// Vod
			EpisodeService::class,
			MovieService::class,
			SeriesService::class,
			TMDbService::class,
		];

		foreach ($services as $service) {
			if (class_exists($service)) {
				$service::setDb($db);
			}
		}
	}
}

<?php

namespace XcVm\Ministra;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Logging\RefusalLog;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\TimeUtils;

/**
 * PortalHandler — portal handler
 *
 * @package XC_VM_Module_Ministra
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PortalHandler {
	/**
	 * Phase 1: Pre-init stub responses (no DB needed).
	 * Exits immediately if the action is handled, otherwise returns void.
	 *
	 */
	public static function handlePreInit(?string $rReqType, ?string $rReqAction) {
		if ($rReqType && $rReqAction) {
			switch ($rReqType) {
				case "stb":
					switch ($rReqAction) {
						case "get_ad":
							exit(json_encode(["js" => []]));

						case "get_storages":
							exit(json_encode(["js" => []]));

						case "log":
							exit(json_encode(["js" => true]));

						case "get_countries":
							exit(json_encode(["js" => []]));

						case "get_timezones":
							exit(json_encode(["js" => []]));

						case "get_cities":
							exit(json_encode(["js" => []]));

						case "search_cities":
							exit(json_encode(["js" => []]));
					}

					break;

				case "remote_pvr":
					switch ($rReqAction) {
						case "start_record_on_stb":
							exit(json_encode(["js" => true]));

						case "stop_record_on_stb":
							exit(json_encode(["js" => true]));

						case "get_active_recordings":
							exit(json_encode(["js" => []]));
					}

					break;

				case "media_favorites":
					exit(json_encode(["js" => ""]));

				case "tvreminder":
					exit(json_encode(["js" => []]));

				case "series":
				case "vod":
					switch ($rReqAction) {
						case "set_not_ended":
							exit(json_encode(["js" => true]));

						case "del_link":
							exit(json_encode(["js" => true]));

						case "log":
							exit(json_encode(["js" => 1]));
					}

					break;

				case "downloads":
					exit(json_encode(["js" => true]));

				case "account_info":
					switch ($rReqAction) {
						case "get_terms_info":
							exit(json_encode(["js" => true]));

						case "get_payment_info":
							exit(json_encode(["js" => true]));

						case "get_agreement_info":
							exit(json_encode(["js" => true]));
					}

					break;

				case "tv_archive":
					switch ($rReqAction) {
						case "set_played_timeshift":
							exit(json_encode(["js" => true]));

						case "set_played":
							exit(json_encode(["js" => true]));

						case "update_played_timeshift_end_time":
							exit(json_encode(["js" => true]));
					}

					break;

				case "itv":
					switch ($rReqAction) {
						case "set_fav_status":
							exit(json_encode(["js" => []]));

						case "set_played":
							exit(json_encode(["js" => true]));
					}

					break;
			}
		}
	}

	/**
	 * Phase 4: Unauthenticated STB actions (get_profile, get_localization, log, get_modules).
	 * Called when $rReqType == 'stb' from the outer switch.
	 * Exits if action is handled, otherwise returns void.
	 *
	 * @param array  &$ctx Context array with device, profile, language, theme, authenticated, etc.
	 */
	public static function handleStbPublic(string $rReqAction, array &$ctx) {
		global $rSettings, $rServers;

		switch ($rReqAction) {
			case "get_profile":
				// A get_profile that does not verify the device is a failed attempt. The
				// guard keeps what it counts as JSON keys: it gets a digest of the MAC,
				// whatever bytes the request carried.
				if (!$ctx["authenticated"]) {
					BruteforceGuard::checkBruteforce($ctx["ip"], is_string($ctx["mac"]) ? hash("sha256", $ctx["mac"]) : null);
					BruteforceGuard::checkFlood();
					RefusalLog::record("INVALID_CREDENTIALS", is_string($ctx["ip"] ?? null) ? $ctx["ip"] : null);
				}

				$rTotal = $ctx["authenticated"]
					? array_merge($ctx["profile"], $ctx["device"]["get_profile_vars"])
					: $ctx["profile"];
				$rTotal["status"] = intval(!$ctx["authenticated"]);
				$rTotal["update_url"] = empty($rSettings["update_url"])
					? ""
					: $rSettings["update_url"];
				$rTotal["test_download_url"] = empty($rSettings["test_download_url"])
					? ""
					: $rSettings["test_download_url"];
				$rTotal["default_timezone"] = $rSettings["default_timezone"];
				$rTotal["default_locale"] = $ctx["device"]["locale"];
				$rTotal["allowed_stb_types"] = $rSettings["allowed_stb_types"];
				$rTotal["allowed_stb_types_for_local_recording"] = $rSettings["allowed_stb_types"];
				$rTotal["storages"] = [];
				$rTotal["tv_channel_default_aspect"] = empty($rSettings["tv_channel_default_aspect"])
					? "fit"
					: $rSettings["tv_channel_default_aspect"];
				$rTotal["playback_limit"] = empty($rSettings["playback_limit"])
					? false
					: intval($rSettings["playback_limit"]);

				if (empty($rTotal["playback_limit"])) {
					$rTotal["enable_playback_limit"] = false;
				}

				$rTotal["show_tv_channel_logo"] = !empty($rSettings["show_tv_channel_logo"]);
				$rTotal["show_channel_logo_in_preview"] = !empty($rSettings["show_channel_logo_in_preview"]);
				$rTotal["enable_connection_problem_indication"] = !empty($rSettings["enable_connection_problem_indication"]);
				$rTotal["hls_fast_start"] = "1";
				$rTotal["check_ssl_certificate"] = 0;
				$rTotal["enable_buffering_indication"] = 1;
				$rTotal["watchdog_timeout"] = mt_rand(80, 120);

				if (empty($rTotal["aspect"])
					&& $rServers[SERVER_ID]["server_protocol"] == "https"
				) {
					$rTotal["aspect"] = "16";
				}

				exit(json_encode(["js" => $rTotal], JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_types_list":
				exit(json_encode([
					"js" => [
						"allowed_stb_types" => array_values(
							(array) ($rSettings["allowed_stb_types"] ?? []),
						),
						"strict_stb_type_check" => empty($rSettings["strict_stb_type_check"])
								? ""
								: $rSettings["strict_stb_type_check"],
					],
				]));

			case "get_localization":
				exit(json_encode(["js" => $ctx["language"][$ctx["device"]["locale"]]]));

			case "log":
				exit(json_encode(["js" => true]));

			case "get_modules":
				$rModules = [
					"all_modules" => [
						"media_browser",
						"vclub",
						"tv",
						"sclub",
						"radio",
						"dvb",
						"tv_archive",
						"time_shift",
						"time_shift_local",
						"epg.reminder",
						"epg.recorder",
						"epg",
						"epg.simple",
						"downloads_dialog",
						"downloads",
						"records",
						"pvr_local",
						"settings.parent",
						"settings.localization",
						"settings.update",
						"settings.playback",
						"settings.common",
						"settings.network_status",
						"settings",
						"account",
						"internet",
						"logout",
						"account_menu",
						// Legacy modules not loaded by default (uncomment to review):
						// "apps",
						// "app_skeleton",
						// "remotepvr",
						// "widget.audio",
						// "widget.radio",
					],
					"switchable_modules" => ["sclub", "vlub"],
					"disabled_modules" => [
						"records",
						"downloads",
						"settings.update",
						"settings.common",
						"pvr_local",
						"media_browser",
					],
					"restricted_modules" => [],
					"template" => $ctx["theme"],
					"launcher_url" => "",
					"launcher_profile_url" => "",
				];

				exit(json_encode(["js" => $rModules]));
		}
	}

	/**
	 * Phase 5: Authenticated action dispatcher.
	 * Sets up player, then dispatches to the appropriate sub-handler.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleAuthenticated(string $rReqType, string $rReqAction, array &$ctx) {
		$ctx["device"]["mag_player"] = trim($ctx["device"]["mag_player"], "'\"");
		$ctx["player"] = !empty($ctx["device"]["mag_player"])
			? $ctx["device"]["mag_player"] . " "
			: "ffmpeg ";

		switch ($rReqType) {
			case "stb":
				self::handleStbSettings($rReqAction, $ctx);

				break;

			case "watchdog":
				self::handleWatchdog($rReqAction, $ctx);

				break;

			case "audioclub":
				self::handleAudioclub($rReqAction, $ctx);

				break;

			case "itv":
				self::handleItv($rReqAction, $ctx);

				break;

			case "vod":
				self::handleVod($rReqAction, $ctx);

				break;

			case "series":
				self::handleSeries($rReqAction, $ctx);

				break;

			case "account_info":
				self::handleAccountInfo($rReqAction, $ctx);

				break;

			case "radio":
				self::handleRadio($rReqAction, $ctx);

				break;

			case "tv_archive":
				self::handleTvArchive($rReqAction, $ctx);

				break;

			case "epg":
				self::handleEpg($rReqAction, $ctx);

				break;
		}
	}

	/**
	 * Phase 5 sub-handler: Authenticated STB settings actions.
	 * Actions: set_modern, set_legacy, get_preload_images, get_settings_profile,
	 *          get_locales, get_tv_aspects, set_volume, set_aspect, set_stream_error,
	 *          set_screensaver_delay, set_playback_buffer, set_plasma_saving,
	 *          set_parent_password, set_locale, set_hdmi_reaction.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleStbSettings(string $rReqAction, array &$ctx) {
		global $db, $rSettings, $rRequest;

		switch ($rReqAction) {
			case "set_modern":
				$ctx["device"]["theme_type"] = 0;
				$db->query(
					"UPDATE `mag_devices` SET `theme_type` = 0 WHERE `mag_id` = ?",
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["data" => true]));

			case "set_legacy":
				$ctx["device"]["theme_type"] = 1;
				$db->query(
					"UPDATE `mag_devices` SET `theme_type` = 1 WHERE `mag_id` = ?",
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["data" => true]));

			case "get_preload_images":
				$rMode = is_numeric($ctx["gMode"]) ? "i_" . $ctx["gMode"] : "i";
				$rImages = [
					"template/" . $ctx["theme"] . "/" . $rMode . "/alert_triangle.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/archive.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/archive_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/bg.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/bg2.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/ears_arrow_l.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/ears_arrow_r.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/hd.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/hd_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mb_prev_bg.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_hor_surround.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_account.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_default.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_internet.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_mb.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_radio.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_setting.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_tv.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/mm_ico_video.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/left_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/logo.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/play.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/play_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/rec.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/rec_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/right_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/star.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/star_white.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/tv_prev_bg.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/volume_bar.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/volume_bg.png",
					"template/" . $ctx["theme"] . "/" . $rMode . "/volume_off.png",
				];

				// Only preload images that actually exist for the current theme.
				// Modern themes use sprite sheets, so the classic per-icon files
				// (play.png, rec.png, hd.png, ...) may be absent — skip them so the
				// client does not fire 404s while precaching.
				$rImages = array_values(
					array_filter($rImages, function ($rPath) {
						return is_file(__DIR__ . "/" . $rPath);
					}),
				);

				exit(json_encode(["js" => $rImages]));

			case "get_settings_profile":
				$db->query(
					"SELECT * FROM `mag_devices` WHERE `mag_id` = ?",
					$ctx["device"]["mag_id"],
				);
				$rInfo = $db->get_row();
				$rJsSettings = [
					"js" => [
						"modules" => [
							["name" => "lock"],
							["name" => "lang"],
							["name" => "update"],
							[
								"name" => "net_info",
								"sub" => [
									["name" => "wired"],
									[
										"name" => "pppoe",
										"sub" => [
											["name" => "dhcp"],
											["name" => "dhcp_manual"],
											["name" => "disable"],
										],
									],
									["name" => "wireless"],
									["name" => "speed"],
								],
							],
							["name" => "video"],
							["name" => "audio"],
							[
								"name" => "net",
								"sub" => [
									[
										"name" => "ethernet",
										"sub" => [
											["name" => "dhcp"],
											["name" => "dhcp_manual"],
											["name" => "manual"],
											["name" => "no_ip"],
										],
									],
									[
										"name" => "pppoe",
										"sub" => [
											["name" => "dhcp"],
											["name" => "dhcp_manual"],
											["name" => "disable"],
										],
									],
									[
										"name" => "wifi",
										"sub" => [
											["name" => "dhcp"],
											["name" => "dhcp_manual"],
											["name" => "manual"],
										],
									],
									["name" => "speed"],
								],
							],
							["name" => "advanced"],
							["name" => "dev_info"],
							["name" => "reload"],
							["name" => "internal_portal"],
							["name" => "reboot"],
						],
					],
				];
				$rJsSettings["js"]["parent_password"] = $rInfo["parent_password"];
				$rJsSettings["js"]["update_url"] = $rSettings["update_url"];
				$rJsSettings["js"]["test_download_url"] = $rSettings["test_download_url"];
				$rJsSettings["js"]["playback_buffer_size"] = $rInfo["playback_buffer_size"];
				$rJsSettings["js"]["screensaver_delay"] = $rInfo["screensaver_delay"];
				$rJsSettings["js"]["plasma_saving"] = $rInfo["plasma_saving"];
				$rJsSettings["js"]["spdif_mode"] = $rInfo["spdif_mode"];
				$rJsSettings["js"]["ts_enabled"] = $rInfo["ts_enabled"];
				$rJsSettings["js"]["ts_enable_icon"] = $rInfo["ts_enable_icon"];
				$rJsSettings["js"]["ts_path"] = $rInfo["ts_path"];
				$rJsSettings["js"]["ts_max_length"] = $rInfo["ts_max_length"];
				$rJsSettings["js"]["ts_buffer_use"] = $rInfo["ts_buffer_use"];
				$rJsSettings["js"]["ts_action_on_exit"] = $rInfo["ts_action_on_exit"];
				$rJsSettings["js"]["ts_delay"] = $rInfo["ts_delay"];
				$rJsSettings["js"]["hdmi_event_reaction"] = $rInfo["hdmi_event_reaction"];
				$rJsSettings["js"]["pri_audio_lang"] = $ctx["profile"]["pri_audio_lang"];
				$rJsSettings["js"]["show_after_loading"] = $rInfo["show_after_loading"];
				$rJsSettings["js"]["sec_audio_lang"] = $ctx["profile"]["sec_audio_lang"];

				if ($rSettings["always_enabled_subtitles"] == 1) {
					$rJsSettings["js"]["pri_subtitle_lang"] = $ctx["profile"]["pri_subtitle_lang"];
					$rJsSettings["js"]["sec_subtitle_lang"] = $ctx["profile"]["sec_subtitle_lang"];
				} else {
					$rJsSettings["js"]["sec_subtitle_lang"] = "";
					$rJsSettings["js"]["pri_subtitle_lang"] =
						$rJsSettings["js"]["sec_subtitle_lang"];
				}

				exit(json_encode($rJsSettings));

			case "get_locales":
				$db->query(
					"SELECT `locale` FROM `mag_devices` WHERE `mag_id` = ?",
					$ctx["device"]["mag_id"],
				);
				$rSelected = $db->get_row();
				$rOutput = [];

				foreach ($ctx["locales"]["get_locales"] as $country => $code) {
					$rSelected = $rSelected["locale"] == $code ? 1 : 0;
					$rOutput[] = ["label" => $country, "value" => $code, "selected" => $rSelected];
				}

				exit(json_encode(["js" => $rOutput]));

			case "get_tv_aspects":
				if (!empty($ctx["device"]["aspect"])) {
					exit($ctx["device"]["aspect"]);
				}

				exit(json_encode($ctx["device"]["aspect"]));

			case "set_volume":
				$rVolume = $rRequest["vol"];

				if (empty($rVolume)) {
					break;
				}

				$ctx["device"]["volume"] = $rVolume;
				$db->query(
					"UPDATE `mag_devices` SET `volume` = ? WHERE `mag_id` = ?",
					$rVolume,
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["data" => true]));

			case "set_aspect":
				$rChannelID = $rRequest["ch_id"];
				$rAspect = $rRequest["aspect"];
				$rDeviceAspect = $ctx["device"]["aspect"];

				if (empty($rDeviceAspect)) {
					$ctx["device"]["aspect"] = ["js" => [$rChannelID => $rAspect]];
					$db->query(
						"UPDATE `mag_devices` SET `aspect` = ? WHERE mag_id = ?",
						json_encode(["js" => [$rChannelID => $rAspect]]),
						$ctx["device"]["mag_id"],
					);
				} else {
					$rDeviceAspect = json_decode($rDeviceAspect, true);
					$rDeviceAspect["js"][$rChannelID] = $rAspect;
					$ctx["device"]["aspect"] = $rDeviceAspect;
					$db->query(
						"UPDATE `mag_devices` SET `aspect` = ? WHERE mag_id = ?",
						json_encode($rDeviceAspect),
						$ctx["device"]["mag_id"],
					);
				}

				updatecache();

				exit(json_encode(["js" => true]));

			case "set_stream_error":
				exit(json_encode(["js" => true]));

			case "set_screensaver_delay":
				if (!empty($_SERVER["HTTP_COOKIE"])) {
					$rDelay = intval($rRequest["screensaver_delay"]);
					$ctx["device"]["screensaver_delay"] = $rDelay;
					$db->query(
						"UPDATE `mag_devices` SET `screensaver_delay` = ? WHERE `mag_id` = ?",
						$rDelay,
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "set_playback_buffer":
				if (!empty($_SERVER["HTTP_COOKIE"])) {
					$rBufferBytes = intval($rRequest["playback_buffer_bytes"]);
					$rBufferSize = intval($rRequest["playback_buffer_size"]);
					$ctx["device"]["playback_buffer_bytes"] = $rBufferBytes;
					$ctx["device"]["playback_buffer_size"] = $rBufferSize;
					$db->query(
						"UPDATE `mag_devices` SET `playback_buffer_bytes` = ? , `playback_buffer_size` = ? WHERE `mag_id` = ?",
						$rBufferBytes,
						$rBufferSize,
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "set_plasma_saving":
				$rPlasmaSaving = intval($rRequest["plasma_saving"]);
				$ctx["device"]["plasma_saving"] = $rPlasmaSaving;
				$db->query(
					"UPDATE `mag_devices` SET `plasma_saving` = ? WHERE `mag_id` = ?",
					$rPlasmaSaving,
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["js" => true]));

			case "set_parent_password":
				if (isset($rRequest["parent_password"])
					&& isset($rRequest["pass"])
					&& isset($rRequest["repeat_pass"])
					&& $rRequest["pass"] == $rRequest["repeat_pass"]
				) {
					$ctx["device"]["parent_password"] = $rRequest["pass"];
					$db->query(
						"UPDATE `mag_devices` SET `parent_password` = ? WHERE `mag_id` = ?",
						$rRequest["pass"],
						$ctx["device"]["mag_id"],
					);
					updatecache();

					exit(json_encode(["js" => true]));
				}

				exit(json_encode(["js" => true]));

			case "set_locale":
				if (!empty($rRequest["locale"])) {
					$ctx["device"]["locale"] = $rRequest["locale"];
					$db->query(
						"UPDATE `mag_devices` SET `locale` = ? WHERE `mag_id` = ?",
						$rRequest["locale"],
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => []]));

			case "set_hdmi_reaction":
				if (!empty($_SERVER["HTTP_COOKIE"]) && isset($rRequest["data"])) {
					$rReaction = $rRequest["data"];
					$ctx["device"]["hdmi_event_reaction"] = $rReaction;
					$db->query(
						"UPDATE `mag_devices` SET `hdmi_event_reaction` = ? WHERE `mag_id` = ?",
						$rReaction,
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));
		}
	}

	/**
	 * Phase 5 sub-handler: Watchdog actions.
	 * Actions: get_events, confirm_event.
	 * Also updates last_watchdog before processing action.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleWatchdog(string $rReqAction, array &$ctx) {
		global $db, $rRequest;

		$ctx["device"]["last_watchdog"] = time();
		$db->query(
			"UPDATE `mag_devices` SET `last_watchdog` = ? WHERE `mag_id` = ?",
			time(),
			$ctx["device"]["mag_id"],
		);
		updatecache();

		switch ($rReqAction) {
			case "get_events":
				$db->query(
					"SELECT * FROM `mag_events` WHERE `mag_device_id` = ? AND `status` = 0 ORDER BY `id` ASC LIMIT 1",
					$ctx["device"]["mag_id"],
				);
				$rData = ["data" => ["msgs" => 0, "additional_services_on" => 1]];

				if (0 < $db->num_rows()) {
					$rEvents = $db->get_row();
					$db->query(
						"SELECT count(*) FROM `mag_events` WHERE `mag_device_id` = ? AND `status` = 0 ",
						$ctx["device"]["mag_id"],
					);
					$rMessages = $db->get_col();
					$rData = [
						"data" => [
							"msgs" => $rMessages,
							"id" => $rEvents["id"],
							"event" => $rEvents["event"],
							"need_confirm" => $rEvents["need_confirm"],
							"msg" => $rEvents["msg"],
							"reboot_after_ok" => $rEvents["reboot_after_ok"],
							"auto_hide_timeout" => $rEvents["auto_hide_timeout"],
							"send_time" => date("d-m-Y H:i:s", $rEvents["send_time"]),
							"additional_services_on" => $rEvents["additional_services_on"],
							"updated" => ["anec" => $rEvents["anec"], "vclub" => $rEvents["vclub"]],
						],
					];
					$rAutoStatus = ["reboot", "reload_portal", "play_channel", "cut_off"];
					if (in_array($rEvents["event"], $rAutoStatus)) {
						$db->query(
							"UPDATE `mag_events` SET `status` = 1 WHERE `id` = ?",
							$rEvents["id"],
						);
					}
				}

				exit(json_encode(["js" => $rData], JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "confirm_event":
				if (empty($rRequest["event_active_id"])) {
					break;
				}

				$rActiveID = $rRequest["event_active_id"];
				$db->query("UPDATE `mag_events` SET `status` = 1 WHERE `id` = ?", $rActiveID);

				exit(json_encode(["js" => ["data" => "ok"]]));
		}
	}

	/**
	 * Phase 5 sub-handler: Audioclub actions.
	 * Actions: get_categories.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleAudioclub(string $rReqAction, array &$ctx) {
		global $rSettings, $rCategories;
		$rCategories = is_array($rCategories ?? null) ? $rCategories : [];

		if ($rReqAction === "get_categories") {
			$rOutput = [];
			$rOutput["js"] = [];
			if ($rSettings["show_all_category_mag"] == 1) {
				$rOutput["js"][] = [
					"id" => "*",
					"title" => "All",
					"alias" => "*",
					"censored" => 0,
				];
			}
			foreach ($rCategories as $rCategory) {
				if ($rCategory["category_type"] == "movie"
					&& in_array($rCategory["id"], $ctx["device"]["category_ids"])
				) {
					$rOutput["js"][] = [
						"id" => $rCategory["id"],
						"title" => $rCategory["category_name"],
						"alias" => $rCategory["category_name"],
						"censored" => intval($rCategory["is_adult"]),
					];
				}
			}
			exit(json_encode($rOutput));
		}
	}

	/**
	 * Phase 5 sub-handler: ITV (live TV) actions.
	 * Actions: create_link, set_claim, set_fav, get_fav_ids, get_all_channels,
	 *          get_ordered_list, get_all_fav_channels, get_epg_info, get_short_epg,
	 *          set_last_id, get_genres.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleItv(string $rReqAction, array &$ctx) {
		global $db, $rSettings, $rServers, $rRequest, $rCategories;
		$rCategories = is_array($rCategories ?? null) ? $rCategories : [];
		$rCategoryIDs = is_array($ctx["device"]["category_ids"] ?? null)
			? $ctx["device"]["category_ids"]
			: [];

		switch ($rReqAction) {
			case "create_link":
				$rCommand = $rRequest["cmd"];
				$rValue = "http://localhost/ch/";
				[$rStreamID, $rStreamValue] = explode(
					"_",
					substr($rCommand, strpos($rCommand, $rValue) + strlen($rValue)),
				);

				if (empty($rStreamValue)) {
					$rEncData =
						"ministra::live/" .
						$ctx["device"]["username"] .
						"/" .
						$ctx["device"]["password"] .
						"/" .
						$rStreamID .
						"/" .
						$rSettings["mag_container"] .
						"/" .
						$ctx["device"]["token"];
					$rToken = Encryption::mintToken(
						$rEncData,
						$rSettings["live_streaming_pass"],
						OPENSSL_EXTRA,
						!empty($rSettings["secure_stream_tokens"]),
					);
					$rURL =
						$ctx["player"] .
						($rSettings["mag_disable_ssl"]
							? $rServers[SERVER_ID]["http_url"]
							: $rServers[SERVER_ID]["site_url"]) .
						"play/" .
						$rToken;

					if ($rSettings["mag_keep_extension"]) {
						$rURL .= "?ext=." . $rSettings["mag_container"];
					}
				} else {
					$rURL = $ctx["player"] . $rStreamValue;
				}

				exit(json_encode([
					"js" => ["id" => $rStreamID, "cmd" => $rURL],
					"streamer_id" => 0,
					"link_id" => 0,
					"load" => 0,
					"error" => "",
				]));

			case "set_claim":
				if (!empty($rRequest["id"]) && !empty($rRequest["real_type"])) {
					$rID = intval($rRequest["id"]);
					$rRealType = $rRequest["real_type"];
					$rDate = date("Y-m-d H:i:s");
					$db->query(
						"INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)",
						$rID,
						$ctx["device"]["mag_id"],
						$rRealType,
						$rDate,
					);
				}

				exit(json_encode(["js" => true]));

			case "set_fav":
				$rChannels = empty($rRequest["fav_ch"]) ? "" : $rRequest["fav_ch"];
				$rChannels = array_filter(array_map("intval", explode(",", $rChannels)));
				$ctx["device"]["fav_channels"]["live"] = $rChannels;
				$db->query(
					"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
					json_encode($ctx["device"]["fav_channels"]),
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["js" => true]));

			case "get_fav_ids":
				exit(json_encode(["js" => $ctx["device"]["fav_channels"]["live"]]));

			case "get_all_channels":
				$rGenre =
					empty($rRequest["genre"]) || !is_numeric($rRequest["genre"])
					? null
					: intval($rRequest["genre"]);

				exit(getStreams($ctx["player"], $rGenre, true));

			case "get_ordered_list":
				$rFav = !empty($rRequest["fav"]) ? 1 : null;
				$rSortBy = !empty($rRequest["sortby"]) ? $rRequest["sortby"] : null;
				$rGenre =
					empty($rRequest["genre"]) || !is_numeric($rRequest["genre"])
					? null
					: intval($rRequest["genre"]);
				$rSearch = !empty($rRequest["search"]) ? $rRequest["search"] : null;

				exit(getStreams($ctx["player"], $rGenre, false, $rFav, $rSortBy, $rSearch));

			case "get_all_fav_channels":
				$rGenre =
					empty($rRequest["genre"]) || !is_numeric($rRequest["genre"])
					? null
					: intval($rRequest["genre"]);

				exit(getStreams($ctx["player"], $rGenre, true, 1));

			case "get_epg_info":
				exit(json_encode(["js" => ["data" => []]], JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_short_epg":
				$rEPG = ["js" => []];
				if (!empty($rRequest["ch_id"])) {
					$rChannelID = $rRequest["ch_id"];
					$rTime = time();
					$rEPGData = [];

					if (file_exists(EPG_PATH . "stream_" . intval($rChannelID))) {
						$rRows = igbinary_unserialize(
							file_get_contents(EPG_PATH . "stream_" . $rChannelID),
						);
						foreach ($rRows as $rRow) {
							if (($rRow["start"] <= $rTime && $rTime <= $rRow["end"])
								|| $rTime <= $rRow["start"]
							) {
								$rRow["start_timestamp"] = $rRow["start"];
								$rRow["stop_timestamp"] = $rRow["end"];
								$rEPGData[] = $rRow;
							}
						}
					}

					if ($rEPGData !== []) {
						$rTimeDifference = TimeUtils::getDiffTimezone($ctx["timezone"]) ?: 0;
						$i = 0;
						$counter = count($rEPGData);
						for ($n = 0; $n < $counter; $n++) {
							if ($rEPGData[$n]["end"] >= time()) {
								$rStartTime = new \DateTime();
								$rStartTime->setTimestamp($rEPGData[$n]["start"]);
								$rStartTime->modify($rTimeDifference . " seconds");
								$rEndTime = new \DateTime();
								$rEndTime->setTimestamp($rEPGData[$n]["end"]);
								$rEndTime->modify($rTimeDifference . " seconds");
								$rEPG["js"][$i]["id"] = $rEPGData[$n]["id"];
								$rEPG["js"][$i]["ch_id"] = $rChannelID;
								$rEPG["js"][$i]["correct"] = $rStartTime->format("Y-m-d H:i:s");
								$rEPG["js"][$i]["time"] = $rStartTime->format("Y-m-d H:i:s");
								$rEPG["js"][$i]["time_to"] = $rEndTime->format("Y-m-d H:i:s");
								$rEPG["js"][$i]["duration"] =
									$rEPGData[$n]["stop_timestamp"] -
									$rEPGData[$n]["start_timestamp"];
								$rEPG["js"][$i]["name"] = $rEPGData[$n]["title"];
								$rEPG["js"][$i]["descr"] = $rEPGData[$n]["description"];
								$rEPG["js"][$i]["real_id"] =
									$rChannelID . "_" . $rEPGData[$n]["start_timestamp"];
								$rEPG["js"][$i]["category"] = "";
								$rEPG["js"][$i]["director"] = "";
								$rEPG["js"][$i]["actor"] = "";
								$rEPG["js"][$i]["start_timestamp"] = $rStartTime->getTimestamp();
								$rEPG["js"][$i]["stop_timestamp"] = $rEndTime->getTimestamp();
								$rEPG["js"][$i]["t_time"] = $rStartTime->format("H:i");
								$rEPG["js"][$i]["t_time_to"] = $rEndTime->format("H:i");
								$rEPG["js"][$i]["mark_memo"] = 0;
								$rEPG["js"][$i]["mark_archive"] = 0;

								if (count($rEPG["js"]) != (intval($rRequest["size"]) ?: 4)) {
									$i++;
								}
							}
						}
					}
				}

				exit(json_encode($rEPG, JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "set_last_id":
				$rChannelID = intval($rRequest["id"]);

				if (0 < $rChannelID) {
					$ctx["device"]["last_itv_id"] = $rChannelID;
					$db->query(
						"UPDATE `mag_devices` SET `last_itv_id` = ? WHERE `mag_id` = ?",
						$rChannelID,
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "get_genres":
				$rOutput = [];
				$rNumber = 1;

				if ($rSettings["show_all_category_mag"] == 1) {
					$rOutput["js"][] = [
						"id" => "*",
						"title" => "All",
						"alias" => "All",
						"active_sub" => true,
						"censored" => 0,
					];
				}

				foreach ($rCategories as $rCategory) {
					if ($rCategory["category_type"] == "live"
						&& in_array($rCategory["id"], $rCategoryIDs)
					) {
						$rOutput["js"][] = [
							"id" => $rCategory["id"],
							"title" => $rCategory["category_name"],
							"modified" => "",
							"number" => (string) $rNumber++,
							"alias" => strtolower($rCategory["category_name"]),
							"censored" => (string) intval($rCategory["is_adult"]),
						];
					}
				}

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
		}
	}

	/**
	 * Phase 5 sub-handler: VOD (Video On Demand) actions.
	 * Actions: set_claim, set_fav, del_fav, get_categories, get_genres_by_category_alias,
	 *          get_years, get_ordered_list, create_link, get_abc.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleVod(string $rReqAction, array &$ctx) {
		global $db, $rSettings, $rServers, $rRequest, $rCategories;
		$rCategories = is_array($rCategories ?? null) ? $rCategories : [];
		$rCategoryIDs = is_array($ctx["device"]["category_ids"] ?? null)
			? $ctx["device"]["category_ids"]
			: [];

		switch ($rReqAction) {
			case "set_claim":
				if (!empty($rRequest["id"]) && !empty($rRequest["real_type"])) {
					$rID = intval($rRequest["id"]);
					$rRealType = $rRequest["real_type"];
					$rDate = date("Y-m-d H:i:s");
					$db->query(
						"INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)",
						$rID,
						$ctx["device"]["mag_id"],
						$rRealType,
						$rDate,
					);
				}

				exit(json_encode(["js" => true]));

			case "set_fav":
				if (!empty($rRequest["video_id"])) {
					$rVideoID = intval($rRequest["video_id"]);
					if (!in_array($rVideoID, $ctx["device"]["fav_channels"]["movie"])) {
						$ctx["device"]["fav_channels"]["movie"][] = $rVideoID;
					}
					$db->query(
						"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
						json_encode($ctx["device"]["fav_channels"]),
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "del_fav":
				if (!empty($rRequest["video_id"])) {
					$rVideoID = intval($rRequest["video_id"]);
					foreach ($ctx["device"]["fav_channels"]["movie"] as $rKey => $rValue) {
						if ($rValue == $rVideoID) {
							unset($ctx["device"]["fav_channels"]["movie"][$rKey]);
							break;
						}
					}
					$db->query(
						"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
						json_encode($ctx["device"]["fav_channels"]),
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "get_categories":
				$rOutput = [];
				$rOutput["js"] = [];

				if ($rSettings["show_all_category_mag"] == 1) {
					$rOutput["js"][] = [
						"id" => "*",
						"title" => "All",
						"alias" => "*",
						"censored" => 0,
					];
				}

				foreach ($rCategories as $rCategory) {
					if ($rCategory["category_type"] == "movie"
						&& in_array($rCategory["id"], $rCategoryIDs)
					) {
						$rOutput["js"][] = [
							"id" => $rCategory["id"],
							"title" => $rCategory["category_name"],
							"alias" => $rCategory["category_name"],
							"censored" => intval($rCategory["is_adult"]),
						];
					}
				}

				exit(json_encode($rOutput));

			case "get_genres_by_category_alias":
				$rOutput = [];
				$rOutput["js"][] = ["id" => "*", "title" => "*"];

				foreach ($rCategories as $rCategory) {
					if ($rCategory["category_type"] == "movie"
						&& in_array($rCategory["id"], $rCategoryIDs)
					) {
						$rOutput["js"][] = [
							"id" => $rCategory["id"],
							"title" => $rCategory["category_name"],
						];
					}
				}

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_years":
				exit(json_encode($ctx["magData"]["get_years"]));

			case "get_ordered_list":
				$rCategory =
					!empty($rRequest["category"]) && is_numeric($rRequest["category"])
					? $rRequest["category"]
					: null;
				$rFav = !empty($rRequest["fav"]) ? 1 : null;
				$rSortBy = !empty($rRequest["sortby"]) ? $rRequest["sortby"] : "added";
				$rSearch = !empty($rRequest["search"]) ? $rRequest["search"] : null;
				$rPicking = [];
				$rPicking["abc"] = !empty($rRequest["abc"]) ? $rRequest["abc"] : "*";
				$rPicking["genre"] = !empty($rRequest["genre"]) ? $rRequest["genre"] : "*";
				$rPicking["years"] = !empty($rRequest["years"]) ? $rRequest["years"] : "*";

				exit(getMovies($rCategory, $rFav, $rSortBy, $rSearch, $rPicking));

			case "create_link":
				$rCommand = $rRequest["cmd"];
				$rSeries = !empty($rRequest["series"]) ? (int) $rRequest["series"] : 0;
				$rError = "";

				if (!stristr($rCommand, "/media/")) {
					$rCommand = json_decode(base64_decode($rCommand), true);
				} else {
					$rCommand = ["series_data" => $rCommand, "type" => "series"];
				}

				if ($rSeries) {
					$rCommand["type"] = "series";
				}

				$rValid = false;

				switch ($rCommand["type"]) {
					case "movie":
						$rValid = in_array($rCommand["stream_id"], $ctx["device"]["vod_ids"]);

						break;

					case "series":
						if (!empty($rCommand["series_data"])) {
							[$rCommand["series_id"], $rCommand["season_num"]] = explode(
								":",
								basename($rCommand["series_data"], ".mpg"),
							);
						}

						$db->query(
							"SELECT t1.stream_id,t2.target_container FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id WHERE t1.`series_id` = ? AND t1.`season_num` = ? ORDER BY `episode_num` ASC LIMIT " .
								intval($rSeries - 1) .
								", 1",
							$rCommand["series_id"],
							$rCommand["season_num"],
						);

						if (0 < $db->num_rows()) {
							$rRow = $db->get_row();
							$rCommand["stream_id"] = $rRow["stream_id"];
							$rCommand["target_container"] = $rRow["target_container"];
							$rValid = in_array(
								$rCommand["series_id"],
								$ctx["device"]["series_ids"],
							);
						} else {
							$rError = "player_file_missing";
						}
				}
				$rEncData =
					"ministra::" .
					$rCommand["type"] .
					"/" .
					$ctx["device"]["username"] .
					"/" .
					$ctx["device"]["password"] .
					"/" .
					$rCommand["stream_id"] .
					"/" .
					$rCommand["target_container"] .
					"/" .
					$ctx["device"]["token"];
				$rToken = Encryption::mintToken(
					$rEncData,
					$rSettings["live_streaming_pass"],
					OPENSSL_EXTRA,
					!empty($rSettings["secure_stream_tokens"]),
				);
				$rURL =
					($rSettings["mag_disable_ssl"]
						? $rServers[SERVER_ID]["http_url"]
						: $rServers[SERVER_ID]["site_url"]) .
					"play/" .
					$rToken;

				if ($rSettings["mag_keep_extension"]) {
					$rURL .= "?ext=." . $rCommand["target_container"];
				}

				$rOutput = [
					"js" => [
						"id" => $rCommand["stream_id"],
						"cmd" => $ctx["player"] . $rURL,
						"load" => "",
						"subtitles" => [],
						"error" => $rError,
					],
				];

				exit(json_encode($rOutput));

			case "get_abc":
				exit(json_encode($ctx["magData"]["get_abc"]));
		}
	}

	/**
	 * Phase 5 sub-handler: Series actions.
	 * Actions: set_claim, set_fav, del_fav, get_categories, get_genres_by_category_alias,
	 *          get_years, get_ordered_list, get_abc.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleSeries(string $rReqAction, array &$ctx) {
		global $db, $rSettings, $rRequest, $rCategories;
		$rCategories = is_array($rCategories ?? null) ? $rCategories : [];
		$rCategoryIDs = is_array($ctx["device"]["category_ids"] ?? null)
			? $ctx["device"]["category_ids"]
			: [];

		switch ($rReqAction) {
			case "set_claim":
				if (!empty($rRequest["id"]) && !empty($rRequest["real_type"])) {
					$rID = intval($rRequest["id"]);
					$rRealType = $rRequest["real_type"];
					$rDate = date("Y-m-d H:i:s");
					$db->query(
						"INSERT INTO `mag_claims` (`stream_id`,`mag_id`,`real_type`,`date`) VALUES(?,?,?,?)",
						$rID,
						$ctx["device"]["mag_id"],
						$rRealType,
						$rDate,
					);
				}

				exit(json_encode(["js" => true]));

			case "set_fav":
				if (!empty($rRequest["video_id"])) {
					$rVideoID = intval($rRequest["video_id"]);
					if (!in_array($rVideoID, $ctx["device"]["fav_channels"]["series"])) {
						$ctx["device"]["fav_channels"]["series"][] = $rVideoID;
					}
					$db->query(
						"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
						json_encode($ctx["device"]["fav_channels"]),
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "del_fav":
				if (!empty($rRequest["video_id"])) {
					$rVideoID = intval($rRequest["video_id"]);
					foreach ($ctx["device"]["fav_channels"]["series"] as $rKey => $rValue) {
						if ($rValue == $rVideoID) {
							unset($ctx["device"]["fav_channels"]["series"][$rKey]);
							break;
						}
					}
					$db->query(
						"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
						json_encode($ctx["device"]["fav_channels"]),
						$ctx["device"]["mag_id"],
					);
					updatecache();
				}

				exit(json_encode(["js" => true]));

			case "get_categories":
				$rOutput = [];
				$rOutput["js"] = [];

				if ($rSettings["show_all_category_mag"] == 1) {
					$rOutput["js"][] = [
						"id" => "*",
						"title" => "All",
						"alias" => "*",
						"censored" => 0,
					];
				}

				foreach ($rCategories as $rCategory) {
					if ($rCategory["category_type"] == "series"
						&& in_array($rCategory["id"], $rCategoryIDs)
					) {
						$rOutput["js"][] = [
							"id" => $rCategory["id"],
							"title" => $rCategory["category_name"],
							"alias" => $rCategory["category_name"],
							"censored" => intval($rCategory["is_adult"]),
						];
					}
				}

				exit(json_encode($rOutput));

			case "get_genres_by_category_alias":
				$rOutput = [];
				$rOutput["js"][] = ["id" => "*", "title" => "*"];

				foreach ($rCategories as $rCategory) {
					if ($rCategory["category_type"] == "series"
						&& in_array($rCategory["id"], $rCategoryIDs)
					) {
						$rOutput["js"][] = [
							"id" => $rCategory["id"],
							"title" => $rCategory["category_name"],
						];
					}
				}

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_years":
				exit(json_encode($ctx["magData"]["get_years"]));

			case "get_ordered_list":
				$rCategory =
					!empty($rRequest["category"]) && is_numeric($rRequest["category"])
					? $rRequest["category"]
					: null;
				$rFav = !empty($rRequest["fav"]) ? 1 : null;
				$rSortBy = !empty($rRequest["sortby"]) ? $rRequest["sortby"] : "added";
				$rSearch = !empty($rRequest["search"]) ? $rRequest["search"] : null;
				$rMovieID = !empty($rRequest["movie_id"]) ? (int) $rRequest["movie_id"] : null;
				$rPicking = [];
				$rPicking["abc"] = !empty($rRequest["abc"]) ? $rRequest["abc"] : "*";
				$rPicking["genre"] = !empty($rRequest["genre"]) ? $rRequest["genre"] : "*";
				$rPicking["years"] = !empty($rRequest["years"]) ? $rRequest["years"] : "*";

				exit(getSeries($rMovieID, $rCategory, $rFav, $rSortBy, $rSearch, $rPicking));

			case "get_abc":
				exit(json_encode($ctx["magData"]["get_abc"]));
		}
	}

	/**
	 * Phase 5 sub-handler: Account info actions.
	 * Actions: get_main_info.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleAccountInfo(string $rReqAction, array &$ctx) {
		global $rSettings;

		if ($rReqAction === "get_main_info") {
			if (empty($ctx["device"]["exp_date"])) {
					$rExpiry = "Unlimited";
			} else {
				$rExpiry = date("F j, Y, g:i a", $ctx["device"]["exp_date"]);
			}
			exit(json_encode([
				"js" => [
					"mac" => $ctx["mac"],
					"phone" => $rExpiry,
					"message" => htmlspecialchars_decode(
						str_replace("\n", "<br/>", $rSettings["mag_message"]),
					),
				],
			]));
		}
	}

	/**
	 * Phase 5 sub-handler: Radio actions.
	 * Actions: get_ordered_list, get_all_fav_radio, set_fav, get_fav_ids.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleRadio(string $rReqAction, array &$ctx) {
		global $db, $rRequest;

		switch ($rReqAction) {
			case "get_ordered_list":
				$rFav = !empty($rRequest["fav"]) ? 1 : null;
				$rSortBy = !empty($rRequest["sortby"]) ? $rRequest["sortby"] : "added";

				exit(getStations($ctx["player"], null, $rFav, $rSortBy));

			case "get_all_fav_radio":
				exit(getStations($ctx["player"], null, 1));

			case "set_fav":
				$f3f9f9fa3c58c22b = empty($rRequest["fav_radio"]) ? "" : $rRequest["fav_radio"];
				$f3f9f9fa3c58c22b = array_filter(
					array_map("intval", explode(",", $f3f9f9fa3c58c22b)),
				);
				$ctx["device"]["fav_channels"]["radio_streams"] = $f3f9f9fa3c58c22b;
				$db->query(
					"UPDATE `mag_devices` SET `fav_channels` = ? WHERE `mag_id` = ?",
					json_encode($ctx["device"]["fav_channels"]),
					$ctx["device"]["mag_id"],
				);
				updatecache();

				exit(json_encode(["js" => true]));

			case "get_fav_ids":
				exit(json_encode(["js" => $ctx["device"]["fav_channels"]["radio_streams"]]));
		}
	}

	/**
	 * Phase 5 sub-handler: TV Archive actions.
	 * Actions: get_next_part_url, create_link, get_link_for_channel.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleTvArchive(string $rReqAction, array &$ctx) {
		global $db, $rSettings, $rServers, $rRequest;

		switch ($rReqAction) {
			case "get_next_part_url":
				if (!empty($rRequest["id"])) {
					$rID = $rRequest["id"];
					$rStreamID = substr($rID, 0, strpos($rID, "_"));
					// The id is an EPG item's real_id, "<stream>_<start of the programme on
					// screen>": the next part is the first programme that starts after it.
					$rDate = intval(substr($rID, strpos($rID, "_") + 1));
					$rRow = null;
					foreach (getepg($rStreamID, $rDate, $rDate + 86400) as $rProgramme) {
						if (0 < $rDate && $rDate < $rProgramme["start"]) {
							$rRow = $rProgramme;
							break;
						}
					}
					if ($rRow) {
						$rProgramStart = $rRow["start"];
						$rDuration = intval(($rRow["end"] - $rRow["start"]) / 60);
						$rTitle = $rRow["title"];
						$rEncData =
							"ministra::timeshift/" .
							$ctx["device"]["username"] .
							"/" .
							$ctx["device"]["password"] .
							"/" .
							$rDuration .
							"/" .
							$rProgramStart .
							"/" .
							$rStreamID .
							"/" .
							$ctx["device"]["token"];
						$rToken = Encryption::mintToken(
							$rEncData,
							$rSettings["live_streaming_pass"],
							OPENSSL_EXTRA,
							!empty($rSettings["secure_stream_tokens"]),
						);
						// The box decodes the title as a URL component, and asks for the part
						// after this one with the real_id it reads here.
						$rURL =
							($rSettings["mag_disable_ssl"]
								? $rServers[SERVER_ID]["http_url"]
								: $rServers[SERVER_ID]["site_url"]) .
							"play/" .
							$rToken .
							"?&osd_title=" .
							rawurlencode($rTitle) .
							"&real_id=" .
							intval($rStreamID) .
							"_" .
							$rProgramStart;
						if ($rSettings["mag_keep_extension"]) {
							$rURL .= "&ext=.ts";
						}
						exit(json_encode(["js" => $ctx["player"] . $rURL]));
					}
				}

				exit(json_encode(["js" => false]));

			case "create_link":
				$rCommand = empty($rRequest["cmd"]) ? "" : $rRequest["cmd"];
				[$rEPGDataID, $rStreamID] = explode("_", pathinfo($rCommand)["filename"]);
				$rRow = getprogramme($rStreamID, $rEPGDataID) ?: null;

				if (!$rRow) {
					break;
				}

				$rStart = $rRow["start"];
				$rDuration = intval(($rRow["end"] - $rRow["start"]) / 60);
				$rEncData =
					"ministra::timeshift/" .
					$ctx["device"]["username"] .
					"/" .
					$ctx["device"]["password"] .
					"/" .
					$rDuration .
					"/" .
					$rStart .
					"/" .
					$rStreamID .
					"/" .
					$ctx["device"]["token"];
				$rToken = Encryption::mintToken(
					$rEncData,
					$rSettings["live_streaming_pass"],
					OPENSSL_EXTRA,
					!empty($rSettings["secure_stream_tokens"]),
				);
				$rURL =
					($rSettings["mag_disable_ssl"]
						? $rServers[SERVER_ID]["http_url"]
						: $rServers[SERVER_ID]["site_url"]) .
					"play/" .
					$rToken;

				if ($rSettings["mag_keep_extension"]) {
					$rURL .= "?ext=.ts";
				}

				$rOutput["js"] = [
					"id" => 0,
					"cmd" => $ctx["player"] . $rURL,
					"storage_id" => "",
					"load" => 0,
					"error" => "",
					"download_cmd" => $rURL,
					"to_file" => "",
				];

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_link_for_channel":
				$rOutput = [];
				$rChannelID = !empty($rRequest["ch_id"]) ? intval($rRequest["ch_id"]) : 0;
				$rStart = strtotime(date("Ymd-H"));
				$rEncData =
					"ministra::timeshift/" .
					$ctx["device"]["username"] .
					"/" .
					$ctx["device"]["password"] .
					"/60/" .
					$rStart .
					"/" .
					$rChannelID .
					"/" .
					$ctx["device"]["token"];
				$rToken = Encryption::mintToken(
					$rEncData,
					$rSettings["live_streaming_pass"],
					OPENSSL_EXTRA,
					!empty($rSettings["secure_stream_tokens"]),
				);
				$rURL =
					($rSettings["mag_disable_ssl"]
						? $rServers[SERVER_ID]["http_url"]
						: $rServers[SERVER_ID]["site_url"]) .
					"play/" .
					$rToken .
					($rSettings["mag_keep_extension"] ? "?ext=.ts" : "") .
					" position:" .
					(intval(date("i")) * 60 + intval(date("s"))) .
					" media_len:" .
					(intval(date("H")) * 3600 + intval(date("i")) * 60 + intval(date("s")));
				$rOutput["js"] = [
					"id" => 0,
					"cmd" => $ctx["player"] . $rURL,
					"storage_id" => "",
					"load" => 0,
					"error" => "",
				];

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
		}
	}

	/**
	 * Phase 5 sub-handler: EPG (Electronic Program Guide) actions.
	 * Actions: get_week, get_data_table, get_simple_data_table, get_all_program_for_ch.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleEpg(string $rReqAction, array &$ctx) {
		global $db, $rRequest;

		switch ($rReqAction) {
			case "get_week":
				$k = -16;
				$i = 0;
				$rEPGWeek = [];
				$rCurDate = strtotime(date("Y-m-d"));

				while ($k < 10) {
					// >=  fixed???
					$rThisDate = $rCurDate + $k * 86400;
					$rEPGWeek["js"][$i]["f_human"] = date("D d F", $rThisDate);
					$rEPGWeek["js"][$i]["f_mysql"] = date("Y-m-d", $rThisDate);
					$rEPGWeek["js"][$i]["today"] = $k == 0 ? 1 : 0;
					$k++;
					$i++;
				}

				exit(json_encode($rEPGWeek));

			case "get_data_table":
				exit(json_encode(["js" => []], JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_simple_data_table":
				if (empty($rRequest["ch_id"]) || empty($rRequest["date"])) {
					exit();
				}

				$rChannelID = $rRequest["ch_id"];
				$rReqDate = $rRequest["date"];
				$rPage = intval($rRequest["p"]);
				$rPageItems = $ctx["theme"] == "xc_vm" ? 7 : 10;
				$rDefaultPage = false;
				$rEPGDatas = [];
				$rStartTime = strtotime($rReqDate . " 00:00:00");
				$rEndTime = strtotime($rReqDate . " 23:59:59");

				if (file_exists(EPG_PATH . "stream_" . intval($rChannelID))) {
					$rRows = igbinary_unserialize(
						file_get_contents(EPG_PATH . "stream_" . $rChannelID),
					);
					foreach ($rRows as $rRow) {
						if ($rStartTime <= $rRow["start"] && $rRow["start"] <= $rEndTime) {
							$rRow["start_timestamp"] = $rRow["start"];
							$rRow["stop_timestamp"] = $rRow["end"];
							$rEPGDatas[] = $rRow;
						}
					}
				}

				if (file_exists(STREAMS_TMP_PATH . "stream_" . intval($rChannelID))) {
					$rStreamRow = igbinary_unserialize(
						file_get_contents(STREAMS_TMP_PATH . "stream_" . intval($rChannelID)),
					)["info"];
				} else {
					$db->query(
						"SELECT `tv_archive_duration` FROM `streams` WHERE `id` = ?;",
						$rRequest["ch_id"],
					);

					if (0 < $db->num_rows()) {
						$rStreamRow = $db->get_row();
					}
				}

				$rChannelIDx = 0;

				foreach ($rEPGDatas as $rKey => $rEPGData) {
					if ($rEPGData["start_timestamp"] <= time()
						&& time() <= $rEPGData["stop_timestamp"]
					) {
						$rChannelIDx = $rKey + 1;
						break;
					}
				}
				if ($rPage == 0) {
					$rDefaultPage = true;
					$rPage = ceil($rChannelIDx / $rPageItems);
					if ($rPage == 0) {
						$rPage = 1;
					}
					if ($rReqDate != date("Y-m-d")) {
						$rPage = 1;
						$rDefaultPage = false;
					}
				}

				$rProgram = array_slice($rEPGDatas, ($rPage - 1) * $rPageItems, $rPageItems);
				$rData = [];
				$rTimeDifference = TimeUtils::getDiffTimezone($ctx["timezone"]);
				$counter = count($rProgram);

				for ($i = 0; $i < $counter; $i++) {
					$open = 0;

					if (time() <= $rProgram[$i]["stop_timestamp"]) {
						$open = 1;
					}

					$rStartTime = new \DateTime();
					$rStartTime->setTimestamp($rProgram[$i]["start"]);
					$rStartTime->modify($rTimeDifference . " seconds");
					$rEndTime = new \DateTime();
					$rEndTime->setTimestamp($rProgram[$i]["end"]);
					$rEndTime->modify($rTimeDifference . " seconds");
					$rData[$i]["id"] = $rProgram[$i]["id"] . "_" . $rChannelID;
					$rData[$i]["ch_id"] = $rChannelID;
					$rData[$i]["time"] = $rStartTime->format("Y-m-d H:i:s");
					$rData[$i]["time_to"] = $rEndTime->format("Y-m-d H:i:s");
					$rData[$i]["duration"] =
						$rProgram[$i]["stop_timestamp"] - $rProgram[$i]["start_timestamp"];
					$rData[$i]["name"] = $rProgram[$i]["title"];
					$rData[$i]["descr"] = $rProgram[$i]["description"];
					$rData[$i]["real_id"] = $rChannelID . "_" . $rProgram[$i]["start"];
					$rData[$i]["category"] = "";
					$rData[$i]["director"] = "";
					$rData[$i]["actor"] = "";
					$rData[$i]["start_timestamp"] = $rStartTime->getTimestamp();
					$rData[$i]["stop_timestamp"] = $rEndTime->getTimestamp();
					$rData[$i]["t_time"] = $rStartTime->format("H:i");
					$rData[$i]["t_time_to"] = $rEndTime->format("H:i");
					$rData[$i]["open"] = $open;
					$rData[$i]["mark_memo"] = 0;
					$rData[$i]["mark_rec"] = 0;
					$rData[$i]["mark_archive"] =
						!empty($rStreamRow["tv_archive_duration"]) &&
						$rEndTime->getTimestamp() < time() &&
						strtotime("-" . $rStreamRow["tv_archive_duration"] . " days") <=
						$rEndTime->getTimestamp()
						? 1
						: 0;
				}

				if ($rDefaultPage) {
					$rCurrentPage = $rPage;
					$rSelectedItem = $rChannelIDx - ($rPage - 1) * $rPageItems;
				} else {
					$rCurrentPage = 0;
					$rSelectedItem = 0;
				}

				$rOutput = [];
				$rOutput["js"]["cur_page"] = $rCurrentPage;
				$rOutput["js"]["selected_item"] = $rSelectedItem;
				$rOutput["js"]["total_items"] = count($rEPGDatas);
				$rOutput["js"]["max_page_items"] = $rPageItems;
				$rOutput["js"]["data"] = $rData;

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));

			case "get_all_program_for_ch":
				$rOutput = [];
				$rOutput["js"] = [];
				$rChannelID = empty($rRequest["ch_id"]) ? 0 : intval($rRequest["ch_id"]);
				$rTimeDifference = TimeUtils::getDiffTimezone($ctx["timezone"]);

				if (file_exists(STREAMS_TMP_PATH . "stream_" . intval($rChannelID))) {
					$rStreamRow = igbinary_unserialize(
						file_get_contents(STREAMS_TMP_PATH . "stream_" . intval($rChannelID)),
					)["info"];
				} else {
					$db->query(
						"SELECT `tv_archive_duration` FROM `streams` WHERE `id` = ?;",
						$rRequest["ch_id"],
					);

					if (0 < $db->num_rows()) {
						$rStreamRow = $db->get_row();
					}
				}

				$rTime = strtotime(date("Y-m-d 00:00:00"));

				if (file_exists(EPG_PATH . "stream_" . intval($rChannelID))) {
					$rRows = igbinary_unserialize(
						file_get_contents(EPG_PATH . "stream_" . $rChannelID),
					);
					foreach ($rRows as $rRow) {
						if ($rTime <= $rRow["start"]) {
							$rRow["start_timestamp"] = $rRow["start"];
							$rRow["stop_timestamp"] = $rRow["end"];
							$rStartTime = new \DateTime();
							$rStartTime->setTimestamp($rRow["start"]);
							$rStartTime->modify($rTimeDifference . " seconds");
							$rEndTime = new \DateTime();
							$rEndTime->setTimestamp($rRow["end"]);
							$rEndTime->modify($rTimeDifference . " seconds");
							$rOutput["js"][] = [
								"start_timestamp" => $rStartTime->getTimestamp(),
								"stop_timestamp" => $rEndTime->getTimestamp(),
								"name" => $rRow["title"],
							];
						}
					}
				}

				exit(json_encode($rOutput, JSON_PARTIAL_OUTPUT_ON_ERROR));
		}
	}

	/**
	 * Phase 6: Unauthenticated fallthrough handler.
	 * Exits: stb/get_profile, the request the bruteforce check counts, is answered
	 * in handleStbPublic() and never gets here.
	 *
	 * @param array  &$ctx Context array
	 */
	public static function handleUnauthenticated(string $rReqType, string $rReqAction, array &$ctx) {
		// A missing or stale token (e.g. replaced by a newer handshake of the same
		// MAC) gets the canonical Stalker middleware answer: Stalker clients take
		// this exact plain-text body as the cue to handshake again, while an empty
		// reply left them stuck on an "unknown error".
		exit('Authorization failed.');
	}

	/** Seconds in the period a handshake's token is made in: get_profile takes the token in that period and in the next. */
	private const HANDSHAKE_PERIOD = 600;

	/**
	 * The token a handshake hands a registered device: eight random bytes, then a
	 * keyed digest of them, of the device's number and of the period it is made
	 * in. Nothing is kept for it: by the digest get_profile tells that a handshake
	 * made the token for this device, and not long ago.
	 *
	 * @param string|null $rRandom The random part of a token, to make that token again
	 * @param int         $rAgo    How many periods ago that token was made
	 */
	private static function handshakeToken($rMagID, ?string $rRandom = null, int $rAgo = 0): string {
		global $rSettings;

		$rRandom ??= strtoupper(bin2hex(random_bytes(8)));
		$rPeriod = intdiv(time(), self::HANDSHAKE_PERIOD) - $rAgo;
		$rDigest = hash_hmac("sha256", $rMagID . "|" . $rRandom . "|" . $rPeriod, "mag handshake|" . $rSettings["live_streaming_pass"]);

		return $rRandom . strtoupper(substr($rDigest, 0, 16));
	}

	/**
	 * The device a handshake made $rToken for, as the panel has it now: what
	 * get_profile verifies a box that comes with that token against. The token is
	 * not the device's yet, and the device's entry is neither read nor written.
	 *
	 * @return array The device, not verified; [] when no handshake made the token, lately, for a device the panel has
	 */
	public static function handshakeDevice($rMagID, $rToken) {
		global $db;

		if (!is_scalar($rMagID) || !is_string($rToken) || strlen($rToken) != 32) {
			return [];
		}
		$rRandom = substr($rToken, 0, 16);
		if (!hash_equals(self::handshakeToken($rMagID, $rRandom), $rToken)
			&& !hash_equals(self::handshakeToken($rMagID, $rRandom, 1), $rToken)
		) {
			return [];
		}
		// As in the handshake: a lookup that failed says nothing about the device.
		if (!$db->query("SELECT `mac` FROM `mag_devices` WHERE `mag_id` = ? LIMIT 1", $rMagID)) {
			http_response_code(503);
			exit();
		}
		$rRow = $db->get_row();
		$rDevice = empty($rRow["mac"]) ? null : getdevice(null, $rRow["mac"]);
		if (!is_array($rDevice) && !empty($rRow["mac"])) {
			http_response_code(503);
			exit();
		}

		return is_array($rDevice) && (string) $rDevice["mag_id"] === (string) $rMagID ? $rDevice : [];
	}

	/**
	 * A box get_profile has verified came with a handshake's token: from here on
	 * that is the device's token, the one its stream links are checked against,
	 * and the one before it stops.
	 */
	public static function adoptToken(string $rToken) {
		global $db, $rDevice;
		static $rLock = null;

		// One verified get_profile of a device at a time stores its token and
		// writes the device's entry: two at once would leave the entry under one
		// token and the database under the other. Held until the request ends.
		$rLock = @fopen(MINISTRA_TMP_PATH . "ministra_" . intval($rDevice["mag_id"]) . ".lock", "c");
		if ($rLock) {
			flock($rLock, LOCK_EX);
		}
		// Not stored, the token is not the device's: the box comes again, and the device keeps the one it has.
		if (!$db->query("UPDATE `mag_devices` SET `token` = ? WHERE `mag_id` = ?", $rToken, $rDevice["mag_id"])) {
			http_response_code(503);
			exit();
		}
		$rDevice["token"] = $rToken;
		SignalDispatcher::cache(intval(SERVER_ID), ["type" => "update_line", "id" => $rDevice["user_id"]], false, false, $db);
	}

	/**
	 * Phase 7: Handshake — token generation.
	 * Hands the device identified by MAC a token to come to get_profile with, and exits with it.
	 * The device keeps the token and the entry it has until get_profile has verified that box.
	 *
	 */
	public static function handleHandshake(string $rMAC) {
		global $db, $rSettings, $rDevice, $rIP;

		$rDevice = getdevice(null, $rMAC);
		$rVerifyToken = null;

		if ($rDevice) {
			$rVerifyToken = Encryption::mintToken(
				igbinary_serialize(["id" => $rDevice["mag_id"], "token" => self::handshakeToken($rDevice["mag_id"])]),
				$rSettings["live_streaming_pass"],
				OPENSSL_EXTRA,
				!empty($rSettings["secure_stream_tokens"]),
			);
			// A device that has no entry gets one, not verified, under the token it has.
			if (!file_exists(MINISTRA_TMP_PATH . "ministra_" . intval($rDevice["mag_id"]))) {
				updatecache();
			}
		} else {
			$rDevice = [];
			// getDevice() answers a MAC the panel does not have and a lookup that failed
			// alike, and a failed lookup says nothing about the device. The MAC is
			// unknown once the database has said so: until then nothing is counted and
			// no token goes out.
			if (!$db->query("SELECT `mag_id` FROM `mag_devices` WHERE `mac` = ? LIMIT 1", $rMAC) || 0 < $db->num_rows()) {
				http_response_code(503);
				exit();
			}
			// An unknown MAC is a failed attempt (counted by its digest, as in
			// get_profile). It is answered as a registered one is: with a token of the
			// same form. Its number is derived from the MAC as sent, so the token is as
			// long each time and as long as a device's, and starts with 0, so it is no
			// device's number.
			BruteforceGuard::checkBruteforce($rIP, hash("sha256", $rMAC));
			BruteforceGuard::checkFlood();
			RefusalLog::record("INVALID_CREDENTIALS", $rIP);
			$db->query("SELECT MAX(`mag_id`) AS `last` FROM `mag_devices`");
			$rLastID = max(1, intval($db->get_row()["last"]));
			$rNumber = 1 + hexdec(substr(hash_hmac("sha256", $rMAC, (string) $rSettings["live_streaming_pass"]), 0, 8)) % $rLastID;
			$rVerifyToken = Encryption::mintToken(
				igbinary_serialize(["id" => "0" . substr((string) $rNumber, 1), "token" => strtoupper(bin2hex(random_bytes(16)))]),
				$rSettings["live_streaming_pass"],
				OPENSSL_EXTRA,
				!empty($rSettings["secure_stream_tokens"]),
			);
		}

		exit(json_encode(["js" => ["token" => $rVerifyToken]]));
	}
}

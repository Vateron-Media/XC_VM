<?php

/**
 * Валидация входных данных для admin API.
 * Извлечено из API::checkMinimumRequirements() (Phase 8.1).
 */
class InputValidator {
	/**
	 * Проверяет минимальные требования к данным для указанного действия.
	 *
	 * @param string $rAction Имя действия (бывшее имя метода API)
	 * @param array  $rData   Массив входных данных
	 * @return bool true если данные валидны
	 */
	public static function validate(string $rAction, array $rData): bool {
		switch ($rAction) {
			case 'scheduleRecording':
				return !empty($rData['title']) && !empty($rData['source_id']);

			case 'processProvider':
				return !empty($rData['ip']) && !empty($rData['port']) && !empty($rData['username']) && !empty($rData['password']) && !empty($rData['name']);

			case 'processBouquet':
				return !empty($rData['bouquet_name']);

			case 'processGroup':
			case 'processGroupLegacy':
				return !empty($rData['group_name']);

			case 'processPackage':
				return !empty($rData['package_name']);

			case 'processCategory':
				return !empty($rData['category_name']) && !empty($rData['category_type']);

			case 'processCode':
				return !empty($rData['code']);

			case 'reorderBouquet':
			case 'setChannelOrder':
				return is_array(json_decode($rData['stream_order_array'] ?? '', true));

			case 'sortBouquets':
				return is_array(json_decode($rData['bouquet_order_array'] ?? '', true));

			case 'blockIP':
			case 'processRTMPIP':
				return !empty($rData['ip']);

			case 'processChannel':
			case 'processStream':
			case 'processMovie':
			case 'processRadio':
				return !empty($rData['stream_display_name']) || isset($rData['review']) || isset($_FILES['m3u_file']);

			case 'processEpisode':
				return !empty($rData['series']) && is_numeric($rData['season_num'] ?? null) && (isset($rData['multi']) || is_numeric($rData['episode'] ?? null));

			case 'processSeries':
				return !empty($rData['title']);

			case 'massEditEpisodes':
			case 'massEditMovies':
			case 'massEditRadios':
			case 'massEditStreams':
			case 'massEditChannels':
			case 'massDeleteStreams':
				return is_array(json_decode($rData['streams'] ?? '', true));

			case 'massEditSeries':
			case 'massDeleteSeries':
				return is_array(json_decode($rData['series'] ?? '', true));

			case 'massEditLines':
			case 'massEditUsers':
				return is_array(json_decode($rData['users_selected'] ?? '', true));

			case 'massEditMags':
			case 'massEditEnigmas':
				return is_array(json_decode($rData['devices_selected'] ?? '', true));

			case 'processISP':
				return !empty($rData['isp']);

			case 'massDeleteMovies':
				return is_array(json_decode($rData['movies'] ?? '', true));

			case 'massDeleteLines':
				return is_array(json_decode($rData['lines'] ?? '', true));

			case 'massDeleteUsers':
				return is_array(json_decode($rData['users'] ?? '', true));

			case 'massDeleteStations':
				return is_array(json_decode($rData['radios'] ?? '', true));

			case 'massDeleteMags':
				return is_array(json_decode($rData['mags'] ?? '', true));

			case 'massDeleteEnigmas':
				return is_array(json_decode($rData['enigmas'] ?? '', true));

			case 'massDeleteEpisodes':
				return is_array(json_decode($rData['episodes'] ?? '', true));

			case 'processMAG':
			case 'processEnigma':
				return !empty($rData['mac']);

			case 'processProfile':
				return !empty($rData['profile_name']);

			case 'processProxy':
			case 'processServer':
				return !empty($rData['server_name']) && !empty($rData['server_ip']);

			case 'installServer':
				return !empty($rData['ssh_port']) && !empty($rData['root_password']);

			case 'orderCategories':
				return is_array(json_decode($rData['categories'] ?? '', true));

			case 'orderServers':
				return is_array(json_decode($rData['server_order'] ?? '', true));

			case 'moveStreams':
				return !empty($rData['content_type']) && !empty($rData['source_server']) && !empty($rData['replacement_server']);

			case 'replaceDNS':
				return !empty($rData['old_dns']) && !empty($rData['new_dns']);

			case 'processUA':
				return !empty($rData['user_agent']);

			case 'processWatchFolder':
				return !empty($rData['folder_type']) && !empty($rData['selected_path']) && !empty($rData['server_id']);

			case 'processEPG':
				return !empty($rData['epg_name']) && !empty($rData['epg_file']);

			case 'processUser':
			case 'processLine':
			case 'processHMAC':
			case 'editAdminProfile':
			case 'editSettings':
			case 'editBackupSettings':
			case 'editCacheCron':
			case 'editPlexSettings':
			case 'editWatchSettings':
			case 'processPlexSync':
			case 'processLogin':
			case 'submitTicket':
				return true;
		}

		return true;
	}

	/**
	 * Проверяет и возвращает STATUS_INVALID_INPUT если данные невалидны.
	 *
	 * @param string $rAction
	 * @param array  $rData
	 * @return array|null null если валидно, иначе массив с ошибкой
	 */
	public static function validateOrFail(string $rAction, array $rData): ?array {
		if (!self::validate($rAction, $rData)) {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
		return null;
	}
}

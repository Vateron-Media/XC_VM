<?php

/**
 * XC_VM — Коды ошибок
 *
 * Массив $rErrorCodes: код ошибки → описание на английском.
 * Используется в generateError() для отображения в debug-режиме.
 *
 * Коды добавляются сюда централизованно.
 * stream/init.php имеет дополнительные коды (CACHE_INCOMPLETE, etc.),
 * которые при миграции будут перенесены сюда.
 */

$rErrorCodes = array(
    'API_IP_NOT_ALLOWED'       => 'IP is not allowed to access the API.',
    'ARCHIVE_DOESNT_EXIST'     => 'Archive files are missing for this stream ID.',
    'ASN_BLOCKED'              => 'ASN has been blocked.',
    'BANNED'                   => 'Line has been banned.',
    'BLOCKED_USER_AGENT'       => 'User-agent has been blocked.',
    'DEVICE_NOT_ALLOWED'       => 'MAG & Enigma devices are not allowed to access this.',
    'DISABLED'                 => 'Line has been disabled.',
    'DOWNLOAD_LIMIT_REACHED'   => 'Reached the simultaneous download limit.',
    'E2_DEVICE_LOCK_FAILED'    => 'Device lock checks failed.',
    'E2_DISABLED'              => 'Device has been disabled.',
    'E2_NO_TOKEN'              => 'No token has been specified.',
    'E2_TOKEN_DOESNT_MATCH'    => "Token doesn't match records.",
    'E2_WATCHDOG_TIMEOUT'      => 'Time limit reached.',
    'EMPTY_USER_AGENT'         => 'Empty user-agents are disallowed.',
    'EPG_DISABLED'             => 'EPG has been disabled.',
    'EPG_FILE_MISSING'         => 'Cached EPG files are missing.',
    'EXPIRED'                  => 'Line has expired.',
    'FORCED_COUNTRY_INVALID'   => 'Country does not match forced country.',
    'GENERATE_PLAYLIST_FAILED' => 'Playlist failed to generate.',
    'HLS_DISABLED'             => 'HLS has been disabled.',
    'HOSTING_DETECT'           => 'Hosting server has been detected.',
    'INVALID_API_PASSWORD'     => 'API password is invalid.',
    'INVALID_CREDENTIALS'      => 'Username or password is invalid.',
    'INVALID_HOST'             => 'Domain name not recognised.',
    'INVALID_STREAM_ID'        => "Stream ID doesn't exist.",
    'INVALID_TYPE_TOKEN'       => "Tokens can't be used for this stream type.",
    'IP_BLOCKED'               => 'IP has been blocked.',
    'IP_MISMATCH'              => "Current IP doesn't match initial connection IP.",
    'ISP_BLOCKED'              => 'ISP has been blocked.',
    'LB_TOKEN_INVALID'         => 'AES Token cannot be decrypted.',
    'LEGACY_EPG_DISABLED'      => 'Legacy epg.php access has been disabled.',
    'LEGACY_GET_DISABLED'      => 'Legacy get.php access has been disabled.',
    'LEGACY_PANEL_API_DISABLED'=> 'Legacy panel_api.php access has been disabled.',
    'LINE_CREATE_FAIL'         => 'Line failed to insert into database.',
    'NO_CREDENTIALS'           => 'No credentials have been specified.',
    'NO_TIMESTAMP'             => 'No archive timestamp has been specified.',
    'NO_TOKEN_SPECIFIED'       => 'No AES encrypted token has been specified.',
    'NOT_ENIGMA_DEVICE'        => "Line isn't an enigma device.",
    'NOT_IN_ALLOWED_COUNTRY'   => 'Not in allowed country list.',
    'NOT_IN_ALLOWED_IPS'       => 'Not in allowed IP list.',
    'NOT_IN_ALLOWED_UAS'       => 'Not in allowed user-agent list.',
    'NOT_IN_BOUQUET'           => "Line doesn't have access to this stream ID.",
    'PLAYER_API_DISABLED'      => 'Player API has been disabled.',
    'PROXY_DETECT'             => 'Proxy has been detected.',
    'PROXY_NO_API_ACCESS'      => "Can't access API's via proxy.",
    'RESTREAM_DETECT'          => 'Restreaming has been detected.',
    'STALKER_CHANNEL_MISMATCH' => "Stream ID doesn't match stalker token.",
    'STALKER_DECRYPT_FAILED'   => 'Failed to decrypt stalker token.',
    'STALKER_INVALID_KEY'      => 'Invalid stalker key.',
    'STALKER_IP_MISMATCH'      => "IP doesn't match stalker token.",
    'STALKER_KEY_EXPIRED'      => 'Stalker token has expired.',
    'STREAM_OFFLINE'           => 'Stream is currently offline.',
    'THUMBNAIL_DOESNT_EXIST'   => "Thumbnail file doesn't exist.",
    'THUMBNAILS_NOT_ENABLED'   => 'Thumbnail not enabled for this stream.',
    'TOKEN_ERROR'              => 'AES token has incomplete data.',
    'TOKEN_EXPIRED'            => 'AES token has expired.',
    'TS_DISABLED'              => 'MPEG-TS has been disabled.',
    'USER_ALREADY_CONNECTED'   => 'Line already connected on a different IP.',
    'USER_DISALLOW_EXT'        => 'Extension is not in allowed list.',
    'VOD_DOESNT_EXIST'         => "VOD file doesn't exist.",
    'WAIT_TIME_EXPIRED'        => 'Stream start has timed out, failed to start.',

    // ── Дополнительные коды (из stream/init.php) ──────────────
    'CACHE_INCOMPLETE'         => 'Cache is being generated...',
    'SUBTITLE_DOESNT_EXIST'    => "Subtitle file doesn't exist.",
    'NO_SERVERS_AVAILABLE'     => 'No servers are currently available for this stream.',
    'PROXY_ACCESS_DENIED'      => 'You cannot access this stream directly while proxy is enabled.',
);

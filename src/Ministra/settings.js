/**
 * Settings modile.
 */

(function () {

    var submenu = module.settings_sub || [];

    if (typeof (stbEvent) === 'undefined' || typeof (stbEvent.onPortalEvent) !== 'function') {
        stbEvent.onPortalEvent = function (params) {
            _debug('params', params);
            params = JSON.parse(params);
            _debug('json params', params);

            if (params.hasOwnProperty("type") && params.type == "settings") {

                if (params.hasOwnProperty("settings_password")) {
                    stb.user.settings_password = params.settings_password;
                }
            }
        }
    }

    if (typeof (dvbManager) != 'undefined') {
        var dvb_supported_scan_types = JSON.parse(dvbManager.GetSupportedScanTypes());
        var dvb_current_scan_types = JSON.parse(dvbManager.GetCurrentScanTypes());
    } else {
        dvb_supported_scan_types = [];
        dvb_current_scan_types = [];
    }

    main_menu.add(word['settings_title'], [], 'mm_ico_setting.png', function () {
        if (connection_problem && connection_problem.on) {
            stb.notice.show(get_word('settings_unavailable'));
        } else {
            _debug('stb.profile.enable_setting_access_by_pass', stb.profile.enable_setting_access_by_pass);
            var url = window.location.protocol + "//" + stb.portal_ip + "/" + stb.portal_path + "/external/settings/index.html?ajax_loader=" + stb.ajax_loader;
            url += '?language=' + stb.stb_lang;
            url += '&token=' + stb.access_token;
            url += '&pri_audio_lang=' + stb.user['pri_audio_lang'];
            url += '&sec_audio_lang=' + stb.user['sec_audio_lang'];
            url += '&pri_subtitle_lang=' + stb.user['pri_subtitle_lang'];
            url += '&sec_subtitle_lang=' + stb.user['sec_subtitle_lang'];
            url += '&dvb_supported_scan_types=' + JSON.stringify(dvb_supported_scan_types);
            url += '&dvb_current_scan_types=' + JSON.stringify(dvb_current_scan_types);
            url += '&enable_setting_access_by_pass=' + stb.profile.enable_setting_access_by_pass;

            _debug(url);

            if (stb.profile.enable_setting_access_by_pass) {
                main_menu.settings_password_promt.callback = function () {
                    stbWindowMgr.openWebFavorites(url, 0);
                };
                main_menu.settings_password_promt.show();
            } else {
                stbWindowMgr.openWebFavorites(url, 0);
            }
        }
    }, { "layer_name": "settings" });

})();

loader.next();
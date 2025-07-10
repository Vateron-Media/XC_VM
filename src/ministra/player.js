/**
 * Player constructor
 * @constructor
 */

var stbEvent = {
    onEvent: function (data) {
    },
    event: 0
};

/**
 * @constructor
 */
function player() {

    var self = this;

    this.on = false;

    this.f_ch_idx = 0;
    this.ch_idx = 0;
    this.channels;
    this.fav_channels;
    this.fav_channels_ids;

    this.start_time;
    this.cur_media_item = {};
    this.cur_tv_item;
    this.last_not_locked_tv_item;
    this.need_show_info = 0;

    this.ch_aspect = {};
    this.ch_aspect_idx = 0;

    this.atrack_types = {
        1: 'MP2',
        2: 'MP3',
        3: 'DD',
        4: 'AAC',
        5: 'PCM',
        6: 'OGG',
        7: 'DTS'
    };

    this.pause = { "on": false };

    this.rec = {
        "on": false,

        "dom_obj": {},

        "show": function () {
            this.on = true;
            this.dom_obj.show();
        },

        "hide": function () {
            this.on = false;
            this.dom_obj.hide();
        },

        "set_seconds": function (seconds) {
            this.label.innerHTML = seconds;
        }
    };

    this.is_tv = false;

    this.cur_media_length = 0;
    this.cur_pos_time = 0;
    this.new_pos_time = 0;
    this.pos_step = 0;
    this.prev_move_pos_dir = 0;
    this.next_step = 0;
    this.diff_pos = 0;

    this.last_state = 0;
    this.prev_state = 0;

    this.send_last_tv_id_to = 1800000;
    this.send_played_tv_archive_to = 60000;
    this.send_played_timeshift_to = 60000;
    this.last_tv_id = 0;

    this.prev_layer = {};

    this.info = { "on": false, "hide_timer": 4000 };

    this.quick_ch_switch = { "on": false, "hide_to": 3000 };

    this.on_create_link = function () {
    };
    this.last_storage_id = 0;

    this.event1_counter = 0;
    this.event5_counter = 0;

    this.play_auto_ended = false;

    this.hist_ch_idx = [0, 0];
    this.hist_f_ch_idx = [0, 0];

    this.init();
    this.init_pause();
    this.init_rec();
    this.init_show_info();
    this.init_quick_ch_switch();
    this.volume.init();

    this.time_shift_indication.init();
    this.progress_bar.init();

    this.init_aspect_info();

    this.send_last_tv_id_callback = function () {
    };

    this.play_continuously = false;

    this.pos_by_numbers_input = '';

    /*this.con_menu = new context_menu();
    this.con_menu.bind();
    this.con_menu.set_x_offset(100);
    this.con_menu.set_y_offset(100);*/
}

player.prototype.init = function () {
    _debug('player.init');
    try {
        stb.InitPlayer();
        stb.SetTopWin(0);
        stb.SetAspect(0x10);
        stb.SetPIG(1, -1, -1, -1);
        stb.SetUserFlickerControl(1);
        stb.SetDefaultFlicker(1);
        stb.SetLoop(0);
        stb.SetMicVolume(100);

        if (stb.IsFileExist && stb.IsFileExist('/usr/lib/fonts/Ubuntu.ttf')) {
            stb.SetSubtitlesFont("/usr/lib/fonts/Ubuntu.ttf");
        }

        stbEvent.onEvent = (function (self) {
            return function () {
                self.event_callback.apply(self, arguments);
            }
        })(this);

        stbEvent.onMediaAvailable = (function (self) {
            return function () {
                self.play_or_download.apply(self, arguments);
            }
        })(this);

        stbEvent.onPortalEvent = function (params) {
            _debug('params', params);
            params = JSON.parse(params);
            _debug('json params', params);

            if (params.hasOwnProperty("type") && params.type == "settings") {

                if (params.hasOwnProperty("parent_password")) {
                    stb.user.parent_password = params.parent_password;
                }

                if (params.hasOwnProperty("settings_password")) {
                    stb.user.settings_password = params.settings_password;
                }

                if (params.hasOwnProperty("screensaver_delay")) {
                    stb.user.screensaver_delay = params.screensaver_delay;
                    screensaver.restart_timer();
                }

                if (params.hasOwnProperty("plasma_saving")) {
                    stb.user.plasma_saving = stb.profile.plasma_saving = params.plasma_saving;
                }

                if (params.hasOwnProperty("ts_enable_icon")) {
                    stb.user.ts_enable_icon = stb.profile.ts_enable_icon = params.ts_enable_icon;
                }

                if (params.hasOwnProperty("ts_path")) {
                    stb.user.ts_path = stb.profile.ts_path = params.ts_path;
                }

                if (params.hasOwnProperty("ts_max_length")) {
                    stb.user.ts_max_length = stb.profile.ts_max_length = params.ts_max_length;
                }

                if (params.hasOwnProperty("ts_buffer_use")) {
                    stb.user.ts_buffer_use = stb.profile.ts_buffer_use = params.ts_buffer_use;
                }

                if (params.hasOwnProperty("ts_action_on_exit")) {
                    stb.user.ts_action_on_exit = stb.profile.ts_action_on_exit = params.ts_action_on_exit;
                }

                if (params.hasOwnProperty("ts_delay")) {
                    stb.user.ts_delay = stb.profile.ts_delay = params.ts_delay;
                }

                if (params.hasOwnProperty("ts_enabled")) {
                    stb.user.ts_enabled = stb.profile.ts_enabled = params.ts_enabled;
                    module.time_shift_local.init();
                }

                if (params.hasOwnProperty("refresh_weather") && module.curweather) {
                    module.curweather.load.call(module.curweather);
                }

                if (params.hasOwnProperty("hdmi_event_reaction")) {
                    stb.user.hdmi_event_reaction = stb.profile.hdmi_event_reaction = params.hdmi_event_reaction;
                }

                if (params.hasOwnProperty("pri_audio_lang")) {
                    stb.user.pri_audio_lang = stb.profile.pri_audio_lang = params.pri_audio_lang;
                }

                if (params.hasOwnProperty("sec_audio_lang")) {
                    stb.user.sec_audio_lang = stb.profile.sec_audio_lang = params.sec_audio_lang;
                }

                if (params.hasOwnProperty("pri_audio_lang") && params.hasOwnProperty("pri_audio_lang")) {
                    stb.player.set_audio_langs(stb.user.pri_audio_lang, stb.user.sec_audio_lang);
                }

                if (params.hasOwnProperty("pri_subtitle_lang")) {
                    stb.user.pri_subtitle_lang = stb.profile.pri_subtitle_lang = params.pri_subtitle_lang;
                }

                if (params.hasOwnProperty("sec_subtitle_lang")) {
                    stb.user.sec_subtitle_lang = stb.profile.sec_subtitle_lang = params.sec_subtitle_lang;
                }

                if (params.hasOwnProperty("subtitle_size")) {
                    stb.user.subtitle_size = stb.profile.subtitle_size = params.subtitle_size;
                }

                if (params.hasOwnProperty("subtitle_color")) {
                    stb.user.subtitle_color = stb.profile.subtitle_color = params.subtitle_color;
                }

                if (params.hasOwnProperty("pri_subtitle_lang") && params.hasOwnProperty("sec_subtitle_lang") && params.hasOwnProperty("subtitle_size") && params.hasOwnProperty("subtitle_color")) {
                    stb.player.set_subtitle_langs(stb.user.pri_subtitle_lang, stb.user.sec_subtitle_lang, stb.user.subtitle_size, stb.user.subtitle_color);
                }

                if (params.hasOwnProperty("play_in_preview_by_ok")) {
                    stb.user.play_in_preview_only_by_ok = stb.profile.play_in_preview_only_by_ok = params.play_in_preview_by_ok;
                }

                if (params.hasOwnProperty("show_after_loading")) {
                    stb.user.display_menu_after_loading = stb.profile.display_menu_after_loading = params.show_after_loading == 'main_menu';
                }

                if (params.hasOwnProperty("dvb_type") && module.dvb) {
                    module.dvb.set_scan_type(params.dvb_type);
                }

                if (params.hasOwnProperty("dvb_antenna_power") && module.dvb) {
                    module.dvb.set_antenna_power(params.dvb_antenna_power);
                }

                if (params.hasOwnProperty("start_dvb_scan") && module.dvb) {
                    module.dvb.scan(params.data);
                }

                if (params.hasOwnProperty("start_manual_dvb_scan") && module.dvb) {
                    module.dvb.manual_scan(params.data);
                }

                if (params.hasOwnProperty("stop_dvb_scan") && module.dvb) {
                    module.dvb.stop_scan();
                }

                if (params.hasOwnProperty("update_dvb_channels") && module.dvb) {
                    module.dvb.init_channels();
                }

                if (params.hasOwnProperty("clear_dvb_channels") && module.dvb) {
                    module.dvb.clear_dvb_channels();
                }

                if (params.hasOwnProperty("mc_proxy_url")) {
                    stb.player.mc_proxy_url = params.mc_proxy_url;
                }
            }
        };

        stbEvent.onBroadcastMessage = function (win_id, msg) {
            _debug('stbEvent.onBroadcastMessage', win_id, msg);
        };

        stbEvent.onMessage = function (win_id, msg, data) {
            _debug('stbEvent.onMessage', win_id, msg, data);

            /*if (msg == 'stalker:open' && data && module[data]){

                stb.cur_single_module = data;
                _debug('stb.cur_single_module', stb.cur_single_module);

                module[stb.cur_layer.layer_name].hide && module[stb.cur_layer.layer_name].hide();

                if (module[data]._show){
                    module[data]._show();
                }else if (module[data].show){
                    module[data].show();
                }

            }else */
            if (msg == 'show') {
                if (data) {
                    data = JSON.parse(data);

                    if (data.module && module[data.module]) {
                        stb.cur_single_module = data.module;
                        _debug('stb.cur_single_module', stb.cur_single_module);

                        module[stb.cur_layer.layer_name].hide && module[stb.cur_layer.layer_name].hide();

                        if (module[stb.cur_single_module]._show) {
                            module[stb.cur_single_module]._show();
                        } else if (module[stb.cur_single_module].show) {
                            module[stb.cur_single_module].show();
                        }
                    }
                }

                stbWindowMgr.windowShow(windowId);
            } else if (msg == 'exit') {
                stbWebWindow.close();
            } else if (msg == 'AutoUpdateWindow:opened') {
                loader.pause();
            } else if (msg == 'AutoUpdateWindow:closed') {
                loader.resume();
            }
        };

    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_audio_langs = function (pri_lang, sec_lang) {
    _debug('player.set_audio_langs', pri_lang, sec_lang);

    pri_lang = pri_lang || "";
    sec_lang = sec_lang || "";

    _debug('pri_lang', pri_lang);
    _debug('sec_lang', sec_lang);

    try {
        stb.SetAudioLangs(pri_lang, sec_lang);
    } catch (e) {
        _debug(e);
    }

    if (['MAG351', 'MAG256', 'MAG257'].indexOf(stb.type) != -1) {
        this.quick_ch_switch.input.style.marginTop = '5px';
    }
};

player.prototype.set_subtitle_langs = function (pri_lang, sec_lang, size, color) {
    _debug('player.set_subtitle_langs', pri_lang, sec_lang, size, color);

    pri_lang = pri_lang || "";
    sec_lang = sec_lang || "";

    _debug('pri_lang', pri_lang);
    _debug('sec_lang', sec_lang);

    if (!pri_lang && sec_lang) {
        pri_lang = sec_lang;
    } else if (!sec_lang && pri_lang) {
        sec_lang = pri_lang;
    }

    try {
        if (!pri_lang) {
            this.subtitle_pid.disable();
            stb.profile['always_enabled_subtitles'] = 0;
        } else {
            this.subtitle_pid.enable();
            stb.profile['always_enabled_subtitles'] = 1;
            stb.SetSubtitleLangs(pri_lang, sec_lang);
        }

        stb.SetSubtitlesSize(size);
        stb.SetSubtitlesColor(color);
    } catch (e) {
        _debug(e);
    }
};

player.prototype.setup_rtsp = function (rtsp_type, rtsp_flags) {
    _debug('player.prototype.setup_rtsp', rtsp_type, rtsp_flags);
    try {
        stb.SetupRTSP(parseInt(rtsp_type), parseInt(rtsp_flags));
    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_cas = function (profile) {
    _debug('player.prototype.setup_cas', profile);
    try {

        if (profile['cas_ini_file']) {
            _debug('stb.LoadCASIniFile', profile['cas_ini_file']);
            stb.LoadCASIniFile(profile['cas_ini_file']);
        }

        if (!profile['cas_ini_file'] && profile['cas_params']) {
            _debug('stb.SetCASParam',
                profile['cas_params'].server_addr,
                parseInt(profile['cas_params'].server_port, 10),
                profile['cas_params'].company_name,
                parseInt(profile['cas_params'].ip_id, 10),
                parseInt(profile['cas_params'].error_level, 10));

            stb.SetCASParam(
                profile['cas_params'].server_addr,
                parseInt(profile['cas_params'].server_port, 10),
                profile['cas_params'].company_name,
                parseInt(profile['cas_params'].ip_id, 10),
                parseInt(profile['cas_params'].error_level, 10)
            );
        }

        if (!profile['cas_ini_file'] && profile['cas_additional_params']) {
            for (var param in profile['cas_additional_params']) {
                if (profile['cas_additional_params'].hasOwnProperty(param)) {
                    _debug('stb.SetAdditionalCasParam', param, profile['cas_additional_params'][param]);
                    stb.SetAdditionalCasParam(param, profile['cas_additional_params'][param]);
                }
            }
        }

        if (profile.hasOwnProperty('cas_hw_descrambling')) {
            _debug('stb.SetCASDescrambling', profile['cas_hw_descrambling']);
            stb.SetCASDescrambling(profile['cas_hw_descrambling']);
        }

        if (!profile['cas_ini_file'] && profile['cas_web_params']) {
            _debug('stb.SetupWebCAS', profile['cas_web_params'].server_addr, profile['cas_web_params'].company_name);
            stb.SetupWebCAS(profile['cas_web_params'].server_addr, profile['cas_web_params'].company_name);
        }

        if (profile['cas_type']) {
            _debug('stb.SetCASType', parseInt(profile['cas_type'], 10));
            stb.SetCASType(parseInt(profile['cas_type'], 10));
        }

    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_hls_fast_start = function (hls_fast_start) {
    _debug('player.set_hls_fast_start', hls_fast_start);

    if (!stb.SetPlayerOption) {
        _debug('stb.SetPlayerOption method not found!');
        return;
    }

    stb.SetPlayerOption('HlsFastStart', hls_fast_start ? '1' : '0')
};

player.prototype.play_or_download = function (content_type, url) {
    _debug('player.play_media', content_type, url);

    /*if (content_type.indexOf('video') == 0 || content_type.indexOf('audio') == 0){*/

    this.init_play_or_download_dialog();

    if (content_type.indexOf('video') == 0) {
        _debug('module.downloads', !!module.downloads);
        if (module.downloads) {
            //this.init_play_or_download_dialog();
            this.play_or_download_dialog.contentType = content_type;
            this.play_or_download_dialog.url = url;
            this.play_or_download_dialog.show();
            _debug('before close');
            try {
                stbWindowMgr.showPortalWindow();
            } catch (e) {
                _debug(e);
            }
            _debug('after close');
        } else {
            _debug('play url');

            stb.set_cur_place('internet');

            main_menu.hide();

            stb.player.prev_layer = main_menu;

            stb.player.play({
                "cmd": "ffmpeg " + stb.player.play_or_download_dialog.url,
                "name": stb.player.play_or_download_dialog.url.split("/").pop()
            });
        }
    } else if (module.downloads) {
        _debug('show download dialog');
        module.downloads.dialog.show({ "parent": main_menu, "url": url });
        stbWindowMgr.showPortalWindow();
    }
};

player.prototype.show_time_shift_exit_confirm = function () {
    _debug('player.show_time_shift_exit_confirm');
    this.init_time_shift_exit_confirm();
    this.time_shift_exit_confirm.show();
};

player.prototype.init_time_shift_exit_confirm = function () {
    _debug('player.init_time_shift_exit_confirm');

    if (this.time_shift_exit_confirm) {
        return;
    }

    this.time_shift_exit_confirm = new ModalForm({
        "title": get_word('confirm_form_title'),
        "text": get_word('time_shift_exit_confirm_text')
    });
    this.time_shift_exit_confirm.getTextDomObj().style.textAlign = "center";
    this.time_shift_exit_confirm.enableOnExitClose();

    var scope = this;

    this.time_shift_exit_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("close_btn"),
            "onclick": function () {
                scope.time_shift_exit_confirm.hide();
            }
        }
    ));

    this.time_shift_exit_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("ok_btn"),
            "onclick": function () {

                scope.time_shift_exit_confirm.hide();

                if (scope.active_local_time_shift) {
                    module.time_shift_local.disable_mode();
                    scope.active_local_time_shift = false;
                    scope.is_tv = true;

                    if (scope.pause.on) {
                        scope.disable_pause();
                    }
                } else {

                    if (scope.cur_media_item.timeshift_hist_id) {
                        scope.update_played_timeshift_end_time(scope.cur_media_item.timeshift_hist_id);
                    }

                    scope.cur_media_item = module.time_shift.stored_media_item;
                    scope.cur_tv_item = scope.cur_media_item;
                    scope.active_time_shift = false;
                    scope.play_last();
                }
            }
        }
    ));
};

player.prototype.init_pvr_dialogs = function () {
    _debug('player.init_pvr_dialogs');

    if (this.pvr_target_select) {
        return;
    }

    var scope = this;

    this.pvr_target_select = new ModalForm({
        "title": get_word('select_form_title'),
        "text": get_word('pvr_target_select_text')
    });
    this.pvr_target_select.getTextDomObj().style.textAlign = "center";
    this.pvr_target_select.enableOnExitClose();

    this.pvr_target_select.addItem(new ModalFormButton(
        {
            "value": get_word("usb_target_btn"),
            "onclick": function () {
                if (scope.pvr_target_select.deferred) {
                    scope.local_pvr_confirm.channel = scope.pvr_target_select.channel;
                    scope.local_pvr_confirm.program = scope.pvr_target_select.program;
                } else {
                    scope.local_pvr_confirm.channel = scope.pvr_target_select.channel;
                }
                scope.local_pvr_confirm.deferred = scope.pvr_target_select.deferred;

                if (scope.pvr_target_select.deferred) {
                    scope.local_pvr_confirm.getItemByName('start_btn').setValue(get_word('add_btn'));
                } else {
                    scope.local_pvr_confirm.getItemByName('start_btn').setValue(get_word('start_btn'));
                }
                scope.pvr_target_select.hide();
                scope.local_pvr_confirm.show({ parent: scope.pvr_target_select._parent });
            }
        }
    ));

    this.pvr_target_select.addItem(new ModalFormButton(
        {
            "value": get_word("server_target_btn"),
            "onclick": function () {

                if (scope.pvr_target_select.deferred) {
                    scope.remote_pvr_confirm.program = scope.pvr_target_select.program;
                } else {
                    scope.remote_pvr_confirm.channel = scope.pvr_target_select.channel;
                }

                scope.remote_pvr_confirm.deferred = scope.pvr_target_select.deferred;

                scope.pvr_target_select.hide();

                if (scope.pvr_target_select.deferred) {
                    scope.remote_pvr_confirm.show({
                        text: get_word('remote_deferred_pvr_confirm_text'),
                        parent: scope.pvr_target_select._parent
                    });
                } else {
                    scope.remote_pvr_confirm.show({
                        text: get_word('remote_pvr_confirm_text'),
                        parent: scope.pvr_target_select._parent
                    });
                }
            }
        }
    ));

    this.remote_pvr_confirm = new ModalForm({
        "title": get_word('confirm_form_title'),
        "text": get_word('remote_pvr_confirm_text')
    });
    this.remote_pvr_confirm.getTextDomObj().style.textAlign = "center";
    this.remote_pvr_confirm.enableOnExitClose();
    this.remote_pvr_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("cancel_btn"),
            "onclick": function () {
                scope.remote_pvr_confirm.hide();
            }
        }
    ));
    this.remote_pvr_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("yes_btn"),
            "onclick": function () {

                if (scope.remote_pvr_confirm.deferred) {
                    scope.remote_pvr_confirm._parent.recorder.add_remote(scope.remote_pvr_confirm.program)
                } else {
                    module.remote_pvr.start_rec(scope.remote_pvr_confirm.channel.id);
                }

                scope.remote_pvr_confirm.hide();
            }
        }
    ));

    this.local_pvr_confirm = new ModalForm({ "title": get_word('rec_options_form_title'), "id": "local_pvr_confirm" });
    this.local_pvr_confirm.enableOnExitClose();

    this.local_pvr_confirm.addItem(
        new ModalFormDateTimeSelect(
            {
                "label": get_word('pvr_start_time') + ':',
                "name": "start_time",
                "onset": function (value) {
                    _debug('onset start_time', value);
                    var start_time = scope.local_pvr_confirm.getItemByName('start_time').getDateValue();
                    var end_time = scope.local_pvr_confirm.getItemByName('end_time').getDateValue();

                    var duration = (end_time.getTime() - start_time.getTime()) / 1000;

                    _debug('duration', duration);

                    if (duration < 0) {
                        duration = 0;
                    }

                    scope.local_pvr_confirm.getItemByName('duration').setTime(duration);
                }
            }
        )
    );

    this.local_pvr_confirm.addItem(
        new ModalFormDateTimeSelect(
            {
                "label": get_word('pvr_end_time') + ':',
                "name": "end_time",
                "onset": function (value) {
                    _debug('onset end_time', value);

                    var start_time = scope.local_pvr_confirm.getItemByName('start_time').getDateValue();
                    var end_time = scope.local_pvr_confirm.getItemByName('end_time').getDateValue();

                    var duration = (end_time.getTime() - start_time.getTime()) / 1000;

                    _debug('duration', duration);

                    if (duration < 0) {
                        duration = 0;
                    }

                    scope.local_pvr_confirm.getItemByName('duration').setTime(duration);
                }
            }
        )
    );

    this.local_pvr_confirm.addItem(
        new ModalFormDateTimeSelect(
            {
                "label": get_word('pvr_duration') + ':',
                "name": "duration",
                "only_time": true,
                "max_time": "05:00",
                "onset": function (value) {
                    _debug('onset duration', value);

                    var start_time = scope.local_pvr_confirm.getItemByName('start_time').getDateValue();

                    var duration_parts = value.split(':');

                    var duration = parseInt(duration_parts[0], 10) * 3600 + parseInt(duration_parts[1], 10) * 60;

                    _debug('duration', duration);

                    start_time.setSeconds(duration);

                    scope.local_pvr_confirm.getItemByName('end_time').setValue(start_time.getTime());
                }
            }
        )
    );

    this.local_pvr_confirm.addItem(new ModalFormSelect({ "label": get_word('usb_device') + ':', "name": "usb_device" }));
    this.local_pvr_confirm.addItem(new ModalFormInput({ "label": get_word('file_name') + ':', "name": "file_name" }));

    this.local_pvr_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("cancel_btn"),
            "onclick": function () {
                scope.local_pvr_confirm.hide();
            }
        }
    ));
    this.local_pvr_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("start_btn"),
            "name": "start_btn",
            "onclick": function () {
                var path = scope.local_pvr_confirm.getItemByName('usb_device').getValue() + '/' + scope.local_pvr_confirm.getItemByName('file_name').getValue();

                var start_time = scope.local_pvr_confirm.getItemByName('start_time').getDateValue();
                var end_time = scope.local_pvr_confirm.getItemByName('end_time').getDateValue();

                var duration = (end_time.getTime() - start_time.getTime()) / 1000;

                _debug('duration', duration);

                if (duration < 0) {
                    duration = 0;
                }

                var now_time = new Date();

                if (start_time.getTime() > now_time.getTime() && !scope.local_pvr_confirm.deferred) {
                    scope.local_pvr_confirm.deferred = true;
                    scope.local_pvr_confirm.program = {
                        "id": 0,
                        "ch_id": scope.local_pvr_confirm.channel.id,
                        "start_timestamp": parseInt(start_time.getTime() / 1000, 10),
                        "stop_timestamp": parseInt(end_time.getTime() / 1000, 10)
                    };
                } else if (scope.local_pvr_confirm.deferred && scope.local_pvr_confirm.program) {
                    if (scope.local_pvr_confirm.program.start_timestamp * 1000 != start_time.getTime()
                        || scope.local_pvr_confirm.program.stop_timestamp * 1000 != end_time.getTime()) {
                        scope.local_pvr_confirm.program.start_timestamp = parseInt(start_time.getTime() / 1000, 10);
                        scope.local_pvr_confirm.program.stop_timestamp = parseInt(end_time.getTime() / 1000, 10);
                    }
                }

                if (scope.local_pvr_confirm.deferred && scope.local_pvr_confirm.program.id == 0) {
                    module.pvr_local.create_for_program(scope.local_pvr_confirm.program, path)
                } else if (scope.local_pvr_confirm.deferred) {
                    scope.local_pvr_confirm._parent.recorder.add_local(scope.local_pvr_confirm.program, path)
                } else {
                    module.pvr_local.create(scope.local_pvr_confirm.channel, path, null, parseInt(end_time.getTime() / 1000, 10));
                }

                scope.local_pvr_confirm.hide();
            }
        }
    ));

    this.local_pvr_confirm.addCustomEventListener('before_show', function () {

        if (scope.local_pvr_confirm.program && scope.local_pvr_confirm.deferred) {
            var start_time = scope.local_pvr_confirm.program.start_timestamp * 1000;
            var end_time = scope.local_pvr_confirm.program.stop_timestamp * 1000;
        } else {

            start_time = 'now';

            var epg = stb.epg_loader.get_curr_and_next(scope.local_pvr_confirm.channel.id);

            if (epg && epg.length > 0) {
                end_time = epg[0].stop_timestamp * 1000;
            } else {
                end_time = new Date().getTime() + 3 * 3600 * 1000;
            }
        }

        scope.local_pvr_confirm.getItemByName('start_time').setValue(start_time);
        scope.local_pvr_confirm.getItemByName('end_time').setValue(end_time);
        scope.local_pvr_confirm.getItemByName('end_time')._onset(scope.local_pvr_confirm.getItemByName('end_time').getValue());
    });

    this.local_pvr_confirm.addCustomEventListener('show', function () {

        var options = stb.usbdisk.storage_info.map(function (storage_info) {
            storage_info.value = storage_info.mountPath;
            storage_info.text = storage_info.vendor
                + ' ' + storage_info.model
                + (storage_info.label ? '(' + storage_info.label + ')' : '')
                + (storage_info.partitionNum > 1 ? ' #' + storage_info.partitionNum : '');
            return storage_info;
        });

        scope.local_pvr_confirm.getItemByName('usb_device').setOptions(options);

        if (scope.local_pvr_confirm.deferred) {
            scope.local_pvr_confirm.getItemByName('file_name').setValue(module.pvr_local.get_record_filename_for_program(scope.local_pvr_confirm.program));
        } else {
            scope.local_pvr_confirm.getItemByName('file_name').setValue(module.pvr_local.get_record_filename(scope.local_pvr_confirm.channel));
        }
    });
};

player.prototype.init_subtitle_encoding_select = function () {
    _debug('player.init_subtitle_encoding_select');

    if (this.subtitle_encoding_select) {
        return;
    }

    this.subtitle_encoding_select = new ModalForm({ "title": get_word('select_form_title') });
    this.subtitle_encoding_select.enableOnExitClose();

    this.subtitle_encoding_select.addItem(new ModalFormSelect(
        {
            "label": get_word('encoding_label') + ':',
            "name": "subtitle_encoding",
            "options": [
                {
                    "text": "UTF-8",
                    "value": "utf-8"
                },
                {
                    "text": "Windows-1250 (cp1250)",
                    "value": "cp1250"
                },
                {
                    "text": "Windows-1251 (cp1251)",
                    "value": "cp1251"
                },
                {
                    "text": "Windows-1252 (cp1252)",
                    "value": "cp1252"
                },
                {
                    "text": "Windows-1253 (cp1253)",
                    "value": "cp1253"
                },
                {
                    "text": "Windows-1254 (cp1254)",
                    "value": "cp1254"
                },
                {
                    "text": "Windows-1255 (cp1255)",
                    "value": "cp1255"
                },
                {
                    "text": "Windows-1256 (cp1256)",
                    "value": "cp1256"
                },
                {
                    "text": "Windows-1257 (cp1257)",
                    "value": "cp1257"
                },
                {
                    "text": "Windows-1258 (cp1258)",
                    "value": "cp1258"
                },
                {
                    "text": "Latin 1 (iso8859-1)",
                    "value": "iso8859-1"
                },
                {
                    "text": "Latin 2 (iso8859-2)",
                    "value": "iso8859-2"
                },
                {
                    "text": "Latin 3 (iso8859-3)",
                    "value": "iso8859-3"
                },
                {
                    "text": "Latin 4 (iso8859-4)",
                    "value": "iso8859-4"
                },
                {
                    "text": "Latin/Cyrillic (iso8859-5)",
                    "value": "iso8859-5"
                },
                {
                    "text": "Latin/Arabic (iso8859-6)",
                    "value": "iso8859-6"
                },
                {
                    "text": "Latin/Greek (iso8859-7)",
                    "value": "iso8859-7"
                },
                {
                    "text": "Latin/Hebrew (iso8859-8)",
                    "value": "iso8859-8"
                },
                {
                    "text": "Latin 5 (iso8859-9)",
                    "value": "iso8859-9"
                },
                {
                    "text": "Latin 6 (iso8859-10)",
                    "value": "iso8859-10"
                },
                {
                    "text": "Latin/Thai (iso8859-11)",
                    "value": "iso8859-11"
                },
                {
                    "text": "Latin/Devanagari (iso8859-12)",
                    "value": "iso8859-12"
                },
                {
                    "text": "Latin 7 (iso8859-13)",
                    "value": "iso8859-13"
                },
                {
                    "text": "Latin 8 (iso8859-14)",
                    "value": "iso8859-14"
                },
                {
                    "text": "Latin 9 (iso8859-15)",
                    "value": "iso8859-15"
                },
                {
                    "text": "Latin 10 (iso8859-16)",
                    "value": "iso8859-16"
                }
            ]
        }
    ));

    var scope = this;

    this.subtitle_encoding_select.addItem(new ModalFormButton({
        "value": get_word("ok_btn"),
        "name": "ok_btn",
        "onclick": function () {
            var subtitle_encoding = scope.subtitle_encoding_select.getItemByName('subtitle_encoding').getValue();

            _debug('subtitle_encoding', subtitle_encoding);

            scope.subtitle_encoding_select.on_confirm && scope.subtitle_encoding_select.on_confirm(subtitle_encoding);
            scope.subtitle_encoding_select.hide();
        }
    }));
};

player.prototype.init_play_or_download_dialog = function () {
    _debug('player.init_play_or_download_dialog');

    if (this.play_or_download_dialog) {
        return;
    }

    this.play_or_download_dialog = new ModalForm({ "title": get_word('play_or_download') });
    this.play_or_download_dialog.enableOnExitClose();

    this.play_or_download_dialog.addItem(new ModalFormButton(
        {
            "value": get_word("player_play"),
            "onclick": function () {

                stb.set_cur_place('internet');

                stb.player.play_or_download_dialog.hide();
                main_menu.hide();

                stb.player.prev_layer = main_menu;

                stb.player.play({
                    "cmd": "ffmpeg " + stb.player.play_or_download_dialog.url,
                    "name": stb.player.play_or_download_dialog.url.split("/").pop()
                });
            }
        }
    ));

    this.play_or_download_dialog.addItem(new ModalFormButton(
        {
            "value": get_word("player_download"),
            "onclick": function () {

                module.downloads.dialog.show({ "parent": main_menu, "url": stb.player.play_or_download_dialog.url });

                stb.player.play_or_download_dialog.hide();
            }
        }
    ));
};

player.prototype.event_callback = function (event, params) {
    _debug('event: ', event);

    event = parseInt(event);

    if ([1, 2, 4, 5].indexOf(event) >= 0) {
        this.last_state = event;
    }

    this.play_initiated = false;

    var self = this;

    switch (event) {
        case 1: // End of stream
            {

                playback_limit.reset();

                try {

                    if (this.cur_media_item.hasOwnProperty('ad_tracking')) {
                        if (this.cur_media_item.ad_tracking.hasOwnProperty('complete')) {
                            stb.advert.track(this.cur_media_item.ad_tracking['complete'], 'complete')
                        }
                    }

                    if (this.cur_media_item.stop_callback) {

                        this.ad_indication.hide();
                        this.ad_skip_indication.hide();
                        stb.advert.stop_ticking();

                        this.cur_media_item.stop_callback();
                        return;
                    }

                    //this.prev_layer && this.prev_layer.show && this.prev_layer.show.call(this.prev_layer, true);

                    this.play_auto_ended = true;

                    if (this.media_type == 'stream' && (this.is_tv || stb.cur_place == 'radio')) {
                        _debug('stream error');

                        this.event1_counter++;

                        _debug('stb.user.tv_playback_retry_limit', stb.user.tv_playback_retry_limit);
                        _debug('stb.user.fading_tv_retry_timeout', stb.user.fading_tv_retry_timeout);
                        _debug('this.event1_counter', this.event1_counter);

                        if (stb.user.tv_playback_retry_limit > 0 && this.event1_counter > stb.user.tv_playback_retry_limit) {
                            _debug('stop replay');
                            return;
                        }

                        var replay_timeout = 1000;

                        if (stb.user.fading_tv_retry_timeout) {
                            replay_timeout *= this.event1_counter <= 2 ? 1 : 30;
                        }

                        if (this.is_tv) {

                            if (stb.profile['enable_stream_error_logging']) {
                                stb.log_stream_error(this.cur_tv_item['id'], 1);
                            }

                            this.replay_channel_timer = window.setTimeout(
                                function () {
                                    self.play_last();
                                },
                                replay_timeout
                            );
                        } else {
                            this.replay_channel_timer = window.setTimeout(
                                function () {
                                    self.play_last_radio();
                                },
                                replay_timeout
                            );
                        }
                    } else {

                        if (this.cur_media_item.media_type != 'vclub_ad' && this.cur_media_item.media_type != 'advert' && this.play_continuously && this.cur_media_item.hasOwnProperty('series') && this.cur_media_item.series && this.cur_media_item.series.length > 0) {

                            _debug('this.cur_media_item.cur_series before', this.cur_media_item.cur_series);
                            _debug('this.cur_media_item.series.length', this.cur_media_item.series.length);
                            _debug('this.cur_media_item.hasOwnProperty(series)', this.cur_media_item.hasOwnProperty('series'));

                            var series_idx = this.cur_media_item.series.indexOf(this.cur_media_item.cur_series);

                            _debug('series_idx before', series_idx);

                            var old_series_idx = series_idx;

                            if (series_idx < this.cur_media_item.series.length - 1) {
                                series_idx++;
                            }

                            _debug('series_idx after', series_idx);

                            if (old_series_idx != series_idx) {

                                this.cur_media_item.cur_series = this.cur_media_item.series[series_idx];
                                this.cur_media_item.disable_ad = true;

                                if (this.cur_media_item.cmd.indexOf('://')) {
                                    this.cur_media_item.cmd = '/media/' + this.cur_media_item.id + '.mpg';
                                }

                                this.play(this.cur_media_item);
                                break;
                            }

                        } else if (this.cur_media_item.playlist && this.cur_media_item.playlist.length > 0) {

                            stb.key_lock = false;

                            if (typeof (this.cur_media_item.playlist[0]) == 'object') {

                                idx = -1;

                                for (var i = 0; i < this.cur_media_item.playlist.length; i++) {
                                    if (this.cur_media_item.id == this.cur_media_item.playlist[i].id) {
                                        idx = i;
                                        break;
                                    }
                                }

                                _debug('playlist idx', idx);

                                if (idx >= 0 && idx < this.cur_media_item.playlist.length - 1) {
                                    idx++;

                                    cur_media_item = this.cur_media_item.playlist[idx].clone();
                                    cur_media_item.playlist = this.cur_media_item.playlist;
                                    _debug('cur_media_item', cur_media_item);

                                    if (cur_media_item.is_audio) {
                                        cur_media_item.number = null;
                                    }

                                    this.play(cur_media_item);

                                    break;
                                }

                            } else {
                                var idx = this.cur_media_item.playlist.indexOf(this.cur_media_item.cmd);

                                _debug('playlist idx', idx);


                                if (idx >= 0 && idx < this.cur_media_item.playlist.length - 1) {

                                    idx++;

                                    var cur_media_item = this.cur_media_item.clone();

                                    cur_media_item.cmd = cur_media_item.playlist[idx];

                                    var real_id_match = /real_id=([^&]*)/.exec(cur_media_item.cmd);

                                    if (real_id_match) {
                                        cur_media_item.real_id = real_id_match[1];
                                    }

                                    var osd_title_match = /osd_title=([^&]*)/.exec(cur_media_item.cmd);

                                    if (osd_title_match) {
                                        cur_media_item.name = decodeURIComponent(osd_title_match[1].replace(/\+/g, '%20'));
                                    } else if (!cur_media_item.hasOwnProperty('keep_original_name') || !cur_media_item.keep_original_name) {
                                        cur_media_item.name = cur_media_item.cmd.substr(this.cur_media_item.cmd.lastIndexOf("/") + 1);
                                    }

                                    if (cur_media_item.show_osd) {
                                        this.need_show_info = 1;
                                    }

                                    if (this.cur_media_item.media_type && this.cur_media_item.media_type == 'vclub_ad') {
                                        delete cur_media_item.media_type;
                                        cur_media_item.disable_ad = true;
                                        module && module.vclub && module.vclub.set_ad_ended_time && module.vclub.set_ad_ended_time(this.cur_media_item.ad_id, stb.GetPosTime(), stb.GetMediaLen(), true);
                                    }

                                    if (cur_media_item.hasOwnProperty('ad_must_watch')) {
                                        delete cur_media_item.ad_must_watch;
                                    }

                                    this.play(cur_media_item);

                                    break;
                                }
                            }
                        }

                        /*if (this.active_time_shift && this.cur_media_item['wowza_dvr'] != 1){*/
                        if (this.active_time_shift) {

                            var cur_piece_pos_time = stb.GetPosTime();
                            _debug('cur_piece_pos_time', cur_piece_pos_time);
                            var diff = 3600 - cur_piece_pos_time;
                            _debug('diff', diff);

                            ///if (cur_piece_pos_time(cur_piece_pos_time - stb.GetPosTime()) > 60 ){
                            if (diff <= 10 && diff >= -10) {
                                var new_url = module.time_shift.get_next_part();

                                if (new_url) {

                                    module.time_shift.update_media_item(new_url);
                                    this.need_show_info = 0;
                                    this.play(module.time_shift.cur_media_item);
                                    break;
                                }

                            } else {
                                this.cur_media_item = module.time_shift.stored_media_item;
                                this.cur_tv_item = this.cur_media_item;
                                this.active_time_shift = false;
                                this.play_last(); // 
                                return;

                            }
                        }

                        if (this.emulate_media_len) {

                            /*var global_pos_time = stb.GetPosTime();
                            this.cur_pos_time = global_pos_time - (this.cur_media_item.position ? this.cur_media_item.position : 0);
    
                            _debug('this.cur_media_length', this.cur_media_length);
                            _debug('this.cur_pos_time', this.cur_pos_time);
                            */

                            if ((this.cur_media_length - this.cur_pos_time) > 60) {

                                if (module.tv_archive) {

                                    new_url = module.tv_archive.get_next_part();

                                    if (new_url) {

                                        this.cur_media_item.cmd = new_url;
                                        //this.cur_media_item.position = undefined;
                                        delete this.cur_media_item.open;
                                        //delete this.cur_media_item.media_len;
                                        this.need_show_info = 0;
                                        this.play(this.cur_media_item);
                                        break;
                                    }
                                }
                            }
                        }

                        if (this.prev_layer && this.prev_layer.show) {
                            this.prev_layer.show.call(this.prev_layer, true);
                        }

                        this.stop();
                    }

                } catch (e) {
                    _debug(e);
                }

                break;
            }
        case 2: // Receive information about stream
            {

                this.set_media_aspect();

                if (stb.GetMetadataInfo) {
                    var metadata = stb.GetMetadataInfo();
                    _debug('stb.GetMetadataInfo()', metadata);

                    metadata = JSON.parse(metadata);

                    if (metadata && metadata.hasOwnProperty('titles') && metadata.titles.length > 1) {

                        var title_idx = self.cur_media_item.cmd.indexOf('?title=');

                        if (title_idx != -1) {
                            var clear_cmd = self.cur_media_item.cmd.substr(0, title_idx);
                        } else {
                            clear_cmd = self.cur_media_item.cmd;
                        }

                        this.cur_media_item.playlist = metadata.titles.map(function (title, idx) {
                            return clear_cmd + '?title=' + idx;
                        });

                        if (metadata.hasOwnProperty('infoCurtitle')) {
                            this.cur_media_item.cmd = clear_cmd + '?title=' + metadata.infoCurtitle;
                        }
                    }

                    _debug('this.cur_media_item', this.cur_media_item);
                }
                /*if (this.cur_media_item.hasOwnProperty('volume_correction')){
                    this.volume.correct_level(parseInt(this.cur_media_item.volume_correction));
                }else{
                    this.volume.correct_level(0);
                }*/

                _debug('this.prev_state', this.prev_state);

                if (this.prev_state == 4) {
                    this.get_pids();
                }

                this.triggerCustomEventListener('event_2', this.cur_media_item);
                //if (this.is_tv){
                if (stb.user['enable_buffering_indication'] && this.cur_media_item.cmd.indexOf('rtp ') != 0) {
                    this.progress_bar.start();
                }
                //}

                break;
            }
        case 4: // Playback started
            {
                this.event1_counter = 0;
                this.event5_counter = 0;

                this.triggerCustomEventListener('event_4', this.cur_media_item);
                if (stb.user['enable_buffering_indication'] && this.cur_media_item.cmd.indexOf('rtp ') != 0) {
                    this.progress_bar.stop();
                }

                if (this.cur_media_item.hasOwnProperty('ad_tracking')) {

                    if (this.cur_media_item.ad_tracking.hasOwnProperty('impression')) {
                        stb.advert.track(this.cur_media_item.ad_tracking['impression'], 'impression')
                    }

                    if (this.cur_media_item.ad_tracking.hasOwnProperty('creativeView')) {
                        stb.advert.track(this.cur_media_item.ad_tracking['creativeView'], 'creativeView')
                    }

                    if (this.cur_media_item.ad_tracking.hasOwnProperty('start')) {
                        stb.advert.track(this.cur_media_item.ad_tracking['start'], 'start')
                    }
                }

                if (this.cur_media_item.hasOwnProperty('volume_correction')) {
                    this.volume.correct_level(parseInt(this.cur_media_item.volume_correction));
                } else {
                    this.volume.correct_level(0);
                }

                this.time_shift_indication.hide();

                if (this.is_tv) {
                    stb.notice.hide();
                }

                if (this.is_tv && this.cur_tv_item && this.cur_tv_item.ready_to_timeshift && module.time_shift_local && module.time_shift_local.enabled) {

                    if (stb.profile.ts_delay !== 'on_pause') {
                        this.enable_local_timeshift = window.setTimeout(function () {
                            module.time_shift_local.enable_mode();
                        }, stb.profile.ts_delay * 1000);
                    }
                }

                if (this.active_local_time_shift && module.time_shift_local) {

                    this.time_shift_indication.show();

                } else if (this.active_time_shift && module.time_shift) {

                    this.cur_pos_time = module.time_shift.get_pos_time();
                    this.cur_media_length = module.time_shift.get_cur_media_length();

                    this.time_shift_indication.show();

                    window.clearTimeout(this.send_played_timeshift_timer);

                    this.send_played_timeshift_timer = window.setTimeout(
                        function () {
                            self.send_played_timeshift(stb.player.cur_media_item.id);
                        },

                        this.send_played_timeshift_to
                    );

                } else if (this.emulate_media_len && module.tv_archive) {
                    /*var global_pos_time = stb.GetPosTime();
                    this.cur_pos_time = global_pos_time - (this.cur_media_item.position ? this.cur_media_item.position : 0);
    
                    _debug('global_pos_time', global_pos_time);
                    _debug('this.cur_media_item.position', this.cur_media_item.position);
                    _debug('this.cur_pos_time 1', this.cur_pos_time);
    
                    if (module.tv_archive){
                        this.cur_pos_time += module.tv_archive.get_file_piece_num() * 3600;
                    }
    
                    _debug('this.cur_pos_time 2', this.cur_pos_time);*/

                    this.cur_pos_time = module.tv_archive.get_pos_time();

                    clearTimeout(this.emulated_media_len_stop);
                    this.emulated_media_len_stop = window.setTimeout(function () {
                        _debug('fire stop');
                        self.show_prev_layer();
                    }, (this.cur_media_length - this.cur_pos_time) * 1000);

                } else {
                    this.cur_media_length = stb.GetMediaLen();
                    this.cur_pos_time = stb.GetPosTime();

                    if (this.cur_media_item.media_type == 'advert' || this.cur_media_item.media_type == 'vclub_ad') {
                        stb.advert && stb.advert.disable();
                    }

                    if (this.cur_media_item.hasOwnProperty('ad_must_watch') && this.cur_media_item.ad_must_watch != 'all') {

                        if (this.cur_media_item.ad_must_watch.indexOf('s') > 0) { // time in seconds
                            var lock_time = parseInt(this.cur_media_item.ad_must_watch, 10);
                            lock_time = this.cur_media_length < lock_time ? this.cur_media_length : lock_time;
                        } else {
                            lock_time = this.cur_media_length * this.cur_media_item.ad_must_watch / 100;
                        }

                        _debug('lock_time', lock_time);

                        this.ad_skip_timer = window.setTimeout(function () {
                            _debug('can skip');
                            stb.key_lock = false;
                            stb.player.ad_skip_indication.show();
                        }, lock_time * 1000);

                        delete this.cur_media_item.ad_must_watch;
                    }
                }
                _debug('player.cur_media_length', this.cur_media_length);
                _debug('player.cur_pos_time', this.cur_pos_time);

                if (this.info.on) {
                    this.set_pos_button_to_cur_time();
                }

                if (this.cur_media_item.hasOwnProperty('ad_tracking')) {

                    stb.advert.start_ticking(this.cur_media_length);
                }

                /*if (this.is_tv){
                    this.send_last_tv_id(this.cur_tv_item.id);
                }*/

                window.clearTimeout(this.send_played_video_timer);

                _debug('stb.cur_place', stb.cur_place);

                if (stb.cur_place == 'vclub') {

                    var time_send_played = (this.cur_media_length * 0.7) * 1000;
                    _debug('time_send_played,', time_send_played);

                    this.send_played_video_timer = window.setTimeout(
                        function () {
                            self.send_played_video(self.cur_media_item.video_id || self.cur_media_item.id);
                        },

                        time_send_played
                    )
                }

                _debug('this.prev_state', this.prev_state);

                if (this.prev_state == 2) {
                    this.get_pids();
                }

                window.clearTimeout(this.send_played_tv_archive_timer);

                if (module.tv_archive && this.cur_media_item.mark_archive) {
                    window.clearTimeout(this.archive_continue_dialog_to);

                    var match = /-\d{10}-(\d+)\.m3u8/.exec(this.cur_media_item.cmd);

                    if (match) {
                        this.cur_media_length = match[1];
                    }

                    var archive_continue_dialog_delay = (this.cur_media_length - this.cur_pos_time - 30) * 1000;

                    _debug('archive_continue_dialog_delay 1', archive_continue_dialog_delay);

                    this.archive_continue_dialog_to = window.setTimeout(function () {
                        if (stb.profile['tv_archive_continued']) {
                            module.tv_archive.get_next_part_url();
                        } else {
                            module.tv_archive.continue_dialog.show();
                        }
                    }, archive_continue_dialog_delay);

                    self = this;

                    this.send_played_tv_archive_timer = window.setTimeout(
                        function () {
                            self.send_played_tv_archive(stb.player.cur_media_item.ch_id);
                        },

                        this.send_played_tv_archive_to
                    );
                }

                break;
            }
        case 5: // Not found
            {

                playback_limit.reset();

                stb.key_lock = false;

                if (this.cur_media_item.hasOwnProperty('ad_tracking')) {
                    if (this.cur_media_item.ad_tracking.hasOwnProperty('error')) {
                        stb.advert.track(this.cur_media_item.ad_tracking['error'], 'error')
                    }
                }
                _debug('this.cur_media_item', this.cur_media_item);
                if (this.cur_media_item.stop_callback) {

                    this.ad_indication.hide();
                    this.ad_skip_indication.hide();
                    stb.advert.stop_ticking();

                    this.cur_media_item.stop_callback();
                    return;
                }

                this.event5_counter++;
                if (this.media_type == 'stream') {

                    if (this.is_tv && stb.profile['enable_stream_error_logging']) {
                        stb.log_stream_error(this.cur_tv_item['id'], 5);
                    }

                    _debug('stb.user.tv_playback_retry_limit', stb.user.tv_playback_retry_limit);
                    _debug('stb.user.fading_tv_retry_timeout', stb.user.fading_tv_retry_timeout);
                    _debug('this.event5_counter', this.event5_counter);

                    if (stb.user.tv_playback_retry_limit > 0 && this.event5_counter > stb.user.tv_playback_retry_limit) {
                        _debug('stop replay');
                        return;
                    }

                    var replay_timeout = 5000;

                    if (stb.user.fading_tv_retry_timeout) {
                        replay_timeout *= this.event5_counter <= 2 ? 1 : 6;
                    }

                    if (this.is_tv) {

                        this.replay_channel_timer = window.setTimeout(
                            function () {
                                self.play_last();
                            },
                            replay_timeout
                        );

                    } else {

                        if (self.prev_layer && self.prev_layer.show && !self.prev_layer.on) {
                            self.prev_layer.show.call(self.prev_layer, true);
                        }

                        var prev_layer = this.prev_layer && this.prev_layer.layer_name;

                        self.stop();

                        stb.notice.show(get_word('player_file_missing'));

                        _debug('this.is_tv', this.is_tv);
                        _debug('prev_layer', prev_layer);

                        if (!this.is_tv && prev_layer == 'tv') {
                            this.play_last(true);
                        }
                    }

                } else {

                    if (this.cur_media_item.media_type != 'advert' && this.play_continuously && this.cur_media_item.hasOwnProperty('series') && this.cur_media_item.series && this.cur_media_item.series.length > 0) {

                        var series_idx = this.cur_media_item.series.lastIndexOf(this.cur_media_item.cur_series);

                        _debug('series_idx before', series_idx);

                        var old_series_idx = series_idx;

                        if (series_idx < this.cur_media_item.series.length - 1) {
                            series_idx++;
                        }

                        _debug('series_idx after', series_idx);

                        if (old_series_idx != series_idx) {

                            this.cur_media_item.cur_series = this.cur_media_item.series[series_idx];
                            this.cur_media_item.disable_ad = true;

                            if (this.cur_media_item.cmd.indexOf('://')) {
                                this.cur_media_item.cmd = '/media/' + this.cur_media_item.id + '.mpg';
                            }

                            this.play(this.cur_media_item);
                            break;
                        }

                    } else if (this.cur_media_item.playlist && this.cur_media_item.playlist.length > 0) {

                        stb.key_lock = false;

                        if (typeof (this.cur_media_item.playlist[0]) == 'object') {

                            idx = -1;

                            for (var i = 0; i < this.cur_media_item.playlist.length; i++) {
                                if (this.cur_media_item.id == this.cur_media_item.playlist[i].id) {
                                    idx = i;
                                    break;
                                }
                            }

                            _debug('playlist idx', idx);

                            if (idx >= 0 && idx < this.cur_media_item.playlist.length - 1) {
                                idx++;

                                cur_media_item = this.cur_media_item.playlist[idx].clone();
                                cur_media_item.playlist = this.cur_media_item.playlist;
                                _debug('cur_media_item', cur_media_item);

                                if (cur_media_item.is_audio) {
                                    cur_media_item.number = null;
                                }

                                this.play(cur_media_item);

                                break;
                            }

                        } else {
                            var idx = this.cur_media_item.playlist.lastIndexOf(this.cur_media_item.cmd);

                            _debug('playlist idx', idx);


                            if (idx >= 0 && idx < this.cur_media_item.playlist.length - 1) {

                                idx++;

                                var cur_media_item = this.cur_media_item.clone();

                                cur_media_item.cmd = cur_media_item.playlist[idx];

                                var real_id_match = /real_id=([^&]*)/.exec(cur_media_item.cmd);

                                if (real_id_match) {
                                    cur_media_item.real_id = real_id_match[1];
                                }

                                var osd_title_match = /osd_title=([^&]*)/.exec(cur_media_item.cmd);

                                if (osd_title_match) {
                                    cur_media_item.name = decodeURIComponent(osd_title_match[1].replace(/\+/g, '%20'));
                                } else if (!cur_media_item.hasOwnProperty('keep_original_name') || !cur_media_item.keep_original_name) {
                                    cur_media_item.name = cur_media_item.cmd.substr(this.cur_media_item.cmd.lastIndexOf("/") + 1);
                                }

                                if (cur_media_item.show_osd) {
                                    this.need_show_info = 1;
                                }

                                if (this.cur_media_item.media_type && this.cur_media_item.media_type == 'advert') {
                                    delete cur_media_item.media_type;
                                    module && module.vclub && module.vclub.set_ad_ended_time && module.vclub.set_ad_ended_time(this.cur_media_item.ad_id, stb.GetPosTime(), stb.GetMediaLen(), true);
                                }

                                if (cur_media_item.hasOwnProperty('ad_must_watch')) {
                                    delete cur_media_item.ad_must_watch;
                                }

                                this.play(cur_media_item);

                            }

                            break;
                        }
                    }
                    stb.remount_storages(
                        function () {
                            if (self.event5_counter == 1) {
                                self.play(self.cur_media_item);
                            } else {
                                if (self.prev_layer && self.prev_layer.show) {
                                    self.prev_layer.show.call(self.prev_layer, true);
                                }

                                self.stop();

                                stb.notice.show(get_word('player_server_unavailable'));
                            }
                        }
                    )
                }
                break;
            }
        case 7: // video content info
            {
                if (this.con_menu.map.length < 3) {
                    this.get_pids();
                    this.set_media_aspect();
                }
                break;
            }

        case 9: // Teletext subtitles
            {
                this.get_pids();

                break;
            }
        case 35: { // PVR Error
            _debug('params', params);

            params = JSON.parse(params);

            if (params && params.hasOwnProperty('id')) {
                module.pvr_local.handle_error(params.id);
            } else {
                stb.notice.show(get_word('local_pvr_interrupted'));
            }

            module.pvr_local.remove_all_with_errors();
            break;
        }
        case 32: // HDMI on
            {

            }
        case 33: // HDMI off
            {

                if (stb.type == 'MAG200') {
                    break;
                }

                _debug('stb.power_off', stb.power_off);

                module && module.tv && module.tv.recalculate_preview_mode();

                if (stb.type == 'MAG256' || stb.type == 'MAG257') {
                    if (stb.power_off && event == 33) {
                        _debug('ignore event');
                        break;
                    } else if (!stb.power_off && event == 32) {
                        _debug('ignore event');
                        break;
                    }
                }

                window.clearTimeout(this.hdmi_reaction_timer);

                _debug('stb.profile[hdmi_event_reaction]', stb.profile['hdmi_event_reaction']);
                _debug('module.blocking.on', module.blocking.on);
                _debug('stb.hdmi_on', stb.hdmi_on);
                _debug('stb.profile[standby_on_hdmi_off]', stb.profile['standby_on_hdmi_off']);

                if (stb.profile['hdmi_event_reaction'] >= 1 && !module.blocking.on) {

                    var hdmi_reaction_timeout = (stb.profile['hdmi_event_reaction'] == 1 || !stb.hdmi_on ? 5 : stb.profile['hdmi_event_reaction']) * 1000;

                    _debug('hdmi_reaction_timeout', hdmi_reaction_timeout);

                    this.hdmi_reaction_timer = window.setTimeout(function () {

                        _debug('stb.power_off', stb.power_off);
                        _debug('stb.cur_place', stb.cur_place);
                        _debug('stb.hdmi_on', stb.hdmi_on);

                        if (stb.hdmi_on) {

                            stb.hdmi_on = false;

                            if (self.on) {
                                if (stb.cur_place == 'tv' && self.is_tv) {
                                    stb.player.stop();
                                } else if (!self.pause.on) {
                                    keydown_observer.emulate_key(key.EXIT);
                                }
                            }

                            if (stb.profile['standby_on_hdmi_off'] && !stb.power_off) {
                                stb.power_off = true;
                                stb.StandBy(1);
                            }
                        } else {

                            stb.hdmi_on = true;

                            if (stb.power_off) {
                                stb.power_off = false;
                                stb.StandBy(0);
                            }

                            if (stb.cur_place == 'tv' && self.is_tv) {

                                _debug('module.tv.on', module.tv.on);

                                if (module.tv.on) {
                                    module.tv.cur_page = 0;
                                    module.tv.load_data();
                                } else {
                                    stb.player.play_last();
                                }
                            }
                        }

                    }, hdmi_reaction_timeout);

                }

                break;
            }
        case 36: // local TimeShift
            {
                _debug('params', params);

                params = JSON.parse(params);

                if (params.event_code === 3 && this.pause.on && stb.profile.ts_buffer_use === 'cyclic') {
                    this.disable_pause();
                } else if (params.event_code === 6) {
                    this.active_local_time_shift = false;
                    this.is_tv = true;
                }

                break;
            }
        case 40: // Scanning DVB Channel in progress (STB_EVENT_DVB_SCAN_PROGRESS)
            {
                _debug('params', params);
                module.dvb && module.dvb.on_scan_result("progress", params);
                break;
            }
        case 41: // Scanning DVB Channel found (STB_EVENT_DVB_SCAN_FOUND)
            {
                _debug('params', params);
                module.dvb && module.dvb.on_scan_result("found", params);
                break;
            }
        case 42: // DVB EPG update (STB_EVENT_DVB_EPG_UPDATE)
            {
                _debug('params', params);

                params = JSON.parse(params) || [];

                module.dvb && module.dvb.update_epg(params.id);

                break;
            }
        case 43: // Antena power off
            {
                _debug('params', params);
                break;
            }
        case 129: // Stream losses
            {
                if (this.is_tv && stb.profile['enable_stream_losses_logging']) {
                    stb.log_stream_error(this.cur_tv_item['id'], 129);
                }
                break;
            }
    }

    if ([1, 2, 4, 5].indexOf(event) >= 0) {
        this.prev_state = event;
    }
};

player.prototype.volume = new function () {
    this.on = false;
    this.level = 100;
    this.step = 5;
    this.dom_obj = {};
    this.mute = { "on": false };
    this.hide_to = 3000;
    this.correction = 0;

    this.init = function () {
        _debug('volume.init');

        this.dom_obj = create_block_element('volume');

        this.container = create_block_element('volume_bar', this.dom_obj);

        this.bar = create_block_element('volume_progress', this.container);

        this.mute.dom_obj = create_block_element('volume_off', this.dom_obj);
        this.mute.dom_obj.hide();

        this.dom_obj.hide();
    };

    this.set_level = function (v) {
        _debug('volume.set_level', v);

        /*if (v > 100){
            v = 100;
        }else if (v < 0){
            v = 0;
        }*/

        this.level = v;

        var final_level = this.level + this.correction;

        _debug('final_level', final_level);

        if (final_level > 100) {
            this.level = 100;
            this.correction = 100 - this.level;
            final_level = 100;
        } else if (final_level < 0) {
            this.level = 0;
            this.correction = 0 - this.level;
            final_level = 0;
        }

        _debug('this.level', this.level);
        _debug('this.correction', this.correction);

        try {
            stb.SetVolume(this._get_real_volume_level(final_level));
        } catch (e) {
            _debug(e);
        }

        if (final_level == 0) {
            if (!this.mute.on) {
                this.show_mute();
            }
        } else {
            if (this.mute.on) {
                this.hide_mute();
            }
        }

        _debug('final_level', final_level);

        this.update_bar(final_level);
    };

    this.correct_level = function (c) {
        _debug('volume.correct_level', c);

        if (!c) {
            c = 0;
        }

        this.correction = c * this.step;

        var level = this.level + this.correction;

        _debug('this.level', this.level);
        _debug('this.correction', this.correction);
        _debug('level', level);

        if (level > 100) {
            level = 100;
            this.correction = 100 - this.level;
        } else if (level < 0) {
            level = 0;
            this.correction = 0 - this.level;
        }

        _debug('this.correction', this.correction);
        _debug('this.level', this.level);
        _debug('level', level);

        _debug('this.mute.on', this.mute.on);

        try {
            if (!this.mute.on) {
                stb.SetVolume(this._get_real_volume_level(level));
            }
        } catch (e) {
            _debug(e);
        }

        this.update_bar(level);
    };

    this._get_real_volume_level = function (level) {

        if (stb.profile['logarithm_volume_control']) {
            level = Math.log(level == 0 ? 1 : level) / Math.log(100) * 100;
        }

        _debug('real_volume_level', level);

        return level;
    };

    this.show = function () {
        _debug('volume.show');

        this.dom_obj.show();
        this.on = true;

        this.t_hide();
    };

    this.t_hide = function () {
        _debug('volume.t_hide');

        window.clearTimeout(this.hide_timer);

        var self = this;

        this.hide_timer = window.setTimeout(function () {

            self.hide();

        }, this.hide_to);
    };

    this.hide = function () {
        _debug('volume.hide');

        this.dom_obj.hide();
        this.on = false;
        this.save();
    };

    this.control = function (dir) {
        _debug('volume.control', dir);

        if (!this.on) {
            this.show();
        } else {
            this.t_hide();
        }

        try {
            if (dir > 0) {
                //if (this.level < 100){
                this.level += this.step;
                //}
            } else {
                //if (this.level > 0){
                this.level -= this.step;
                //}
            }

            /*if (this.level > 100){
                this.level = 100;
            }else if (this.level < 0){
                this.level = 0;
            }*/

            this.set_level(this.level);
        } catch (e) {
            _debug(e);
        }
    };

    this.show_mute = function () {
        _debug('volume.show_mute');

        try {
            stb.SetVolume(0);
        } catch (e) {
            _debug(e);
        }

        this.mute.dom_obj.show();

        if (!this.on) {
            this.show();
        }

        window.clearTimeout(this.hide_timer);

        this.container.hide();
        this.mute.on = true;

        this.start_move_mute_countdown();
    };

    this.start_move_mute_countdown = function () {
        _debug('volume.start_move_mute_counting');

        if (stb.profile['plasma_saving'] === '1') {

            window.clearTimeout(this.move_mute_to);

            var self = this;

            if (!this.mute.on) {
                return;
            }

            this.move_mute_to = window.setTimeout(function () {

                _debug('move_mute_to fired');
                _debug('stb.player.on', stb.player.on);
                _debug('self.mute.on', self.mute.on);

                if (!self.mute.on) {
                    return;
                }

                self.start_move_mute();

            }, stb.profile['plasma_saving_timeout'] * 1000);
        }
    };

    this.start_move_mute = function () {
        _debug('volume.start_move_mute');

        var self = this;

        this.move_mute_interval = window.setInterval(function () {

            self.mute.dom_obj.style.left = Math.floor(Math.random() * 200) + 'px';

        }, 10000);
    };

    this.stop_move_mute = function () {
        _debug('volume.stop_move_mute');

        window.clearInterval(this.move_mute_interval);
        window.clearTimeout(this.move_mute_to);

        this.mute.dom_obj.style.left = 0;
    };

    this.hide_mute = function () {
        _debug('volume.hide_mute');

        try {
            stb.SetVolume(this._get_real_volume_level(this.level));
        } catch (e) {
            _debug(e);
        }

        this.mute.dom_obj.hide();
        this.stop_move_mute();
        this.container.show();

        this.update_bar(this.level);
        this.mute.on = false;

        if (!this.on) {
            this.show();
        } else {
            this.t_hide();
        }
    };

    this.mute_switch = function () {
        _debug('volume.mute_switch');

        if (this.mute.on) {
            this.hide_mute();
        } else {
            this.show_mute();
        }
    };

    this.update_bar = function (level) {
        _debug('volume.update_bar', level);

        //var width = 14*this.level/this.step;
        var width = 10 * ((level / this.step) - 1);

        if (width > 0) {
            width += 5;
        } else {
            width = 10;
        }

        _debug('bar width', width);

        this.bar.style.width = width + 'px';
    };

    this.save = function () {
        _debug('volume.save');

        stb.load(
            {
                'type': 'stb',
                'action': 'set_volume',
                'vol': this.level
            },

            function (result) {

            }
        )
    }
};

/*player.prototype.seek_bar = new function(){
    
    this.seek_bar_dom_obj = $('seek_bar');
    
    this.show = function(){
        this.seek_bar_dom_obj.show();
    };
    
    this.hide = function(){
        this.seek_bar_dom_obj.hide();
    };
    
    this.set_pos = function(){
        
    }
};*/

player.prototype.define_media_type = function (cmd) {
    _debug('player.define_media_type', cmd);

    if (cmd.indexOf('://') > 0 && !this.cur_media_item.is_advert) {

        _debug('stb.cur_place', stb.cur_place);

        if ((cmd.indexOf('mmsh://') >= 0 || cmd.indexOf('rtsp://') >= 0 || cmd.indexOf('rtmp://') >= 0 || cmd.indexOf('udp://') >= 0 || cmd.indexOf('rtp://') >= 0 || cmd.indexOf('http://') >= 0 || cmd.indexOf('dvb://') >= 0) && !this.active_time_shift && stb.cur_place != 'demo' && stb.cur_place != 'internet' && stb.cur_place != 'epg_simple' && stb.cur_place != 'epg' && stb.cur_place != 'radio' && stb.cur_place != 'vclub' && stb.cur_place != 'karaoke' && stb.cur_place != 'audioclub' && !this.cur_media_item.is_audio && !this.cur_media_item.promo && !this.cur_media_item.radio) {
            this.is_tv = true;
        } else {
            this.is_tv = false;
        }

        this.proto = cmd.match(/(\S*):\/\//)[1];

        return 'stream';
    } else {

        this.is_tv = false;
        this.proto = 'file';

        return 'file';
    }
};

player.prototype.play_last = function (dont_show_osd) {
    _debug('player.play_last');

    this.prev_layer = module.tv;
    if (!dont_show_osd) {
        this.show_info_after_play();
    }
    this.play(this.last_not_locked_tv_item || this.cur_tv_item);
};

player.prototype.play_last_radio = function () {
    _debug('player.play_last_radio');

    this.show_info_after_play();
    this.play(this.cur_media_item);
};

player.prototype.init_first_channel = function () {
    _debug('player.init_first_channel');

    if (typeof (this.channels) != 'undefined' &&
        typeof (this.fav_channels) != 'undefined' &&
        typeof (this.fav_channels_ids) != 'undefined') {


        if (stb.user.fav_itv_on) {

            this.f_ch_idx = this.fav_channels.getIdxById(stb.user.last_itv_id);

            if (this.f_ch_idx === null) {
                this.f_ch_idx = 0;
            }

            var channel = this.fav_channels[this.f_ch_idx];

        } else {

            this.ch_idx = this.channels.getIdxById(stb.user.last_itv_id);

            if (this.ch_idx === null) {
                this.ch_idx = 0;
            }

            channel = this.channels[this.ch_idx];
        }

        this.cur_media_item = this.cur_tv_item = this.last_not_locked_tv_item = channel || {};
    }
};

player.prototype.first_play = function () {
    _debug('player.first_play');

    this.need_show_info = 1;

    stb.set_cur_place(module.tv.layer_name);
    stb.set_cur_layer(module.tv);
    stb.player.prev_layer = module.tv;

    this.play(this.cur_tv_item);
};

player.prototype.play = function (item) {
    _debug('player.play', item);

    if (authentication_problem && authentication_problem.on) {
        _debug('Authentication problem, player disabled');
        return;
    }

    var cmd;

    this.on = true;
    stb.clock.show();
    //this.cur_media_item = item;

    window.clearTimeout(this.replay_channel_timer);
    window.clearTimeout(this.archive_continue_dialog_to);
    window.clearTimeout(this.ad_skip_timer);
    window.clearTimeout(this.enable_local_timeshift);

    this.ad_indication.hide();
    this.ad_skip_indication.hide();
    stb.advert.stop_ticking();

    if (this.pause.on) {
        this.hide_pause();
    }

    if (this.volume.mute.on) {
        this.volume.start_move_mute_countdown();
    }

    if (typeof (item) == 'object') {
        if (!item.hasOwnProperty('cmd')) {
            return;
        }

        cmd = item.cmd;
    } else {
        cmd = item;
    }

    if (item.hasOwnProperty && item.hasOwnProperty('position') && parseInt(item.position) > 0 && !this.emulate_media_len) {
        cmd += ' position:' + item.position;
    }


    if (item.hasOwnProperty && item.hasOwnProperty('media_len') && parseInt(item.media_len) > 0) {
        cmd += ' media_len:' + item.media_len;
    }

    if (item.hasOwnProperty && item.hasOwnProperty('atrack') && item.atrack) {
        cmd = cmd.replace(/atrack:(\d+)/, '');
        cmd += ' atrack:' + item.atrack;
        cmd.replace(/\s+/g, ' ');
    }

    if (stb.usbdisk.storage_info.length > 0) {
        cmd = cmd.replace(/\/media\/USB-\*/, stb.usbdisk.storage_info[0]['mountPath']);
    }

    _debug('cmd', cmd);

    this.active_time_shift = item.hasOwnProperty('live_date');

    playback_limit.start_counting();

    var media_len_part = /media_len:(\d*)/.exec(cmd);

    this.prev_state = 0;

    if (module.tv_archive && this.cur_media_item.mark_archive && this.cur_media_item.archive_hist_id) {
        this.update_played_tv_archive_end_time(this.cur_media_item.archive_hist_id);
    }

    if (this.active_time_shift && this.cur_media_item.timeshift_hist_id) {
        this.update_played_timeshift_end_time(this.cur_media_item.timeshift_hist_id);
        this.cur_media_item.timeshift_hist_id = null;
    }

    if (media_len_part) {
        this.emulate_media_len = true;
        this.cur_media_length = media_len_part[1];
        _debug('cmd 1', cmd);
        cmd = cmd.replace(/media_len:(\d*)/g, '').trim();
        _debug('cmd 2', cmd);
        _debug('this.cur_media_length', this.cur_media_length);
    } else {
        this.emulate_media_len = false;
    }

    var position_part = /position:(\d*)/.exec(cmd);

    if (position_part) {
        item.position = position_part[1];
    }

    this.triggerCustomEventListener('stop', this.cur_media_item);

    if (this.file_type == 'audio' || this.cur_media_item.is_audio) {
        this.triggerCustomEventListener('audiostop', this.cur_media_item);
    }

    this.cur_media_item = item;

    _debug('item.position', item.position);
    _debug('this.emulate_media_len', this.emulate_media_len);
    _debug('cmd: ', cmd);

    this.media_type = this.define_media_type(cmd);

    if (this.media_type == 'file' || this.cur_media_item.is_audio) {
        this.file_type = this.get_file_type(this.cur_media_item);
    } else {
        this.file_type = undefined;
    }

    _debug('player.proto', this.proto);

    if (this.is_tv) {
        this.cur_tv_item = item;

        if (this.cur_tv_item.lock != '1') {
            this.last_not_locked_tv_item = item;
        }
    }

    _debug('player.media_type: ', this.media_type);
    _debug('player.file_type: ', this.file_type);
    _debug('player.is_tv: ', this.is_tv);

    this.on_play && this.on_play(this.cur_media_item['id']);
    this.triggerCustomEventListener('onplay', this.cur_media_item['id']);

    if (this.file_type == 'audio' || this.cur_media_item.is_audio) {
        this.triggerCustomEventListener('audiostart', this.cur_media_item);
    } else if (stb.player.cur_media_item.radio || typeof (stb.player.radio_idx) != 'undefined') {
        this.triggerCustomEventListener('radiostart', this.cur_media_item);
    }

    _debug('stb.profile[plasma_saving]', stb.profile['plasma_saving']);
    _debug('module.tv.on', module.tv && module.tv.on);

    if (this.is_tv && module.tv && module.tv.on && stb.profile['plasma_saving'] === '1') {
        module.tv.start_tv_plasma_saving_count();
    }

    this.play_initiated = true;

    _debug('stb.cur_place', stb.cur_place);

    if (this.media_type == 'stream' && !stb.player.cur_media_item.hasOwnProperty('series') && !stb.player.cur_media_item.hasOwnProperty('is_file')) {

        if (item.hasOwnProperty('open') && !item.open) {
            _debug('channel is closed');
            try {
                stb.Stop();
            } catch (e) {
            }
            this.show_info(this.cur_media_item);
        } else {

            if (this.is_tv) {
                stb.setFrontPanel(item.number);
            }

            if (!this.active_time_shift && !this.active_local_time_shift && (parseInt(item.use_http_tmp_link) == 1 || parseInt(item.use_load_balancing) == 1 || stb.user['force_ch_link_check'] && this.is_tv)) {
                var self = this;
                stb.player.on_create_link = function (result) {
                    _debug('player.on_create_link', result);

                    if (result.error) {
                        self.cur_media_item.error = result.error;
                        module.tv.preview_msg.innerHTML = get_word('error_channel_' + result.error);
                    } else {
                        self.cur_media_item.error = '';
                        module.tv.preview_msg.innerHTML = '';
                    }
                    if (result.cmd && result.cmd.search(/%mac%/ig) !== -1) {
                        result.cmd = result.cmd.replace(/%mac%/ig, stb.profile.mac);
                    }

                    module.tv.handle_advert(result, item);
                };

                this.create_link('itv', cmd, 0, false, item.disable_ad || stb.advert && stb.advert.disabled, false, stb.user['force_ch_link_check']);
            } else {
                if (cmd && cmd.search(/%mac%/ig) !== -1) {
                    cmd = cmd.replace(/%mac%/ig, stb.profile.mac);
                }
                this.play_now(cmd);
            }

            if (this.is_tv) {

                this.send_last_tv_id(this.cur_tv_item.id, this.cur_tv_item.lock == '1');

                if (this.cur_tv_item.lock != '1') {

                    if (stb.user.fav_itv_on) {

                        this.f_ch_idx = this.fav_channels.getIdxByVal('number', item.number);

                        this.hist_f_ch_idx.push(item);
                        this.hist_f_ch_idx.shift();
                        _debug('this.hist_f_ch_idx', this.hist_f_ch_idx);
                    } else {

                        this.ch_idx = this.channels.getIdxByVal('number', item.number);

                        this.hist_ch_idx.push(item);
                        this.hist_ch_idx.shift();
                        _debug('this.hist_ch_idx', this.hist_ch_idx);
                    }
                }
            }
        }

    } else if (cmd.indexOf('/usbdisk') > 0 || cmd.indexOf('/USB-') > 0 || cmd.indexOf('/ram/mnt/smb/') > 0 || cmd.indexOf('/av/') > 0 || cmd.indexOf('/UPnP/') > 0 || stb.cur_place == 'media_browser' || stb.player.cur_media_item.media_type == 'advert' || stb.player.cur_media_item.media_type == 'vclub_ad') {

        this.play_now(cmd);

    } else if (stb.cur_place == 'karaoke') {

        this.create_link('karaoke', cmd, 0);

    } else if (stb.cur_place == 'records' || stb.cur_place == 'remote_pvr' || stb.cur_place == 'epg_simple' || stb.cur_place == 'epg') {

        if (item.mark_archive && !item.mark_rec) {
            this.create_link('tv_archive', cmd, 0, '', false, item.download || false);
        } else {
            this.create_link('remote_pvr', cmd, 0, '', false, item.download || false);
        }

    } else {

        var series_number = item.cur_series || 0;
        if (stb.player.cur_media_item.rtsp_url) {
            cmd = '/media/' + this.cur_media_item.id + '.mpg';

            if (this.cur_media_item.hasOwnProperty('position')) {
                cmd += ' position:' + this.cur_media_item.position;
            }
        } else if (stb.player.cur_media_item.is_file) {
            cmd = '/media/file_' + this.cur_media_item.id + '.mpg';

            if (this.cur_media_item.hasOwnProperty('position')) {
                cmd += ' position:' + this.cur_media_item.position;
            }
        }
        this.create_link('vod', cmd, series_number, item.forced_storage || '', item.disable_ad || stb.advert && stb.advert.disabled, item.download || false);
    }
};

player.prototype.create_link = function (type, uri, series_number, forced_storage, disable_ad, download, force_ch_link_check) {

    series_number = series_number || "";

    _debug('player.create_link', type, uri, series_number, forced_storage, disable_ad, download, force_ch_link_check);

    stb.load(
        {
            "type": type,
            "action": "create_link",
            "cmd": uri,
            "series": series_number,
            "forced_storage": forced_storage,
            "disable_ad": disable_ad || false,
            "download": download || false,
            "force_ch_link_check": force_ch_link_check || false

        },

        function (result) {

            _debug('player.create_link callback type', type);

            if (type !== 'itv' && type !== 'remote_pvr') {
                _debug('create_link callback: ', result);

                this.last_storage_id = result.storage_id;

                _debug('this.last_storage_id', this.last_storage_id);

                if (result.cmd && result.cmd.indexOf('://') === -1) {
                    stb.Mount(result.cmd);
                }
            }

            this.on_create_link && this.on_create_link(result);
        },

        this
    )
};

player.prototype.delete_link = function (uri) {
    _debug('player.delete_link', uri);

    stb.load(
        {
            "type": "vod",
            "action": "del_link",
            "item": uri
        },
        function (result) {
            _debug('del_link result', result);
        }
    )
};

player.prototype.play_now = function (item) {
    _debug('player.play_now', item);

    if (typeof (item) == 'object') {
        var uri = item.cmd;

        if (item.hasOwnProperty('streamer_id')) {
            _log('play', {
                "streamer_id": item.streamer_id,
                "link_id": (item.link_id || 0),
                "ch_id": item.id
            }, this.cur_media_item.id);
        } else {
            _log('play', item);
        }

    } else {
        uri = item;
        _log('play', uri);
    }

    this.start_time = Date.parse(new Date()) / 1000;

    if (this.need_show_info) {
        this.show_info(this.cur_media_item);
    }

    this.init_con_menu();

    if (module.time_shift_local && module.time_shift_local.enabled && this.cur_media_item.allow_local_timeshift === '1' && (uri.indexOf('rtp ') === 0 || uri.indexOf('ffrt ') === 0)) {
        _debug('replacing solution to extTimeShift');
        uri = uri.replace(/^(rtp|ffrt)+\s/, 'extTimeShift ');
        _debug('uri', uri);
        this.cur_tv_item.ready_to_timeshift = true;
    }

    try {

        if (this.cur_media_item.error) {
            stb.Stop();
        } else {
            var use_proxy = false;

            if (stb.user['web_proxy_host']) {

                if (stb.user['web_proxy_exclude_list']) {
                    var web_proxy_exclude_list = stb.user['web_proxy_exclude_list'].split(' ').filter(function (p) {
                        return p
                    });

                    _debug('web_proxy_exclude_list', web_proxy_exclude_list);

                    if (web_proxy_exclude_list) {
                        var result = /:\/\/([^\/]+)/.exec(uri);
                        if (result) {
                            var host = result[1];

                            _debug('host', host);

                            use_proxy = !web_proxy_exclude_list.some(function (mask) {
                                return host.indexOf(mask) !== -1;
                            });
                        }
                    }
                }
            }

            _debug('use_proxy', use_proxy);

            var match = /(rtp|udp):\/\/([^\s]+)(.*)/.exec(uri);

            if (this.mc_proxy_url && match) {
                _debug('mc_proxy_url', this.mc_proxy_url);
                uri = uri.replace(/.+:\/\/[^\/]+/, 'ffrt ' + this.mc_proxy_url + '/' + match[1] + '/' + match[2] + match[3]);
                _debug('new uri', uri);
            }

            if (use_proxy) {

                var proxy_addr = 'http://';
                if (stb.user['web_proxy_user']) {
                    proxy_addr += stb.user['web_proxy_user'] + ':' + stb.user['web_proxy_pass'] + '@';
                }
                proxy_addr += stb.user['web_proxy_host'] + ':' + stb.user['web_proxy_port'];

                _debug('proxy_addr', proxy_addr);

                stb.Play(uri, proxy_addr);
            } else {
                stb.Play(uri);
            }
        }
    } catch (e) {
        _debug(e)
    }
};

player.prototype.stop = function () {
    _debug('player.stop');

    this.on_stop && this.on_stop();

    stb.advert.stop_ticking();

    _debug('stb.cur_place', stb.cur_place);
    _debug('this.play_auto_ended', this.play_auto_ended);
    _debug('this.cur_media_item', this.cur_media_item);
    _debug('this.cur_media_item.media_type', this.cur_media_item.media_type);

    if (stb.cur_place == 'vclub' && !this.play_auto_ended) {

        var cur_series = this.cur_media_item.cur_series || 0;
        try {
            var end_time = stb.GetPosTime();
        } catch (e) {
            var end_time = 0;
        }

        if (this.cur_media_item.media_type == 'vclub_ad') {
            module && module.vclub && module.vclub.set_ad_ended_time && module.vclub.set_ad_ended_time(this.cur_media_item.ad_id, end_time, stb.GetMediaLen());
        } else if (this.cur_media_item.media_type != 'advert') {
            this.cur_media_item.video_id = this.cur_media_item.video_id || this.cur_media_item.id;
            this.cur_media_item.video_id && module && module.vclub && module.vclub.set_not_ended && module.vclub.set_not_ended(this.cur_media_item.video_id, cur_series, end_time, this.cur_media_item.id);
        }
    }

    if (stb.cur_place == 'vclub' && this.play_auto_ended) {
        this.cur_media_item.video_id = this.cur_media_item.video_id || this.cur_media_item.id;
        if (this.cur_media_item.media_type == 'vclub_ad') {
            module && module.vclub && module.vclub.set_ad_ended_time && module.vclub.set_ad_ended_time(this.cur_media_item.ad_id, end_time, stb.GetMediaLen());
        } else if (this.cur_media_item.media_type != 'advert') {
            this.cur_media_item.video_id && module && module.vclub && module.vclub.set_ended && module.vclub.set_ended(this.cur_media_item.video_id);
        }
    }

    if (this.cur_media_item.stop_callback) {
        this.cur_media_item.stop_callback();
        return;
    }

    if (module.tv_archive && this.cur_media_item.mark_archive && this.cur_media_item.archive_hist_id) {
        this.update_played_tv_archive_end_time(this.cur_media_item.archive_hist_id);
        this.cur_media_item.archive_hist_id = null;
    }

    if (this.active_time_shift && this.cur_media_item.timeshift_hist_id) {
        this.update_played_timeshift_end_time(this.cur_media_item.timeshift_hist_id);
        this.cur_media_item.timeshift_hist_id = null;
    }

    if (module.tv && stb.profile['plasma_saving'] === '1') {
        module.tv.stop_tv_plasma_saving_count();
        this.volume.stop_move_mute();
    }

    this.on_stop = undefined;

    this.prev_layer = {};

    this.event1_counter = 0;
    this.event5_counter = 0;

    this.need_show_info = 0;

    this.cur_media_length = 0;

    this.active_time_shift = false;

    this.active_local_time_shift = false;

    playback_limit.reset();
    this.time_shift_indication.hide();
    this.ad_indication.hide();
    this.ad_skip_indication.hide();

    this.set_pos_button(0);

    window.clearTimeout(this.replay_channel_timer);
    window.clearTimeout(this.emulated_media_len_stop);
    window.clearTimeout(this.archive_continue_dialog_to);
    window.clearTimeout(this.ad_skip_timer);
    window.clearTimeout(this.enable_local_timeshift);

    this.on_create_link = function () {
    };

    this.on = false;

    this.triggerCustomEventListener('stop', this.cur_media_item);

    if (this.file_type == 'audio' || this.cur_media_item.is_audio) {
        this.triggerCustomEventListener('audiostop', this.cur_media_item);
    } else if (stb.player.cur_media_item.radio || typeof (stb.player.radio_idx) != 'undefined') {
        this.triggerCustomEventListener('radiostop', this.cur_media_item);
    }

    if (this.pause.on) {
        this.disable_pause();
    }

    if (this.quick_ch_switch.on) {
        this.cancel_quick_ch_switch();
    }

    try {
        stb.Stop();
        _debug('stb.Stop()');

        if (stb && stb.Set3DConversionMode) {
            stb.Set3DConversionMode(0);
        }

    } catch (e) {
    }

    if (this.media_type == 'file') {
        //stb.Umount();
        var storage = stb.mounted_storage;
        this.umount_timer = window.setTimeout(function () {
            stb.Umount(storage)
        }, 10000);
    }

    this.play_auto_ended = false;

    window.clearTimeout(this.send_played_itv_timer);
    window.clearTimeout(this.send_played_tv_archive_timer);
    window.clearTimeout(this.send_played_timeshift_timer);
    window.clearTimeout(this.send_played_video_timer);
    window.clearTimeout(this.replay_channel_timer);

    this.hide_info();
    _log('stop');
};

player.prototype.init_pause = function () {
    this.pause.dom_obj = create_block_element('pause');
    this.pause.dom_obj.hide();
};

player.prototype.init_rec = function () {
    this.rec.dom_obj = create_block_element('rec_container');
    create_block_element('rec_left', this.rec.dom_obj);
    this.rec.label = create_block_element('rec_main', this.rec.dom_obj);
    this.rec.dom_obj.hide();
};

player.prototype.show_rec_icon = function (record) {
    _debug('player.show_rec_icon', record);

    _debug('record[t_start_ts]', record['t_start_ts']);
    _debug('record[t_stop_ts]', record['t_stop_ts']);
    _debug('stb.clock.timestamp', stb.clock.timestamp);

    window.clearInterval(this.tick_timer);

    stb.player.rec.set_seconds(stb.clock.convert_sec_to_human_time(record['t_start_ts'] - stb.clock.timestamp));

    var self = this;
    this.tick_timer = window.setInterval(function () {
        self.tick_s(record)
    }, 1000);

    stb.player.rec.show();
};

player.prototype.hide_rec_icon = function () {
    _debug('player.hide_rec_icon');

    stb.player.rec.hide();
    window.clearInterval(this.tick_timer);
    stb.player.rec.set_seconds(0);
};

player.prototype.tick_s = function (record) {
    stb.player.rec.set_seconds(stb.clock.convert_sec_to_human_time(stb.clock.timestamp - record['t_start_ts']));
};

player.prototype.pause_switch = function () {
    _debug('player.pause_switch');

    if (this.is_tv && this.prev_layer.on) {
        return;
    }

    _debug('module.time_shift', !!module.time_shift);
    _debug('module.time_shift_local', !!module.time_shift_local);
    _debug('module.time_shift_local.enabled', module.time_shift_local ? !!module.time_shift_local.enabled : false);
    _debug('this.cur_media_item.allow_local_timeshift', this.cur_media_item.allow_local_timeshift);

    if (this.is_tv && (!module.time_shift || !parseInt(this.cur_media_item.enable_tv_archive, 10)) && (!module.time_shift_local || !module.time_shift_local.enabled || !parseInt(this.cur_media_item.allow_local_timeshift, 10) || (this.cur_media_item.cmd.indexOf('rtp ') == -1 && this.cur_media_item.cmd.indexOf('ffrt ') == -1))
        && !module.radio_widget.widget_on) {
        return;
    }

    _debug('this.pause.on - ', this.pause.on);

    if (this.pause.on) {
        this.disable_pause();
    } else {

        if (this.is_tv && parseInt(this.cur_media_item.enable_tv_archive, 10) && module.time_shift && !this.prev_layer.on) {
            module.time_shift.set_media_item(this.cur_tv_item);
            module.time_shift.get_link_for_channel();

            this.active_time_shift = true;
        } else if (this.is_tv && parseInt(this.cur_media_item.allow_local_timeshift, 10) && module.time_shift_local && module.time_shift_local.enabled && !this.prev_layer.on) {

            _debug('enter in timeshift mode');

            module.time_shift_local.enable_mode();

            this.active_local_time_shift = true;

        } else {
            if (!this.cur_media_item.hasOwnProperty('live_date')) {
                this.active_time_shift = false;
            }
        }

        if ((this.file_type == 'audio' || this.cur_media_item.is_audio) && this.cur_media_item.playlist) {
            this.triggerCustomEventListener('audiopause', this.cur_media_item);
        } else if (stb.player.cur_media_item.radio || typeof (stb.player.radio_idx) != 'undefined') {
            this.triggerCustomEventListener('radiopause', this.cur_media_item);
        }

        if (module.tv_archive && this.cur_media_item.mark_archive) {
            window.clearTimeout(this.archive_continue_dialog_to);
        }

        try {
            stb.Pause();
        } catch (e) {
        }
        this.pause.on = true;
        this.pause.dom_obj.show();

        if (stb.profile['plasma_saving'] === '1') {
            this.start_disabling_pause_count();
        }
    }
};

player.prototype.disable_pause = function () {
    _debug('player.disable_pause');

    _debug('this.active_time_shift', this.active_time_shift);
    _debug('this.active_local_time_shift', this.active_local_time_shift);

    this.stop_disabling_pause_count();

    if (this.active_time_shift) {
        _debug('new Date() - module.time_shift.cur_media_item.live_date', (new Date().getTime() - module.time_shift.cur_media_item.live_date.getTime()) / 1000);

        if ((new Date() - module.time_shift.cur_media_item.live_date) / 1000 < 5) {
            this.active_time_shift = false;
            this.is_tv = true;
            this.play_last();
        } else {
            this.time_shift_indication.show();
            if (this.is_tv) {
                this.play(module.time_shift.cur_media_item);
            } else {
                try {
                    stb.Continue();
                } catch (e) {
                }
            }

            this.is_tv = false;
        }

    } else if (this.active_local_time_shift) {
        this.time_shift_indication.show();
        try {
            stb.Continue();
        } catch (e) {
        }
        this.is_tv = false;
    } else {

        if ((this.file_type == 'audio' || this.cur_media_item.is_audio) && this.cur_media_item.playlist) {
            this.triggerCustomEventListener('audiocontinue', this.cur_media_item);
        } else if (stb.player.cur_media_item.radio || typeof (stb.player.radio_idx) != 'undefined') {
            this.triggerCustomEventListener('radiocontinue', this.cur_media_item);
        }

        try {
            if (module.tv_archive && this.cur_media_item.mark_archive) {

                var match = /-\d{10}-(\d+)\.m3u8/.exec(this.cur_media_item.cmd);

                if (match) {
                    var cur_media_length = match[1];
                } else {
                    cur_media_length = stb.GetMediaLen();
                }

                var archive_continue_dialog_delay = (cur_media_length - stb.GetPosTime() - 30) * 1000;

                _debug('archive_continue_dialog_delay 1', archive_continue_dialog_delay);

                window.clearTimeout(this.archive_continue_dialog_to);

                this.archive_continue_dialog_to = window.setTimeout(function () {
                    if (stb.profile['tv_archive_continued']) {
                        module.tv_archive.get_next_part_url();
                    } else {
                        module.tv_archive.continue_dialog.show();
                    }
                }, archive_continue_dialog_delay);
            }
        } catch (e) {
        }

        try {
            stb.Continue();
        } catch (e) {
        }
    }

    this.hide_pause();
};

player.prototype.hide_pause = function () {
    _debug('player.hide_pause');
    this.pause.on = false;
    this.pause.dom_obj.hide();
};

player.prototype.start_disabling_pause_count = function () {
    _debug('player.start_disabling_pause_count');

    window.clearTimeout(this.disable_pause_to);

    this.disable_pause_to = window.setTimeout(function () {

        _debug('disable_pause fired');

        if (stb.player.pause.on) {
            stb.player.disable_pause();
        }

    }, stb.profile['plasma_saving_timeout'] * 1000);
};

player.prototype.stop_disabling_pause_count = function () {
    _debug('player.stop_disabling_pause_count');

    window.clearTimeout(this.disable_pause_to);
};

player.prototype.show_info_after_play = function () {
    this.need_show_info = 1;
};

player.prototype.init_show_info = function () {

    this.info.dom_obj = create_block_element("osd_info");

    this.info.clock = create_block_element("osd_info_clock", this.info['dom_obj']);

    this.info.time_shift_mark = create_block_element("osd_info_time_shift_mark", this.info['dom_obj']);

    this.info.title = create_block_element("osd_info_title", this.info['dom_obj']);

    this.info.logo = create_block_element("osd_info_logo", this.info['dom_obj']);

    this.info.epg = create_block_element("osd_info_epg", this.info['dom_obj']);

    this.info.video_container = create_block_element("", this.info['dom_obj']);

    var pos_bar = create_block_element("pos_bar", this.info.video_container);
    this.info.pos_bar = pos_bar;

    this.info.pos_button = create_block_element("pos_button", pos_bar);

    //this.info.pos_time = create_block_element("pos_time", this.info.video_container);

    this.pos_time_bar = create_block_element("pos_time", this.info.video_container);

    this.info.cur_pos_time = create_block_element("cur_pos_time", this.pos_time_bar);

    var pos_time_separator = create_block_element("pos_time_separator", this.pos_time_bar);
    pos_time_separator.innerHTML = '/';

    this.info.total_pos_time = create_block_element("total_pos_time", this.pos_time_bar);

    this.info.pos_series = create_block_element("pos_series", this.info.video_container);

    this.info.epg.hide();
    this.info.video_container.hide();

    this.info.dom_obj.hide();
};

player.prototype.show_info = function (item, direct_call) {
    _debug('show_info');

    item = item || this.cur_media_item;
    direct_call = direct_call || false;

    if (this.info.on) {
        window.clearTimeout(this.info.hide_timeout);
        var show_epg_desc = true;
    } else {
        this.info.dom_obj.show();
        this.info.on = true;
        show_epg_desc = false;
    }

    _debug('item', item);

    var title = '';

    if (item.hasOwnProperty('number') && item.number) {
        title = item.number + '. ';
    }

    var title_idx = item.name.indexOf('?title=');

    var part = '';

    if (title_idx != -1) {

        part = item.name.substr(title_idx + 7);

        item.name = item.name.substr(0, title_idx);
    }

    var cmd_title_idx = item.cmd.indexOf('?title=');

    if (!part && cmd_title_idx != -1) {
        part = item.cmd.substr(cmd_title_idx + 7);
    }

    title += item.name;

    if (part) {

        title += ' ' + get_word('iso_title') + ' ' + (parseInt(part, 10) + 1);

        if (item.hasOwnProperty('playlist')) {
            title += '/' + item.playlist.length;
        }
    }

    var osd_title_match = /osd_title=([^&]*)/.exec(item.cmd);

    if (osd_title_match) {
        title = decodeURIComponent(osd_title_match[1].replace(/\+/g, '%20'));
    }

    this.info.title.innerHTML = title;

    _debug('this.is_tv', this.is_tv);

    if (item.is_audio) {
        this.info.clock.style.visibility = 'hidden';
    } else {
        this.info.clock.style.visibility = 'visible';
    }

    try {

        if (stb.cur_place == 'radio') {
            if (!this.info.epg.isHidden()) {
                this.info.epg.hide();
            }

            if (!this.info.video_container.isHidden()) {
                this.info.video_container.hide();
            }

        } else if (this.is_tv) {
            if (this.info.epg.isHidden()) {
                this.info.epg.show();
            }

            if (!this.info.video_container.isHidden()) {
                this.info.video_container.hide();
            }

            if (item.hasOwnProperty('open') && !item.open) {

                if (item.hasOwnProperty('error') && item.error) {
                    this.info.epg.innerHTML = '<span style="color:#ffb0b0">' + get_word('error_channel_' + item.error) + '</span>';
                } else {
                    this.info.epg.innerHTML = '<span style="color:#ffb0b0">' + get_word('msg_channel_not_available') + '</span>';
                }

            } else if (item.hasOwnProperty('error') && item.error) {
                this.info.epg.innerHTML = '<span style="color:#ffb0b0">' + get_word('error_channel_' + item.error) + '</span>';
            } else if (item.type && item.type == 'dvb' && typeof (dvbManager) !== 'undefined') {

                epg = dvbManager.GetEPGBrief(item.dvb_id);
                _debug('epg', epg);

                try {
                    epg = JSON.parse(epg);
                    epg = epg.events || [];
                } catch (e) {
                    _debug(e);
                    epg = [];
                }

                epg = epg.slice(0, 2);

                if (epg.length > 0) {
                    this.info.epg.innerHTML = epg.reduce(function (prev, program) {

                        return prev
                            + stb.clock.convert_timestamp_to_human_time(program.start)
                            + ' ' + program.name + '<br>';
                    }, '');
                } else {
                    this.info.epg.innerHTML = '';
                }

            } else {

                var epg = stb.epg_loader.get_curr_and_next(item.id);

                if (direct_call && show_epg_desc && epg && epg.length > 0 && epg[0].hasOwnProperty('descr') && epg[0].descr && this.info.epg.getAttribute("descr") !== "1") {
                    this.info.epg.innerHTML = epg[0].t_time + ' ' + epg[0].descr;
                    this.info.epg.setAttribute("descr", "1");
                } else {
                    this.info.epg.innerHTML = stb.epg_loader.get_osd_info(epg);
                    this.info.epg.setAttribute("descr", "0");
                }
            }
        } else {

            _debug('this.info.epg.isHidden()', this.info.epg.isHidden());

            if (!this.info.epg.isHidden()) {
                this.info.epg.hide();
            }

            _debug('this.info.video_container.isHidden()', this.info.video_container.isHidden());

            _debug('this.last_state', this.last_state);

            if (this.info.video_container.isHidden()) {
                this.info.video_container.show();
            }

            if (this.last_state == 4) {
                this.set_pos_button_to_cur_time();
            }
        }

        if (item.logo && stb.profile['show_tv_channel_logo']) {
            this.info.logo.innerHTML = '<img src="' + item.logo + '">';
        } else {
            this.info.logo.innerHTML = '';
        }

        if (this.active_time_shift || this.active_local_time_shift) {
            this.pos_time_bar.addClass('padding_pos_bar')
        } else {
            this.pos_time_bar.removeClass('padding_pos_bar')
        }

        stb.clock.show();

        if (item.cur_series && parseInt(item.cur_series) > 0) {
            this.info.pos_series.innerHTML = (item.cur_season ? item.cur_season + ' ' + get_word('player_season') + ', ' : '') + item.cur_series + ' ' + get_word('player_series');
        } else if ((this.cur_media_item.hasOwnProperty('live_date') || this.pause.on) && this.active_time_shift) {
            this.info.pos_series.innerHTML = module.time_shift.get_current_date();
        } else {
            this.info.pos_series.innerHTML = '';
        }

        if (item.enable_tv_archive == 1 && module.time_shift || item.allow_local_timeshift == 1 && module.time_shift_local && module.time_shift_local.enabled) {
            this.info.time_shift_mark.show();
        } else {
            this.info.time_shift_mark.hide();
        }

        var self = this;

        this.info.hide_timeout = window.setTimeout(function () {
            self.info.dom_obj.hide();
            self.info.on = false;
        },
            this.info.hide_timer * (this.info.epg.getAttribute("descr") == "1" ? 4 : 1));
    } catch (e) {
        _debug(e);
    }
};

player.prototype.hide_info = function () {
    _debug('player.hide_info');

    this.reset_pos_by_numbers();
    this.new_pos_time = 0;
    window.clearTimeout(this.info.hide_timeout);
    this.info.dom_obj.hide();
    this.info.on = false;
};

player.prototype._find_nearest_ch_idx = function (direction, condition, invert_condition) {
    _debug('player._find_nearest_channel', direction, condition);

    invert_condition = invert_condition || false;

    if (stb.user.fav_itv_on) {
        var channels = this.fav_channels;
        var ch_idx = this.f_ch_idx;
    } else {
        channels = this.channels;
        ch_idx = this.ch_idx;
    }

    for (var _param in condition) {
        if (condition.hasOwnProperty(_param)) {
            var param = _param;
            var value = condition[param];
        }
    }

    if (direction > 0) {
        var end = channels.length - 1;
    } else {
        end = 0;
    }

    if (direction > 0) {

        for (var i = ch_idx + 1; i <= channels.length - 1; i++) {

            if (invert_condition) {
                if (channels[i][param] != value) {
                    return i;
                }
            } else {
                if (channels[i][param] == value) {
                    return i;
                }
            }
        }

        for (i = 0; i < ch_idx; i++) {

            if (invert_condition) {
                if (channels[i][param] != value) {
                    return i;
                }
            } else {
                if (channels[i][param] == value) {
                    return i;
                }
            }
        }

    } else {

        for (i = ch_idx - 1; i >= 0; i--) {
            if (invert_condition) {
                if (channels[i][param] != value) {
                    return i;
                }
            } else {
                if (channels[i][param] == value) {
                    return i;
                }
            }
        }

        for (i = channels.length - 1; i > ch_idx; i--) {
            if (invert_condition) {
                if (channels[i][param] != value) {
                    return i;
                }
            } else {
                if (channels[i][param] == value) {
                    return i;
                }
            }
        }

    }
    return null;
};

player.prototype.switch_channel = function (dir, show_info, do_not_invert) {

    _debug('switch_channel', dir);

    if (!do_not_invert && stb.user.invert_channel_switch_direction == 1) {
        dir = -1 * dir;
    }

    if (this.active_time_shift || this.active_local_time_shift) {
        this.time_shift_indication.show();
        return;
    }

    if (!this.is_tv) {
        return;
    }

    if (show_info) {
        this.need_show_info = 1;
    } else {
        this.need_show_info = 0;
    }

    var item = {};

    _debug('module.tv.genre', module.tv.genre);

    if (dir > 0) {

        if (stb.user.fav_itv_on) {

            if (module.tv.genre && module.tv.genre.id != '*') {
                this.f_ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": module.tv.genre.id });

                _debug('nearest this.f_ch_idx', this.f_ch_idx);
            } else {

                if (stb.profile['hide_tv_genres_in_fullscreen']) {
                    this.f_ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": stb.profile['hide_tv_genres_in_fullscreen'][0] }, true);
                } else {
                    if (this.f_ch_idx < this.fav_channels.length - 1) {
                        this.f_ch_idx++;
                    } else {
                        this.f_ch_idx = 0;
                    }
                }
            }

            _debug('this.f_ch_idx:', this.f_ch_idx);

            item = this.fav_channels[this.f_ch_idx];

        } else {

            if (module.tv.genre && module.tv.genre.id != '*') {
                this.ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": module.tv.genre.id });

                _debug('nearest this.ch_idx', this.ch_idx);
            } else {

                if (stb.profile['hide_tv_genres_in_fullscreen']) {
                    this.ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": stb.profile['hide_tv_genres_in_fullscreen'][0] }, true);
                } else {
                    if (this.ch_idx < this.channels.length - 1) {
                        this.ch_idx++;
                    } else {
                        this.ch_idx = 0;
                    }
                }
            }

            _debug('this.ch_idx:', this.ch_idx);

            item = this.channels[this.ch_idx];
        }

    } else {
        if (stb.user.fav_itv_on) {

            if (module.tv.genre && module.tv.genre.id != '*') {
                this.f_ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": module.tv.genre.id });

                _debug('nearest this.f_ch_idx', this.f_ch_idx);
            } else {

                if (stb.profile['hide_tv_genres_in_fullscreen']) {
                    this.f_ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": stb.profile['hide_tv_genres_in_fullscreen'][0] }, true);
                } else {
                    if (this.f_ch_idx > 0) {
                        this.f_ch_idx--;
                    } else {
                        this.f_ch_idx = this.fav_channels.length - 1;
                    }
                }
            }

            _debug('this.f_ch_idx:', this.f_ch_idx);

            item = this.fav_channels[this.f_ch_idx];

        } else {

            if (module.tv.genre && module.tv.genre.id != '*') {
                this.ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": module.tv.genre.id });

                _debug('nearest this.ch_idx', this.ch_idx);
            } else {

                if (stb.profile['hide_tv_genres_in_fullscreen']) {
                    this.ch_idx = this._find_nearest_ch_idx(dir, { "tv_genre_id": stb.profile['hide_tv_genres_in_fullscreen'][0] }, true);
                } else {
                    if (this.ch_idx > 0) {
                        this.ch_idx--;
                    } else {
                        this.ch_idx = this.channels.length - 1;
                    }
                }
            }

            _debug('this.ch_idx:', this.ch_idx);

            item = this.channels[this.ch_idx];
        }
    }

    if (!item) {
        _debug('no channel');
        return;
    }

    if (parseInt(item.use_http_tmp_link) == 1 || parseInt(item.use_load_balancing) == 1 || stb.user['force_ch_link_check']) {
        this.on_create_link = function (result) {
            _debug('player.tv.on_create_link', result);

            if (result.error == 'limit') {
                stb.notice.show(word['player_limit_notice']);
            } else if (result.error == 'nothing_to_play') {
                stb.notice.show(word['player_file_missing']);
            } else if (result.error == 'link_fault') {
                stb.notice.show(word['player_server_error']);
            } else if (result.error == 'access_denied') {
                stb.notice.show(word['player_access_denied']);
            } else {
                stb.player.play_now(result);
            }
        }
    }

    this.play(item);
};

player.prototype.send_last_tv_id = function (id, censored) {
    _debug('send_last_tv_id', id, censored);

    censored = censored || false;

    _debug('censored', censored);

    var self = this;

    window.clearTimeout(this.send_played_itv_timer);

    this.send_played_itv_timer = window.setTimeout(
        function () {
            self.send_played_itv(id, censored);
        },

        this.send_last_tv_id_to
    );

    if (censored) {
        return;
    }

    _debug('this.last_tv_id', this.last_tv_id);

    if (id == this.last_tv_id) {
        return;
    }

    this.last_tv_id = stb.user.last_itv_id = id;

    stb.load(
        {
            "type": "itv",
            "action": "set_last_id",
            "id": id
        },

        function (result) {
            _debug('last_tv_id saved', result);

            _debug('typeof this.send_last_tv_id_callback', typeof (this.send_last_tv_id_callback));

            this.send_last_tv_id_callback();

            this.send_last_tv_id_callback = function () {
            };
        },

        this
    )
};

player.prototype.send_played_itv = function (id, censored) {

    censored = censored || false;

    stb.load(
        {
            "type": "itv",
            "action": "set_played",
            "itv_id": id,
            "censored": censored
        },

        function (result) {

        }
    );
};

player.prototype.send_played_timeshift = function (id) {

    stb.load(
        {
            "type": "tv_archive",
            "action": "set_played_timeshift",
            "ch_id": id
        },

        function (result) {
            _debug('on timeshift set_played', result);

            this.cur_media_item.timeshift_hist_id = result;
        },

        this
    );
};

player.prototype.update_played_timeshift_end_time = function (id) {

    stb.load(
        {
            "type": "tv_archive",
            "action": "update_played_timeshift_end_time",
            "hist_id": id
        },

        function (result) {
            _debug('on update_played_timeshift_end_time', result);
        },

        this
    );
};

player.prototype.send_played_tv_archive = function (id) {

    stb.load(
        {
            "type": "tv_archive",
            "action": "set_played",
            "ch_id": id
        },

        function (result) {
            _debug('on archive set_played', result);

            this.cur_media_item.archive_hist_id = result;
        },

        this
    );
};

player.prototype.update_played_tv_archive_end_time = function (id) {

    stb.load(
        {
            "type": "tv_archive",
            "action": "update_played_end_time",
            "hist_id": id
        },

        function (result) {
            _debug('on update_played_end_time', result);

        },

        this
    );
};

player.prototype.send_played_video = function (video_id) {
    _debug('player.send_played_video', video_id);

    stb.load(
        {
            "type": "vod",
            "action": "set_played",
            "video_id": video_id,
            "storage_id": this.last_storage_id
        },

        function (result) {

        }
    );

};

player.prototype.show_prev_layer = function () {
    _debug('player.show_prev_layer');

    try {
        if (this.prev_layer && this.prev_layer.show) {
            this.prev_layer.show.call(this.prev_layer, true);
        } else {
            if (this.is_tv) {
                module.tv._show();
            }
        }

        if (this.prev_layer && this.prev_layer.cur_view == 'short') {
            return;
        }

        this.stop();
    } catch (e) {
        _debug(e);
    }
};

player.prototype.time_shift_indication = {
    on: false,

    init: function () {
        _debug('time_shift_indication.init');

        this.time_shift_info_container = create_block_element('time_shift_info_container');
        this.time_shift_info_txt = create_block_element('time_shift_info_block', this.time_shift_info_container);

        this.time_shift_info_txt.innerHTML = get_word('TIME SHIFT');

        create_block_element('time_shift_info_block_right', this.time_shift_info_container);

        this.time_shift_info_container.hide();
    },

    show: function () {
        _debug('time_shift_indication.show');
        this.time_shift_info_container.show();
        this.on = true;
        this.t_hide();
    },

    t_hide: function () {
        _debug('time_shift_indication.t_hide');
        window.clearTimeout(this.time_shift_indication_hide);
        var self = this;
        this.time_shift_indication_hide = window.setTimeout(function () {
            self.hide();
        }, 3000);
    },

    hide: function () {
        _debug('time_shift_indication.hide');
        this.time_shift_info_container.hide();
        this.on = false;
    }
};

player.prototype.ad_indication = {
    on: false,

    init: function () {
        _debug('ad_indication.init');

        this.ad_indication_container = create_block_element('time_shift_info_container');
        this.ad_indication_txt = create_block_element('time_shift_info_block', this.ad_indication_container);

        this.ad_indication_txt.innerHTML = get_word('commercial');

        create_block_element('time_shift_info_block_right', this.ad_indication_container);

        this.ad_indication_container.hide();
    },

    show: function () {
        _debug('ad_indication.show');
        this.ad_indication_container.show();
        this.on = true;
        this.t_hide();
    },

    t_hide: function () {
        _debug('ad_indication.t_hide');
        window.clearTimeout(this.ad_indication_hide);
        var self = this;
        this.ad_indication_hide = window.setTimeout(function () {
            self.hide();
        }, 3000);
    },

    hide: function () {
        _debug('ad_indication.hide');
        this.ad_indication_container && this.ad_indication_container.hide && this.ad_indication_container.hide();
        this.on = false;
    }
};

player.prototype.ad_skip_indication = {
    on: false,

    init: function () {
        _debug('ad_skip_indication.init');

        this.ad_skip_indication_container = create_block_element('ad_skip_container');
        this.ad_skip_indication_txt = create_block_element('ad_skip_block', this.ad_skip_indication_container);

        this.ad_skip_indication_txt.innerHTML = '<div class="ad_skip_txt">' + get_word('ad_skip') + '</div>';

        create_block_element('time_shift_info_block_right', this.ad_skip_indication_container);

        this.ad_skip_indication_container.hide();
    },

    show: function () {
        _debug('ad_skip_indication.show');
        this.ad_skip_indication_container.show();
        this.on = true;
        //this.t_hide();
    },

    t_hide: function () {
        _debug('ad_skip_indication.t_hide');
        window.clearTimeout(this.ad_skip_indication_hide);
        var self = this;
        this.ad_skip_indication_hide = window.setTimeout(function () {
            self.hide();
        }, 3000);
    },

    hide: function () {
        _debug('ad_skip_indication.hide');
        this.ad_skip_indication_container && this.ad_skip_indication_container.hide && this.ad_skip_indication_container.hide();
        this.on = false;
    }
};

player.prototype.bind = function () {

    var self = this;

    this.switch_channel.bind(key.UP, self, 1, true);
    this.switch_channel.bind(key.DOWN, self, -1, true);

    this.switch_channel.bind(key.CHANNEL_NEXT, self, 1, true, true);
    this.switch_channel.bind(key.CHANNEL_PREV, self, -1, true, true);

    (function () {
        stb.player.ClockOnVideo.toggle();
    }).bind(key.CLOCK, this);

    (function () {

        if (this.info.on) {
            this.set_pos_and_play();
        } else {
            this.pause_switch();
        }

    }).bind(key.PAUSE, this);


    (function () {
        if (this.info.on) {
            this.set_pos_and_play(true);
        } else if (this.quick_ch_switch.on) {
            this.cancel_quick_ch_switch();
        } else if (this.is_tv) {
            this.hide_info();
            module.tv._show();
            module.tv.set_short_container();
        } else {
            if (this.active_time_shift || this.active_local_time_shift) {
                this.show_time_shift_exit_confirm();
            } else if (this.prev_layer.layer_name == 'epg' && this.prev_layer.player_overlay_mode) {
                this.play_last();
                module.epg.show(false, true);
            } else {
                this.show_prev_layer();
                if (this.cur_media_item.hasOwnProperty('ad_tracking')) {

                    this.ad_indication.hide();
                    this.ad_skip_indication.hide();
                    stb.advert.stop_ticking();

                    if (this.cur_media_item.ad_tracking.hasOwnProperty('close')) {
                        stb.advert.track(this.cur_media_item.ad_tracking['close'], 'close')
                    }

                    this.cur_media_item.stop_callback && this.cur_media_item.stop_callback();
                }
            }
        }
    }).bind(key.EXIT, this);

    (function () {
        this.prev_layer && this.prev_layer.hide && this.prev_layer.hide();
        this.play_last();
    }).bind(key.TV, this);

    (function () {
        if (this.is_tv && !module.tv.on) {

            var rec_idx = stb.recordings.getIdxByVal('ch_id', this.cur_tv_item.id);

            _debug('rec_idx', rec_idx);

            if (rec_idx !== null) {

                if (stb.recordings[rec_idx].local == 1) {
                    module.pvr_local.stop_channel_rec(this.cur_tv_item);
                } else {
                    module.remote_pvr.stop_channel_rec(this.cur_tv_item);
                }

                return;
            }

        } else {
            if (this.active_time_shift || this.active_local_time_shift) {
                this.show_time_shift_exit_confirm();
            } else {
                this.show_prev_layer();
            }
        }
    }).bind(key.STOP, self);

    //this.show_prev_layer.bind(key.STOP, self);

    this.show_info.bind(key.INFO, self, null, true);

    this.move_pos.bind(key.FFWD, this, 1);

    this.move_pos.bind(key.REW, this, -1);

    (function () {

        if (this.cur_media_item.media_type == 'advert' || this.cur_media_item.media_type == 'vclub_ad') {

            this.ad_indication.hide();
            this.ad_skip_indication.hide();
            stb.advert.stop_ticking();

            if (this.cur_media_item.hasOwnProperty('ad_tracking')) {
                if (this.cur_media_item.ad_tracking.hasOwnProperty('close')) {
                    stb.advert.track(this.cur_media_item.ad_tracking['close'], 'close')
                }
            }

            this.cur_media_item.stop_callback && this.cur_media_item.stop_callback();
            return;
        } else if (this.info.on && !this.quick_ch_switch.on) {
            if (this.is_tv) {
                this.hide_info();
                module.tv._show();
                module.tv.set_short_container();
            } else {
                this.set_pos_and_play();
            }

        } else if (this.quick_ch_switch.on) {
            this.hide_quick_ch_switch();
        } else if (this.prev_layer && this.prev_layer.cur_view == 'short' && !this.is_tv) {

            if (this.active_time_shift || this.active_local_time_shift) {
                this.show_time_shift_exit_confirm();
            } else {
                this.show_prev_layer();
            }

        } else if (this.is_tv) {
            if (this.active_local_time_shift) {
                this.show_time_shift_exit_confirm();
            } else {
                module.tv._show(module.tv.genre);
                module.tv.set_short_container();
            }
        }

    }).bind(key.OK, this);

    (function () {

        if (!module.tv || !module.tv.on) {
            this.con_menu && this.con_menu.show && this.con_menu.show();
        }

    }).bind(key.APP, this).priority_bind(key.AUDIO, this);


    (function () {
        if (this.on) {
            this.volume.control(1);
        }
    }).bind(key.VOL_UP, this);

    (function () {
        if (this.on) {
            this.volume.control(-1);
        }
    }).bind(key.VOL_DOWN, this);


    (function (dir) {

        _debug('stb.profile[enable_arrow_keys_setpos]', stb.profile['enable_arrow_keys_setpos']);
        _debug('dir', dir);

        if (stb.profile['enable_arrow_keys_setpos']) {
            this.move_pos(dir);
        } else {
            this.volume.control(dir);
        }

    }).bind(key.RIGHT, this, 1).bind(key.LEFT, this, -1);

    (function () {
        if (this.on) {
            this.volume.mute_switch();
        }
    }).bind(key.MUTE, this);

    this.numpad_key_handler.bind(key.NUM1, this, 1);
    this.numpad_key_handler.bind(key.NUM2, this, 2);
    this.numpad_key_handler.bind(key.NUM3, this, 3);
    this.numpad_key_handler.bind(key.NUM4, this, 4);
    this.numpad_key_handler.bind(key.NUM5, this, 5);
    this.numpad_key_handler.bind(key.NUM6, this, 6);
    this.numpad_key_handler.bind(key.NUM7, this, 7);
    this.numpad_key_handler.bind(key.NUM8, this, 8);
    this.numpad_key_handler.bind(key.NUM9, this, 9);
    this.numpad_key_handler.bind(key.NUM0, this, 0);

    (function () {

        if (this.info.on) {
            if (this.pos_by_numbers_input != '') {
                this.pos_by_numbers_back_key_handler();
            }
        }

        if (this.quick_ch_switch.on) {
            this.del_quick_go_ch();
        } else {
            if (this.is_tv) {
                this.hist_back();
            }
        }
    }).bind(key.BACK, this);

    (function () {

        if (this.is_tv) {
            this.change_tv_channel_aspect();
        } else {
            this.change_aspect();
        }

    }).bind(key.FRAME, this);

    (function () {

        if (this.is_tv && !module.tv.on) {

            var rec_idx = stb.recordings.getIdxByVal('ch_id', this.cur_tv_item.id);

            _debug('rec_idx', rec_idx);

            if (rec_idx !== null) {

                if (stb.recordings[rec_idx].t_start_ts * 1000 > new Date().getTime()) {
                    stb.notice.show(get_word('rec_channel_has_scheduled_recording'));
                } else if (stb.recordings[rec_idx].local == 1) {
                    module.pvr_local.rec_switch(this.cur_tv_item);
                } else {
                    module.remote_pvr.rec_switch(this.cur_tv_item);
                }

                return;
            }

            this.init_pvr_dialogs();

            if (loader.template == "xc_vm") {
                if (module.pvr_local) {
                    if (this.cur_tv_item['allow_local_pvr']) {
                        // show confirm
                        this.local_pvr_confirm.deferred = false;
                        this.local_pvr_confirm.channel = this.cur_tv_item;
                        this.local_pvr_confirm.show({ parent: this });
                    } else {
                        stb.notice.show(get_word('channel_recording_restricted'));
                    }
                } else {
                    stb.notice.show(get_word('recording_disabled'));
                }
            } else {
                var allow_remote_pvr = module.remote_pvr && this.cur_tv_item['allow_pvr'];
                var allow_local_pvr = module.pvr_local && this.cur_tv_item['allow_local_pvr'];
                if (allow_remote_pvr && allow_local_pvr) {
                    this.pvr_target_select.deferred = false;
                    this.pvr_target_select.channel = this.cur_tv_item;
                    this.pvr_target_select.show({ parent: this });
                } else if (allow_remote_pvr) {
                    // show confirm
                    this.remote_pvr_confirm.deferred = false;
                    this.remote_pvr_confirm.channel = this.cur_tv_item;
                    this.remote_pvr_confirm.show({ parent: this });
                } else if (allow_local_pvr) {
                    // show confirm
                    this.local_pvr_confirm.deferred = false;
                    this.local_pvr_confirm.channel = this.cur_tv_item;
                    this.local_pvr_confirm.show({ parent: this });
                } else {
                    stb.notice.show(get_word('channel_recording_restricted'));
                }
            }
        }

    }).bind(key.REC, this).bind(key.RED, this);

    this.volume.set_level.bind(key.REFRESH, this.volume, 50);

    (function (dir) {

        _debug('dir', dir);

        var self = this;

        if (this.cur_media_item.media_type != 'vclub_ad' && this.cur_media_item.media_type != 'advert' && this.cur_media_item.hasOwnProperty('series') && this.cur_media_item.series && this.cur_media_item.series.length > 0) {

            _debug('this.cur_media_item.cur_series before', this.cur_media_item.cur_series);
            _debug('this.cur_media_item.series.length', this.cur_media_item.series.length);

            var series_idx = this.cur_media_item.series.indexOf(this.cur_media_item.cur_series);

            _debug('series_idx before', series_idx);

            var old_series_idx = series_idx;

            if (dir > 0) {
                if (series_idx < this.cur_media_item.series.length - 1) {
                    series_idx++;
                }
            } else {
                if (series_idx > 0) {
                    series_idx--;
                }
            }

            _debug('series_idx after', series_idx);

            if (old_series_idx != series_idx) {

                this.cur_media_item.cur_series = this.cur_media_item.series[series_idx];
                this.cur_media_item.disable_ad = true;

                if (this.cur_media_item.cmd.indexOf('://')) {
                    this.cur_media_item.cmd = '/media/' + this.cur_media_item.id + '.mpg';
                }

                this.play(this.cur_media_item);
            }

            return;
        }

        _debug('this.active_time_shift', this.active_time_shift);

        if (this.active_time_shift) {

            if (!this.pause.on) {

                if (module.tv_archive && this.cur_media_item.mark_archive) {
                    window.clearTimeout(this.archive_continue_dialog_to);
                }

                try {
                    stb.Pause();
                } catch (e) {
                    _debug(e);
                }
                this.pause.on = true;
            }

            if (dir > 0) {
                module.time_shift.pos_to_next_program();
            } else {
                module.time_shift.pos_to_previous_program();
            }

            if (!this.info.on) {
                this.show_info();
            }

            window.clearTimeout(this.info.hide_timeout);

            this.info.hide_timeout = window.setTimeout(function () {
                stb.player.set_pos_and_play();
            }, 4000);
        }

        // start time-shift mode
        if (this.is_tv && parseInt(this.cur_media_item.enable_tv_archive, 10) && module.time_shift && !this.prev_layer.on) {

            if (dir > 0) {
                return;
            }

            if (!this.pause.on) {

                if (module.tv_archive && this.cur_media_item.mark_archive) {
                    window.clearTimeout(this.archive_continue_dialog_to);
                }

                try {
                    stb.Pause();
                } catch (e) {
                    _debug(e);
                }
                this.pause.on = true;
            }

            module.time_shift.set_media_item(this.cur_tv_item);
            module.time_shift.get_link_for_channel();
            this.is_tv = false;
            this.active_time_shift = true;
            this.cur_pos_time = module.time_shift.get_pos_time();
            this.cur_media_length = module.time_shift.get_cur_media_length();
            _debug('this.cur_media_length', this.cur_media_length);
            _debug('this.cur_pos_time', this.cur_pos_time);

            module.time_shift.pos_to_cur_program_begin();

            if (!this.info.on) {
                this.show_info();
            }

            window.clearTimeout(this.info.hide_timeout);

            this.info.hide_timeout = window.setTimeout(function () {
                self.hide_info();
            }, 4000);
        }

        if (this.cur_media_item.media_type == 'advert' || this.cur_media_item.media_type == 'vclub_ad') {

            this.ad_indication.hide();
            this.ad_skip_indication.hide();
            stb.advert.stop_ticking();

            if (this.cur_media_item.hasOwnProperty('ad_tracking')) {
                if (this.cur_media_item.ad_tracking.hasOwnProperty('close')) {
                    stb.advert.track(this.cur_media_item.ad_tracking['close'], 'close')
                }
            }

            this.cur_media_item.stop_callback && this.cur_media_item.stop_callback();
            return;
        }

        var idx = this.cur_media_item.playlist.lastIndexOf(this.cur_media_item.cmd);

        _debug('idx', idx);

        if (idx == -1) {
            return;
        }

        idx = idx + dir;

        if (!this.cur_media_item.playlist[idx]) {
            return;
        }

        var cur_media_item = this.cur_media_item.clone();

        cur_media_item.cmd = cur_media_item.playlist[idx];

        //cur_media_item.name = cur_media_item.cmd.substr(this.cur_media_item.cmd.lastIndexOf("/") + 1);

        var real_id_match = /real_id=([^&]*)/.exec(cur_media_item.cmd);

        if (real_id_match) {
            cur_media_item.real_id = real_id_match[1];
        }

        var osd_title_match = /osd_title=([^&]*)/.exec(cur_media_item.cmd);

        if (osd_title_match) {
            cur_media_item.name = decodeURIComponent(osd_title_match[1].replace(/\+/g, '%20'));
        } else if (!cur_media_item.hasOwnProperty('keep_original_name') || !cur_media_item.keep_original_name) {
            cur_media_item.name = cur_media_item.cmd.substr(this.cur_media_item.cmd.lastIndexOf("/") + 1);
        }

        if (cur_media_item.show_osd) {
            stb.Stop();
            this.need_show_info = 1;
        }

        if (cur_media_item.hasOwnProperty('ad_must_watch')) {
            delete cur_media_item.ad_must_watch;
        }

        if (this.cur_media_item.media_type && this.cur_media_item.media_type == 'vclub_ad') {
            delete cur_media_item.media_type;
            cur_media_item.disable_ad = true;
            module && module.vclub && module.vclub.set_ad_ended_time && module.vclub.set_ad_ended_time(this.cur_media_item.ad_id, stb.GetPosTime(), stb.GetMediaLen());
        }

        this.play(cur_media_item);

    }).bind(key.NEXT, this, 1).bind(key.PREV, this, -1);

    (function () {
        if (this.is_tv && module.epg) {
            if (module.epg.on) {
                module.epg.hide();
            } else {
                module.epg.ch_id = this.cur_tv_item.id;
                module.epg.show(false, true);
            }
        }
    }).bind(key.EPG, this).bind(key.BLUE, this);
};

player.prototype.numpad_key_handler = function (num) {
    _debug('player.numpad_key_handler', num);

    if (stb.cur_place == 'radio') {
        return;
    }

    if (this.is_tv) {
        this.show_quick_ch_switch(num)
    } else {
        this.change_pos_by_numbers(num)
    }
};

player.prototype.change_pos_by_numbers = function (num) {
    _debug('player.change_pos_by_numbers', num);

    this.processing_pos_input();

    this.pos_by_numbers_input += num.toString();

    if (this.pos_by_numbers_input.length > 6) {
        this.pos_by_numbers_input = this.pos_by_numbers_input.substr(this.pos_by_numbers_input.length - 6, 6);
    }

    this.pos_by_numbers_input = '' + parseInt(this.pos_by_numbers_input, 10);

    //var new_pos_time = this.human_time_to_sec(this.pos_by_numbers_input);
    var new_pos_time = this.number_to_sec(this.pos_by_numbers_input);
    this.set_pos_button(new_pos_time);
    this.update_current_time(this.pos_by_numbers_input);
};

player.prototype.pos_by_numbers_back_key_handler = function () {
    _debug('player.pos_by_numbers_back_key_handler');

    this.processing_pos_input();

    this.pos_by_numbers_input = this.pos_by_numbers_input.substr(0, this.pos_by_numbers_input.length - 1);

    //var new_pos_time = this.human_time_to_sec(this.pos_by_numbers_input);
    var new_pos_time = this.number_to_sec(this.pos_by_numbers_input);
    this.set_pos_button(new_pos_time);
    this.update_current_time(this.pos_by_numbers_input);
};

player.prototype.processing_pos_input = function () {
    _debug('player.processing_pos_input');

    if (!this.info.on) {
        this.show_info();
    }

    if (this.pos_by_numbers_input == '') {
        this.update_current_time(0);

        this.info.cur_pos_time.setAttribute('active', 'active');
    }

    window.clearTimeout(this.info.hide_timeout);

    var self = this;

    this.info.hide_timeout = window.setTimeout(function () {
        self.set_pos_and_play();
    }, 4000);

    if (module.tv_archive && this.cur_media_item.mark_archive) {
        window.clearTimeout(this.archive_continue_dialog_to);
    }

    if (!this.pause.on) {
        try {
            stb.Pause();
        } catch (e) {
            _debug(e);
        }
        this.pause.on = true;
    }
};

player.prototype.reset_pos_by_numbers = function () {
    _debug('player.reset_pos_by_numbers');

    this.pos_by_numbers_input = '';
    this.info.cur_pos_time.setAttribute('active', '');
};

player.prototype.save_fav_ids = function () {
    _debug('player.save_fav');

    stb.load(
        {
            'type': 'itv',
            'action': 'set_fav',
            'fav_ch': this.fav_channels_ids.join(',')
        },

        function (result) {
            _debug('fav_saved', result);

            //stb.load_fav_channels();

            stb.load(
                {
                    'type': 'itv',
                    'action': 'get_all_fav_channels',
                    'fav': 1
                },

                function (result) {
                    _debug('get_all_fav_channels result', result);

                    stb.player.fav_channels = result.data || [];
                },

                this
            )
        },

        this
    )
};

player.prototype.get_file_type = function (item) {

    if (item.is_audio) {
        return 'audio';
    }

    var cmd = '';

    if (typeof (item) == 'object') {
        cmd = item.cmd;
    } else {
        cmd = item;
    }

    var p = /^(.*)\.(\S+)$/

    var ext = ['mp3', 'ac3', 'wav', 'flac', 'ogg'];

    var type = 'video';

    if (ext.indexOf(cmd.replace(p, "$2")) != -1) {
        type = 'audio';
    }

    _debug('file_type', type);

    return type;
};

player.prototype.set_pos_button_to_cur_time = function () {
    _debug('player.set_pos_button_to_cur_time');

    try {

        _debug('this.active_time_shift', this.active_time_shift);

        if (this.active_time_shift && module.time_shift) {

            //this.cur_media_length = module.time_shift.get_cur_media_length();
            this.cur_media_length = module.time_shift.get_cur_media_length();
            this.cur_pos_time = module.time_shift.get_pos_time();
            _debug('this.cur_media_length', this.cur_media_length);
            _debug('this.cur_pos_time', this.cur_pos_time);

        } else if (this.active_local_time_shift) {

            /*var now = new Date();
            this.cur_media_length = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
            this.cur_pos_time = this.cur_media_length - (stb.GetMediaLen() - stb.GetPosTime());*/

            this.cur_media_length = stb.GetMediaLen();
            this.cur_pos_time = stb.GetPosTime();

        } else if (this.emulate_media_len && module.tv_archive) {
            /*var global_pos_time = stb.GetPosTime();

            this.cur_pos_time = global_pos_time - (this.cur_media_item.position ? this.cur_media_item.position : 0);

            _debug('global_pos_time', global_pos_time);
            _debug('this.cur_media_item.position', this.cur_media_item.position);
            _debug('this.cur_pos_time 1', this.cur_pos_time);

            if (module.tv_archive){
                this.cur_pos_time += module.tv_archive.get_file_piece_num() * 3600;
            }*/

            this.cur_pos_time = module.tv_archive.get_pos_time();

            _debug('this.cur_pos_time 1', this.cur_pos_time);

        } else {
            this.cur_pos_time = stb.GetPosTime();
        }

        _debug('this.cur_pos_time', this.cur_pos_time);

        this.set_pos_button(this.cur_pos_time);
    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_pos_button = function (to_time) {
    _debug('player.set_pos_button', to_time);

    //this.new_pos_time = to_time;

    /*if (this.new_pos_time < 0){
        if (this.active_time_shift){ 
            this.cur_media_length = 86400;
            //this.cur_pos_time = 86400 + to_time;
            //
            //to_time =  86400;
        }else{
            this.new_pos_time = 0;
        }
    }*/

    var real_to_time = to_time;

    try {

        if (this.active_time_shift) {

            _debug('this.cur_media_length 1', this.cur_media_length);

            if (to_time > this.cur_media_length) {

                /*if (module.time_shift.is_last_archive_day()){
                    to_time = this.cur_media_length;
                }else{
                    to_time = 0;
                    module.time_shift.cur_piece_date.setDate(module.time_shift.cur_piece_date.getDate()-1);
                }*/
                //this.cur_pos_time = to_time = 0;

                if (!module.time_shift.is_last_archive_day()) {
                    this.cur_pos_time = to_time = 0;
                    //_debug('++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++++!date + 1');
                    module.time_shift.cur_piece_date.setDate(module.time_shift.cur_piece_date.getDate() + 1);
                    this.cur_media_length = module.time_shift.get_cur_media_length();

                    //this.diff_pos -= 86400;
                    this.diff_pos = 0;
                } else {
                    to_time = this.cur_media_length;
                }

            } else if (to_time < 0) {

                _debug('this.cur_pos_time 1', this.cur_pos_time);

                if (module.time_shift.can_reduce_day()) {

                    _debug('set previous day');
                    this.cur_pos_time = to_time = 86400;
                    this.cur_media_length = 86400;

                    this.diff_pos = 0;
                    //_debug('------------------------------------------------------------------------------------------------------------------------! date - 1');
                    module.time_shift.cur_piece_date.setDate(module.time_shift.cur_piece_date.getDate() - 1);

                } else {
                    to_time = 0;
                }
            } else {
                if (!module.time_shift.in_archive(to_time) && this.diff_pos) {
                    _debug('this.diff_pos', this.diff_pos);
                    //_debug('!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!');
                    this.cur_pos_time = to_time = module.time_shift.get_first_piece_position();
                    this.diff_pos = 0;
                    //return;
                }
            }

            _debug('this.cur_media_length', this.cur_media_length);
            _debug('this.new_pos_time', this.new_pos_time);
            _debug('this.diff_pos', this.diff_pos);
            _debug('this.prev_to_time', this.prev_to_time);
            _debug('this.cur_pos_time', this.cur_pos_time);
            _debug('to_time', to_time);

            this.new_pos_time = to_time;
            this.info.pos_series.innerHTML = module.time_shift.get_current_date();

            var program_name = module.time_shift.get_program_name_by_pos(to_time);

            _debug('program_name', program_name);

            if (program_name) {
                this.info.title.innerHTML = this.cur_media_item.number + '. ' + this.cur_media_item.name + ' — ' + program_name;
                _debug('this.info.title.innerHTML', this.info.title.innerHTML);
            }

        } else {
            if (to_time > this.cur_media_length) {
                to_time = this.cur_media_length;
            } else if (to_time < 0) {
                to_time = 0;
            }
        }

        this.prev_to_time = real_to_time;

        /*if (to_time > this.cur_media_length){
            to_time = this.cur_media_length;
        }else if (to_time < 0){
            to_time = 0;
        }*/

        this.new_pos_time = to_time;

        this.update_current_time(to_time, true);

        var to_pos = 0;

        if (this.cur_media_length != 0) {
            //to_pos = Math.round(590*to_time/this.cur_media_length);
            _debug('this.info.pos_bar.offsetWidth', this.info.pos_bar.offsetWidth);
            _debug('this.info.pos_bar.clientWidth', this.info.pos_bar.clientWidth);
            _debug('this.info.pos_bar.isHidden()', this.info.pos_bar.isHidden());
            _debug('this.info.pos_bar.style.display', this.info.pos_bar.style.display);
            to_pos = Math.round((this.info.pos_bar.offsetWidth - (this.info.pos_button.offsetWidth + 12)) * to_time / this.cur_media_length);
        }

        _debug('to_pos', to_pos);

        this.info.pos_button.moveX(to_pos);
    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_pos_and_play = function (reset, do_not_hide_info) {
    _debug('set_pos_and_play', reset, do_not_hide_info);

    _debug('this.info.on', this.info.on);

    if (!this.info.on) {
        return;
    }

    window.clearTimeout(this.info.hide_timeout);

    try {
        if (!reset) {

            if (this.active_time_shift && module.time_shift) {

                _debug('module.time_shift.in_process', module.time_shift.in_process);

                if (module.time_shift.in_process) {

                    var self = this;

                    this.info.hide_timeout = window.setTimeout(function () {
                        self.set_pos_and_play();
                    }, 500);

                    _debug('return set_pos_and_play');
                    return;
                }

                var new_url = module.time_shift.get_url_by_pos(this.new_pos_time);
                _debug('new_url', new_url);

                module.time_shift.update_media_item(new_url);

                this.play(module.time_shift.cur_media_item);

                if (!do_not_hide_info) {
                    this.hide_info();
                }

                this.pos_step = 10;
                this.diff_pos = 0;
                this.next_step = 0;
                this.pause.on = false;

                return;
            } else if (this.emulate_media_len && module.tv_archive) {

                /*var new_pos_time = this.new_pos_time + parseInt(this.cur_media_item.position, 10);

                if (module.tv_archive){
                    new_pos_time -= module.tv_archive.get_file_piece_num() * 3600;
                }

                _debug('new_pos_time', new_pos_time);*/

                new_url = module.tv_archive.get_part_by_pos(this.new_pos_time);
                _debug('new_url', new_url);

                if (new_url) {
                    this.cur_media_item.cmd = new_url;
                    delete this.cur_media_item.open;
                    this.need_show_info = 0;
                    this.play(this.cur_media_item);
                } else {

                    //var new_pos_time = this.new_pos_time + parseInt(this.cur_media_item.position, 10);
                    var new_pos_time = this.new_pos_time + module.tv_archive.get_start_position() - module.tv_archive.get_file_piece_num() * 3600;

                    _debug('this.new_pos_time', this.new_pos_time);
                    _debug('new_pos_time', new_pos_time);

                    stb.SetPosTime(new_pos_time);
                }
            } else {
                _debug('stb.SetPosTime', this.new_pos_time);
                stb.SetPosTime(this.new_pos_time);
            }
        } else {
            if (this.active_time_shift) {
                //module.time_shift.cur_media_item = module.time_shift.stored_media_item.clone();
                _debug('module.time_shift.cur_media_item.live_date.getTime()', module.time_shift.cur_media_item.live_date.getTime());
                module.time_shift.cur_piece_date = new Date(module.time_shift.cur_media_item.live_date);
            }
        }
    } catch (e) {
        _debug(e);
    }

    this.disable_pause();
    //this.info.dom_obj.hide();
    //this.info.on = false;
    if (!do_not_hide_info) {
        this.hide_info();
    }

    this.pos_step = 10;
    this.diff_pos = 0;
    this.next_step = 0;
};

player.prototype.move_pos = function (dir) {
    _debug('player.move_pos', dir);

    _debug('this.info.on', this.info.on);
    _debug('this.is_tv', this.is_tv);

    this.reset_pos_by_numbers();

    /*if (this.is_tv){
        return;
    }*/

    if (this.is_tv && this.prev_layer.on) {
        return;
    }

    if (this.is_tv && (!module.time_shift || !parseInt(this.cur_media_item.enable_tv_archive, 10)) && (!module.time_shift_local || !module.time_shift_local.enabled || !parseInt(this.cur_media_item.allow_local_timeshift, 10) || (this.cur_media_item.cmd.indexOf('rtp ') == -1 && this.cur_media_item.cmd.indexOf('ffrt ') == -1))
    ) {
        return;
    }

    if (this.is_tv && parseInt(this.cur_media_item.enable_tv_archive, 10) && module.time_shift && !this.prev_layer.on) {

        if (dir > 0) {
            return;
        }

        module.time_shift.set_media_item(this.cur_tv_item);
        module.time_shift.get_link_for_channel();
        this.is_tv = false;
        this.active_time_shift = true;
        this.cur_pos_time = module.time_shift.get_pos_time();
        this.cur_media_length = module.time_shift.get_cur_media_length();
        _debug('this.cur_media_length', this.cur_media_length);
        _debug('this.cur_pos_time', this.cur_pos_time);
    } else if (this.is_tv && parseInt(this.cur_media_item.allow_local_timeshift, 10) && module.time_shift_local && module.time_shift_local.enabled && !this.prev_layer.on) {

        module.time_shift_local.enable_mode();

        this.is_tv = false;
        this.active_local_time_shift = true;
        this.cur_pos_time = stb.GetPosTime();
        this.cur_media_length = stb.GetMediaLen();
    }

    if (!this.info.on) {
        this.show_info();
    }

    window.clearTimeout(this.info.hide_timeout);

    var self = this;

    this.info.hide_timeout = window.setTimeout(function () {
        self.set_pos_and_play();
    }, 4000);

    if (!this.pause.on) {

        if (module.tv_archive && this.cur_media_item.mark_archive) {
            window.clearTimeout(this.archive_continue_dialog_to);
        }

        try {
            stb.Pause();
        } catch (e) {
            _debug(e);
        }
        this.pause.on = true;
    }

    if (!this.info.on) {
        this.show_info();
    }

    var media_len = stb.GetMediaLen();

    _debug('media_len', media_len);
    _debug('this.last_state', this.last_state);
    _debug('this.active_time_shift', this.active_time_shift);

    if ((media_len <= 0 || this.last_state != 4) && !this.active_time_shift) {
        return;
    }

    if (this.prev_move_pos_dir != dir) {
        this.pos_step = 10;
        this.next_step = 0;
    } else {
        if (this.active_time_shift) {
            if (this.pos_step >= 3600) {
                this.pos_step = 3600;
                this.next_step = 0;
            } else {

                _debug('this.pos_step 1', this.pos_step);

                if (this.pos_step == 10) {
                    this.pos_step = 30;
                } else {
                    this.pos_step = this.pos_step * 2;
                }
                //this.pos_step = this.pos_step * 2;
                this.next_step = 0;
            }
        }
    }

    _debug('this.pos_step', this.pos_step);

    this.prev_move_pos_dir = dir;

    _debug('this.next_step', this.next_step);

    if ((this.diff_pos + this.next_step + this.cur_pos_time) > this.cur_media_length) {
        this.diff_pos = this.cur_media_length - this.cur_pos_time;
    } else if ((this.diff_pos + this.next_step + this.cur_pos_time) < 0) {
        this.diff_pos = -this.cur_pos_time;
    } else {
        this.next_step += this.pos_step;
    }

    if (dir > 0) {
        this.diff_pos += this.next_step;
    } else {
        this.diff_pos -= this.next_step;
    }

    _debug('this.diff_pos', this.diff_pos);

    var new_pos_time = this.cur_pos_time + this.diff_pos;

    _debug('new_pos_time', new_pos_time);

    try {
        this.set_pos_button(new_pos_time);
    } catch (e) {
        _debug(e);
    }
};

player.prototype.update_current_time = function (cur_time, strict) {
    _debug('player.update_current_time', cur_time);

    //this.info.pos_time.innerHTML = this.sec_to_human_time(cur_time) + '/' + this.sec_to_human_time(this.cur_media_length);
    //this.info.cur_pos_time.innerHTML   = this.sec_to_human_time(cur_time);
    if (strict) {
        this.info.cur_pos_time.innerHTML = this.sec_to_human_time(cur_time);
    } else {
        this.info.cur_pos_time.innerHTML = this.pos_format_str(cur_time);
    }
    this.info.total_pos_time.innerHTML = this.sec_to_human_time(this.cur_media_length);
};

player.prototype.sec_to_human_time = function (seconds) {
    var hh = Math.floor(seconds / 3600);
    var mm = Math.floor((seconds - 3600 * hh) / 60);
    var ss = seconds - hh * 3600 - mm * 60;

    if (hh < 10) {
        hh = '0' + hh;
    }

    if (mm < 10) {
        mm = '0' + mm;
    }

    if (ss < 10) {
        ss = '0' + ss;
    }

    return hh + ':' + mm + ':' + ss;
};

player.prototype.pos_format_str = function (number) {

    number = '' + number;

    var zeros = 6 - number.length;

    var zeros_str = '';

    for (var i = 0; i < zeros; i++) {
        zeros_str += '0';
    }

    number = zeros_str + number;

    return number.substr(0, 2) + ':' + number.substr(2, 2) + ':' + number.substr(4, 2);
};

player.prototype.number_to_sec = function (number) {

    var hh = 0;
    var mm = 0;
    var ss = 0;

    number = '' + number;

    if (number.length > 4) {
        hh = number.substr(0, number.length - 4);
        number = number.substring(number.length - 4);
        hh = parseInt(hh, 10);
        if (hh > 99) {
            hh = 99;
        }
    }

    if (number.length > 2) {
        mm = number.substr(0, number.length - 2);
        number = number.substring(number.length - 2);
        mm = parseInt(mm, 10);
        if (mm > 59) {
            mm = 59;
            ss = 59;
        }
    }

    if (number.length > 0 && !ss) {
        ss = parseInt(number, 10);
        if (ss > 59) {
            ss = 59;
        }
    }

    return hh * 3600 + mm * 60 + ss;
};

player.prototype.human_time_to_sec = function (time) {
    _debug('player.human_time_to_sec', time);

    if (!time) {
        return 0;
    }

    return time.toString().split('').reverse().reduce(function (prev, cur, i, arr) {

        cur = parseInt(cur, 10);

        if ((i % 2)) {
            cur = cur * 10
        }

        cur = cur * Math.pow(60, Math.floor(i / 2));

        return parseInt(prev, 10) + cur;
    })
};

player.prototype.init_quick_ch_switch = function () {
    _debug('player.init_quick_ch_switch');

    this.quick_ch_switch.dom_obj = create_block_element('quick_ch_switch');

    this.quick_ch_switch.input = create_block_element('quick_ch_input', this.quick_ch_switch.dom_obj);

    this.quick_ch_switch.dom_obj.hide();
};

player.prototype.show_quick_ch_switch = function (num) {
    _debug('player.show_quick_ch_switch');

    if (!this.is_tv) {
        return;
    }

    if (!this.quick_ch_switch.on) {
        this.quick_ch_switch.dom_obj.show();
        this.quick_ch_switch.on = true;
    }

    if (this.quick_ch_switch.input.innerHTML.length < 5) {
        if (this.quick_ch_switch.input.innerHTML.length == 0 && num == 0) {

        } else {
            this.quick_ch_switch.input.innerHTML = this.quick_ch_switch.input.innerHTML + '' + num;
        }
    }

    this.t_hide_quick_ch_switch();
};

player.prototype.quick_go_to_ch = function () {
    _debug('player.quick_go_to_ch');

    var ch_num = parseInt(this.quick_ch_switch.input.innerHTML);

    _debug('ch_num', ch_num);

    var item = {};

    _debug('stb.profile[hide_tv_genres_in_fullscreen]', stb.profile['hide_tv_genres_in_fullscreen']);

    if (stb.user.fav_itv_on) {

        this.f_ch_idx = this.fav_channels.getIdxByVal('number', ch_num);

        _debug('this.f_ch_idx', this.f_ch_idx);

        if (this.f_ch_idx >= 0) {

        } else {
            this.f_ch_idx = 0;
        }

        item = this.fav_channels[this.f_ch_idx];

        if (stb.profile['hide_tv_genres_in_fullscreen'] && module.tv.genre && module.tv.genre.id != stb.profile['hide_tv_genres_in_fullscreen'][0] && item.tv_genre_id == stb.profile['hide_tv_genres_in_fullscreen'][0]) {
            _debug('reset item');
            item = null;
        }

        _debug('item', item);

    } else {

        this.ch_idx = this.channels.getIdxByVal('number', ch_num);

        _debug('this.ch_idx', this.ch_idx);

        if (this.ch_idx >= 0) {

        } else {
            this.ch_idx = 0;
        }

        item = this.channels[this.ch_idx];


        if (stb.profile['hide_tv_genres_in_fullscreen'] && module.tv.genre && module.tv.genre.id != stb.profile['hide_tv_genres_in_fullscreen'][0] && item && item.tv_genre_id == stb.profile['hide_tv_genres_in_fullscreen'][0]) {
            _debug('reset item');
            item = null;
        }

        _debug('item', item);
    }

    if (!empty(item)) {
        stb.player.need_show_info = 1;
        stb.player.play(item);
    }
};

player.prototype.del_quick_go_ch = function () {
    _debug('player.del_quick_go_ch');

    if (!this.quick_ch_switch.on) {
        return;
    }

    this.t_hide_quick_ch_switch();

    this.quick_ch_switch.input.innerHTML = this.quick_ch_switch.input.innerHTML.substr(0, this.quick_ch_switch.input.innerHTML.length - 1);

    //this.quick_ch_switch.input.innerHTML = ch_num;
};

player.prototype.t_hide_quick_ch_switch = function () {
    _debug('player.t_hide_quick_ch_switch');

    window.clearTimeout(this.quick_ch_switch.hide_timer);

    var self = this;

    this.quick_ch_switch.hide_timer = window.setTimeout(function () {

        self.hide_quick_ch_switch();

    }, this.quick_ch_switch.hide_to);
};

player.prototype.hide_quick_ch_switch = function () {
    _debug('player.hide_quick_ch_switch');

    if (!this.quick_ch_switch.on) {
        return;
    }

    this.quick_go_to_ch();

    this.quick_ch_switch.dom_obj.hide();
    this.quick_ch_switch.on = false;

    this.quick_ch_switch.input.innerHTML = '';
};

player.prototype.cancel_quick_ch_switch = function () {
    _debug('player.cancel_quick_ch_switch');

    window.clearTimeout(this.quick_ch_switch.hide_timer);

    this.quick_ch_switch.dom_obj.hide();
    this.quick_ch_switch.on = false;

    this.quick_ch_switch.input.innerHTML = '';
};

player.prototype.change_aspect = function () {
    _debug('player.change_aspect');

    if (module.tv && module.tv.on && module.tv.cur_view == 'short') {
        return;
    }

    if (!this.aspect_info_container.isHidden()) {
        if (stb.aspect_idx < stb.aspect_array.length - 1) {
            stb.aspect_idx++;
        } else {
            stb.aspect_idx = 0;
        }
    }

    _debug('set aspect', stb.aspect_array[stb.aspect_idx].alias);

    this.show_aspect_info(get_word('aspect_' + stb.aspect_array[stb.aspect_idx].alias).toUpperCase());

    stb.SetAspect(stb.aspect_array[stb.aspect_idx].mode);

    stb.load(
        {
            "type": "stb",
            "action": "set_aspect",
            "aspect": stb.aspect_array[stb.aspect_idx].mode
        },

        function (result) {

        },

        this
    )
};

player.prototype.change_tv_channel_aspect = function () {
    _debug('player.change_tv_channel_aspect');

    if (module.tv && module.tv.on && module.tv.cur_view == 'short') {
        return;
    }

    _debug('this.ch_aspect[this.cur_media_item.id]', this.ch_aspect[this.cur_media_item.id]);

    if (this.ch_aspect[this.cur_media_item.id] === undefined) {
        this.ch_aspect[this.cur_media_item.id] = stb.aspect_array[this.ch_aspect_idx].mode;
    }

    if (!this.aspect_info_container.isHidden()) {
        if (this.ch_aspect_idx < stb.aspect_array.length - 1) {
            this.ch_aspect_idx++;
        } else {
            this.ch_aspect_idx = 0;
        }
    }

    this.ch_aspect[this.cur_media_item.id] = stb.aspect_array[this.ch_aspect_idx].mode;

    _debug('set aspect', stb.aspect_array[this.ch_aspect_idx].alias);

    this.show_aspect_info(get_word('aspect_' + stb.aspect_array[this.ch_aspect_idx].alias).toUpperCase());

    stb.SetAspect(stb.aspect_array[this.ch_aspect_idx].mode);

    stb.load(
        {
            "type": "stb",
            "action": "set_aspect",
            "aspect": stb.aspect_array[this.ch_aspect_idx].mode,
            "ch_id": this.is_tv ? this.cur_media_item.id : 0
        },

        function (result) {

        },

        this
    )
};

player.prototype.init_aspect_info = function () {
    _debug('player.init_aspect_info');

    this.aspect_info_container = create_block_element('aspect_info_container');
    this.aspect_info_txt = create_block_element('aspect_block', this.aspect_info_container);

    create_block_element('aspect_block_right', this.aspect_info_container);

    this.aspect_info_container.hide();
};

player.prototype.show_aspect_info = function (text) {
    _debug('player.show_aspect_info');

    window.clearTimeout(this.aspect_info_timer);

    this.aspect_info_txt.innerHTML = text;
    this.aspect_info_container.show();

    var self = this;

    this.aspect_info_timer = window.setTimeout(function () {
        self.aspect_info_container.hide();
    }, 3000);
};

player.prototype.hist_back = function () {

    this.need_show_info = 1;

    var item;

    if (stb.user.fav_itv_on) {
        item = this.hist_f_ch_idx[0];
    } else {
        item = this.hist_ch_idx[0];
    }

    if (!empty(item)) {
        this.play(item);
    }
};

player.prototype.get_pids = function () {
    _debug('player.get_pids');

    try {
        this.audio_pid.get_all();

        _debug('test');
        _debug('typeof this.subtitle_pid', typeof (this.subtitle_pid));
        _debug('typeof this.subtitle_pid.get_all', typeof (this.subtitle_pid.get_all));

        this.subtitle_pid.get_all();

        this.build_con_menu();
    } catch (e) {
        _debug(e);
    }
};

player.prototype.set_media_aspect = function () {
    _debug('player.set_media_aspect');

    _debug('this.cur_media_item.mark_archive', this.cur_media_item.mark_archive);
    _debug('this.active_time_shift', this.active_time_shift);
    _debug('this.active_local_time_shift', this.active_local_time_shift);

    if (this.is_tv || module.tv_archive && this.cur_media_item.mark_archive || this.active_time_shift || this.active_local_time_shift) {

        var ch_id = this.is_tv || this.active_time_shift || this.active_local_time_shift ? this.cur_media_item.id : this.cur_media_item.ch_id;

        _debug('ch_id', ch_id);

        _debug('this.ch_aspect[ch_id]', this.ch_aspect[ch_id]);

        if (this.ch_aspect[ch_id] !== undefined) {

            try {
                stb.SetAspect(parseInt(this.ch_aspect[ch_id], 10));
            } catch (e) {
                _debug(e);
            }

            this.ch_aspect_idx = stb.aspect_array.getIdxByVal('mode', this.ch_aspect[ch_id]);

            _debug('aspect_alias', stb.aspect_array[this.ch_aspect_idx].alias);
        } else {

            _debug('this.profile[tv_channel_default_aspect]', stb.profile['tv_channel_default_aspect']);

            var cur_aspect = stb.profile['tv_channel_default_aspect'];

            this.ch_aspect_idx = stb.aspect_array.getIdxByVal('mode', cur_aspect);

            _debug('aspect_alias', stb.aspect_array[this.ch_aspect_idx].alias);

            try {
                stb.SetAspect(cur_aspect);
            } catch (e) {
                _debug(e);
            }
        }

        _debug('this.ch_aspect_idx', this.ch_aspect_idx);
    } else {
        _debug('aspect_alias', stb.aspect_array[stb.aspect_idx].alias);
        try {
            stb.SetAspect(stb.aspect_array[stb.aspect_idx].mode);
        } catch (e) {
            _debug(e);
        }
    }
};

player.prototype.audio_pid = {

    all_pids: [],
    cur_pid: 0,
    cur_pid_idx: 0,

    get_all: function () {
        _debug('audio_pid.get_all');

        if (stb.GetAudioPIDsEx) {
            var audio_pids = stb.GetAudioPIDsEx();
        } else {
            audio_pids = stb.GetAudioPIDs();
        }

        _debug('audio_pids str', audio_pids);

        audio_pids = eval('(' + audio_pids + ')');

        _debug('audio_pids obj', audio_pids);

        this.cur_pid = stb.GetAudioPID();

        _debug('cur_pid', this.cur_pid);

        this.cur_pid_idx = audio_pids.getIdxByVal('pid', this.cur_pid);

        if (this.cur_pid_idx !== null) {
            audio_pids[this.cur_pid_idx].selected = true;
        }

        this.all_pids = audio_pids;
    },

    set: function (pid) {
        _debug('audio_pid.set', pid);

        this.all_pids[this.cur_pid_idx].selected = false;
        this.cur_pid = pid;
        this.cur_pid_idx = this.all_pids.getIdxByVal('pid', this.cur_pid);

        stb.SetAudioPID(pid);

        _debug('stb.player.is_tv', stb.player.is_tv);

        if (stb.player.is_tv) {

            stb.player.cur_media_item.atrack = pid;

            var idx = stb.player.channels.getIdxByVal('id', stb.player.cur_media_item.id);

            _debug('channels idx', idx);

            if (idx !== null) {
                stb.player.channels[idx].atrack = pid;
            }

            idx = stb.player.fav_channels.getIdxByVal('id', stb.player.cur_media_item.id);

            _debug('fav_channel idx', idx);

            if (idx !== null) {
                stb.player.fav_channels[idx].atrack = pid;
            }

            idx = module.tv.data_items.getIdxByVal('id', stb.player.cur_media_item.id);

            _debug('data_items idx', idx);

            if (idx !== null) {
                module.tv.data_items[idx].atrack = pid;
            }
        }
    },

    get_for_menu: function () {
        _debug('audio_pid.get_for_menu');

        var lang;
        var title;
        var map = [];

        for (var i = 0; i < this.all_pids.length; i++) {

            if (this.all_pids[i].lang.length > 1 && this.all_pids[i].lang[1] != '') {
                lang = ' - ' + this.all_pids[i].lang[1];
            } else if (this.all_pids[i].lang[0] != '') {
                lang = ' - ' + this.all_pids[i].lang[0];
            } else {
                lang = '';
            }

            title = get_word('player_track') + ' ' + (i + 1) + lang + (this.all_pids[i].hasOwnProperty('type') && stb.player.atrack_types.hasOwnProperty(this.all_pids[i].type) ? ' [' + stb.player.atrack_types[this.all_pids[i].type] + ']' : '');

            map.push({
                "title": title, "cmd": (function (pid) {
                    return function () {
                        stb.player.audio_pid.set(pid)
                    }
                })(this.all_pids[i].pid), "active": !!this.all_pids[i].selected
            });
        }

        _debug('map', map);

        return map;
    }
};

player.prototype.subtitle_pid = {

    all_pids: [],
    cur_pid: 0,
    cur_pid_idx: 0,
    enabled: false,

    enable: function () {
        _debug('subtitle_pid.enable');
        stb.SetSubtitles(true);
        this.enabled = true;
    },

    disable: function () {
        _debug('subtitle_pid.disable');
        stb.SetSubtitles(false);
        this.enabled = false;
    },

    get_all: function () {
        _debug('subtitle_pid.get_all');

        _debug('stb.profile[always_enabled_subtitles]', stb.profile['always_enabled_subtitles']);

        if (stb.profile['always_enabled_subtitles'] || stb.player.cur_media_item.cmd.indexOf(' strack:') != -1) {
            this.enable();
        } else {
            this.disable();
        }

        var subtitle_pids = stb.GetSubtitlePIDs();

        _debug('subtitle_pids str', subtitle_pids);

        subtitle_pids = eval('(' + subtitle_pids + ')');

        this.cur_pid = stb.GetSubtitlePID();

        _debug('cur_pid', this.cur_pid);

        this.cur_pid_idx = subtitle_pids.getIdxByVal('pid', this.cur_pid);

        _debug('this.cur_pid_idx', this.cur_pid_idx);

        if (this.cur_pid_idx !== null && this.enabled) {
            subtitle_pids[this.cur_pid_idx].selected = true;
        }

        if (stb.player.cur_media_item.hasOwnProperty('subtitles')) {
            subtitle_pids = subtitle_pids.concat(stb.player.cur_media_item.subtitles)
        }

        _debug('subtitle_pids', subtitle_pids);

        this.all_pids = subtitle_pids;

        _debug('stb.user[pri_subtitle_lang]', stb.user['pri_subtitle_lang']);
        _debug('stb.user[sec_subtitle_lang]', stb.user['sec_subtitle_lang']);

        if (stb.profile['always_enabled_subtitles'] && (stb.user['pri_subtitle_lang'] || stb.user['sec_subtitle_lang'])) {
            var picked_subs = this.all_pids.filter(function (item) {
                return item.hasOwnProperty('lang')
                    && Array.isArray(item.lang)
                    && (
                        stb.user['pri_subtitle_lang'] && item.lang.indexOf(stb.user['pri_subtitle_lang']) != -1
                        || stb.user['sec_subtitle_lang'] && item.lang.indexOf(stb.user['sec_subtitle_lang']) != -1
                        || stb.user['pri_subtitle_lang'] && item.lang.indexOf(stb.user['pri_subtitle_lang'].slice(0, 2)) != -1
                        || stb.user['sec_subtitle_lang'] && item.lang.indexOf(stb.user['sec_subtitle_lang'].slice(0, 2)) != -1
                    )
            });

            _debug('picked_subs', picked_subs);

            var picked_embedded_subs = picked_subs.filter(function (item) {
                return !item.hasOwnProperty('file');
            });

            var all_embedded_subs = this.all_pids.filter(function (item) {
                return !item.hasOwnProperty('file');
            });

            _debug('picked_embedded_subs', picked_embedded_subs);

            var picked_subtitle = null;

            if (picked_subs.length > 0 && picked_embedded_subs.length == 0) {
                picked_subtitle = picked_subs[0];
            } else if (all_embedded_subs.length == 0 && this.all_pids.length > 0) {
                picked_subtitle = this.all_pids[0];
            }

            _debug('picked_subtitle', picked_subtitle);

            if (picked_subtitle) {

                this._set_current_pid(picked_subtitle.pid);

                if (picked_subtitle.hasOwnProperty('file')) {
                    stb.SetSubtitlesEncoding('utf-8'); // UTF as default
                    stb.LoadExternalSubtitles(picked_subtitle.file);
                }
            }
        }
    },

    _set_current_pid: function (pid) {
        _debug('subtitle_pid._set_current_pid', pid);

        this.enable();

        this.cur_pid_idx = this.all_pids.getIdxByVal('pid', pid);

        _debug('this.cur_pid_idx', this.cur_pid_idx);
        _debug('this.all_pids', this.all_pids);

        this.all_pids[this.cur_pid_idx].selected = true;
        this.cur_pid = pid;
        this.cur_pid_idx = this.all_pids.getIdxByVal('pid', this.cur_pid);
    },

    set: function (pid) {
        _debug('subtitle_pid.set', pid);

        var cur_pid_idx = this.all_pids.getIdxByVal('pid', pid);

        if (this.all_pids[cur_pid_idx].hasOwnProperty('file')) {

            var self = this;

            stb.player.init_subtitle_encoding_select();

            stb.player.subtitle_encoding_select.on_confirm = function (encoding) {
                _debug('on_confirm', encoding);
                self._set_current_pid(pid);
                stb.SetSubtitlesEncoding(encoding);
                stb.LoadExternalSubtitles(self.all_pids[cur_pid_idx].file);
            };

            stb.player.subtitle_encoding_select.show();

        } else {
            this._set_current_pid(pid);
            stb.SetSubtitlePID(pid);
        }
    },

    get_for_menu: function () {
        _debug('subtitle_pid.get_for_menu');

        var lang;
        var title;
        var map = [];

        var sub_off = this.all_pids.some(function (pid_obj) {
            return pid_obj.selected;
        });

        _debug('sub_off', sub_off);

        var self = this;

        map.push({
            'title': get_word('player_off'), 'cmd': function () {
                self.disable()
            }, 'active': !sub_off
        });

        for (var i = 0; i < this.all_pids.length; i++) {

            if (this.all_pids[i].lang.length > 1 && this.all_pids[i].lang[1] != '') {
                lang = ' - ' + this.all_pids[i].lang[1];
            } else if (this.all_pids[i].lang[0] != '') {
                lang = ' - ' + this.all_pids[i].lang[0];
            } else {
                lang = '';
            }

            title = get_word('player_subtitle') + ' ' + (i + 1) + lang;

            map.push({
                'title': title, 'cmd': (function (pid) {
                    return function () {
                        stb.player.subtitle_pid.set(pid)
                    }
                })(this.all_pids[i].pid), 'active': this.all_pids[i].selected
            });
        }

        return map;
    }
};

player.prototype.init_con_menu = function () {
    _debug('player.init_con_menu');

    if (!this.con_menu) {
        this.con_menu = new context_menu();
        this.con_menu.bind();
        this.con_menu.set_x_offset(100);
        this.con_menu.set_y_offset(100);
    }

    var claim_cmd = [
        {
            "cmd": function () {
                stb.player.send_claim('sound')
            },
            "title": get_word('player_on_sound')
        },
        {
            "cmd": function () {
                stb.player.send_claim('video')
            },
            "title": get_word('player_on_video')
        }
    ];

    if (this.is_tv) {
        claim_cmd = claim_cmd.concat(
            [
                {
                    "cmd": function () {
                        stb.player.send_claim('no_epg')
                    },
                    "title": get_word('no epg')
                }
                ,
                {
                    "cmd": function () {
                        stb.player.send_claim('wrong_epg')
                    },
                    "title": get_word('wrong epg')
                }
            ]
        )
    }

    var map = [
        {
            "title": get_word('player_claim'),
            "cmd": claim_cmd
        }
    ];

    var ClockMap = {
        "title": get_word('videoClockTitle'),
        "cmd": [
            {
                "cmd": function () {
                    stb.player.ClockOnVideo.changeType('Off');
                },
                "title": get_word('videoClock_off'),
                "active": (stb.user.video_clock == 'Off')
            },
            {
                "cmd": function () {
                    stb.player.ClockOnVideo.changeType('upRight');
                },
                "title": get_word('videoClock_upRight'),
                "active": (stb.user.video_clock == 'upRight')
            },
            {
                "cmd": function () {
                    stb.player.ClockOnVideo.changeType('upLeft');
                },
                "title": get_word('videoClock_upLeft'),
                "active": (stb.user.video_clock == 'upLeft')
            },
            {
                "cmd": function () {
                    stb.player.ClockOnVideo.changeType('downRight');
                },
                "title": get_word('videoClock_downRight'),
                "active": (stb.user.video_clock == 'downRight')
            },
            {
                "cmd": function () {
                    stb.player.ClockOnVideo.changeType('downLeft');
                },
                "title": get_word('videoClock_downLeft'),
                "active": (stb.user.video_clock == 'downLeft')
            }

        ],
        "type": 'switch'
    };
    map = map.concat(ClockMap);


    this.con_menu.construct(map);
};

player.prototype.build_con_menu = function () {
    _debug('player.build_con_menu');

    if (this.con_menu.map.length > 2) {
        this.con_menu.map.splice(0, this.con_menu.map.length - 2);
    }

    if (stb && stb.Set3DConversionMode) {
        var mode = stb.Get3DConversionMode ? stb.Get3DConversionMode() : 0;
        this.con_menu.map.unshift(
            {
                "title": get_word('3D mode'),
                "type": "switch",
                "cmd": [
                    {
                        "title": get_word('mode {0}').format(1), "cmd": function () {
                            _debug('mode 1');
                            stb.Set3DConversionMode(0)
                        }, active: (mode == 0)
                    },
                    {
                        "title": get_word('mode {0}').format(2), "cmd": function () {
                            _debug('mode 2');
                            stb.Set3DConversionMode(1)
                        }, active: (mode == 1)
                    },
                    {
                        "title": get_word('mode {0}').format(3), "cmd": function () {
                            _debug('mode 3');
                            stb.Set3DConversionMode(2)
                        }, active: (mode == 2)
                    },
                    {
                        "title": get_word('mode {0}').format(4), "cmd": function () {
                            _debug('mode 4');
                            stb.Set3DConversionMode(3)
                        }, active: (mode == 3)
                    }
                ]
            }
        );
    }

    this.con_menu.map.unshift(
        {
            "title": get_word('player_subtitle'),
            "type": "switch",
            "cmd": this.subtitle_pid.get_for_menu()
        }
    );

    this.con_menu.map.unshift(
        {
            "title": get_word('player_audio'),
            "type": "switch",
            "cmd": this.audio_pid.get_for_menu()
        }
    );

    _debug('this.con_menu.map', this.con_menu.map);

    if (this.con_menu.on) {
        this.con_menu.hide();

        this.con_menu.construct(this.con_menu.map);
        this.con_menu.show();
    } else {

        this.con_menu.construct(this.con_menu.map);
    }
};

player.prototype.send_claim = function (type) {
    _debug('player.send_claim', type);

    if (this.is_tv) {
        this.itv_claim(type);
    } else if (stb.cur_place == 'vclub') {
        this.video_claim(type);
    } else if (stb.cur_place == 'karaoke') {
        this.karaoke_claim(type);
    }

    stb.notice.show(get_word('player_ty'));
};

player.prototype.video_claim = function (type) {
    _debug('player.video_claim', type);

    stb.load(
        {
            "type": "vod",
            "action": "set_claim",
            "id": this.cur_media_item.video_id || this.cur_media_item.id,
            "real_type": type
        },
        function (result) {

        }
    );
};

player.prototype.itv_claim = function (type) {
    _debug('player.itv_claim', type);

    stb.load(
        {
            "type": "itv",
            "action": "set_claim",
            "id": this.cur_media_item.id,
            "real_type": type
        },
        function (result) {

        }
    );
};

player.prototype.karaoke_claim = function (type) {
    _debug('player.karaoke_claim', type);

    stb.load(
        {
            "type": "karaoke",
            "action": "set_claim",
            "id": this.cur_media_item.id,
            "real_type": type
        },
        function (result) {

        }
    );
};

player.prototype.set_fav_status = function () {
    _debug('player.save_fav_status');

    stb.load(
        {
            "type": "itv",
            "action": "set_fav_status",
            "fav_itv_on": stb.user.fav_itv_on
        },
        function (result) {

        }
    );
};

var playback_limit = {

    start_counting: function () {
        _debug('playback_limit.start_counting');

        _debug('stb.user[playback_limit]', stb.user['playback_limit']);

        if (!stb.user['playback_limit']) {
            _debug('playback_limit not set');
            return;
        }

        window.clearTimeout(this.timer);

        var self = this;

        this.timer = window.setTimeout(function () {

            self.show_message.call(self);

        }, stb.user['playback_limit'] * 3600000);

    },

    show_message: function () {
        _debug('playback_limit.show_message');

        if (!stb.player.on) {
            return;
        }

        var self = this;

        this.stop_timer = window.setTimeout(function () {
            stb.msg.callback = function () {
            };
            stb.msg.hide();
            keydown_observer.emulate_key(key.MENU);
        }, 30000);

        stb.msg.push(
            {
                "msg": get_word('playback_limit_reached'),
                "callback": function () {
                    _debug('clear stop_timer');
                    window.clearTimeout(self.stop_timer);
                    playback_limit.start_counting();
                }
            });
    },

    reset: function () {
        _debug('playback_limit.reset');

        window.clearTimeout(this.timer);
    }
};

player.prototype.progress_bar = {

    load: 0,

    init: function () {
        _debug('progressBar.init');

        this.dom_obj = create_block_element('playback_progress_block');
        this.progress = create_block_element('playback_progress', this.dom_obj);
        this.dom_obj.hide();
    },

    start: function () {
        _debug('progressBar.start');

        this.dom_obj.show();

        var self = this;

        window.clearInterval(this.update_timer);
        window.clearTimeout(this.stop_timer);
        window.clearTimeout(this.hide_timeout);

        this.update_timer = window.setInterval(function () {
            self.update();
        }, 300);
    },

    update: function (load) {
        _debug('progressBar.update', load);

        if (!load) {
            load = stb.GetBufferLoad();
        }

        _debug('load 1', load);

        if (this.load > load) {
            load = 100;
        }

        _debug('load 2', load);

        this.set_progress(load);

        if (load == 100) {
            //var self = this;
            window.clearTimeout(this.stop_timer);
            window.clearInterval(this.update_timer);
            //this.stop_timer = window.setTimeout(function(){self.stop()}, 300);
            this.stop();
        }
    },

    set_progress: function (load) {
        _debug('set_progress', load);

        this.load = load;

        var max = this.dom_obj.offsetWidth - 4;

        var width = max / 100 * load;

        this.progress.style.width = width + 'px';
    },

    stop: function () {
        _debug('progressBar.stop');

        //this.dom_obj.hide();

        this.set_progress(100);
        var self = this;
        window.clearTimeout(this.hide_timeout);
        this.hide_timeout = window.setTimeout(function () {
            self.dom_obj.hide();
            self.progress.style.width = 0;
        }, 300);

        window.clearInterval(this.update_timer);
        this.load = 0;
        //this.progress.style.width = 0;
    }
};

//Clock On Video by Agnitumus
player.prototype.ClockOnVideo = {
    "on": false,
    "position": "Off",

    changeType: function (value, do_not_save) {

        if (!do_not_save) {
            this.position = value;
        }

        this.dom_obj.removeClass('onVideo' + stb.user.video_clock);

        stb.user.video_clock = value;

        _debug('player.change_ClockOnVideoType');
        _debug('set clock Type', stb.user.video_clock);

        if (!do_not_save) {
            stb.load(
                {
                    "type": "stb",
                    "action": "set_clock_on_video",
                    "clockType": stb.user.video_clock
                },

                function (result) {

                },

                this
            );
        }

        this.dom_obj.addClass('onVideo' + value);
        this.Refresh();
    },
    Refresh: function () {

        if (stb.user.video_clock == 'Off') {
            this.dom_obj.hide();
            this.on = false;
        } else {
            this.on = true;
            this.dom_obj.show();
        }
        stb.clock.show();
    },
    init: function () {
        _debug('player.init_ClockOnVideo');

        this.dom_obj = document.createElement('div');
        this.dom_obj.className = 'osd_clock_block';
        document.body.insertBefore(this.dom_obj, document.querySelector('.loader'));

        _debug('stb.user.video_clock', stb.user.video_clock);

        this.position = stb.user.video_clock = stb.user.video_clock || 'Off';

        this.osd_clock = create_block_element('osd_clock', this.dom_obj);
        this.dom_obj.addClass('onVideo' + stb.user.video_clock);
        this.Refresh();

        var self = this;

        stb.clock.addCustomEventListener("tick", function (date) {
            if (self.on && stb.player.on) {
                self.osd_clock.innerHTML = get_word('time_format').format(date.hours, date.minutes, date.ap_hours, date.ap_mark);
            }
        });
    },
    toggle: function () {
        _debug('ClockOnVideo.toggle');

        if (this.on) {
            this.changeType('Off', true);
        } else {
            if (this.position == 'Off') {
                this.position = 'upRight';
            }
            this.changeType(this.position, true);
        }
    }
};
//END Clock On Video


/*
 * END Player
 */
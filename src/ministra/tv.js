/**
 * TV module.
 */

(function () {

    /* TV */
    function tv_constructor() {

        this.layer_name = 'tv';

        this.row_blocks = ['number', 'logo', 'now_playing', 'fav', 'lock', 'scrambled', 'archive', 'pvr', 'local_time_shift', 'name', 'quality_high', 'quality_medium', 'quality_low', 'cur_playing'];

        this.load_params = {
            'type': 'itv',
            'action': 'get_ordered_list'
        };

        if (loader.template == "xc_vm") {
            this.preview_pos_map = [
                { "mode": 720, "xsize": 595, "ysize": 335, "x": 615, "y": 82 },
                { "mode": 1080, "xsize": 893, "ysize": 503, "x": 920, "y": 123 },
                { "mode": 3840, "xsize": 1785, "ysize": 1005, "x": 1845, "y": 246 }
            ];
        } else {
            this.preview_pos_map = [
                { "mode": 480, "xsize": 300, "ysize": 240, "x": 350, "y": 63 },
                { "mode": 576, "xsize": 320, "ysize": 256, "x": 350, "y": 74 },
                { "mode": 720, "xsize": 569, "ysize": 320, "x": 625, "y": 93 },
                { "mode": 1080, "xsize": 854, "ysize": 480, "x": 933, "y": 139 },
                { "mode": 3840, "xsize": 1708, "ysize": 960, "x": 1875, "y": 278 }
            ];
        }
        _debug('preview_pos_map', this.preview_pos_map);

        this.superclass = ListLayer.prototype;

        this.sort_menu = {};

        this.view_menu = {};
        //this.fav_menu  = {};
        this.filter_menu = {};
        this.genres = [];

        //this.last_ch_id = 0;

        this.quick_ch_switch = { "on": false, "hide_to": 3000 };

        this.row_callback_timer;
        this.row_callback_timeout = 500;

        this.fav_manage_mode = false;
        this.auto_play = true;

        this.password_input = new password_input({ "parent": this, "proceed_navigation": true });
        this.password_input.bind();

        this._show = function (genre) {

            _debug('tv._show', genre);

            _debug('this.auto_play', this.auto_play);

            genre = genre || this.genre || this.genres[0];

            if (!genre) {
                genre = { "id": "*", "title": get_word("all_title") };
            }

            this.load_params['genre'] = genre.id;
            this.load_params['force_ch_link_check'] = stb.user['force_ch_link_check'];

            this.genre = genre;

            this.update_header_path([{ "alias": "genre", "item": genre.title }]);

            this.sort_menu.action();

            this.show();
        };

        this.recalculate_preview_mode = function () {
            _debug('tv.recalculate_preview_mode');

            try {
                stb.video_mode = stb.RDir('vmode');
            } catch (e) {
                _debug(e)
            }

            _debug('stb.video_mode', stb.video_mode);

            _debug('parseInt(stb.video_mode)', parseInt(stb.video_mode));
            _debug('stb.graphic_mode', stb.graphic_mode);

            if (loader.template == "xc_vm") {
                this.preview_pos = this.preview_pos_map[this.preview_pos_map.getIdxByVal("mode", parseInt(stb.video_mode))];
            } else {
                if (stb.graphic_mode == 1080) {
                    this.preview_pos = this.preview_pos_map[this.preview_pos_map.getIdxByVal("mode", parseInt(stb.video_mode))];

                    this.preview_pos.xsize = this.preview_pos.xsize * 0.667;
                    this.preview_pos.ysize = this.preview_pos.ysize * 0.667;

                    this.preview_pos.x = 945;
                    this.preview_pos.y = 274;

                } else {
                    this.preview_pos = this.preview_pos_map[this.preview_pos_map.getIdxByVal("mode", parseInt(stb.video_mode))];
                }
            }

            if (stb.player.on && stb.player.is_tv && this.on == false) {
                _debug('stb.SetPIG');
                stb.SetPIG(1, -1, -1, -1);
            } else if (this.on && this.cur_view == 'short') {
                _debug('stb.SetViewport');
                stb.SetViewport(this.preview_pos.xsize, this.preview_pos.ysize, this.preview_pos.x, this.preview_pos.y)
            }
        };

        this.init = function () {
            this.superclass.init.call(this);

            if (stb.profile['tv_quality_filter']) {
                this.dom_obj.setAttribute("rel", "quality-filter");
            }

            if (stb.user['enable_buffering_indication']) {
                this.progress_bar.init(this);
            }

            this.init_aspects();

            this.recalculate_preview_mode();

            var scope = this;

            this.parent_password_promt = new ModalForm({
                "title": get_word('parent_password_title'),
                "parent": main_menu
            });
            this.parent_password_promt.enableOnExitClose();

            this.parent_password_promt.addItem(new ModalFormInput({
                "label": get_word('password_label'),
                "name": "parent_password",
                "type": "password",
                "onchange": function () {
                    _debug('change');
                    scope.parent_password_promt.resetStatus()
                }
            }));

            this.parent_password_promt.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.show_player();
                        var parent_password = scope.parent_password_promt.getItemByName('parent_password').getValue();

                        _debug('parent_password', parent_password);
                        _debug('stb.user.parent_password', stb.user.parent_password);

                        if (parent_password == stb.user.parent_password) {
                            scope.parent_password_promt.hide();
                            scope.parent_password_promt.callback && scope.parent_password_promt.callback();
                        } else {
                            scope.parent_password_promt.setStatus(get_word('parent_password_error'));
                        }
                    }
                }
            ));

            this.parent_password_promt.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.show_player();
                        scope.parent_password_promt.hide();
                    }
                }
            ));

            if (loader.template == "xc_vm") { this.init_search_box(); }
        };

        this.show = function (do_not_load) {
            _debug('tv.show', do_not_load);

            /*if(this.data_items && this.data_items[this.cur_row] && this.data_items[this.cur_row].id && stb.player.cur_media_item && this.data_items[this.cur_row].id == stb.player.cur_media_item.id){
                do_not_load = true;
            }else{
                do_not_load = false;
                if (this.genre && this.genre.hasOwnProperty('id')){
                    this.load_params['genre'] = this.genre.id;
                }
            }*/


            if (this.data_items && this.data_items[this.cur_row] && this.data_items[this.cur_row].id && stb.player.cur_media_item && this.data_items[this.cur_row].id == stb.player.cur_media_item.id) {
                do_not_load = true;
            } else {
                do_not_load = false;
            }

            _debug('do_not_load', do_not_load);

            if (!do_not_load && !this.do_not_load) {
                this.cur_page = 0;
            }

            if (this.do_not_load) {
                do_not_load = true;
            }

            _debug('this.cur_page', this.cur_page);

            this.superclass.show.call(this, do_not_load);

            stb.clock.show();

            try {
                _debug('tv.cur_view', this.cur_view);

                if (this.cur_view == 'short') {
                    stb.SetTopWin(1);
                    stb.SetViewport(this.preview_pos.xsize, this.preview_pos.ysize, this.preview_pos.x, this.preview_pos.y);

                    _debug('stb.player.on', stb.player.on);
                    _debug('stb.player.is_tv', stb.player.is_tv);

                    if (stb.player.on && stb.player.is_tv && stb.profile['plasma_saving'] === '1') {
                        this.start_tv_plasma_saving_count();
                    }

                } else {
                    stb.SetTopWin(0);
                }

                stb.EnableVKButton(false);
                stb.EnableServiceButton(false);
            } catch (e) {
                _debug(e);
            }
        };

        this.sortIDs = function (channels) {
            var i;
            for (i = 0; i < channels.length; i++) {
                channels[i]["number"] = (i + 1).toString();
            }
            return channels;
        };

        this.hide = function (do_not_reset) {
            _debug('tv.hide', do_not_reset);

            if (!do_not_reset) {
                this.cur_page = 0;
                this.genre = null;
                this.do_not_load = false;
            }

            this.short_epg_loader.stop();

            _debug('module.epg', typeof (module.epg));

            if (module.epg) {
                if (stb.player.pvr_target_select && stb.player.pvr_target_select.on) {
                    stb.player.pvr_target_select.hide();
                }
                if (stb.player.remote_pvr_confirm && stb.player.remote_pvr_confirm.on) {
                    stb.player.remote_pvr_confirm.hide();
                }
                if (stb.player.local_pvr_confirm && stb.player.local_pvr_confirm.on) {
                    stb.player.local_pvr_confirm.hide();
                }
                module.epg.hide();
            }

            try {

                if (this.fav_manage_mode) {
                    this.switch_fav_manage_mode();
                }

                this.sort_menu.on && this.sort_menu.hide && this.sort_menu.hide();
                this.view_menu.on && this.view_menu.hide && this.view_menu.hide();
                this.fav_menu.on && this.fav_menu.hide && this.fav_menu.hide();
                this.filter_menu.on && this.filter_menu.hide && this.filter_menu.hide();
                this.password_input.on && this.password_input.hide && this.password_input.hide();

                this.superclass.hide.call(this, do_not_reset);

                _debug('SetTopWin');

                if (this.quick_ch_switch.on) {
                    this.cancel_quick_ch_switch();
                }

                if (!do_not_reset) {

                    this.auto_play = stb.player.on;

                    stb.player.stop();
                    //this.last_ch_id = 0;
                }

                stb.SetTopWin(0);
                stb.SetPIG(1, -1, -1, -1);
                stb.EnableVKButton(true);
                stb.EnableServiceButton(!!stb.user['enable_service_button']);
            } catch (e) {
                _debug(e);
            }
        };

        this.init_aspects = function () {
            _debug('tv.init_aspects');

            stb.load(
                {
                    "type": "stb",
                    "action": "get_tv_aspects"
                },
                function (result) {
                    _debug('on get_tv_aspect', result);

                    if (result instanceof Array) {
                        result = {};
                    }

                    stb.player.ch_aspect = result || {};

                    _debug('stb.player.ch_aspect', stb.player.ch_aspect);
                }
            )
        };

        this.reset = function () {
            _debug('tv.reset');

            this.superclass.reset.apply(this);
            this.cur_page = 0;
        };

        this.clear_list = function () {
            _debug('tv.clear_list');

            this.superclass.clear_list.call(this);

            this.short_info_box.innerHTML = '';
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            //this.check_for_play.bind(key.OK, this);

            (function () {

                if (this.fav_manage_mode) {
                    this.switch_fav_manage_mode();
                } else if (this.quick_ch_switch.on) {
                    this.hide_quick_ch_switch();
                } else {
                    if (stb.profile['play_in_preview_only_by_ok'] && this.cur_view == 'short') {
                        if (this.data_items[this.cur_row].id != stb.player.cur_media_item.id || !stb.player.on) {
                            this.check_for_play_in_preview();
                        } else {
                            this.hide(true);
                        }
                    } else {
                        this.check_for_play();
                    }
                }

            }).bind(key.OK, this);

            (function () {

                if (single_module.indexOf(this.layer_name) != -1) {
                    return;
                }

                this.hide();
                main_menu.show();
            }).bind(key.MENU, this).bind(key.LEFT, this);

            (function () {
                if (module.epg_simple) {

                    _debug('this.data_items[this.cur_row]', this.data_items[this.cur_row]);

                    this.cur_item = stb.player.cur_media_item.clone();

                    _debug('this.cur_item', this.cur_item);

                    if (this.data_items[this.cur_row].lock == 1 && !this.data_items[this.cur_row].unlocked && this.genre.alias !== 'for adults') {
                        return;
                    }

                    _debug('stb.player.on', stb.player.on);

                    if (stb.player.on) {
                        this.auto_play = true;
                        stb.player.stop();
                    }

                    //module.epg.ch_id = this.data_items[this.cur_row].id;
                    //module.epg.show();

                    module.epg_simple.ch_id = this.data_items[this.cur_row].id;
                    module.epg_simple.ch_name = this.data_items[this.cur_row].name;
                    module.epg_simple.channel = this.data_items[this.cur_row];
                    this.hide(true);
                    module.epg_simple.show();
                }
            }).bind(key.RIGHT, this).bind(key.EPG, this);

            (function () {
                if (module.epg) {
                    if (stb.player.on) {
                        this.auto_play = true;
                        stb.player.stop();
                    }

                    module.epg.ch_id = this.data_items[this.cur_row].id;
                    this.hide(true);
                    module.epg.show();
                }
            }).bind(key.INFO, this);

            (function () {
                if (this.fav_manage_mode) {
                    this.switch_fav_manage_mode();
                } else if (this.quick_ch_switch.on) {
                    this.cancel_quick_ch_switch();
                } else {

                    if (single_module.indexOf(this.layer_name) != -1) {
                        if (window.self !== window.top) {
                            stb.player.stop();
                            // minimize
                            this.hide();
                            parent.postMessage('hide', '*');
                        } else if (typeof (stbWebWindow) != 'undefined' && windowId !== 1) {
                            stb.player.stop();
                            // minimize
                            this.hide();
                            stbWindowMgr.windowHide(windowId);
                        } else if (window.referrer) {
                            stb.player.stop();
                            window.location = window.referrer;
                        } else {
                            if (loader.template == "xc_vm") {
                                stb.player.stop();
                            } else {
                                this.check_for_play();
                            }
                        }

                        return;
                    }
                    if (loader.template == "xc_vm") {
                        stb.player.stop();
                    }

                    this.hide();
                    main_menu.show();
                }
            }).bind(key.EXIT, this);

            this.show_quick_ch_switch.bind(key.NUM1, this, 1);
            this.show_quick_ch_switch.bind(key.NUM2, this, 2);
            this.show_quick_ch_switch.bind(key.NUM3, this, 3);
            this.show_quick_ch_switch.bind(key.NUM4, this, 4);
            this.show_quick_ch_switch.bind(key.NUM5, this, 5);
            this.show_quick_ch_switch.bind(key.NUM6, this, 6);
            this.show_quick_ch_switch.bind(key.NUM7, this, 7);
            this.show_quick_ch_switch.bind(key.NUM8, this, 8);
            this.show_quick_ch_switch.bind(key.NUM9, this, 9);
            this.show_quick_ch_switch.bind(key.NUM0, this, 0);

            (function () {
                if (this.quick_ch_switch.on) {
                    this.del_quick_go_ch();
                } else if (stb.profile['play_in_preview_only_by_ok']) {
                    this.hide(true);
                    if (this.cur_view != 'short') {
                        main_menu.show();
                    }
                }
            }).bind(key.BACK, this);


            this.shift_row.bind(key.CHANNEL_PREV, this, -1);
            this.shift_row.bind(key.CHANNEL_NEXT, this, 1);

            this.add_to_censored_check.bind(key.APP, this);
        };

        this.add_to_censored_check = function () {
            _debug('tv.add_to_censored_check');

            var self = this;

            this.password_input.callback = function () {
                self.add_del_censored();
            };

            this.hide_player();
            this.password_input.show();
        };

        this.add_del_censored = function () {
            _debug('tv.add_del_censored');

            if (this.data_items[this.cur_row].lock == 1) {
                this.del_from_censored(this.data_items[this.cur_row].id);
            } else {
                this.add_to_censored(this.data_items[this.cur_row].id);
            }
        };

        this.add_to_censored = function (ch_id) {
            _debug('tv.add_to_censored', ch_id);

            var cur_item = this.data_items[this.cur_row];
            var cur_row = this.cur_row;

            var self = this;

            stb.load(
                {
                    "type": "itv",
                    "action": "add_to_censored",
                    "ch_id": ch_id
                },
                function (result) {
                    _debug('add_to_censored result', result);

                    if (result) {
                        cur_item.lock = 1;
                        self.map[cur_row].lock_block.show();

                        if (self.cur_row == cur_row) {
                            self.active_row.lock_block.show();
                        }

                        var idx = stb.player.channels.getIdxByVal("id", ch_id);
                        if (idx !== null) {
                            //stb.player.channels[idx].lock = 1;
                            stb.player.channels.splice(idx, 1);
                        }

                        stb.load_channels(this.genre);
                        stb.load_fav_channels(this.genre.id);
                    }
                },
                this
            );
        };

        this.del_from_censored = function (ch_id) {
            _debug('tv.del_from_censored', ch_id);

            var cur_item = this.data_items[this.cur_row];
            var cur_row = this.cur_row;

            var self = this;

            stb.load(
                {
                    "type": "itv",
                    "action": "del_from_censored",
                    "ch_id": ch_id
                },
                function (result) {
                    _debug('del_from_censored result', result);

                    if (result) {
                        cur_item.lock = 0;
                        /*cur_item.lock_block.hide();*/
                        self.map[cur_row].lock_block.hide();

                        if (self.cur_row == cur_row) {
                            self.active_row.lock_block.hide();
                        }

                        stb.load_channels(this.genre);
                        stb.load_fav_channels(this.genre.id);
                    }
                },
                this
            );
        };

        this.check_for_play = function () {
            _debug('tv.check_for_play');

            _debug('lock', this.data_items[this.cur_row].lock);

            if (!this.data_items[this.cur_row].open) {

                if (this.data_items[this.cur_row].hasOwnProperty('error') && this.data_items[this.cur_row].error) {
                    stb.notice.show(get_word('error_channel_' + this.data_items[this.cur_row].error));
                } else {
                    stb.notice.show(get_word('msg_channel_not_available'));
                }
                return;
            }

            if (this.data_items[this.cur_row].lock && !this.data_items[this.cur_row].unlocked && this.genre.alias != 'for adults') {
                var self = this;

                this.password_input.callback = function () {
                    self.data_items[self.cur_row].unlocked = true;
                    self.play();
                };

                this.hide_player();
                this.password_input.show();
            } else {
                this.play();
            }
        };

        this.play = function () {
            _debug('tv.play');

            _debug('this.data_items[this.cur_row]', this.data_items[this.cur_row]);
            _debug('empty(this.data_items[this.cur_row]', empty(this.data_items[this.cur_row]));

            if (empty(this.data_items[this.cur_row])) {
                return;
            }

            /*this.hide(true);
            
            stb.player.prev_layer = this;*/

            _debug('stb.player.on', stb.player.on);

            if (!stb.player.on) {
                /*if (this.cur_view == 'short'){
                    stb.player.need_show_info = 0;
                }else{
                    stb.player.need_show_info = 1;
                }*/

                //this.last_ch_id = this.data_items[this.cur_row].id;

                if (this.cur_view != 'short') {
                    this.hide(true);

                    stb.player.prev_layer = this;

                    stb.player.need_show_info = 1;
                    this._play_now(this.data_items[this.cur_row]);
                } else {
                    //this.show_info(this.cur_media_item);
                }
            } else {
                if (this.cur_view == 'short') {
                    if (this.data_items[this.cur_row].id !== stb.player.cur_media_item.id) {
                        this._play_now(this.data_items[this.cur_row]);
                    } else {
                        this.hide(true);

                        stb.player.prev_layer = this;
                    }
                }
            }
        };

        this.init_sort_menu = function (map, options) {
            this.sort_menu = new bottom_menu(this, options);
            this.sort_menu.init(map);
            this.sort_menu.bind();
        };

        this.sort_menu_switcher = function () {

            if (this.fav_manage_mode) {
                return;
            }

            if (this.sort_menu && this.sort_menu.on) {
                this.show_player();
                this.sort_menu.hide();
            } else {
                this.hide_player();
                this.sort_menu.show();
            }
        };

        this.init_view_menu = function (map, options) {
            this.view_menu = new bottom_menu(this, options);
            this.view_menu.init(map);
            this.view_menu.bind();
        };

        this.view_switcher = function () {

            if (this.fav_manage_mode) {
                return;
            }

            if (this.view_menu && this.view_menu.on) {
                this.show_player();
                this.view_menu.hide();
            } else {
                this.hide_player();
                this.view_menu.show();
            }
        };

        this.init_search_menu = function (map, options) {
            this.search_menu = new bottom_menu(this, options);
            this.search_menu.init(map);
            this.search_menu.bind();
        };

        this.search_menu_switcher = function () {
            if (this.search_box && this.search_box.on) {
                this.search_box.hide();
                this.show_player();
            } else {
                this.search_box.show();
                this.hide_player();
            }
        };

        this.hide_player = function () {
            if (loader.template == "xc_vm") {
                _debug("tv.hide_player");
                try {
                    stb.SetTopWin(0);
                    stb.SetPIG(1, -1, -1, -1);
                } catch (e) {
                    _debug(e);
                }
            }
        }

        this.show_player = function () {
            if (loader.template == "xc_vm") {
                _debug("tv.show_player");
                if (this.cur_view == 'short') {
                    try {
                        stb.SetTopWin(1);
                        stb.SetViewport(this.preview_pos.xsize, this.preview_pos.ysize, this.preview_pos.x, this.preview_pos.y);
                    } catch (e) {
                        _debug(e);
                    }
                }
            }
        }

        this.init_search_box = function () {
            _debug('tv.init_search_box');
            var scope = this;

            /* TV SEARCH DIALOG */
            this.search_box = new ModalForm({ "title": "SEARCH" });
            this.search_box.enableOnExitClose();
            this.search_box.addItem(new ModalFormInput(
                {
                    "name": "search_box_input",
                    "oninput": function () {
                        scope.tv_search();
                    }
                }
            ));
            this.search_box.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.show_player();
                        scope.search_box.reset();
                        scope.search_box.hide();
                    }
                }
            ));

            this.search_box.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.show_player();
                        scope.tv_search();
                        scope.search_box.hide();
                    }
                }
            ));

            this.search_box.addCustomEventListener('hide', function () {
                _debug('search_box_dialog', 'hide');
                if (typeof (stb.IsVirtualKeyboardActive) == 'function' && stb.IsVirtualKeyboardActive()) {
                    stb.HideVirtualKeyboard && stb.HideVirtualKeyboard();
                }
            });

            this.search_box.addCustomEventListener('show', function () {
                _debug('search_box_dialog', 'show');
                if (typeof (stb.IsVirtualKeyboardActive) == 'function' && !stb.IsVirtualKeyboardActive()) {
                    stb.ShowVirtualKeyboard && stb.ShowVirtualKeyboard();
                }
                scope.update_header_path([{ "alias": "search", "item": '' }]);
                scope.tv_search();
            });

            this.tv_search = function () {

                var search_str = scope.search_box.getItemByName("search_box_input").getValue();
                _debug('this.load_params.search', search_str);

                try {
                    if (scope.on && (search_str.length >= 3 || search_str.length == 0)) {
                        scope.load_params.search = search_str;
                        scope.update_header_path([{
                            "alias": "search",
                            "item": search_str.length >= 3 ? '"' + search_str + '"' : ''
                        }]);
                        scope.load_data();
                        scope.reset();
                    }
                } catch (e) {
                    _debug(e);
                }

            }
        };

        this.init_fav_menu = function (map, options) {
            this.fav_menu = new bottom_menu(this, options);
            this.fav_menu.init(map);
            this.fav_menu.bind();
        };

        this.fav_menu_switcher = function () {

            if (this.fav_menu && this.fav_menu.on) {
                this.fav_menu.hide();
            } else {
                this.fav_menu.show();
            }
        };

        this.init_filter_menu = function (map, options) {
            this.filter_menu = new bottom_menu(this, options);
            this.filter_menu.init(map);
            this.filter_menu.bind();
        };

        this.filter_switcher = function () {
            if (this.filter_menu && this.filter_menu.on) {
                this.filter_menu.hide();
            } else {
                this.filter_menu.show();
            }
        };

        this.set_wide_container = function () {

            if (this.cur_view == 'short') {
                stb.player.stop();
            }

            this.superclass.set_wide_container.apply(this);

            if (this.load_params.fav) {
                this.color_buttons.get('blue').enable();
                this.fav_menu && this.fav_menu.enable_by_name("fav_manage");
            }

            try {
                stb.SetTopWin(0);
                stb.SetPIG(1, -1, -1, -1);
            } catch (e) {
                _debug(e);
            }
        };

        this.set_short_container = function () {
            _debug('tv.set_short_container');

            this.superclass.set_short_container.apply(this);

            if (loader.template == "xc_vm") {
                this.color_buttons.get('blue').enable();
            } else {
                if (this.load_params.fav) {
                    this.color_buttons.get('blue').enable();
                } else if (!stb.profile['tv_quality_filter']) {
                    this.color_buttons && this.color_buttons.get('blue').disable();
                } else {
                    this.fav_menu && this.fav_menu.disable_by_name("fav_manage");
                }
            }

            try {
                _debug('this.preview_pos', this.preview_pos);
                stb.SetTopWin(1);
                stb.SetViewport(this.preview_pos.xsize, this.preview_pos.ysize, this.preview_pos.x, this.preview_pos.y);
            } catch (e) {
                _debug(e);
            }

            this.fill_short_info(this.data_items[this.cur_row]);

            _debug('this.loading', this.loading);

            if (this.data_items && this.data_items[this.cur_row] && !this.loading) {
                stb.player.need_show_info = 0;
                //this._play_now(this.data_items[this.cur_row]);
                this.set_active_row(this.cur_row);
            }

            // set active list w/ info item
            if (this.view_menu && this.view_menu.set_passive_row) {
                this.view_menu.set_passive_row();
                this.view_menu.cur_row_idx = 0;
                this.view_menu.set_active_row();
            }
        };

        this.init_short_info = function () {
            this.info_box = create_block_element('', this.main_container);

            this.short_info_box = create_block_element('tv_timetable', this.info_box);

            if (!stb.IsEmulator) {
                this.short_info_box.addClass('epg_mask');
            }

            this.preview_box = create_block_element('tv_prev_window', this.info_box);
            this.preview_msg = create_block_element('tv_prev_msg', this.preview_box);
            this.clock_box = create_block_element('tv_clock', this.info_box);
        };

        this.fill_list = function (data) {
            _debug('tv.fill_list');

            if (this.fav_manage_mode && !empty(this.cur_row_data)) {

                var idx = 0;

                if (this.page_dir < 0) {
                    idx = data.length - 1;
                }

                var cur_item_id = this.cur_row_data.id;
                var next_item_id = data[idx].id;

                var cur_row_fav_idx = stb.player.fav_channels_ids.indexOf(cur_item_id);
                var next_row_fav_idx = stb.player.fav_channels_ids.indexOf(next_item_id);

                stb.player.fav_channels_ids[cur_row_fav_idx] = next_item_id;
                stb.player.fav_channels_ids[next_row_fav_idx] = cur_item_id;

                this.cur_row_data.number = data[idx].number;

                data[idx] = this.cur_row_data;
            }

            this.load_params['from_ch_id'] = 0;

            data = this.sync_channels(data);

            this.superclass.fill_list.call(this, data);

            if (this.cur_view == 'short') {
                //this.fill_short_info(this.data_items[this.cur_row])
            }
        };

        this.sync_channels = function (data) {
            _debug('sync_channels');

            data = data.map(function (channel) {

                _debug('channel.id', channel.id);

                var idx = stb.player.channels.getIdxByVal('id', channel.id);

                if (idx !== null) {

                    if (stb.player.channels[idx].atrack) {
                        channel.atrack = stb.player.channels[idx].atrack
                    }

                    stb.player.channels[idx].open = channel.open;

                    var cmd_idx = channel.cmds.getIdxByVal('url', stb.player.channels[idx].cmd);

                    _debug('cmd_idx', cmd_idx);

                    if (!stb.player.channels[idx].manually_selected || cmd_idx === null) {
                        stb.player.channels[idx].cmd = channel.cmd;
                        stb.player.channels[idx].use_http_tmp_link = channel.use_http_tmp_link;
                        stb.player.channels[idx].wowza_tmp_link = channel.wowza_tmp_link;
                        stb.player.channels[idx].nginx_secure_link = channel.nginx_secure_link;
                        stb.player.channels[idx].use_load_balancing = channel.use_load_balancing;

                        if (channel.hasOwnProperty('error')) {
                            stb.player.channels[idx].error = channel.error;
                        } else {
                            stb.player.channels[idx].error = null;
                        }

                    } else {
                        channel.cmd = stb.player.channels[idx].cmd;
                        channel.use_http_tmp_link = stb.player.channels[idx].use_http_tmp_link;
                        channel.wowza_tmp_link = stb.player.channels[idx].wowza_tmp_link;
                        channel.nginx_secure_link = stb.player.channels[idx].nginx_secure_link;
                        channel.use_load_balancing = stb.player.channels[idx].use_load_balancing;
                    }

                    stb.player.channels[idx].cmds = channel.cmds;

                    var fav_idx = stb.player.fav_channels.getIdxByVal('id', channel.id);

                    if (fav_idx !== null) {
                        var fav_num = stb.player.fav_channels[fav_idx].number;
                        stb.player.fav_channels[fav_idx] = stb.player.channels[idx].clone();
                        stb.player.fav_channels[fav_idx].number = fav_num;

                        if (stb.user.fav_itv_on) {
                            channel.number = fav_num;
                        }
                    }
                }

                if (stb.profile['play_in_preview_only_by_ok'] && channel.id == stb.player.cur_tv_item.id && stb.player.on) {
                    channel.now_playing = 1;
                }

                if (channel.archive == 1 && !module.time_shift) {
                    channel.archive = 0;
                }

                if (stb.profile.ts_enable_icon && module.time_shift_local && module.time_shift_local.enabled && channel.allow_local_timeshift == 1 && channel.archive != 1) {
                    channel.local_time_shift = 1;
                } else {
                    channel.local_time_shift = 0;
                }

                if (channel.pvr == 1 && (channel.allow_local_pvr == 0 || !module.pvr_local) && (channel.allow_pvr == 0 || !module.remote_pvr)) {
                    channel.pvr = 0;
                }

                return channel;
            });

            return data;
        };

        this.fill_short_info = function (item) {
            _debug('tv.fill_short_info');

            if (item && !item.open) {
                if (item.error) {
                    this.preview_msg.innerHTML = get_word('error_channel_' + item.error);
                } else {
                    this.preview_msg.innerHTML = word['msg_channel_not_available'];
                }
            }

            if (item && item.epg) {
                this.short_info_box.innerHTML = '';
                this.short_epg_loader.start();
            }
        };

        this.fill_short_epg = function (epg) {

            var class_name = '';
            var txt = '';

            for (var i = 0; i < epg.length; i++) {

                if (i == 0) {
                    class_name = 'current';
                } else {
                    class_name = '';
                }

                txt += '<div class="mark_memo" style="display:' + ((!!epg[i].mark_memo) ? 'block' : 'none') + '"></div>';
                txt += '<div class="dummy_mark_memo" style="display:' + ((!!epg[i].mark_memo) ? 'none' : 'block') + '"></div>';

                txt += '<span class="time">' + epg[i].t_time + ' - </span><span class="' + class_name + '">' + epg[i].name + '</span><br>';
            }

            this.short_info_box.innerHTML = txt;
        };

        this.shift_row_callback = function (item) {

            _debug('tv.shift_row_callback', item);

            if (empty(item)) {
                return;
            }

            _debug('this.data_items[this.cur_row].id', this.data_items[this.cur_row].id);
            _debug('stb.player.cur_media_item', stb.player.cur_media_item);
            _debug('stb.player.on', stb.player.on);

            window.clearTimeout(this.row_callback_timer);

            if (stb.player.cur_media_item && stb.player.cur_media_item.id && this.data_items[this.cur_row].id == stb.player.cur_media_item.id && stb.player.on) {
                this.auto_play = false;
                this.short_epg_loader.start();
                return;
            }

            this.preview_msg.innerHTML = '';

            //window.clearTimeout(this.row_callback_timer);

            var self = this;

            _debug('stb.profile[play_in_preview_only_by_ok]', stb.profile['play_in_preview_only_by_ok']);
            _debug('this.auto_play', this.auto_play);

            if (stb.profile['play_in_preview_only_by_ok'] && !this.auto_play) {
                this.short_info_box.innerHTML = '';
                this.short_epg_loader.start();
                return;
            }

            this.auto_play = false;

            _debug('before set timeout');
            this.row_callback_timer = window.setTimeout(function () {

                _debug('row_callback');
                if (!self.on) {
                    return;
                }

                //self.fill_short_info(item);

                /*if (item.open){
 
                    _debug('item.lock', item.lock);
 
                    if (item.lock && self.genre.alias != 'for adults'){
                        self.password_input.callback = function(){
                            stb.player.need_show_info = 0;
                            stb.player.prev_layer = self;
                            self.data_items[self.cur_row].unlocked = true;
                            self.fill_short_info(item);
                            self._play_now(item);
                        };
 
                        self.password_input.show();
                    }else{
                        self.fill_short_info(item);
                        stb.player.need_show_info = 0;
                        stb.player.prev_layer = self;
                        self._play_now(item);
                    }
                }else{
                    self.fill_short_info(item);
                    stb.player.stop();
                }*/

                if (!self.do_not_load) {
                    self.check_for_play_in_preview();
                } else {
                    self.check_for_play_in_preview(self.cur_item || null);
                }
                self.do_not_load = false;

            },
                this.row_callback_timeout);

            _debug('this.row_callback_timeout', this.row_callback_timeout);
            _debug('after set timeout');
        };

        this.check_for_play_in_preview = function (item) {
            _debug('tv.check_for_play_in_preview', item);

            item = item || this.data_items[this.cur_row];

            _debug('item', item);

            var self = this;

            if (item.open) {

                _debug('item.lock', item.lock);

                if (item.lock && self.genre.alias != 'for adults') {
                    self.password_input.callback = function () {
                        stb.player.need_show_info = 0;
                        stb.player.prev_layer = self;
                        self.data_items[self.cur_row].unlocked = true;
                        self.fill_short_info(item);
                        self._play_now(item);
                    };
                    this.hide_player();
                    self.password_input.show();
                } else {
                    self.fill_short_info(item);
                    stb.player.need_show_info = 0;
                    stb.player.prev_layer = self;
                    self._play_now(item);
                }
            } else {
                self.fill_short_info(item);
                stb.player.stop();
            }
        };

        this.handle_advert = function (items, current_item) {
            _debug('tv.handle_advert', items, current_item);

            var preview = this.on && this.cur_view == 'short';

            _debug('preview', preview);

            var self = this;

            if (Array.isArray(items)) {

                var adverts = items;

                var callback = function () {
                    _debug('tv callback');

                    stb.key_lock = false;

                    var item = current_item.clone();
                    item.disable_ad = true;

                    self._play_now(item);
                };

                for (var i = 0; i < adverts.length; i++) {

                    var advert = adverts[i];

                    if (i != adverts.length - 1) {

                        callback = (function (ad, cb) {

                            return function () {
                                _debug('ad callback', ad);

                                if (!preview) {
                                    self.hide(true);

                                    stb.player.prev_layer = self;
                                }

                                stb.key_lock = true;

                                stb.player.need_show_info = 0;

                                stb.player.play({
                                    'id': ad.id,
                                    'ad_id': ad.id,
                                    'cmd': ad.cmd,
                                    'media_type': ad.media_type || '',
                                    'is_advert': true,
                                    'ad_tracking': ad.ad_tracking || {},
                                    'ad_must_watch': ad.ad_must_watch || 0,
                                    'stop_callback': cb
                                });

                                stb.player.ad_indication.show();
                            }

                        })(advert, callback);
                    }
                }

                if (!preview) {
                    self.hide(true);

                    stb.player.prev_layer = self;
                }

                stb.key_lock = true;

                stb.player.need_show_info = 0;

                stb.player.play({
                    'id': adverts[0].id,
                    'ad_id': adverts[0].id,
                    'cmd': adverts[0].cmd,
                    'media_type': adverts[0].media_type || '',
                    'is_advert': true,
                    'ad_tracking': adverts[0].ad_tracking || {},
                    'ad_must_watch': adverts[0].ad_must_watch || 0,
                    'stop_callback': callback

                });

                stb.player.ad_indication.show();

            } else {
                stb.player.play_now(items);
            }

        };

        this._play_now = function (item) {
            _debug('tv._play_now', item);

            if (stb.player.cur_media_item && stb.player.cur_media_item.id && item.id == stb.player.cur_media_item.id && stb.player.on) {
                return;
            }

            if (parseInt(item.use_http_tmp_link) == 1 || parseInt(item.use_load_balancing) == 1 || stb.user['force_ch_link_check']) {

                stb.player.on_create_link = function (result) {
                    _debug('tv.on_create_link', result);

                    if (result.error == 'limit') {
                        stb.notice.show(word['player_limit_notice']);
                    } else if (result.error == 'nothing_to_play') {
                        stb.notice.show(word['player_file_missing']);
                    } else if (result.error == 'link_fault') {
                        stb.notice.show(word['player_server_error']);
                    } else {
                        this.handle_advert(result, item);
                    }
                }
            }

            if (stb.profile['play_in_preview_only_by_ok']) {
                this.show_now_playing(item.id);
            }

            stb.player.play(item);
        };

        this.show_now_playing = function (ch_id) {
            _debug('tv.show_now_playing', ch_id);

            var self = this;

            var playing_idx = -1;

            this.map.map(function (item, idx) {

                if (!self.data_items[idx]) {
                    return;
                }

                if (self.data_items[idx].id != ch_id) {
                    self.data_items[idx].now_playing = 0;
                    item.now_playing_block.hide();
                } else {
                    playing_idx = idx;
                    self.data_items[idx].now_playing = 1;
                    item.now_playing_block.show();
                }
            });

            if (playing_idx >= 0 && playing_idx == this.cur_row) {
                this.active_row.now_playing_block.show();
            } else {
                this.active_row.now_playing_block.hide();
            }
        };

        this.hide_now_playing = function () {
            _debug('tv.hide_now_playing');

            this.map.map(function (item, idx) {
                item.now_playing_block.hide();
            })
        };

        this.add_to_fav = function () {
            _debug('tv.add_to_fav');

            _debug('this.player.fav_channels before', stb.player.fav_channels_ids);

            stb.player.fav_channels_ids.push(this.data_items[this.cur_row].id);

            _debug('this.player.fav_channels after', stb.player.fav_channels_ids);

            this.data_items[this.cur_row].fav = 1;

            this.map[this.cur_row].fav_block.show();
            this.active_row.fav_block.show();

            this.data_items[this.cur_row].number = stb.player.fav_channels.length + 1;

            stb.player.fav_channels.push(this.data_items[this.cur_row]);

            stb.player.save_fav_ids();
        };

        this.del_from_fav = function () {
            _debug('tv.del_from_fav');

            _debug('this.player.fav_channels before', stb.player.fav_channels_ids);

            var fav_idx = stb.player.fav_channels_ids.indexOf(this.data_items[this.cur_row].id);

            var removed_idx = stb.player.fav_channels_ids.splice(fav_idx, 1);

            _debug('removed_idx', removed_idx);

            _debug('this.player.fav_channels after', stb.player.fav_channels_ids);

            this.data_items[this.cur_row].fav = 0;

            this.map[this.cur_row].fav_block.hide();
            this.active_row.fav_block.hide();

            var fav_ch_idx = stb.player.fav_channels.getIdxByVal('id', this.data_items[this.cur_row].id);

            if (fav_ch_idx !== null) {
                stb.player.fav_channels.splice(fav_ch_idx, 1);
            }

            stb.player.save_fav_ids();
        };

        this.add_del_fav = function () {
            _debug('tv.add_del_fav');

            if (this.fav_manage_mode) {
                return;
            }

            //if (this.load_params.fav == true){
            //return;
            //}

            if (this.data_items[this.cur_row].fav) {
                this.del_from_fav();
            } else {
                this.add_to_fav();
            }
        };

        this.set_active_row = function (num) {
            _debug('tv.set_active_row');

            this.short_epg_loader.stop();

            this.superclass.set_active_row.call(this, num);

            //this.handling_block(this.data_items[num].number, this.active_row, 'number');
        };

        this.shift_row = function (dir) {

            if (this.fav_manage_mode) {
                var cur_data_items = this.data_items.clone();
                //var cur_row_data = this.data_items[this.cur_row].clone();
                var cur_row_data = this.data_items[this.cur_row];
                var cur_row_num = this.cur_row;
                var cur_number = cur_row_data.number;
                var cur_item_id = cur_row_data.id;

                var cur_row_fav_idx = stb.player.fav_channels_ids.indexOf(cur_item_id);

                _debug('stb.player.fav_channels_ids before', stb.player.fav_channels_ids);

            }

            this.data_items[this.cur_row].unlocked = false;

            this.superclass.shift_row.call(this, dir);

            if (this.fav_manage_mode) {

                var next_row_data = this.data_items[this.cur_row];
                var next_number = next_row_data.number;
                var next_item_id = this.data_items[this.cur_row].id;

                _debug('cur_number', cur_number);

                _debug('next_row_data.number before', next_row_data.number);

                next_row_data.number = cur_number;
                cur_row_data.number = next_number;

                _debug('next_row_data.number after', next_row_data.number);

                var next_row_fav_idx = stb.player.fav_channels_ids.indexOf(next_item_id);

                stb.player.fav_channels_ids[cur_row_fav_idx] = next_item_id;
                stb.player.fav_channels_ids[next_row_fav_idx] = cur_item_id;

                this.data_items[this.cur_row] = cur_row_data;
                this.data_items[cur_row_num] = next_row_data;

                if (this.data_items[this.cur_row].hasOwnProperty('open') && !this.data_items[this.cur_row].open) {
                    this.map[this.cur_row]['row'].addClass('close');
                    this.active_row['row'].addClass('close');
                } else {
                    this.map[this.cur_row]['row'].removeClass('close');
                    this.active_row['row'].removeClass('close');
                }

                if (this.data_items[cur_row_num].hasOwnProperty('open') && !this.data_items[cur_row_num].open) {
                    this.map[cur_row_num]['row'].addClass('close');
                } else {
                    this.map[cur_row_num]['row'].removeClass('close');
                }

                this.active_row['number_block'].innerHTML = next_number;

                _debug('stb.player.fav_channels_ids after', stb.player.fav_channels_ids);

                for (var j = 0; j < this.row_blocks.length; j++) {
                    this.handling_block(cur_row_data[this.row_blocks[j]], this.map[this.cur_row], this.row_blocks[j]);

                    this.handling_block(next_row_data[this.row_blocks[j]], this.map[cur_row_num], this.row_blocks[j]);
                }
            }
        };

        this.handling_block = function (data, row_items, block_name) {
            this.superclass.handling_block.call(this, data, row_items, block_name);

            var block_obj = row_items[block_name + '_block'];

            if (stb.profile['show_channel_logo_in_preview']) {

                if (block_name == 'logo') {
                    if (data) {
                        if (block_obj.isHidden()) {
                            block_obj.show();
                        }

                        block_obj.innerHTML = '<img src="' + data + '">';
                    } else {
                        if (!block_obj.isHidden()) {
                            block_obj.hide();
                        }

                        block_obj.innerHTML = '';
                    }
                }
            } else {
                if (block_name == 'logo') {
                    if (!block_obj.isHidden()) {
                        block_obj.hide();
                    }
                }
            }
        };

        this.shift_page = function (dir) {

            if (this.fav_manage_mode) {
                stb.player.save_fav_ids();

                this.cur_row_data = this.data_items[this.cur_row];
            }

            this.superclass.shift_page.call(this, dir);
        };

        this.switch_fav_manage_mode = function () {
            _debug('tv.switch_fav_manage_mode');

            if (this.load_params.fav != true) {
                return;
            }

            _debug('typeof(tv.fav_manage_mode)', typeof (this.fav_manage_mode));
            _debug('tv.fav_manage_mode before', this.fav_manage_mode);

            if (this.fav_manage_mode) {
                stb.player.save_fav_ids();
                this.active_row['row'].setClass('active_row_bg');

                if (this.data_items[this.cur_row].hasOwnProperty('open') && !this.data_items[this.cur_row].open) {
                    this.active_row['row'].addClass('close');
                }

                this.color_buttons.get('red').enable();
                this.color_buttons.get('green').enable();
                this.color_buttons.get('yellow').enable();

                if (stb.profile['tv_quality_filter']) {
                    this.color_buttons.get('blue').enable();
                }

            } else {
                this.active_row['row'].setClass('moved_active_row_bg');

                if (this.data_items[this.cur_row].hasOwnProperty('open') && !this.data_items[this.cur_row].open) {
                    this.active_row['row'].addClass('close');
                }

                this.color_buttons.get('red').disable();
                this.color_buttons.get('green').disable();
                this.color_buttons.get('yellow').disable();
                this.color_buttons.get('blue').disable();
            }

            this.fav_manage_mode = !this.fav_manage_mode;

            _debug('tv.fav_manage_mode after', this.fav_manage_mode);
        };

        this.init_quick_ch_switch = function () {
            _debug('tv.init_quick_ch_switch');

            this.quick_ch_switch.dom_obj = create_block_element('quick_ch_switch tv_preview_quick_ch_switch');

            this.quick_ch_switch.input = create_block_element('quick_ch_input', this.quick_ch_switch.dom_obj);

            if (['MAG351', 'MAG256', 'MAG257'].indexOf(stb.type) != -1) {
                this.quick_ch_switch.input.style.marginTop = '5px';
            }

            this.quick_ch_switch.dom_obj.hide();
        };

        this.show_quick_ch_switch = function (num) {
            _debug('tv.show_quick_ch_switch');

            if (this.cur_view != 'short') {
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

        this.quick_go_to_ch = function () {
            _debug('tv.quick_go_to_ch');

            var ch_num = parseInt(this.quick_ch_switch.input.innerHTML);

            _debug('ch_num', ch_num);

            var item = {};

            if (stb.user.fav_itv_on) {

                stb.player.f_ch_idx = stb.player.fav_channels.getIdxByVal('number', ch_num);

                _debug('stb.player.f_ch_idx', stb.player.f_ch_idx);

                if (stb.player.f_ch_idx >= 0) {

                } else {
                    stb.player.f_ch_idx = 0;
                }

                _debug('stb.player.f_ch_idx', stb.player.f_ch_idx);

                item = stb.player.fav_channels[stb.player.f_ch_idx];

                _debug('item', item);

            } else {

                stb.player.ch_idx = stb.player.channels.getIdxByVal('number', ch_num);

                _debug('stb.player.ch_idx', stb.player.ch_idx);

                if (stb.player.ch_idx >= 0) {

                } else {
                    stb.player.ch_idx = 0;
                }

                _debug('stb.player.ch_idx', stb.player.ch_idx);

                item = stb.player.channels[stb.player.ch_idx];

                _debug('item', item);
            }

            if (!empty(item)) {
                if (this.cur_view == 'short') {

                    var self = this;

                    stb.player.send_last_tv_id_callback = function () {
                        self.load_data.apply(self)
                    };
                    stb.player.need_show_info = 0;
                    this._play_now(item);
                } else {
                    stb.player.send_last_tv_id(item.id);
                }


                this.cur_page = 0;
                //this.load_data();
            }
        };

        this.del_quick_go_ch = function () {
            _debug('tv.del_quick_go_ch');

            if (!this.quick_ch_switch.on) {
                return;
            }

            this.t_hide_quick_ch_switch();

            this.quick_ch_switch.input.innerHTML = this.quick_ch_switch.input.innerHTML.substr(0, this.quick_ch_switch.input.innerHTML.length - 1);

            //this.quick_ch_switch.input.innerHTML = ch_hum;
        };

        this.t_hide_quick_ch_switch = function () {
            _debug('tv.t_hide_quick_ch_switch');

            window.clearTimeout(this.quick_ch_switch.hide_timer);

            var self = this;

            this.quick_ch_switch.hide_timer = window.setTimeout(function () {

                self.hide_quick_ch_switch();

            }, this.quick_ch_switch.hide_to);
        };

        this.hide_quick_ch_switch = function () {
            _debug('tv.hide_quick_ch_switch');

            if (!this.quick_ch_switch.on) {
                return;
            }

            this.quick_go_to_ch();

            this.fill_short_info(this.data_items[this.cur_row]);

            this.cancel_quick_ch_switch();
        };

        this.cancel_quick_ch_switch = function () {
            _debug('tv.cancel_quick_ch_switch');

            window.clearTimeout(this.quick_ch_switch.hide_timer);

            this.quick_ch_switch.dom_obj.hide();
            this.quick_ch_switch.on = false;

            this.quick_ch_switch.input.innerHTML = '';
        };

        this.short_epg_loader = {

            parent: {},

            start: function () {
                _debug('tv.short_epg_loader.start');

                var self = this;

                this.load();

                window.clearInterval(this.timer);

                this.timer = window.setInterval(function () {
                    self.load()
                }, 5 * 60 * 1000);
            },

            stop: function () {
                _debug('tv.short_epg_loader.stop');

                window.clearInterval(this.timer);
            },

            load: function () {
                _debug('tv.short_epg_loader.load');

                if (this.parent && this.parent.data_items && this.parent.cur_row >= 0) {

                    if (this.parent.data_items[this.parent.cur_row].type == 'dvb') {
                        this.fill(stb.epg_loader.get_curr_and_next(this.parent.data_items[this.parent.cur_row].id, false, 5));
                    } else {

                        var epg = stb.epg_loader.get_curr_and_next(this.parent.data_items[this.parent.cur_row].id, null, 5);

                        _debug('epg', epg);

                        var ch_id = this.parent.data_items[this.parent.cur_row].id;

                        _debug('ch_id', ch_id);

                        if (Array.isArray(epg) && epg.length < 5) {

                            stb.load(
                                {
                                    "type": "itv",
                                    "action": "get_short_epg",
                                    "ch_id": ch_id,
                                    "size": 10
                                },

                                function (result) {
                                    _debug('tv.short_epg_loader.load callback', result);

                                    if (result && Array.isArray(result)) {

                                        stb.epg_loader.epg[ch_id] = epg = result;

                                        epg.splice(5);
                                    } else {
                                        epg = [];
                                    }

                                    this.fill(epg);

                                },

                                this
                            )
                        } else {
                            this.fill(epg);
                        }
                    }
                }
            },

            fill: function (data) {
                _debug('tv.short_epg_loader.fill', data);

                this.parent.fill_short_epg(data);
            }
        };

        this.progress_bar = {

            load: 0,

            init: function (parent) {
                _debug('progressBar.init');

                this.dom_obj = create_block_element('playback_progress_block', parent.dom_obj);
                this.progress = create_block_element('playback_progress', this.dom_obj);
                this.dom_obj.hide();

                var self = this;

                stb.player.addCustomEventListener('event_2', function () {
                    _debug('event_2');
                    self.start.call(self);
                });

                stb.player.addCustomEventListener('event_4', function () {
                    _debug('event_4');
                    self.stop.call(self);
                });
            },

            start: function () {
                _debug('progressBar.start');

                if (stb.player.cur_media_item.cmd.indexOf('rtp ') === 0) {
                    return;
                }

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
                    var self = this;
                    window.clearTimeout(this.stop_timer);
                    window.clearInterval(this.update_timer);
                    this.stop_timer = window.setTimeout(function () {
                        self.stop()
                    }, 300);
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

        this.start_tv_plasma_saving_count = function () {
            _debug('tv.start_tv_plasma_saving_count');

            window.clearTimeout(this.make_fillscreen_to);

            if (this.on && this.cur_view == 'short') {
                this.make_fillscreen_to = window.setTimeout(function () {

                    _debug('make_fillscreen_to fired');

                    if (module.tv.on && module.tv.cur_view == 'short') {
                        module.tv.hide(true);
                    }

                }, stb.profile['plasma_saving_timeout'] * 1000);
            }
        };

        this.stop_tv_plasma_saving_count = function () {
            _debug('tv.stop_tv_plasma_saving_count');

            window.clearTimeout(this.make_fillscreen_to);
        }
    }

    tv_constructor.prototype = new ListLayer();

    var tv = new tv_constructor();

    tv.bind();
    tv.init();

    tv.init_short_info();

    tv.init_quick_ch_switch();

    tv.short_epg_loader.parent = tv;

    //tv.set_wide_container();
    //tv.set_short_container();

    if (single_module.indexOf('tv') == -1) {
        tv.init_left_ear(word['ears_back']);
    }

    tv.init_right_ear(word['ears_tv_guide']);

    if (loader.template == "xc_vm") {
        var color_buttons_map = [
            { "label": word['tv_view'], "cmd": tv.view_switcher },
            { "label": word['tv_sort'], "cmd": tv.sort_menu_switcher },
            { "label": word['tv_favorite'], "cmd": tv.add_del_fav },
            { "label": word['radio_search'], "cmd": tv.search_menu_switcher }

        ];
    } else {
        var color_buttons_map = [
            { "label": word['tv_view'], "cmd": tv.view_switcher },
            { "label": word['tv_sort'], "cmd": tv.sort_menu_switcher },
            { "label": word['tv_favorite'], "cmd": tv.add_del_fav },
            { "label": get_word('tv_move'), "cmd": tv.switch_fav_manage_mode }
        ];
    }

    tv.init_color_buttons(color_buttons_map);

    var sort_menu_map = [
        {
            "label": word['tv_by_number'],
            "cmd": function () {
                this.parent.show_player();
                this.parent.load_params.fav = false;
                stb.user.fav_itv_on = 0;
                stb.player.set_fav_status();
                this.parent.load_params.sortby = 'number';
                this.parent.load_params.hd = 0;
                stb.player.channels = tv.sortIDs(stb.player.channels.sortBy('snumber'));
            }
        },
        {
            "label": word['tv_by_title'],
            "cmd": function () {
                this.parent.show_player();
                this.parent.load_params.fav = false;
                stb.user.fav_itv_on = 0;
                stb.player.set_fav_status();
                this.parent.load_params.sortby = 'name';
                this.parent.load_params.hd = 0;
                stb.player.channels = tv.sortIDs(stb.player.channels.sortBy('name'));
            }
        },
        {
            "label": word['tv_only_favorite'],
            "cmd": function () {
                this.parent.show_player();
                this.parent.load_params.sortby = 'fav';
                stb.user.fav_itv_on = 1;
                stb.player.set_fav_status();
                this.parent.load_params.fav = true;
                this.parent.load_params.hd = 0;

                this.parent.color_buttons.get('blue').enable();
                stb.player.channels = tv.sortIDs(stb.player.channels.sortBy('snumber'));
            }
        }
    ];

    if (stb.profile['show_tv_only_hd_filter_option']) {

        sort_menu_map.push(
            {
                "label": get_word('tv_only_hd'),
                "cmd": function () {
                    this.parent.show_player();
                    this.parent.load_params.fav = false;
                    stb.user.fav_itv_on = 0;
                    stb.player.set_fav_status();
                    this.parent.load_params.sortby = 'number';
                    this.parent.load_params.hd = 1;

                }
            }
        );
    }

    if (stb.profile['tv_quality_filter']) {
        var filter_menu_map = [
            {
                "label": get_word('tv_quality_low'),
                "type": "checkbox",
                "name": "tv_quality_low",
                "group": "tv_quality",
                "cmd": function () {
                    this.parent.load_params.quality = "low";
                }
            },
            {
                "label": get_word('tv_quality_medium'),
                "type": "checkbox",
                "name": "tv_quality_medium",
                "group": "tv_quality",
                "cmd": function () {
                    this.parent.load_params.quality = "medium";
                }
            },
            {
                "label": get_word('tv_quality_high'),
                "type": "checkbox",
                "name": "tv_quality_high",
                "group": "tv_quality",
                "cmd": function () {
                    this.parent.load_params.quality = "high";
                }
            }
        ];

        tv.init_filter_menu(
            filter_menu_map,
            {
                "color": "blue",
                "need_update_header": false
            }
        );
    }

    tv.init_sort_menu(
        sort_menu_map,
        {
            "offset_x": 217,
            "color": "green"
        }
    );

    tv.init_view_menu(
        [
            {
                "label": word['tv_list_w_info'], "cmd": function () {
                    this.parent.show_player();
                    this.parent.set_short_container()
                }
            },
            {
                "label": word['tv_list'], "cmd": function () {
                    this.parent.show_player();
                    this.parent.set_wide_container()
                }
            }
        ],
        {
            "offset_x": 27,
            "color": "red",
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );

    _debug('stb.user.fav_itv_on', stb.user.fav_itv_on);

    if (stb.user.fav_itv_on) {
        tv.sort_menu.set_passive_row();
        tv.sort_menu.cur_row_idx = 2;
        tv.sort_menu.set_active_row();
        tv.sort_menu.action();
    }

    _debug('stb.profile[tv_quality_filter]', stb.profile['tv_quality_filter']);
    _debug('stb.profile[tv_quality]', stb.profile['tv_quality']);

    if (stb.profile['tv_quality_filter']) {
        tv.filter_menu.check_by_name('tv_quality_' + stb.profile['tv_quality']);
    }

    tv.init_fav_menu(
        [
            {
                "label": get_word('tv_move'),
                "name": "fav_manage",
                "cmd": function () {
                    tv.switch_fav_manage_mode();
                }
            },
            {
                "label": get_word('tv_fav_add') + '/' + get_word('tv_fav_del'),
                "cmd": function () {
                    tv.add_del_fav();
                }
            }
        ],
        {
            "color": "yellow",
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );

    tv.view_menu.dependency = [tv.sort_menu, tv.fav_menu, tv.filter_menu];

    tv.sort_menu.dependency = [tv.view_menu, tv.fav_menu, tv.filter_menu];

    tv.fav_menu.dependency = [tv.view_menu, tv.sort_menu, tv.filter_menu];

    if (tv.filter_menu.hasOwnProperty('dependency')) {
        tv.filter_menu.dependency = [tv.view_menu, tv.sort_menu, tv.fav_menu];
    }

    tv.set_short_container();

    tv.init_header_path(word['tv_title']);

    tv.auto_play = true;

    module.tv = tv;

    /* END TV */

    /* Integrate tv in main menu */

    stb.load(
        {
            "type": "itv",
            "action": "get_genres"
        },
        function (result) {
            _debug('callback genres');

            var genres = result;

            module.tv.genres = genres;
            module.tv.genre = genres[0];

            var map = [];

            for (var i = 0; i < genres.length; i++) {
                map.push(
                    {
                        "title": genres[i].title,
                        "cmd": (function (genre) {
                            return function () {
                                _debug('genre.id', genre.id);

                                stb.load_fav_channels(genre.id);
                                stb.load_channels(genre);
                            }

                        })(genres[i])
                    }
                );
            }


            main_menu.add(word['tv_title'], map, 'mm_ico_tv.png', '', module.tv);

            loader.next();
        }
    );

    tv.hide();

})();
/**
 * Radio modile.
 */

(function () {

    /* Radio */
    function radio_constructor() {

        this.layer_name = 'radio';

        this.row_blocks = ['playing', 'paused', 'number', 'fav', 'name'];

        this.load_params = {
            'type': 'radio',
            'action': 'get_ordered_list'
        };

        this.map = [];

        this.superclass = ListLayer.prototype;

        this.row_callback_timer;
        this.row_callback_timeout = 500;

        this.init = function () {

            _debug('radio.init');

            this.superclass.init.call(this);

            var self = this;

            stb.player.addCustomEventListener("radiostart", function (item) {
                _debug('radio.radiostart', item);

                if (self.on && self.data_items[0].radio && item.radio) {

                    var cur_idx = stb.player.radio_idx || 0;

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();
                    }

                    _debug('item.cmd', item.cmd);

                    var idx = self.data_items.getIdxByVal("id", item.id);

                    if (idx == -1) {
                        return;
                    }

                    _debug('idx', idx);

                    self.data_items[idx].playing = 1;
                    self.map[idx].playing_block.show();

                    self.data_items[cur_idx].paused = 0;
                    self.map[cur_idx].paused_block.hide();

                    if (self.cur_row == idx) {
                        self.active_row.playing_block.show();
                        self.active_row.paused_block.hide();
                    }

                    stb.player.radio_idx = idx;

                    /*self.set_active_row(self.cur_row);*/
                }
            });

            stb.player.addCustomEventListener("radiostop", function (item) {
                _debug('radio.radiostop', item);

                if (self.on && self.data_items[0].radio && item.radio) {

                    var cur_idx = stb.player.radio_idx || -1;

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();

                        self.data_items[cur_idx].paused = 0;
                        self.map[cur_idx].paused_block.hide();

                        if (self.cur_row == cur_idx) {
                            self.active_row.playing_block.hide();
                            self.active_row.paused_block.hide();
                        }
                    }

                    /*self.set_active_row(self.cur_row);*/
                }
            });

            stb.player.addCustomEventListener("radiopause", function (item) {
                _debug('radio.radiopause', item);

                if (self.on && self.data_items[0].radio && item.radio) {

                    var cur_idx = self.data_items.getIdxByVal("id", item.id);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();

                        self.data_items[cur_idx].paused = 1;
                        self.map[cur_idx].paused_block.show();

                        if (self.cur_row == cur_idx) {
                            self.active_row.playing_block.hide();
                            self.active_row.paused_block.show();
                        }
                    }
                }
            });

            stb.player.addCustomEventListener("radiocontinue", function (item) {
                _debug('radio.radiocontinue', item);

                if (self.on && self.data_items[0].radio && item.radio) {

                    var cur_idx = self.data_items.getIdxByVal("id", item.id);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].paused = 0;
                        self.map[cur_idx].paused_block.hide();

                        self.data_items[cur_idx].playing = 1;
                        self.map[cur_idx].playing_block.show();

                        if (self.cur_row == cur_idx) {
                            self.active_row.paused_block.hide();
                            self.active_row.playing_block.show();
                        }
                    }
                }
            });

            this.load_fav_radio();

            this.load_fav_ids();

            this.init_search_box();
        };

        this.show = function () {
            _debug('radio.show');

            if (typeof (stb.user.fav_radio_on) != 'undefined' && stb.user.fav_radio_on == 1) {
                this.load_params.fav = true;
                this.load_params.sortby = 'fav';
            }
            if (stb.player.cur_media_item && stb.player.cur_media_item.radio) {
                if (this.cur_page != stb.player.cur_media_item.page) {
                    this.load_params.p = this.cur_page = stb.player.cur_media_item.page;
                }
            }

            this.load_params.all = 0;

            this.superclass.show.call(this);

        };

        this.hide = function (do_not_reset) {
            _debug('radio.hide');

            /*this.superclass.hide.call(this, do_not_reset);*/
            /*stb.player.stop();*/

            if (!do_not_reset) {
                this.sidebar && this.sidebar.reset && this.sidebar.reset();

                if (this.sidebar && this.sidebar.on) {
                    this.sidebar.hide();
                }
            }
            this.dom_obj.hide();
            this.on = false;
            this.update_header_path([{ "alias": "playing", "item": "*" }]);
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.play.bind(key.OK, this);

            (function () {
                var header_path = [{ "alias": "search", "item": '' }];
                if (stb.user.fav_radio_on != 1) {
                    header_path.push({ "alias": "sortby", "item": '' });
                }
                this.update_header_path(header_path);

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
                    }
                    return;
                }

                this.hide(true);
                main_menu.show();
            }).bind(key.EXIT, this).bind(key.LEFT, this).bind(key.MENU, this);

            this.shift_row_ch_channel.bind(key.CHANNEL_PREV, this, -1);
            this.shift_row_ch_channel.bind(key.CHANNEL_NEXT, this, 1);
        };

        this.play = function () {
            _debug('radio.play');

            this.update_header_path([{ "alias": "playing", "item": this.data_items[this.cur_row].name }]);

            stb.player.stop();
            stb.player.need_show_info = 1;
            if (this.data_items) {
                stb.player.playlist = this.data_items;
            }

            if (!this.data_items[this.cur_row].open) {

                if (this.data_items[this.cur_row].hasOwnProperty('error') && this.data_items[this.cur_row].error) {
                    stb.notice.show(get_word('error_channel_' + this.data_items[this.cur_row].error));
                } else {
                    stb.notice.show(get_word('msg_channel_not_available'));
                }
                return;
            }

            stb.player.play(this.data_items[this.cur_row]);
        };

        this.set_active_row = function (num) {

            _debug('radio.set_active_row', num);

            this.superclass.set_active_row.call(this, num);

            if (!this.data_items[this.cur_row]) {
                _debug("next step row - ", this.cur_row);
                return;
            }
            _debug('this.data_items[this.cur_row].cmd', this.data_items[this.cur_row].cmd);
            _debug('stb.player.on', stb.player.on);
            _debug('stb.player.cur_media_item', stb.player.cur_media_item);
            _debug('stb.player.file_type', stb.player.file_type);

            if (stb.player.cur_media_item && this.data_items[this.cur_row].id == stb.player.cur_media_item.id && stb.player.on && stb.player.media_type == 'stream') {

                this.active_row['row'].setAttribute("status", "playing");
                this.active_row['row'].addClass("playing");

            } else {

                this.active_row['row'].setAttribute("status", "");
                this.active_row['row'].removeClass("playing");

            }

            if (num == 0 && stb.player.cur_media_item.radio && typeof (stb.player.radio_idx) != 'undefined' && stb.player.cur_media_item.id == this.data_items[this.cur_row].id) {
                var idx = stb.player.radio_idx;
                this.data_items[idx].playing = 1;
                this.map[idx].playing_block.show();

                this.data_items[idx].paused = 0;
                this.map[idx].paused_block.hide();

                if (this.cur_row == idx && this.cur_page == stb.player.cur_media_item.page) {
                    this.active_row.playing_block.show();
                    this.active_row.paused_block.hide();
                }
            } else if (typeof (stb.player.radio_idx) != 'undefined' || (stb.player.cur_media_item && stb.player.cur_media_item.radio)) {
                var idx = typeof (stb.player.radio_idx) != 'undefined' ? stb.player.radio_idx : this.data_items.getIdxByVal('id', stb.player.cur_media_item.id);
                idx = idx || 0;
                /*this.superclass.set_active_row.call(this, idx);*/
                if (stb.player.cur_media_item && this.data_items[idx] && this.data_items[idx].id == stb.player.cur_media_item.id) {
                    if (stb.player.cur_media_item.playing) {
                        this.map[idx].paused_block.hide();
                        this.map[idx].playing_block.show();
                    }
                    if (stb.player.cur_media_item.paused) {
                        this.map[idx].paused_block.show();
                        this.map[idx].playing_block.hide();
                    }
                }
                if (this.cur_row == idx && this.cur_page == stb.player.cur_media_item.page) {
                    this.active_row.playing_block.show();
                    this.active_row.paused_block.hide();
                }
            }

        };

        this.shift_row_ch_channel = function (dir) {
            window.clearTimeout(this.row_callback_timer);
            this.data_items[this.cur_row].unlocked = false;
            if (this.data_items[this.cur_row].hasOwnProperty('open') && !this.data_items[this.cur_row].open) {
                this.map[this.cur_row]['row'].addClass('close');
                this.active_row['row'].addClass('close');
            } else {
                this.map[this.cur_row]['row'].removeClass('close');
                this.active_row['row'].removeClass('close');
            }
            this.superclass.shift_row.call(this, dir);
            _debug('before set timeout');
            var self = this;
            this.row_callback_timer = window.setTimeout(function () {
                _debug('row_callback');
                if (self.loading) {
                    return;
                }
                window.clearTimeout(self.row_callback_timer);
                self.play();
            },
                this.row_callback_timeout);

            _debug('this.row_callback_timeout', this.row_callback_timeout);
            _debug('after set timeout');
        };

        this.init_sort_menu = function (map, options) {
            this.sort_menu = new bottom_menu(this, options);
            this.sort_menu.init(map);
            this.sort_menu.bind();
        };

        this.sort_menu_switcher = function () {
            if (this.sort_menu && this.sort_menu.on) {
                this.sort_menu.hide();
            } else {
                this.sort_menu.show();
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
            } else {
                this.sidebar && this.sidebar.full_reset && this.sidebar.full_reset();
                this.search_box.show();
            }
        };

        this.init_search_box = function () {
            _debug('radio.init_search_box');
            var scope = this;

            /* RADIO SEARCH DIALOG */
            this.search_box = new ModalForm({ "title": get_word('radio_search_box') });
            this.search_box.enableOnExitClose();
            this.search_box.addItem(new ModalFormInput(
                {
                    "name": "search_box_input",
                    "oninput": function () {
                        scope.radio_search();
                    }
                }
            ));
            this.search_box.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.search_box.reset();
                        scope.search_box.hide();
                    }
                }
            ));

            this.search_box.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.radio_search();
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
                scope.radio_search();
            });

            this.radio_search = function () {

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

        this.add_to_fav = function () {
            _debug('radio.add_to_fav');

            _debug('this.player.fav_radio before', stb.player.fav_radio_ids);

            stb.player.fav_radio_ids.push(this.data_items[this.cur_row].id);

            _debug('this.player.fav_radio after', stb.player.fav_radio_ids);

            this.data_items[this.cur_row].fav = 1;

            this.map[this.cur_row].fav_block.show();
            this.active_row.fav_block.show();

            if (typeof (stb.player.fav_radio) == 'undefined') {
                stb.player.fav_radio = [];
            }

            this.data_items[this.cur_row].number = stb.player.fav_radio.length + 1;

            stb.player.fav_radio.push(this.data_items[this.cur_row]);

            this.save_fav_ids();
        };

        this.del_from_fav = function () {
            _debug('radio.del_from_fav');

            _debug('this.player.fav_radio before', stb.player.fav_radio_ids);

            var fav_idx = stb.player.fav_radio_ids.indexOf(this.data_items[this.cur_row].id);

            var removed_idx = stb.player.fav_radio_ids.splice(fav_idx, 1);

            _debug('removed_idx', removed_idx);

            _debug('this.player.fav_radio after', stb.player.fav_radio_ids);

            this.data_items[this.cur_row].fav = 0;

            this.map[this.cur_row].fav_block.hide();
            this.active_row.fav_block.hide();

            if (typeof (stb.player.fav_radio) == 'undefined') {
                stb.player.fav_radio = [];
            }
            var fav_radio_idx = stb.player.fav_radio.getIdxByVal('id', this.data_items[this.cur_row].id);

            if (fav_radio_idx !== null) {
                stb.player.fav_radio.splice(fav_radio_idx, 1);
            }

            this.save_fav_ids();
        };

        this.add_del_fav = function () {
            _debug('radio.add_del_fav');

            if (this.data_items[this.cur_row].fav) {
                this.del_from_fav();
            } else {
                this.add_to_fav();
            }
        };

        this.load_fav_radio = function () {

            stb.load(
                {
                    'type': 'radio',
                    'action': 'get_all_fav_radio',
                    'fav': 1
                },

                function (result) {
                    _debug('all_fav_radio', result);

                    stb.loader.add_pos(stb.load_step, 'fav_radio loaded');

                    stb.player.fav_radio = typeof (result) != 'undefined' && result ? result.data : [];

                    _debug('stb.player.fav_radio', stb.player.fav_radio);
                    /*this.radio_loaded();*/
                },

                this
            )
        };

        this.load_fav_ids = function () {

            stb.load(
                {
                    'type': 'radio',
                    'action': 'get_fav_ids'
                },

                function (result) {
                    _debug('fav_radio_ids', result);
                    stb.player.fav_radio_ids = typeof (result) != 'undefined' && result ? result : [];
                    _debug('stb.player.fav_radio_ids', stb.player.fav_radio_ids);
                },

                this
            )
        };

        this.save_fav_ids = function () {
            _debug('radio.save_fav');

            stb.load(
                {
                    'type': 'radio',
                    'action': 'set_fav',
                    'fav_radio': stb.player.fav_radio_ids.join(',')
                },

                function (result) {
                    _debug('fav_saved', result);

                    //stb.load_fav_channels();

                    stb.load(
                        {
                            'type': 'radio',
                            'action': 'get_all_fav_radio',
                            'fav': 1
                        },

                        function (result) {
                            _debug('get_all_fav_radio result', result);

                            stb.player.fav_radio = typeof (result) != 'undefined' && result ? result.data : [];
                        },

                        this
                    )
                },

                this
            )
        };
    }

    radio_constructor.prototype = new ListLayer();

    var radio = new radio_constructor();

    radio.bind();
    radio.init();

    if (single_module.indexOf('radio') == -1) {
        radio.init_left_ear(word['ears_back']);
    }

    var color_buttons_map = [
        {
            "label": '', "cmd": function () {
            }
        },
        { "label": word['radio_sort'], "cmd": radio.sort_menu_switcher },
        { "label": word['radio_favorite'], "cmd": radio.add_del_fav },
        { "label": word['radio_search'], "cmd": radio.search_menu_switcher }
    ];

    radio.init_color_buttons(color_buttons_map);

    var sort_menu_map = [
        {
            "label": word['radio_by_number'],
            "cmd": function () {
                stb.player.stop();
                this.parent.load_params.fav = false;
                this.parent.load_params.sortby = 'number';
                stb.user.fav_radio_on = 0;
                module.radio_widget && module.radio_widget.set_radio_widget_items_list();
                this.parent.load_params.all = 0;
            }
        },
        {
            "label": word['radio_by_title'],
            "cmd": function () {
                stb.player.stop();
                this.parent.load_params.fav = false;
                this.parent.load_params.sortby = 'name';
                stb.user.fav_radio_on = 0;
                module.radio_widget && module.radio_widget.set_radio_widget_items_list();
                this.parent.load_params.all = 0;
            }
        },
        {
            "label": word['radio_only_favorite'],
            "cmd": function () {
                stb.player.stop();
                this.parent.load_params.sortby = 'fav';
                this.parent.load_params.fav = true;
                stb.user.fav_radio_on = 1;
                module.radio_widget && module.radio_widget.set_radio_widget_items_list();
                this.parent.load_params.all = 0;
            }
        }
    ];

    radio.init_sort_menu(
        sort_menu_map,
        {
            "offset_x": 217,
            "color": "green"
        }
    );

    var fav_menu_map = [
        {
            "label": get_word('radio_fav_add') + '/' + get_word('radio_fav_del'),
            "cmd": function () {
                radio.add_del_fav();
            }
        }
    ];

    radio.init_fav_menu(
        fav_menu_map,
        {
            "color": "yellow",
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );

    var search_menu_map = [
        {
            "label": get_word('radio_search'),
            "cmd": function () {
                this.parent.load_params.quality = "high";
            }
        }
    ];

    radio.init_search_menu(
        search_menu_map,
        {
            "color": "blue",
            "need_update_header": false
        }
    );

    radio.fav_menu.dependency = [radio.search_menu, radio.sort_menu];

    radio.sort_menu.dependency = [radio.search_menu, radio.fav_menu];

    radio.search_menu.dependency = [radio.sort_menu, radio.fav_menu];

    radio.init_header_path(word['radio_title']);

    radio.hide();
    module.radio = radio;

    /* END RADIO */

    /* Integrate karaoke in main menu */

    main_menu.add(word['radio_title'], [], 'mm_ico_radio.png', function () {
        main_menu.hide();
        module.radio.show();
    },
        module.radio);

})();

loader.next();
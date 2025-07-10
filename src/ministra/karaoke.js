/**
 * Karaoke module.
 */
(function () {

    /* KARAOKE */
    function karaoke_constructor() {

        this.layer_name = 'karaoke';

        this.row_blocks = ['fav', 'singer', 'name'];

        this.load_params = {
            "type": "karaoke",
            "action": "get_ordered_list"
        };

        this.superclass = ListLayer.prototype;

        this.sort_menu = {};

        this.other_menu = {};

        this.search_box = {};

        this.init = function () {

            _debug('karaoke.init');

            this.superclass.init.call(this);

            this.load_fav_karaoke();

            this.load_fav_ids();

            this.init_search_box();
        };

        this.load_abc = function () {
            _debug('karaoke.load_abc');

            stb.load(
                {
                    "type": "karaoke",
                    "action": "get_abc"
                },
                function (result) {
                    _debug('callback abc');

                    this.sidebar.fill_items("abc", result);
                },
                this
            )
        };

        this._show = function () {

            _debug('karaoke._show');

            try {
                this.sort_menu.action();

                this.superclass.show.call(this);

                this.load_abc();
            } catch (e) {
                _debug(e);
            }
        };

        this.hide = function (do_not_reset) {

            _debug('karaoke.hide');

            if (!do_not_reset) {
                this.search_box && this.search_box.reset && this.search_box.reset();
            }

            this.search_box.on && this.search_box.hide && this.search_box.hide();
            this.sort_menu.on && this.sort_menu.hide && this.sort_menu.hide();

            this.superclass.hide.call(this, do_not_reset);
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

        this.init_fav_menu = function (map, options) {
            this.fav_menu = new bottom_menu(this, options);
            this.fav_menu.init(map);
            this.fav_menu.bind();
        };

        this.add_to_fav = function () {
            _debug('karaoke.add_to_fav');

            _debug('this.player.fav_karaoke before', stb.player.fav_karaoke_ids);

            stb.player.fav_karaoke_ids.push(this.data_items[this.cur_row].id);

            _debug('this.player.fav_karaoke after', stb.player.fav_karaoke_ids);

            this.data_items[this.cur_row].fav = 1;

            this.map[this.cur_row].fav_block.show();
            this.active_row.fav_block.show();

            if (typeof (stb.player.fav_karaoke) == 'undefined') {
                stb.player.fav_karaoke = [];
            }

            this.data_items[this.cur_row].number = stb.player.fav_karaoke.length + 1;

            stb.player.fav_karaoke.push(this.data_items[this.cur_row]);

            this.save_fav_ids();
        };

        this.del_from_fav = function () {
            _debug('karaoke.del_from_fav');

            _debug('this.player.fav_karaoke before', stb.player.fav_karaoke_ids);

            var fav_idx = stb.player.fav_karaoke_ids.indexOf(this.data_items[this.cur_row].id);

            var removed_idx = stb.player.fav_karaoke_ids.splice(fav_idx, 1);

            _debug('removed_idx', removed_idx);

            _debug('this.player.fav_karaoke after', stb.player.fav_karaoke_ids);

            this.data_items[this.cur_row].fav = 0;

            this.map[this.cur_row].fav_block.hide();
            this.active_row.fav_block.hide();

            if (typeof (stb.player.fav_karaoke) == 'undefined') {
                stb.player.fav_karaoke = [];
            }
            var fav_karaoke_idx = stb.player.fav_karaoke.getIdxByVal('id', this.data_items[this.cur_row].id);

            if (fav_karaoke_idx !== null) {
                stb.player.fav_karaoke.splice(fav_karaoke_idx, 1);
            }

            this.save_fav_ids();
        };

        this.add_del_fav = function () {
            _debug('karaoke.add_del_fav');

            if (this.data_items[this.cur_row].fav) {
                this.del_from_fav();
            } else {
                this.add_to_fav();
            }
        };

        this.load_fav_karaoke = function () {

            stb.load(
                {
                    'type': 'karaoke',
                    'action': 'get_all_fav_karaoke',
                    'fav': 1
                },

                function (result) {
                    _debug('all_fav_karaoke', result);

                    stb.loader.add_pos(stb.load_step, 'fav_karaoke loaded');

                    stb.player.fav_karaoke = typeof (result) != 'undefined' && result ? result.data : [];

                    _debug('stb.player.fav_karaoke', stb.player.fav_karaoke);
                    /*this.karaoke_loaded();*/
                },

                this
            )
        };

        this.load_fav_ids = function () {

            stb.load(
                {
                    'type': 'karaoke',
                    'action': 'get_fav_ids'
                },

                function (result) {
                    _debug('fav_karaoke_ids', result);
                    stb.player.fav_karaoke_ids = typeof (result) != 'undefined' && result ? result : [];
                    _debug('stb.player.fav_karaoke_ids', stb.player.fav_karaoke_ids);
                },

                this
            )
        };

        this.save_fav_ids = function () {
            _debug('karaoke.save_fav');
            var scope = this;

            stb.load(
                {
                    'type': 'karaoke',
                    'action': 'set_fav',
                    'fav_karaoke': stb.player.fav_karaoke_ids.join(',')
                },

                function (result) {
                    _debug('fav_saved', result);

                    stb.load(
                        {
                            'type': 'karaoke',
                            'action': 'get_all_fav_karaoke',
                            'fav': 1
                        },

                        function (result) {
                            _debug('get_all_fav_karaoke result', result);

                            stb.player.fav_karaoke = typeof (result) != 'undefined' && result ? result.data : [];
                        },

                        this
                    )
                },

                this
            )
        };

        this.init_other_menu = function (map, options) {
            this.other_menu = new bottom_menu(this, options);
            this.other_menu.init(map);
            this.other_menu.bind();
        };

        this.other_switcher = function () {
            if (this.other_menu && this.other_menu.on) {
                this.other_menu.hide();
            } else {
                this.other_menu.show();
            }
        };

        this.init_search_box = function () {
            _debug('karaoke.init_search_box');
            var scope = this;

            /* KARAOKE SEARCH DIALOG */
            this.search_box = new ModalForm({ "title": get_word('karaoke_search_box') });
            this.search_box.enableOnExitClose();
            this.search_box.addItem(new ModalFormInput(
                {
                    "name": "search_box_input",
                    "oninput": function () {
                        scope.karaoke_search();
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
                        scope.karaoke_search();
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
                scope.karaoke_search();
            });

            this.karaoke_search = function () {

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

        this.search_box_switcher = function () {
            if (this.search_box && this.search_box.on) {
                this.search_box.hide();
            } else {
                this.search_box.show();
            }
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            (function () {

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

                this.hide();
                main_menu.show();
            }).bind(key.MENU, this).bind(key.EXIT, this).bind(key.LEFT, this);

            this.play.bind(key.OK, this);
        };

        this.play = function () {
            _debug('karaoke.play');

            var self = this;

            _debug('cmd', this.data_items[this.cur_row].cmd);
            _debug('indexOf', this.data_items[this.cur_row].cmd.indexOf('://'));

            if (this.data_items[this.cur_row].cmd.indexOf('://') < 0) {
                stb.player.on_create_link = function (result) {
                    _debug('karaoke.on_create_link', result);

                    if (result.error == 'limit') {
                        stb.notice.show(word['player_limit_notice']);
                    } else if (result.error == 'nothing_to_play') {
                        stb.notice.show(word['player_file_missing']);
                    } else if (result.error == 'link_fault') {
                        stb.notice.show(word['player_server_error']);
                    } else {

                        self.hide(true);

                        stb.player.prev_layer = self;
                        stb.player.need_show_info = 1;
                        stb.player.play_now(result.cmd);
                    }
                }
            } else {

                this.hide(true);

                stb.player.prev_layer = self;
                stb.player.need_show_info = 1;
            }

            stb.player.play(this.data_items[this.cur_row]);
        };
    }

    karaoke_constructor.prototype = new ListLayer();

    var karaoke = new karaoke_constructor();

    karaoke.bind();
    karaoke.init();

    if (single_module.indexOf('karaoke') == -1) {
        karaoke.init_left_ear(word['ears_back']);
    }

    karaoke.init_color_buttons([
        {
            "label": word['karaoke_view'], "cmd": (function () {
            })
        },
        { "label": word['karaoke_sort'], "cmd": karaoke.sort_menu_switcher },
        { "label": word['karaoke_favorite'], "cmd": karaoke.add_del_fav },
        { "label": word['karaoke_find'], "cmd": karaoke.other_switcher }
    ]);

    //karaoke.color_buttons[karaoke.color_buttons.getIdxByVal('color', 'red')].text_obj.setClass('disable_color_btn_text');
    karaoke.color_buttons.get('red').disable();

    karaoke.init_sidebar();

    karaoke.sidebar.init_items("abc", { "header": word['karaoke_by_letter'], "width": 26, "align": "center" });

    karaoke.sidebar.bind();

    karaoke.init_sort_menu(
        [
            {
                "label": word['karaoke_by_performer'], "cmd": function () {
                    this.parent.load_params.sortby = 'singer';
                    this.parent.load_params.fav = false;
                }
            },
            {
                "label": word['karaoke_by_title'], "cmd": function () {
                    this.parent.load_params.sortby = 'name';
                    this.parent.load_params.fav = false;
                }
            },
            {
                "label": word['karaoke_only_favorite'], "cmd": function () {
                    this.parent.load_params.sortby = 'fav';
                    this.parent.load_params.fav = true;
                }
            }
        ],
        {
            "offset_x": 217,
            "color": "green"
        }
    );

    var fav_menu_map = [
        {
            "label": get_word('karaoke_fav_add') + '/' + get_word('karaoke_fav_del'),
            "cmd": function () {
                karaoke.add_del_fav();
            }
        }
    ];

    karaoke.init_fav_menu(
        fav_menu_map,
        {
            "color": "yellow",
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );

    karaoke.init_other_menu(
        [
            {
                "label": word['karaoke_search_box'], "cmd": function () {
                    this.parent.search_box_switcher()
                }
            },
            {
                "label": word['karaoke_sampling'], "cmd": function () {
                    this.parent.sidebar_switcher()
                }
            }
        ],
        {
            "offset_x": 520,
            "color": "blue",
            "need_reset_load_data": false
        }
    );

    karaoke.init_header_path(word['karaoke_title']);

    karaoke.sidebar.dependency = [karaoke.sort_menu, karaoke.search_box];
    karaoke.sort_menu.dependency = [karaoke.sidebar, karaoke.search_box];
    karaoke.search_box.dependency = [karaoke.sidebar, karaoke.sort_menu];

    karaoke.hide();

    module.karaoke = karaoke;

    /* END KARAOKE */

    /* Integrate karaoke in main menu */

    main_menu.add(get_word('karaoke_title'), [], 'mm_ico_karaoke.png', function () {
        main_menu.hide();
        module.karaoke._show();
    },
        module.karaoke);

})();

loader.next();
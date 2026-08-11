/**
 * Video Club module.
 */
(function () {

    /* VCLUB */
    function vclub_constructor() {

        this.layer_name = 'vclub';

        this.row_blocks = ['hd', 'sd', 'fav', 'lock', 'low_quality', 'name', 'today', 'yesterday', 'week_and_more'];

        this.load_params = {
            'type': 'vod',
            'action': 'get_ordered_list',
            'movie_id': 0,
            'season_id': 0,
            'episode_id': 0
        };

        this.history = []; //{page:1, row:2, load_params:{}, header_path:[]}

        this.superclass = ListLayer.prototype;

        this.category_alias = '';

        this.sort_menu = {};

        this.search_box = {};

        this.view_menu = {};

        this.other_menu = {};
        this.current_movie = {};

        this.row_callback_timer;
        this.row_callback_timeout = 1000;

        this.password_input = new password_input({ "parent": this });
        this.password_input.bind();

        this.rent_password_input = new password_input({ "parent": this });
        this.rent_password_input.bind();

        this.series_switch = new series_switch({ "parent": this });
        this.series_switch.bind();

        this.storage_switch = new series_switch({ "parent": this });
        this.storage_switch.box_input_format = '{0}';
        this.storage_switch.continuously_box_enable = false;
        this.storage_switch.bind();

        /*this.dialog = new downloads_dialog_constructor();
        this.dialog.hide();*/

        this.init = function () {
            this.superclass.init.call(this);
            this.init_info();
            this.download_exist = new ModalForm({
                title: get_word("alert_form_title"),
                text: get_word("identical_download_exist")
            });
            this.download_exist.getButtonsBlockDomObj().style.textAlign = "center";
            this.download_exist.getTextDomObj().style.textAlign = "center";
            this.download_exist.enableOnExitClose();

            var scope = this;

            this.download_exist.addItem(new ModalFormButton(
                {
                    "value": get_word("close_btn"),
                    "onclick": function () {
                        scope.download_exist.hide();
                    }
                }
            ));


            this.rent_confirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('rent_movie_text')
            });
            this.rent_confirm.getTextDomObj().style.textAlign = "center";
            this.rent_confirm.enableOnExitClose();
            this.rent_confirm.addCustomEventListener('show', function () {
                scope.on = false;
            });
            this.rent_confirm.addCustomEventListener('hide', function () {
                scope.on = true;
            });

            this.rent_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.rent_confirm.hide();
                    }
                }
            ));

            this.rent_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("yes_btn"),
                    "onclick": function () {
                        scope.rent_confirm.hide();

                        scope.rent_password_input.callback = function () {
                            scope.check_price(scope.rent_confirm.video_id);
                        };

                        scope.rent_password_input.show();
                    }
                }
            ));

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

            this.price_confirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('rent_movie_price_text')
            });
            this.price_confirm.getTextDomObj().style.textAlign = "center";
            this.price_confirm.enableOnExitClose();
            this.price_confirm.addCustomEventListener('show', function () {
                scope.on = false;
            });
            this.price_confirm.addCustomEventListener('hide', function () {
                scope.on = true;
            });

            this.price_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.price_confirm.hide();
                    }
                }
            ));

            this.price_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("pay_btn"),
                    "onclick": function () {

                        scope.rent(scope.price_confirm.video_id, scope.price_confirm.price);

                        scope.price_confirm.hide();
                    }
                }
            ));

            this.subscription_message = new ModalForm({ "title": get_word('notice_form_title'), "text": " " });
            this.subscription_message.getTextDomObj().style.textAlign = "center";
            this.subscription_message.enableOnExitClose();
            this.subscription_message.addCustomEventListener('show', function () {
                scope.on = false;
            });
            this.subscription_message.addCustomEventListener('hide', function () {
                scope.on = true;
            });
            this.subscription_message.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.subscription_message.hide();
                    }
                }
            ));


            this.complete_confirm = new ModalForm({
                "title": get_word('notice_form_title'),
                "text": get_word('service_subscribe_success')
            });
            this.complete_confirm.enableOnExitClose();
            this.complete_confirm.getTextDomObj().style.textAlign = "center";
            this.complete_confirm.addCustomEventListener('show', function () {
                scope.on = false;
            });
            this.complete_confirm.addCustomEventListener('before_hide', function () {
                scope.on = true;
            });
            this.complete_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        //scope.on = true;
                        scope.complete_confirm.hide();
                    }
                }
            ));

            this.complete_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("play_btn"),
                    "onclick": function () {
                        scope.complete_confirm.hide();
                        //scope.on = false;
                        scope.play(scope.play_url, scope.storage);
                    }
                }
            ));
        };

        this.load_genres = function (alias) {

            alias = alias || '';

            _debug('vclub.load_genres', alias);

            stb.load(
                {
                    "type": "vod",
                    "action": "get_genres_by_category_alias",
                    "cat_alias": alias
                },
                function (result) {
                    _debug('callback categories');

                    this.sidebar.fill_items("genre", result);
                },
                this
            )
        };

        this.load_years = function (category_id) {
            _debug('vclub.load_years');

            stb.load(
                {
                    "type": "vod",
                    "action": "get_years",
                    "category": category_id
                },
                function (result) {
                    _debug('callback years');

                    this.sidebar.fill_items("years", result);
                },
                this
            )
        };

        this.load_abc = function () {
            _debug('vclub.load_abc');

            stb.load(
                {
                    "type": "vod",
                    "action": "get_abc"
                },
                function (result) {
                    _debug('callback abc');

                    this.sidebar.fill_items("abc", result);
                },
                this
            )
        };

        this._show = function (category) {

            _debug('vclub.show');

            category = category || this.categories && this.categories[0];

            if (!category) {
                category = { "id": "*", "alias": "*", "title": get_word("all_title") };
            }

            this.load_params['category'] = category.id;

            this.update_header_path([{ "alias": "genre", "item": category.title }]);

            try {
                this.sort_menu.action();

                this.superclass.show.call(this);

                this.load_abc();
                this.load_genres(category.alias);
                this.load_years(category.id);
            } catch (e) {
                _debug(e);
            }
        };

        /*this.show = function(category){
            
            _debug('vclub.show');
            
            this.load_params['category'] = category.id;
            
            try{
                this.sort_menu.action();
                
                this.superclass.show.call(this);
                
                this.load_abc();
                this.load_genres(category.alias);
                this.load_years(category.id);
            }catch(e){
                _debug(e);
            }
        };*/

        this.hide = function (do_not_reset) {

            _debug('vclub.hide', do_not_reset);

            if (!do_not_reset) {
                this.search_box && this.search_box.reset && this.search_box.reset();
            }

            this.search_box.on && this.search_box.hide && this.search_box.hide();
            this.sort_menu.on && this.sort_menu.hide && this.sort_menu.hide();
            this.view_menu.on && this.view_menu.hide && this.view_menu.hide();
            this.other_menu.on && this.other_menu.hide && this.other_menu.hide();

            this.info.on && this.info.hide && this.info.hide();
            this.password_input.on && this.password_input.hide && this.password_input.hide();
            this.rent_password_input.on && this.rent_password_input.hide && this.rent_password_input.hide();
            this.series_switch.on && this.series_switch.hide && this.series_switch.hide();
            this.storage_switch.on && this.storage_switch.hide && this.storage_switch.hide();

            stb.player.pause && stb.player.pause.on && stb.player.hide_pause();

            if (!do_not_reset && stb.player.on) {
                stb.player.stop && stb.player.stop();
            }

            if (!do_not_reset) {

                this.clear_short_info();

                this.history = [];

                this.update_header_path([
                    { "alias": "movie", "item": '' },
                    { "alias": "season", "item": '' },
                    { "alias": "episode", "item": '' }
                ]);
            }

            this.superclass.hide.call(this, do_not_reset);
        };

        this.back = function () {
            _debug('vclub.back');

            _debug('this.history.length', this.history.length);

            if (this.history.length == 1) {

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

                    this.history.push({
                        "page": this.cur_page,
                        "row": this.cur_row,
                        "load_params": this.load_params
                    });

                    return;
                }

                this.hide();
                main_menu.show();
            }

            var level = this.history.splice(this.history.length - 1, 1)[0];

            _debug('level', level);

            this.update_header_path([
                { "alias": "movie", "item": '' },
                { "alias": "season", "item": '' },
                { "alias": "episode", "item": '' }
            ]);

            if (level) {
                this.load_params = level.load_params;

                this.load_params['row'] = level.row;
                this.cur_page = level.load_params['p'];

                if (level.header_path && level.header_path.length > 0) {
                    this.update_header_path(level.header_path);
                }
            }

            if (level) {
                this.load_data();
            }

            this.load_params['row'] = 0;
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

        this.init_search_box = function () {
            _debug('vclub.init_search_box');
            var scope = this;

            /* VIDEO SEARCH DIALOG */
            this.search_box = new ModalForm({ "title": get_word('vclub_search_box') });
            this.search_box.enableOnExitClose();
            this.search_box.addItem(new ModalFormInput(
                {
                    "name": "search_box_input",
                    "oninput": function () {
                        scope.video_search();
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
                        scope.video_search();
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
                scope.video_search();
            });

            this.video_search = function () {

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
                this.sidebar && this.sidebar.full_reset && this.sidebar.full_reset();
                this.search_box.show();
            }
        };

        this.init_view_menu = function (map, options) {
            this.view_menu = new bottom_menu(this, options);
            this.view_menu.init(map);
            this.view_menu.bind();
        };

        this.view_switcher = function () {
            if (this.view_menu && this.view_menu.on) {
                this.view_menu.hide();
            } else {
                this.view_menu.show();
            }
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

        this.fill_short_info = function (item) {
            _debug('fill_short_info');

            if (!item) {
                return;
            }

            if (!item.is_movie) {
                return;
            }

            //item.name
            //item.o_name
            //item.year
            //item.director
            //item.screenshot_uri
            //item.genres_str

            var info = '';

            if (item.rating_kinopoisk && stb.profile['kinopoisk_rating']) {
                info += '<span>' + get_word('vclub_rating') + ': </span>' + item.rating_kinopoisk + '<br>';
            }

            if (item.rent_info) {
                info += get_word('vclub_purchased');

                if (item.rent_info['expires_in']) {
                    info += ', ' + get_word('vclub_rent_expires_in') + ': ' + item.rent_info['expires_in'];
                }
                info += '<br>';
            }

            if (item.age) {
                info += '<span>' + get_word('vclub_age') + ': </span>' + item.age + '<br>';
            }

            if (item.rating_mpaa) {
                info += '<span>' + get_word('vclub_rating_mpaa') + ': </span>' + item.rating_mpaa + '<br>';
            }

            info += '<span>' + word['vclub_genre'] + ': </span>' + item.genres_str;

            if (item.country) {
                info += '<br><span>' + word['vclub_country'] + ': </span>' + item.country
            }

            info += '<br><span>' + word['vclub_year'] + ': </span>' + item.year
                + ' <span>' + word['vclub_length'] + ': </span>' + item.time + ' ' + word['vclub_minutes'] + '.<br>'
                + '<span>' + word['vclub_director'] + ': </span>' + item.director;

            this.short_info_box.innerHTML = info;
            if (item.screenshot_uri) {
                this.screenshot_box.innerHTML = '<img src="' + item.screenshot_uri + '">';
            } else {
                this.screenshot_box.innerHTML = '';
            }
        };

        this.clear_short_info = function () {
            _debug('clear_short_info');

            this.short_info_box.innerHTML = '';
            this.screenshot_box.innerHTML = '';
        };

        this.init_short_info = function () {
            this.info_box = create_block_element('', this.main_container);

            this.short_info_box = create_block_element('vclub_info_box', this.info_box);

            if (!stb.IsEmulator) {
                this.short_info_box.addClass('vclub_info_box_mask');
            }

            this.screenshot_box = create_block_element('vclub_screenshot_box', this.info_box);
        };

        this.shift_row_callback = function (item) {

            if (item.is_movie) {
                this.enable_color_buttons();
                this.current_movie = item;
            } else {
                this.disable_color_buttons();
                if (item.is_season) {
                    this.current_movie['cur_season'] = item.season_number;
                } else if (item.is_episode && item.hasOwnProperty('series')) {
                    this.current_movie['series'] = item.series;
                    this.current_movie['cur_series'] = item.series_number;
                } else if (item.is_file && this.current_movie.hasOwnProperty('series')) {
                    stb.player.play_continuously = true;
                    item['series'] = this.current_movie['series'];
                    item['cur_season'] = this.current_movie['cur_season'];
                    item['cur_series'] = this.current_movie['cur_series'];
                }

                if (item.is_file && this.current_movie['not_ended_file_id'] == item['id']) {
                    item['position'] = this.current_movie['position'] || 0;
                }
            }

            if (!item.is_movie) {
                return;
            }
            window.clearTimeout(this.row_callback_timer);

            var self = this;

            this.row_callback_timer = window.setTimeout(function () {

                self.fill_short_info(item);

            },
                this.row_callback_timeout);
        };

        this.set_middle_container = function () {

            this.superclass.set_middle_container.apply(this);

            this.fill_short_info(this.data_items[this.cur_row]);

        };

        this.handling_block = function (data, row_items, block_name) {

            var passive_blocks = ['today_block', 'yesterday_block', 'week_and_more_block'];
            var active_blocks = ['active_today_block', 'active_yesterday_block', 'active_week_and_more_block'];

            var block_obj = row_items[block_name + '_block'];

            if (data === 1) {
                if (block_obj.isHidden()) {
                    block_obj.show();
                }
            } else if (data === 0 || typeof (data) == "undefined") {
                if (!block_obj.isHidden()) {
                    block_obj.hide();

                    /*var reset_margin = !passive_blocks.splice(0, passive_blocks.indexOf(block_name)).reduce(function(previousValue, currentValue, index, array){
                        return previousValue || !row_items[currentValue].isHidden();
                    }, false);

                    _debug('block_name', block_name);
                    _debug('reset_margin', reset_margin);*/

                    if ((passive_blocks.indexOf(block_obj.className) >= 0 && reset_margin)) {

                        var reset_margin = !passive_blocks.splice(0, passive_blocks.indexOf(block_name)).reduce(function (previousValue, currentValue, index, array) {
                            return previousValue || !row_items[currentValue].isHidden();
                        }, false);

                        _debug('block_name', block_name);
                        _debug('reset_margin', reset_margin);

                        if (reset_margin) {
                            row_items['name_block'].style.marginRight = '';
                        }
                    } else if (active_blocks.indexOf(block_obj.className) >= 0) {

                        /*reset_margin = !active_blocks.splice(0, active_blocks.indexOf(block_name)).reduce(function(previousValue, currentValue, index, array){
                            return previousValue || !row_items[currentValue].isHidden();
                        }, false);

                        _debug('active_blocks', active_blocks);
                        _debug('reset_margin', reset_margin);*/

                        row_items['name_block'].style.marginRight = (screen.height == 720) ? '229px' : '130px';
                    } else if (passive_blocks.indexOf(block_obj.className) == -1) {
                        row_items['name_block'].style.marginRight = '';
                    }
                }
            } else {
                if (block_obj.isHidden()) {
                    block_obj.show();
                }
                /*
                    if (passive_blocks.indexOf(block_obj.className) >= 0){
                        row_items['name_block'].style.marginRight = '103px';
                    }else if (active_blocks.indexOf(block_obj.className) >= 0){
                        row_items['name_block'].style.marginRight = '130px';
                    }else if (passive_blocks.indexOf(block_obj.className) == -1){
                        row_items['name_block'].style.marginRight = '';
                    }
                }else{
                    if (passive_blocks.indexOf(block_obj.className) >= 0){
                        row_items['name_block'].style.marginRight = '103px';
                    }else if (active_blocks.indexOf(block_obj.className) >= 0){
                        row_items['name_block'].style.marginRight = '130px';
                    }else if (passive_blocks.indexOf(block_obj.className) == -1){
                        row_items['name_block'].style.marginRight = '';
                    }
                }*/

                if (passive_blocks.indexOf(block_obj.className) >= 0) {
                    row_items['name_block'].style.marginRight = (screen.height == 720) ? '200px' : '103px';
                } else if (active_blocks.indexOf(block_obj.className) >= 0) {
                    row_items['name_block'].style.marginRight = (screen.height == 720) ? '229px' : '130px';
                } else if (passive_blocks.indexOf(block_obj.className) == -1) {
                    row_items['name_block'].style.marginRight = '';
                }

                block_obj.innerHTML = data;
            }
        };

        this.init_info = function () {
            this.info = new vclub_info(this);
            this.info.init();
            this.info.bind();

            this.info.color_buttons = new ColorButtonsBar([
                {
                    "label": word['downloads_download'], "cmd": function () {
                        if (module.downloads) {
                            this.action.call(this, false);
                        }
                    }
                },
                { "label": '', "cmd": '' },
                { "label": '', "cmd": '' },
                { "label": '', "cmd": '' }
            ], this.info.dom_obj, undefined);

            this.info.color_buttons.bind.call(this.info);

            this.full_info_switch.bind(key.RIGHT, this).bind(key.INFO, this);

            /*var color_buttons = this.color_buttons.buttons_bar.cloneNode(true);
            
            color_buttons.addClass('disabled_all_buttons');
            
            this.info.dom_obj.appendChild(color_buttons);*/
        };

        this.full_info_switch = function () {
            _debug('full_info_switch');

            if (this.info && this.info.on) {
                this.on = true;
                this.info.hide();
            } else {
                this.on = false;
                this.info.show(this.current_movie);
            }
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.action.bind(key.OK, this, true);
            this.check_for_storage_selection.bind(key.PLAY, this, true);
            this.check_for_pass.bind(key.REC, this, false);

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
                        window.location = window.referrer;
                    }
                    return;
                }

                this.update_header_path([{ "alias": "search", "item": '' }]);
                this.hide();
                main_menu.show();
            }).bind(key.MENU, this);

            this.back.bind(key.EXIT, this).bind(key.LEFT, this);

            this.load_data.bind(key.REFRESH, this);
        };

        this.fill_list = function (data) {
            _debug('vclub.fill_list', data);

            this.superclass.fill_list.apply(this, [data]);

            _debug('this.history.length', this.history.length);
            _debug('this.history', this.history);

            if (data.length === 0 && this.history.length == 1) {
                this.enable_color_buttons();
            }
        };

        this.action = function () {
            _debug('vclub.action');

            if (this.data_items[this.cur_row].is_file) {

                this.check_for_pass(true);
                return;
            }

            if (this.data_items[this.cur_row].is_movie && this.data_items[this.cur_row].has_files == '0') {

                this.check_for_pass(true);
                return;
            }

            this.page_dir = 1;

            this.history.push({
                "page": this.cur_page,
                "row": this.cur_row,
                "load_params": this.load_params.clone(),
                "header_path": this.header_path_map.clone()
            });

            if (this.data_items[this.cur_row].is_movie) {
                this.update_header_path([{
                    "alias": "movie",
                    "item": this.data_items[this.cur_row].name
                }, { "alias": "sortby", "item": '' }, { "alias": "genre", "item": '' }, { "alias": "search", "item": '' }]);
                this.load_params['movie_id'] = this.data_items[this.cur_row].id;
            } else if (this.data_items[this.cur_row].is_season) {
                this.update_header_path([{ "alias": "season", "item": this.data_items[this.cur_row].name }]);
                this.load_params['season_id'] = this.data_items[this.cur_row].id;
            } else if (this.data_items[this.cur_row].is_episode) {
                this.update_header_path([{ "alias": "episode", "item": this.data_items[this.cur_row].name }]);
                this.load_params['episode_id'] = this.data_items[this.cur_row].id;
            }

            this.cur_page = 1;

            this.load_data();
        };

        this.disable_color_buttons = function () {
            _debug('vclub.disable_color_buttons');
            this.color_buttons.get('red').disable();
            this.color_buttons.get('green').disable();
            this.color_buttons.get('yellow').disable();
            this.color_buttons.get('blue').disable();
        };

        this.enable_color_buttons = function () {
            _debug('vclub.enable_color_buttons');
            this.color_buttons.get('red').enable();
            this.color_buttons.get('green').enable();
            this.color_buttons.get('yellow').enable();
            this.color_buttons.get('blue').enable();
        };

        this.check_for_storage_selection = function (play_url) {
            _debug('vclub.check_for_storage_selection', play_url);

            _debug('stb.user.is_moderator', stb.user.is_moderator);

            var self = this;

            if (!stb.user.is_moderator || this.data_items[this.cur_row].rtsp_url) {
                this.check_for_pass(play_url);
                return;
            }

            stb.load(
                {
                    "type": "video_master",
                    "action": "get_storages_for_video",
                    "video_id": this.data_items[this.cur_row].id
                },
                function (result) {
                    _debug('get_storages_for_video result', result);

                    if (!result || !result.length || result.length == 0) {
                        stb.notice.show(get_word('player_file_missing'));
                        return;
                    }

                    self.storage_switch.callback = function (storage) {
                        _debug('storage', storage);
                        self.check_for_pass.call(self, play_url, storage);
                    };

                    self.storage_switch.show(result, result[0]);
                },
                this
            );
        };

        this.check_price = function (video_id) {
            _debug('vclub.check_price', video_id);

            stb.load({
                "type": "account",
                "action": "check_video_price",
                "video_id": video_id
            },

                function (result) {
                    _debug('on check_price', result);

                    if (!result) {
                        this.subscription_message.show(get_word('service_subscribe_server_error'));
                    } else if (result.hasOwnProperty('message')) {
                        this.subscription_message.show(result['message']);
                    } else if (result.result === '0' || result.result === 0) {
                        this.rent(video_id, 0);
                    } else if (result.result !== false) {
                        this.price_confirm.price = this.price_confirm.price_str = result.result;

                        if (!/[^0-9\.,]/.test(this.price_confirm.price)) {
                            this.price_confirm.price_str = this.price_confirm.price_str + get_word('package_price_measurement');
                        }

                        this.price_confirm.video_id = video_id;
                        _debug('this.price_confirm.video_id', this.price_confirm.video_id);

                        var msg = get_word('rent_movie_price_text').format(this.price_confirm.price_str);

                        if (result.hasOwnProperty('rent_duration') && result.rent_duration != 0) {
                            msg += '<br>' + get_word('rent_duration_text').format(result.rent_duration);
                        }

                        this.price_confirm.show(msg);
                    }
                },

                this
            );
        };

        this.rent = function (video_id, price) {
            _debug('video.rent', video_id, price);

            stb.load({
                "type": "account",
                "action": "rent_video",
                "video_id": video_id,
                "price": price
            },

                function (result) {
                    _debug('on do_subscribe', result);

                    if (!result || result.hasOwnProperty('result') && result.result === 0) {
                        this.subscription_message.show(get_word('service_subscribe_server_error'));
                    } else if (result.hasOwnProperty('message')) {
                        this.subscription_message.show(result['message']);
                    } else if (result.result > 0) {
                        this.complete_confirm.show(get_word('service_subscribe_success'));

                        this.cur_item['rent_info'] = result.rent_info;
                        this.cur_item['open'] = 1;

                        if (this.cur_item['id'] == this.data_items[this.cur_row]['id']) {
                            this.set_active_row(this.cur_row);
                            this.map[this.cur_row]['row'].removeClass('close');
                        }

                    } else {
                        this.complete_confirm.show(get_word('service_subscribe_fail'));
                    }
                },

                this
            );
        };

        this.check_for_pass = function (play_url, storage) {
            _debug('vclub.check_for_play', play_url, storage);

            _debug('lock', this.current_movie.lock);

            _debug('this.load_params[category]', this.load_params['category']);

            if (this.load_params['category'] == 'coming_soon') {
                stb.notice.show(get_word('coming_soon_video'));
                return;
            }

            var self = this;

            if (this.current_movie.for_rent && !this.current_movie.hasOwnProperty('rent_info')) {

                self.rent_confirm.video_id = self.data_items[self.cur_row].video_id || self.data_items[self.cur_row].id;
                self.rent_confirm.show();

                this.play_url = play_url;
                this.storage = storage;
                this.cur_item = this.data_items[this.cur_row];

            } else if (this.current_movie.lock) {

                this.password_input.callback = function () {
                    self.check_for_series(play_url, storage);
                };

                this.password_input.show();
            } else {
                this.check_for_series(play_url, storage);
            }
        };

        this.check_for_series = function (play_url, storage) {
            _debug('vclub.check_for_series', play_url, storage);

            if (this.data_items[this.cur_row].has_files == '0') {
                if (this.data_items[this.cur_row].series && this.data_items[this.cur_row].series.length > 0) {

                    var self = this;

                    this.series_switch.callback = function (series) {
                        _debug('series', series);
                        self.data_items[self.cur_row].cur_series = series;
                        self.play(play_url, storage);
                    };

                    this.series_switch.show(this.data_items[this.cur_row].series, this.data_items[this.cur_row].cur_series);
                } else {
                    this.play(play_url, storage);
                }
            } else {
                this.play(play_url, storage);
            }
        };

        this.get_link = function (video_cmd, episode, callback) {

            var match, link;

            if (video_cmd.indexOf('://') < 0) {

                stb.player.on_create_link = function (result) {
                    _debug('vclub.on_create_link', result);

                    if (Array.isArray(result)) {
                        for (var i = 0; i < result.length; i++) {
                            _debug('link', link);
                            if (result[i].type == 'ad') {
                                continue;
                            }

                            if (result[i].cmd) {
                                if (match = /[\s]([^\s]*)$/.exec(result[i].cmd)) {
                                    result[i].cmd = match[1];
                                    link = result[i];
                                    break;
                                }
                            }
                        }
                    } else {
                        if (result.cmd) {
                            if (match = /[\s]([^\s]*)$/.exec(result.cmd)) {
                                result.cmd = match[1];
                            }
                        }

                        link = result
                    }

                    callback && callback(link.cmd);
                }

            } else {
                callback(video_cmd);
                return;
            }

            stb.player.create_link('vod', video_cmd, episode, '');
        };

        this.play = function (play_url, storage, callback) {
            _debug('vclub.play', play_url, storage);

            var self = this;

            _debug('cmd', this.data_items[this.cur_row].cmd);
            _debug('indexOf', this.data_items[this.cur_row].cmd.indexOf('://'));

            if (this.data_items[this.cur_row].cmd.indexOf('://') < 0 || this.data_items[this.cur_row].protocol == 'custom') {

                stb.player.on_create_link = function (result) {
                    _debug('vclub.on_create_link', result);

                    if (!play_url && callback) {
                        if (result.cmd) {
                            if (match = /[\s]([^\s]*)$/.exec(result.cmd)) {
                                result.cmd = match[1];
                            }
                        }
                        callback && callback(result);
                    }

                    if (result.error == 'limit') {
                        stb.notice.show(word['player_limit_notice']);
                    } else if (result.error == 'nothing_to_play') {
                        stb.notice.show(word['player_file_missing']);
                    } else if (result.error == 'link_fault') {
                        stb.notice.show(word['player_server_error']);
                    } else if (result.error == 'access_denied') {
                        stb.notice.show(word['player_access_denied']);
                    } else {

                        var match;
                        if (match = /[\s]([^\s]*)$/.exec(result.cmd)) {
                            var url = match[1];
                        } else {
                            url = result.cmd;
                        }
                        _debug('url: ', url);

                        if (result.hasOwnProperty('cmd') && result.cmd && result.cmd.indexOf('://') != -1 && module.downloads && module.downloads.identical_download_exist(url)) {
                            _debug('identical_download_exist!');

                            self.download_exist.show();

                            return;
                        }

                        if (play_url) {

                            if (self.info.on) {
                                self.info.hide();
                            }

                            self.hide(true);

                            stb.player.on_stop = (function (player) {
                                return function () {
                                    _debug('player.on_stop');
                                    if (result.hasOwnProperty('cmd')) {
                                        player.delete_link(result.cmd);
                                    } else if (result.length && result[result.length - 1].cmd) {
                                        player.delete_link(result[result.length - 1].cmd);
                                    }
                                }
                            })(stb.player);

                            stb.player.prev_layer = self;
                            stb.player.need_show_info = 1;

                            var cur_media_item = stb.player.cur_media_item.clone();

                            if (result.length && result.length >= 2) {

                                var movie = result.splice(result.length - 1)[0];

                                _debug('movie', movie);

                                var callback = function () {

                                    stb.key_lock = false;

                                    _debug('movie callback');

                                    self.hide(true);

                                    try {
                                        stb.Stop();
                                    } catch (e) {
                                        _debug(e);
                                    }

                                    _debug('movie', movie);
                                    _debug('cur_media_item', cur_media_item);

                                    stb.player.cur_media_item = cur_media_item.clone();
                                    stb.player.cur_media_item.disable_ad = true;

                                    if (movie.hasOwnProperty('subtitles')) {
                                        stb.player.cur_media_item.subtitles = movie.subtitles.map(function (item, idx) {
                                            item.pid = 'external_' + idx;
                                            item.lang = [item.lang, ''];
                                            return item;
                                        });
                                    }

                                    stb.player.play(stb.player.cur_media_item);
                                };

                                var adverts = result;

                                _debug('adverts', adverts);

                                for (var i = 0; i < adverts.length; i++) {

                                    var advert = adverts[i];

                                    if (i != adverts.length - 1) {

                                        callback = (function (ad, cb) {

                                            return function () {
                                                _debug('ad callback', ad);

                                                self.hide(true);

                                                stb.player.prev_layer = self;

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

                                stb.player.prev_layer = self;

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

                            } else if (result.hasOwnProperty('subtitles')) {

                                stb.player.cur_media_item.subtitles = result.subtitles.map(function (item, idx) {
                                    item.pid = 'external_' + idx;
                                    item.lang = [item.lang, ''];
                                    return item;
                                });

                                stb.player.play_now(result);
                            }

                            //stb.player.play_now(result);
                        } else {
                            //callback && callback(result.cmd);
                            self.add_download.call(self, self.data_items[self.cur_row], url);
                        }
                    }
                }
            } else {

                if (play_url) {
                    if (this.info.on) {
                        this.info.hide();
                    }

                    this.hide(true);

                    stb.player.prev_layer = self;
                    stb.player.need_show_info = 1;
                    //stb.player.play(this.data_items[this.cur_row]);
                } else {
                    var url = /[\s]([^\s]*)$/.exec(this.data_items[this.cur_row].cmd)[1];
                    _debug('url: ', url);

                    self.add_download.call(self, self.data_items[self.cur_row], url);

                    return;
                }
            }

            var played_item = this.data_items[this.cur_row].clone();

            if (storage) {
                played_item.forced_storage = storage;
            }

            if (!play_url) {
                played_item.download = !play_url;
            }

            played_item.name = this.current_movie['name'];

            stb.player.play(played_item);
        };

        this.add_download = function (item, url) {
            _debug('vclub.add_download', item, url);

            _debug('path: ', this.data_items[this.cur_row].path);
            _debug('url', url);

            if (this.data_items[this.cur_row].protocol == 'custom') {
                url = this.data_items[this.cur_row].cmd;
                var url_match = /(\S+):\/\/(\S+)/.exec(url);
                _debug('url_match', url_match);
                if (url_match) {
                    url = url_match[0];
                }
            }

            var filename = this.data_items[this.cur_row].path;

            if (this.data_items[this.cur_row].cur_series && parseInt(this.data_items[this.cur_row].cur_series, 10) != 0) {
                filename += '_E' + this.data_items[this.cur_row].cur_series;
            }

            if (url) {
                var ext = /\.(\w*)$/.exec(url);
                if (!ext) {
                    ext = [, 'mpg'];
                }
            } else {
                ext = [, 'mpg'];
            }

            filename += '.' + ext[1];

            _debug('filename: ', filename);

            var self = this;

            var video_cmd = this.data_items[this.cur_row].cmd;
            var episode = this.data_items[this.cur_row].cur_series || 0;

            var dialog_options = { "parent": this, "url": url, "name": filename, "secure_url": true };

            if (module.downloads) {
                _debug('downloads');
                module.downloads.dialog.show(dialog_options);
            }
        };

        this.set_ad_ended_time = function (ad_id, end_time, total_time, ended) {
            _debug('vclub.set_ad_ended_time', ad_id, end_time, total_time, ended);

            stb.load(
                {
                    "type": "vclub_advertising",
                    "action": "set_ad_ended_time",
                    "ad_id": ad_id,
                    "end_time": end_time,
                    "total_time": total_time,
                    "ended": ended
                },
                function (result) {
                    _debug('on set_ad_ended_time', result);
                },
                this
            );
        };

        this.set_not_ended = function (video_id, series, end_time, file_id) {
            _debug('vclub.set_not_ended', video_id, series, end_time, file_id);

            if (this.load_params.not_ended && empty(this.data_items[this.cur_row].series)) {
                this.data_items[this.cur_row].position = this.current_movie['position'] = end_time;
            }

            stb.load(
                {
                    "type": "vod",
                    "action": "set_not_ended",
                    "video_id": video_id,
                    "series": series,
                    "end_time": end_time,
                    "file_id": file_id
                },

                function (result) {

                },

                this
            )
        };

        this.set_ended = function (video_id) {
            _debug('vclub.set_ended', video_id);

            stb.load(
                {
                    "type": "vod",
                    "action": "set_ended",
                    "video_id": video_id
                },

                function (result) {

                },

                this
            )
        };

        this.add_to_fav = function () {
            _debug('vclub.add_to_fav');

            this.data_items[this.cur_row].fav = 1;

            this.map[this.cur_row].fav_block.show();
            this.active_row.fav_block.show();

            this.set_fav(this.data_items[this.cur_row].id);
        };

        this.del_from_fav = function () {
            _debug('vclub.del_from_fav');

            this.data_items[this.cur_row].fav = 0;

            this.map[this.cur_row].fav_block.hide();
            this.active_row.fav_block.hide();

            this.del_fav(this.data_items[this.cur_row].id);
        };

        this.add_del_fav = function () {
            _debug('vclub.add_del_fav');

            //if (this.load_params.fav == true){
            //    return;
            //}

            if (this.data_items[this.cur_row].fav) {
                this.del_from_fav();
            } else {
                this.add_to_fav();
            }
        };

        this.set_fav = function (id) {
            _debug('vclub.set_fav', id);

            stb.load(
                {
                    "type": "vod",
                    "action": "set_fav",
                    "video_id": id
                },

                function (result) {

                }
            )
        };

        this.del_fav = function (id) {
            _debug('vclub.del_fav', id);

            stb.load(
                {
                    "type": "vod",
                    "action": "del_fav",
                    "video_id": id
                },

                function (result) {

                }
            )
        };

        this.sidebar_switcher = function () {
            _debug('vclub.sidebar_switcher');

            if (this.sidebar && !this.sidebar.on) {
                this.search_box && this.search_box.reset && this.search_box.reset();
                this.load_data();
            }

            this.superclass.sidebar_switcher.call(this);
        };
    }

    vclub_constructor.prototype = new ListLayer();

    var vclub = new vclub_constructor();

    vclub.bind();
    vclub.init();

    vclub.init_short_info();

    //vclub.set_wide_container();
    vclub.set_middle_container();

    if (single_module.indexOf('vclub') == -1) {
        vclub.init_left_ear(word['ears_back']);
    }

    vclub.init_right_ear(word['ears_about_movie']);

    vclub.init_color_buttons([
        { "label": word['vclub_view'], "cmd": vclub.view_switcher },
        { "label": word['vclub_sort'], "cmd": vclub.sort_menu_switcher },
        { "label": word['vclub_fav'], "cmd": vclub.add_del_fav },
        { "label": word['vclub_find'], "cmd": vclub.other_switcher }
    ]);

    //vclub.init_info();

    vclub.init_sidebar();

    vclub.sidebar.init_items("abc", { "header": word['vclub_by_letter'], "width": 26, "align": "center" });
    vclub.sidebar.init_items("genre", { "header": word['vclub_by_genre'], "width": 95 });
    vclub.sidebar.init_items("years", { "header": word['vclub_by_year'], "width": 45 });

    vclub.sidebar.bind();

    var sort_menu = [
        {
            "label": word['vclub_by_addtime'], "cmd": function () {
                this.parent.load_params.fav = false;
                this.parent.load_params.sortby = 'added';
                this.parent.load_params.hd = false;
                this.parent.load_params.not_ended = false
            }
        },
        {
            "label": word['vclub_by_title'], "cmd": function () {
                this.parent.load_params.fav = false;
                this.parent.load_params.sortby = 'name';
                this.parent.load_params.hd = false;
                this.parent.load_params.not_ended = false
            }
        },
        {
            "label": word['vclub_top'], "cmd": function () {
                this.parent.load_params.fav = false;
                this.parent.load_params.sortby = 'top';
                this.parent.load_params.hd = false;
                this.parent.load_params.not_ended = false
            }
        },
        {
            "label": word['vclub_only_favorite'], "cmd": function () {
                this.parent.load_params.sortby = 'name';
                this.parent.load_params.fav = true;
                this.parent.load_params.hd = false;
                this.parent.load_params.not_ended = false
            }
        }
    ];

    vclub.init_sort_menu(
        sort_menu,
        {
            "offset_x": 217,
            "color": "green"
        }
    );

    vclub.init_search_box();

    vclub.init_view_menu(
        [
            {
                "label": word['vclub_list_w_info'], "cmd": function () {
                    this.parent.set_middle_container()
                }
            },
            {
                "label": word['vclub_list'], "cmd": function () {
                    this.parent.set_wide_container()
                }
            }
            //{"label" : word['vclub_list_w_info'], "cmd" : function(){this.parent.set_middle_container()}}
        ],
        {
            "offset_x": 27,
            "color": "red",
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );

    vclub.init_other_menu(
        [
            {
                "label": word['vclub_search_box'], "cmd": function () {
                    this.parent.search_box_switcher()
                }
            },
            {
                "label": word['vclub_query_box'], "cmd": function () {
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

    vclub.init_header_path(word['vclub_title']);

    vclub.sidebar.dependency = [vclub.sort_menu, vclub.search_box, vclub.view_menu, vclub.other_menu];
    vclub.sort_menu.dependency = [vclub.sidebar, vclub.search_box, vclub.view_menu, vclub.other_menu];
    vclub.search_box.dependency = [vclub.sidebar, vclub.sort_menu, vclub.view_menu, vclub.other_menu];
    vclub.view_menu.dependency = [vclub.sidebar, vclub.sort_menu, vclub.search_box, vclub.other_menu];
    vclub.other_menu.dependency = [vclub.sidebar, vclub.sort_menu, vclub.search_box, vclub.view_menu];

    vclub.hide();

    module.vclub = vclub;
    /* END VCLUB */

    /* Integrate vclub in main menu */
    stb.load(
        {
            "type": "vod",
            "action": "get_categories"
        },
        function (result) {
            _debug('callback categories');

            var categories = result;

            var map = [];

            module.vclub.categories = categories;

            for (var i = 0; i < categories.length; i++) {
                map.push(
                    {
                        "title": categories[i].title,
                        "cmd": (function (category) {


                            return function () {
                                _debug('category', category);

                                if (category.censored) {
                                    module.vclub.parent_password_promt.callback = function () {
                                        main_menu.hide();
                                        module.vclub._show(category);

                                        module.vclub.history.push({
                                            "page": module.vclub.cur_page,
                                            "row": module.vclub.cur_row,
                                            "load_params": module.vclub.load_params
                                        })
                                    };
                                    module.vclub.parent_password_promt.show();
                                } else {
                                    main_menu.hide();
                                    module.vclub._show(category);

                                    module.vclub.history.push({
                                        "page": module.vclub.cur_page,
                                        "row": module.vclub.cur_row,
                                        "load_params": module.vclub.load_params
                                    })
                                }
                            }

                        })(categories[i])
                    }
                );
            }

            if (single_module.indexOf('vclub') != -1) {
                module.vclub.history.push({
                    "page": module.vclub.cur_page,
                    "row": module.vclub.cur_row,
                    "load_params": module.vclub.load_params
                });
            }

            main_menu.add(word['vclub_title'], map, 'mm_ico_video.png', '', module.vclub);

            loader.next();
        },
        this
    )

})();

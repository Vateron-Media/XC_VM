/**
 * Audio Club module.
 */
(function () {

    function audioclub_constructor() {

        this.layer_name = 'audioclub';

        this.row_blocks = ['playing', 'paused', 'name', 'genres', 'seek_bar'];

        this.load_params = {
            'type': 'audioclub',
            'action': 'get_ordered_list',
            'performer_id': 0,
            'genre_id': 0,
            'year_id': 0
        };

        this.history = []; //{page:1, row:2, load_params:{}, header_path:[]}

        this.superclass = ListLayer.prototype;

        this.init = function () {
            _debug('audioclub.init');
            this.superclass.init.call(this);

            this.set_middle_container();

            this.init_modal_windows();

            var self = this;

            stb.player.addCustomEventListener("audiostart", function (item) {
                _debug('audioclub.audiostart', item);

                if (self.on && self.data_items[0].is_track && item.is_track) {

                    var cur_idx = self.data_items.getIdxByVal("id", item.id);

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

                    self.set_active_row(self.cur_row);
                }
            });

            stb.player.addCustomEventListener("audiostop", function (item) {
                _debug('audioclub.audiostop', item);

                if (self.on && self.data_items[0].is_track && item.is_track) {

                    var cur_idx = self.data_items.getIdxByVal("id", item.id);

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

                    window.clearInterval(self.seek_bar_interval);
                    self.set_active_row(self.cur_row);
                }
            });

            stb.player.addCustomEventListener("audiopause", function (item) {
                _debug('audioclub.audiopause', item);

                if (self.on && self.data_items[0].is_track && item.is_track) {

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

            stb.player.addCustomEventListener("audiocontinue", function (item) {
                _debug('audioclub.audiocontinue', item);

                if (self.on && self.data_items[0].is_track && item.is_track) {

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
        };

        this.init_modal_windows = function () {
            _debug('audioclub.init_modal_windows');

            var scope = this;

            /* NEW PLAYLIST DIALOG */
            this.new_playlist_dialog = new ModalForm({ "title": get_word('audioclub_new_playlist') });

            this.new_playlist_dialog.enableOnExitClose();

            this.new_playlist_dialog.addItem(new ModalFormInput({
                "label": get_word('playlist_name') + ':',
                "name": "playlist_name"
            }));

            this.new_playlist_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.new_playlist_dialog.hide();
                    }
                }
            ));

            this.new_playlist_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        var name = scope.new_playlist_dialog.getItemByName("playlist_name").getValue();

                        _debug('name', name);

                        if (name) {
                            stb.load(
                                {
                                    "type": "audioclub",
                                    "action": "create_playlist",
                                    "name": name,
                                    "track_id": scope.new_playlist_dialog.track_id
                                },
                                function (result) {
                                    if (result) {
                                        scope.new_playlist_dialog.hide();
                                    } else {
                                        stb.notice.push(get_word('audioclub_saving_error'));
                                    }
                                },
                                this
                            );
                        }

                    }
                }
            ));

            /* SELECT PLAYLIST DIALOG */
            this.select_playlist_dialog = new ModalForm({ "title": get_word('audioclub_select_playlist') });

            this.select_playlist_dialog.enableOnExitClose();

            this.select_playlist_dialog.addItem(new ModalFormSelect({
                "label": get_word('audioclub_playlist') + ':',
                "name": "playlist_id"
            }));

            this.select_playlist_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.select_playlist_dialog.hide();
                    }
                }
            ));

            this.select_playlist_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        var playlist_id = scope.select_playlist_dialog.getItemByName("playlist_id").getValue();

                        if (playlist_id == -1) {
                            scope.new_playlist_dialog.track_id = scope.select_playlist_dialog.track_id;
                            scope.new_playlist_dialog.show();
                            scope.select_playlist_dialog.hide();
                            return;
                        }

                        stb.load(
                            {
                                "type": "audioclub",
                                "action": "add_track_to_playlist",
                                "playlist_id": playlist_id,
                                "track_id": scope.select_playlist_dialog.track_id
                            },
                            function (result) {
                                if (result) {
                                    scope.select_playlist_dialog.hide();
                                } else {
                                    stb.notice.push(get_word('audioclub_saving_error'));
                                }
                            },
                            this
                        );
                    }
                }
            ));

            /* REMOVE FROM PLAYLIST CONFIRM */
            this.remove_from_playlist_confirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('remove_from_playlist_confirm')
            });

            this.remove_from_playlist_confirm.enableOnExitClose();
            this.remove_from_playlist_confirm.getTextDomObj().style.textAlign = "center";

            this.remove_from_playlist_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.remove_from_playlist_confirm.hide();
                    }
                }
            ));

            this.remove_from_playlist_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        stb.load(
                            {
                                "type": "audioclub",
                                "action": "remove_from_playlist",
                                "playlist_id": scope.load_params['playlist_id'],
                                "track_id": scope.data_items[scope.cur_row].id
                            },
                            function (result) {
                                _debug('remove_from_playlist result');
                                this.load_data();
                            },
                            scope
                        );

                        scope.remove_from_playlist_confirm.hide();
                    }
                }
            ));

            /* DELETE PLAYLIST CONFIRM */
            this.delete_playlist_confirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('delete_playlist_confirm')
            });

            this.delete_playlist_confirm.enableOnExitClose();
            this.delete_playlist_confirm.getTextDomObj().style.textAlign = "center";

            this.delete_playlist_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.delete_playlist_confirm.hide();
                    }
                }
            ));

            this.delete_playlist_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        stb.load(
                            {
                                "type": "audioclub",
                                "action": "delete_playlist",
                                "playlist_id": scope.data_items[scope.cur_row].id
                            },
                            function (result) {
                                _debug('delete_playlist_confirm result');
                                this.load_data();
                            },
                            scope
                        );

                        scope.delete_playlist_confirm.hide();
                    }
                }
            ));

            /* TRACK SEARCH DIALOG */
            this.new_track_search_dialog = new ModalForm({ "title": get_word('track_search') });
            this.new_track_search_dialog.enableOnExitClose();
            this.new_track_search_dialog.addItem(new ModalFormInput(
                {
                    "name": "track_search",
                    "onchange": function () {
                        _debug('track_search.onchange');
                        if (typeof (stb.IsVirtualKeyboardActive) == 'function' && !stb.IsVirtualKeyboardActive()) {
                            return;
                        }
                        scope.track_search();
                    }
                }
            ));
            this.new_track_search_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.new_track_search_dialog.hide();
                    }
                }
            ));

            this.new_track_search_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        _debug('track_search.ok_btn.onclick');
                        scope.track_search();
                    }
                }
            ));

            /* ALBUM SEARCH DIALOG */
            this.new_album_search_dialog = new ModalForm({ "title": get_word('album_search') });
            this.new_album_search_dialog.enableOnExitClose();
            this.new_album_search_dialog.addItem(new ModalFormInput(
                {
                    "name": "album_search",
                    "onchange": function () {
                        if (typeof (stb.IsVirtualKeyboardActive) == 'function' && !stb.IsVirtualKeyboardActive()) {
                            return;
                        }
                        scope.album_search();
                    }
                }
            ));
            this.new_album_search_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.new_album_search_dialog.hide();
                    }
                }
            ));

            this.new_album_search_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.album_search();
                    }
                }
            ));

            this.new_track_search_dialog.addCustomEventListener('hide', function () {
                _debug('track_search_dialog', 'hide');
                scope.enableAllColorButton();
            });

            this.new_track_search_dialog.addCustomEventListener('show', function () {
                _debug('track_search_dialog', 'show');
                scope.disableAllColorButton();
            });

            this.new_album_search_dialog.addCustomEventListener('hide', function () {
                _debug('album_search_dialog', 'hide');
                scope.enableAllColorButton();
            });

            this.new_album_search_dialog.addCustomEventListener('show', function () {
                _debug('album_search_dialog', 'show');
                scope.disableAllColorButton();
            });
        };

        this._show = function (category) {
            _debug('audioclub._show', category);

            this.category = category = category || this.categories && this.categories[0];

            if (!this.category) {
                this.category = category = { "alias": "albums", "title": get_word("audioclub_albums") };
            }

            this.load_params['category'] = category.alias;

            this.update_header_path([{ "alias": "category", "item": category.title }]);

            this.superclass.show.call(this);
        };

        this.hide = function (do_not_reset) {
            _debug('audioclub.hide', do_not_reset);

            this.clear_short_info();

            this.history = [];

            this.update_header_path([
                { "alias": "playlist", "item": '' },
                { "alias": "year", "item": '' },
                { "alias": "genre", "item": '' },
                { "alias": "album", "item": '' },
                { "alias": "performer", "item": '' },
                { "alias": "search_track", "item": '' },
                { "alias": "album_search", "item": '' }
            ]);

            this.new_playlist_dialog.hide();
            this.select_playlist_dialog.hide();
            this.remove_from_playlist_confirm.hide();
            this.new_album_search_dialog.hide();
            this.new_track_search_dialog.hide();

            this.superclass.hide.call(this, do_not_reset);
        };

        this.reset = function () {
            _debug('audioclub.reset');

            this.superclass.reset.call(this);

            window.clearInterval(this.seek_bar_interval);
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.action.bind(key.OK, this);

            (function () {

                if (single_module.indexOf(this.layer_name) != -1) {
                    return;
                }

                this.hide();
                main_menu.show();
            }).bind(key.MENU, this);

            (function () {
            }).bind(key.RIGHT, this);

            this.back.bind(key.EXIT, this).bind(key.LEFT, this);
        };

        this.action = function () {
            _debug('audioclub.action');

            if (this.data_items[this.cur_row].is_track) {

                this.play();
                return;
            }

            this.page_dir = 1;

            if (this.data_items[this.cur_row].is_album && !this.data_items[this.cur_row].is_search_result) {
                this.history.push({
                    "page": this.cur_page,
                    "row": this.cur_row,
                    "load_params": this.load_params.clone(),
                    "header_path": this.header_path_map.clone()
                });
            }

            if (this.data_items[this.cur_row].is_album) {

                this.update_header_path([{ "alias": "performer", "item": '' }, {
                    "alias": "album",
                    "item": this.data_items[this.cur_row].name
                }]);

                this.load_params = {
                    'type': 'audioclub',
                    'action': 'get_track_list',
                    'album_id': this.data_items[this.cur_row].id
                }

            } else if (this.data_items[this.cur_row].is_performer) {
                this.update_header_path([{
                    "alias": "performer",
                    "item": this.data_items[this.cur_row].name
                }, { "alias": "album", "item": '' }]);
                this.load_params['performer_id'] = this.data_items[this.cur_row].id;
                this.load_params['category'] = 'albums';
            } else if (this.data_items[this.cur_row].is_genre) {
                this.update_header_path([{ "alias": "genre", "item": this.data_items[this.cur_row].name }]);
                this.load_params['genre_id'] = this.data_items[this.cur_row].id;
                this.load_params['category'] = 'albums';
            } else if (this.data_items[this.cur_row].is_year) {
                this.update_header_path([{ "alias": "year", "item": this.data_items[this.cur_row].name }]);
                this.load_params['year_id'] = this.data_items[this.cur_row].id;
                this.load_params['category'] = 'albums';
            } else if (this.data_items[this.cur_row].is_playlist) {
                this.update_header_path([{ "alias": "playlist", "item": this.data_items[this.cur_row].name }]);
                this.load_params['playlist_id'] = this.data_items[this.cur_row].id;
                this.load_params['action'] = 'get_track_list';
            } else {
                this.update_header_path([
                    { "alias": "playlist", "item": '' },
                    { "alias": "year", "item": '' },
                    { "alias": "genre", "item": '' },
                    { "alias": "album", "item": '' },
                    { "alias": "performer", "item": '' },
                    { "alias": "search_track", "item": '' },
                    { "alias": "album_search", "item": '' }
                ]);

                if (this.category.alias == 'years') {
                    this.load_params['year_id'] = this.data_items[this.cur_row].id;
                }
            }

            this.cur_page = 1;

            this.load_data();
        };

        this.play = function () {
            _debug('audioclub.play');

            var item = this.data_items[this.cur_row].clone();
            item.number = null;

            stb.load(
                {
                    "type": "audioclub",
                    "action": "get_track_list",
                    "album_id": this.load_params['album_id'],
                    "playlist_id": this.load_params['playlist_id'],
                    "as_playlist": 1
                },
                function (result) {
                    _debug('playlist', result);
                    item.playlist = result.data;
                    stb.player.stop();
                    stb.player.need_show_info = 0;
                    stb.player.play(item);
                }
            );
        };

        this.set_active_row = function (num) {
            _debug('audioclub.set_active_row', num);

            this.superclass.set_active_row.call(this, num);

            if (!this.data_items[this.cur_row]) {
                return;
            }
            _debug('this.data_items[this.cur_row].cmd', this.data_items[this.cur_row].cmd);
            _debug('stb.player.on', stb.player.on);
            _debug('stb.player.cur_media_item', stb.player.cur_media_item);
            _debug('stb.player.file_type', stb.player.file_type);

            if (stb.player.cur_media_item && stb.player.cur_media_item.is_track && this.data_items[this.cur_row].is_track &&
                this.data_items[this.cur_row].id == stb.player.cur_media_item.id && stb.player.on && stb.player.file_type == 'audio') {

                this.active_row['row'].setAttribute("status", "playing");

                this.active_row['row'].addClass("playing");

                if (this.active_row.seek_bar_block.childNodes.length > 0) {
                    this.active_row.seek_bar_block.childNodes[0].style.width = 0;
                }

                this.active_row.seek_bar_block.show();
                this._start_updating_seek_bar();
            } else {
                this.active_row['row'].setAttribute("status", "");
                this.active_row['row'].removeClass("playing");
                this.active_row.seek_bar_block.hide();
                if (this.active_row.seek_bar_block.childNodes.length > 0) {
                    this.active_row.seek_bar_block.childNodes[0].style.width = 0;
                }
                window.clearInterval(this.seek_bar_interval);
            }

            if (this.data_items[this.cur_row].is_track || this.data_items[this.cur_row].is_playlist) {
                this.color_buttons.get('yellow').enable();
            } else {
                this.color_buttons.get('yellow').disable();
            }

            if (this.data_items[this.cur_row].is_playlist) {
                this.color_buttons.get('yellow').text_obj.innerHTML = get_word('audioclub_remove_playlist');
            } else if (this.load_params['playlist_id']) {
                this.color_buttons.get('yellow').text_obj.innerHTML = get_word('remove_from_playlist');
            } else {
                this.color_buttons.get('yellow').text_obj.innerHTML = get_word('add_to_playlist');
            }
        };

        this._start_updating_seek_bar = function () {
            _debug('media_browser._start_updating_seek_bar');

            var self = this;

            window.clearInterval(this.seek_bar_interval);

            this._update_seek_bar();

            this.seek_bar_interval = window.setInterval(function () {
                self._update_seek_bar();
            }, 1000);
        };

        this._update_seek_bar = function () {
            if (this.active_row.seek_bar_block.childNodes.length == 0) {
                var inner = create_block_element("seek_bar_inner", this.active_row.seek_bar_block);
            } else {
                inner = this.active_row.seek_bar_block.childNodes[0];
            }

            var pos_time = stb.GetPosTime();
            var media_len = stb.GetMediaLen();

            _debug('pos_time', pos_time);
            _debug('media_len', media_len);
            _debug('this.active_row.seek_bar_block.offsetWidth', this.active_row.seek_bar_block.offsetWidth);

            var width = this.active_row.seek_bar_block.offsetWidth * pos_time / media_len;

            _debug('width', width);

            if (width > this.active_row.seek_bar_block.offsetWidth) {
                width = this.active_row.seek_bar_block.offsetWidth;
            }

            if (media_len == 0) {
                width = 0;
            }

            _debug('width 2', width);

            inner.style.width = width + 'px';
        };

        this.back = function () {
            _debug('audioclub.back');

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
                { "alias": "playlist", "item": '' },
                { "alias": "year", "item": '' },
                { "alias": "genre", "item": '' },
                { "alias": "album", "item": '' },
                { "alias": "performer", "item": '' },
                { "alias": "search_track", "item": '' },
                { "alias": "album_search", "item": '' }
            ]);

            if (level) {
                this.load_params = level.load_params;

                this.load_params['row'] = level.row;
                this.cur_page = level.page;

                if (level.header_path && level.header_path.length > 0) {
                    this.update_header_path(level.header_path);
                }
            }

            if (level) {
                this.load_data();
            }

            this.load_params['row'] = 0;
        };

        this.init_short_info = function () {
            this.info_box = create_block_element('', this.main_container);

            this.screenshot_box = create_block_element('audioclub_screenshot_box', this.info_box);

            this.short_info_box = create_block_element('audioclub_info_box', this.info_box);
        };

        this.fill_short_info = function (item) {
            _debug('audioclub.fill_short_info');

            if (!item) {
                return;
            }

            var info = '';

            if (item.performer_name) {
                info += '<span>' + get_word('audioclub_performer') + ': </span>' + item.performer_name + '<br>';
            }

            if (item.album_name) {
                info += '<span>' + get_word('audioclub_album') + ': </span>' + item.album_name + '<br>';
            }

            if (item.album_year) {
                info += '<span>' + get_word('audioclub_year') + ': </span>' + item.album_year + '<br>';
            }

            if (item.album_country) {
                info += '<span>' + get_word('audioclub_country') + ': </span>' + item.album_country + '<br>';
            }

            if (item.languages) {
                info += '<span>' + get_word('audioclub_languages') + ': </span>' + item.languages + '<br>';
            }

            if (item.language) {
                info += '<span>' + get_word('audioclub_language') + ': </span>' + item.language + '<br>';
            }

            if (item.tracks) {
                info += '<span>' + get_word('audioclub_tracks') + ': </span>' + item.tracks + '<br>';
            }

            this.short_info_box.innerHTML = info;
            if (item.cover_uri) {
                this.screenshot_box.innerHTML = '<img src="' + item.cover_uri + '">';
            } else {
                this.screenshot_box.innerHTML = '';
            }
        };

        this.clear_short_info = function () {
            _debug('audioclub.clear_short_info');

            this.short_info_box.innerHTML = '';
            this.screenshot_box.innerHTML = '';
        };

        this.shift_row_callback = function (item) {

            window.clearTimeout(this.row_callback_timer);

            var self = this;

            this.row_callback_timer = window.setTimeout(function () {

                self.fill_short_info(item);
            },
                this.row_callback_timeout);
        };

        this.fill_list = function (data) {
            _debug('audioclub.fill_list');

            this.dom_obj.setAttribute('data-place', this.load_params['category'] || "");

            data = data.map(function (item) {

                if (stb.player.cur_media_item && stb.player.cur_media_item.is_track && item.id == stb.player.cur_media_item.id && stb.player.on && item.is_track) {
                    if (stb.player.pause.on) {
                        item.paused = 1;
                    } else {
                        item.playing = 1;
                    }
                }

                return item;
            });

            this.superclass.fill_list.call(this, data);
        };

        this.add_del_to_playlist = function () {
            _debug('audioclub.add_del_to_playlist');
            if (this.data_items[this.cur_row].is_playlist) { // remove playlist confirm

                this.delete_playlist_confirm.show();

            } else if (this.load_params['playlist_id']) { // remove from playlist

                this.remove_from_playlist_confirm.show();

            } else { // add to playlist
                stb.load(
                    {
                        "type": "audioclub",
                        "action": "get_user_playlists"
                    },
                    function (result) {
                        _debug('user_playlists result', result);

                        if (result.length == 0) {
                            this.new_playlist_dialog.track_id = this.data_items[this.cur_row].id;
                            this.new_playlist_dialog.show()
                        } else {

                            var options = result.map(function (playlist) {
                                return {
                                    "value": playlist.id,
                                    "text": playlist.name
                                };
                            });

                            options.unshift({ "value": -1, "text": get_word('audioclub_create_new') });

                            this.select_playlist_dialog.getItemByName('playlist_id').setOptions(options);
                            this.select_playlist_dialog.track_id = this.data_items[this.cur_row].id;
                            this.select_playlist_dialog.show();
                        }
                    },
                    this
                )
            }
        }

        this.track_search_dialog = function () {
            _debug('audioclub.track_search');
            this.new_track_search_dialog._dom_obj.addClass('audioclub_wide_text_input');
            this.new_track_search_dialog.show();

            if (typeof (stb.ShowVirtualKeyboard) == 'function') {
                stb.ShowVirtualKeyboard();
            }
        }

        this.album_search_dialog = function () {
            _debug('audioclub.album_search');
            this.new_album_search_dialog._dom_obj.addClass('audioclub_wide_text_input');
            this.new_album_search_dialog.show();

            if (typeof (stb.ShowVirtualKeyboard) == 'function') {
                stb.ShowVirtualKeyboard();
            }

        }

        this.track_search = function () {
            var search_str = this.new_track_search_dialog.getItemByName("track_search").getValue();
            if (search_str.length == 0) {
                return;
            }
            _debug('track_search: search_str = ', search_str);
            if (typeof (stb.HideVirtualKeyboard) == 'function') {
                stb.HideVirtualKeyboard();
            }

            this.new_track_search_dialog.hide();

            if (search_str) {
                this.history.push({
                    "page": this.cur_page,
                    "row": this.cur_row,
                    "load_params": this.load_params.clone(),
                    "header_path": this.header_path_map.clone()
                });
                this.update_header_path([{ "alias": "album_search", "item": '' }]);
                this.update_header_path([{ "alias": "search_track", "item": get_word('track_search') }]);

                this.load_params = {
                    'type': 'audioclub',
                    'action': 'track_search',
                    'search_str': search_str
                }
                this.cur_page = 1;
                this.load_data();
            }
        }

        this.album_search = function () {

            var search_str = this.new_album_search_dialog.getItemByName("album_search").getValue();
            if (search_str.length == 0) {
                return;
            }
            _debug('album_search: search_str = ', search_str);
            if (typeof (stb.HideVirtualKeyboard) == 'function') {
                stb.HideVirtualKeyboard();
            }

            this.new_album_search_dialog.hide();

            if (search_str) {
                this.history.push({
                    "page": this.cur_page,
                    "row": this.cur_row,
                    "load_params": this.load_params.clone(),
                    "header_path": this.header_path_map.clone()
                });
                this.update_header_path([{ "alias": "search_track", "item": '' }]);
                this.update_header_path([{ "alias": "album_search", "item": get_word('album_search') }]);

                this.load_params = {
                    'type': 'audioclub',
                    'action': 'album_search',
                    'search_str': search_str
                }
                this.cur_page = 1;
                this.load_data();
            }

        }

        this.disableAllColorButton = function () {
            this.color_buttons.get('red').disable();
            this.color_buttons.get('green').disable();
            /*            this.color_buttons.get('yellow').disable();
                        this.color_buttons.get('blue').disable();*/
        }

        this.enableAllColorButton = function () {
            this.color_buttons.get('red').enable();
            this.color_buttons.get('green').enable();
            /*            this.color_buttons.get('yellow').enable();
                        this.color_buttons.get('blue').disable();*/
        }
    }

    audioclub_constructor.prototype = new ListLayer();

    var audioclub = new audioclub_constructor();

    if (single_module.indexOf('audioclub') == -1) {
        audioclub.init_left_ear(word['ears_back']);
    }

    audioclub.init_color_buttons([
        { "label": get_word('track_search'), "cmd": audioclub.track_search_dialog },
        { "label": get_word('album_search'), "cmd": audioclub.album_search_dialog },
        { "label": get_word('add_to_playlist'), "cmd": audioclub.add_del_to_playlist },
        { "label": ''/*get_word('playlist')*/, "cmd": audioclub.playlist }
    ]);

    audioclub.color_buttons.buttons_bar.addClass('audioclub_buttons_bar');

    audioclub.bind();

    audioclub.init();

    audioclub.init_short_info();

    audioclub.init_header_path(get_word('audioclub_title'));

    audioclub.hide();

    module.audioclub = audioclub;

    stb.load(
        {
            "type": "audioclub",
            "action": "get_categories"
        },
        function (categories) {
            _debug('callback categories');

            var map = [];

            module.audioclub.categories = categories;

            for (var i = 0; i < categories.length; i++) {
                map.push(
                    {
                        "title": categories[i].title,
                        "cmd": (function (category) {

                            return function () {
                                _debug('alias', category.alias);

                                main_menu.hide();
                                module.audioclub._show(category);

                                module.audioclub.history.push({
                                    "page": module.audioclub.cur_page,
                                    "row": module.audioclub.cur_row,
                                    "load_params": module.audioclub.load_params
                                })
                            }

                        })(categories[i])
                    }
                );
            }

            if (single_module.indexOf('audioclub') != -1) {
                module.audioclub.history.push({
                    "page": module.audioclub.cur_page,
                    "row": module.audioclub.cur_row,
                    "load_params": module.audioclub.load_params
                });
            }

            main_menu.add(get_word('audioclub_title'), map, 'mm_ico_audio.png', '', module.audioclub);

            loader.next();
        }
    );
})();
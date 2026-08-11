(function () {

    module.radio_widget = {

        on: false,
        widget_on: false,
        get_result: { data: [] },

        init: function () {
            _debug('radio_widget.init');

            this.dom_obj = create_block_element('radio_widget_block', main_menu.dom_obj);

            this.title_obj = create_block_element('title', this.dom_obj);

            this.items_obj = create_block_element('items', this.dom_obj);

            this.buttons_block_obj = create_block_element('buttons_block', this.dom_obj);

            this.prev_btn = create_block_element('prev_btn', this.buttons_block_obj);
            this.next_btn = create_block_element('next_btn', this.buttons_block_obj);
            this.play_btn = create_block_element('play_btn', this.buttons_block_obj);
            this.pause_btn = create_block_element('pause_btn', this.buttons_block_obj);

            var self = this;

            stb.player.addCustomEventListener("radiostart", function (item) {
                if (item.radio) {
                    _debug('radio_widget.radiostart');
                    self.show(item);
                }
            });

            stb.player.addCustomEventListener("radiostop", function (item) {
                if (self.on || self.widget_on) {
                    _debug('radio_widget.radiostop');
                    self.hide();
                }
            });

            stb.player.addCustomEventListener("radiopause", function (item) {
                if (item.radio) {
                    _debug('radio_widget.radiopause');
                    self.show(item);
                    self.pause_btn.hide();
                    self.play_btn.show();
                }
            });

            stb.player.addCustomEventListener("radiocontinue", function (item) {
                if (item.radio) {
                    _debug('radio_widget.radiocontinue');
                    self.show(item);
                    self.play_btn.hide();
                    self.pause_btn.show();
                }
            });

            main_menu.addCustomEventListener("mainmenushow", function (layer_name) {
                if (layer_name != 'radio') {
                    return;
                }
                _debug('radio_widget.mainmenushow');

                self.on = self.widget_on;
            });

            main_menu.addCustomEventListener("mainmenuhide", function () {
                _debug('radio_widget.mainmenuhide');

                self.on = false;
            });

            this.set_radio_widget_items_list();

            this.hide();
        },

        show: function (item) {
            _debug('radio_widget.show', item);

            this.title_obj.innerHTML = item.name;
            this._update_cur_items();
            this.dom_obj.show();
            this.widget_on = true;

            if (main_menu.on) {
                this.on = true;
            }
            this.play_btn.hide();
            this.pause_btn.show();
        },

        hide: function () {
            _debug('radio_widget.hide');
            this.dom_obj.hide();
            this.widget_on = this.on = false;
        },

        bind: function () {
            _debug('radio_widget.bind');
            var self = this;
            (function (dir) {

                _debug('dir', dir);

                var idx = self._get_current_idx();

                _debug('playlist idx', idx);

                if (idx >= 0 && idx <= self._get_total_playlist_items() - 1) {

                    idx = idx + dir;

                    if (!self.get_result.data[idx]) {
                        return;
                    }
                    try {
                        if (typeof (self.get_result.data[idx]) == 'object') {
                            var cur_media_item = self.get_result.data[idx].clone();
                            if (cur_media_item.page != module.radio.cur_page) {
                                module.radio.cur_page = cur_media_item.page;
                                module.radio.load_params.p = cur_media_item.page;
                                module.radio.load_data();
                            }
                            stb.player.radio_idx = idx - (cur_media_item.page - 1) * self.get_result.max_page_items;
                            stb.player.cur_media_item = cur_media_item;
                            module.radio.cur_row = stb.player.radio_idx;
                            stb.player.cur_media_item.playing = module.radio.data_items[stb.player.radio_idx].playing = 1;
                            stb.player.cur_media_item.paused = module.radio.data_items[stb.player.radio_idx].paused = 0;
                            stb.player.play(cur_media_item);
                        }
                    } catch (e) {
                        _debug(e);
                        stb.player.stop();
                    }
                }

            }).bind(key.NEXT, this, 1).bind(key.PREV, this, -1).bind(key.CHANNEL_NEXT, this, 1).bind(key.CHANNEL_PREV, this, -1);

        },

        _update_cur_items: function () {

            var cur_idx = this._get_current_idx();
            var total_items = this._get_total_playlist_items();

            this.items_obj.innerHTML = '[' + (cur_idx + 1) + '/' + total_items + ']';

            if (cur_idx < total_items - 1) {
                this.next_btn.show();
            } else {
                this.next_btn.hide();
            }

            if (cur_idx > 0) {
                this.prev_btn.show();
            } else {
                this.prev_btn.hide();
            }
        },

        _get_current_idx: function () {
            _debug('radio_widget._get_current_idx');
            return this.get_result.data.getIdxByVal("id", stb.player.cur_media_item.id);
        },

        _get_total_playlist_items: function () {
            _debug('radio_widget._get_total_playlist_items');
            return this.get_result.data.length || 0;
        },

        shift_playlist: function (dir) {
            _debug('radio_widget.shift_playlist', dir);
        },

        set_radio_widget_items_list: function () {
            _debug('set_radio_widget_items_list');
            var load_params = (module.radio && module.radio.load_params) ? module.radio.load_params : {
                'type': 'radio',
                'action': 'get_ordered_list'
            };
            load_params.all = 1;
            _debug('load_params', load_params);
            stb.load(
                load_params,
                function (result) {
                    this.get_result = result;
                },
                this
            );
        }

    };

    module.radio_widget.init();
    module.radio_widget.bind();

})();

loader.next();
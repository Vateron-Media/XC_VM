(function () {

    module.audio_widget = {

        on: false,
        widget_on: false,

        init: function () {
            _debug('audio_widget.init');

            this.dom_obj = create_block_element('audio_widget_block', main_menu.dom_obj);

            this.title_obj = create_block_element('title', this.dom_obj);

            this.items_obj = create_block_element('items', this.dom_obj);

            this.seek_bar_obj = create_block_element('seek_bar', this.dom_obj);
            this.seek_inner_obj = create_block_element('inner', this.seek_bar_obj);

            this.buttons_block_obj = create_block_element('buttons_block', this.dom_obj);

            this.prev_btn = create_block_element('prev_btn', this.buttons_block_obj);
            this.next_btn = create_block_element('next_btn', this.buttons_block_obj);
            this.play_btn = create_block_element('play_btn', this.buttons_block_obj);
            this.pause_btn = create_block_element('pause_btn', this.buttons_block_obj);

            var self = this;

            stb.player.addCustomEventListener("audiostart", function (item) {
                if (!item.is_track) {
                    return;
                }
                _debug('audio_widget.audiostart');
                self.show(item);
            });

            stb.player.addCustomEventListener("audiostop", function (item) {
                _debug('audio_widget.audiostop');
                self.hide();
            });

            stb.player.addCustomEventListener("audiopause", function (item) {
                _debug('audio_widget.audiopause');

                self.pause_btn.hide();
                self.play_btn.show();
            });

            stb.player.addCustomEventListener("audiocontinue", function (item) {
                _debug('audio_widget.audiopause');

                self.play_btn.hide();
                self.pause_btn.show();
            });

            main_menu.addCustomEventListener("mainmenushow", function (layer_name) {
                if (layer_name != 'audioclub' && layer_name != 'media_browser') {
                    return;
                }
                _debug('audio_widget.mainmenushow');

                self.on = self.widget_on;

                if (self.on) {
                    self._start_updating_seek_bar();
                }
            });

            main_menu.addCustomEventListener("mainmenuhide", function () {
                _debug('audio_widget.mainmenuhide');

                self.on = false;
                window.clearInterval(self.seek_bar_interval);
            });

            this.hide();
        },

        show: function (item) {
            if (!item.is_track) {
                return;
            }
            _debug('audio_widget.show', item);

            this.title_obj.innerHTML = item.name;
            this._update_cur_items();
            this.dom_obj.show();
            this.widget_on = true;

            if (main_menu.on) {
                this.on = true;

                this._start_updating_seek_bar();
            }

            this.play_btn.hide();
            this.pause_btn.show();
        },

        hide: function () {
            _debug('audio_widget.hide');
            this.dom_obj.hide();
            this.widget_on = this.on = false;
            window.clearInterval(this.seek_bar_interval);
        },

        bind: function () {
            _debug('audio_widget.bind');

            (function (dir) {

                _debug('dir', dir);

                var idx = this._get_current_idx();

                _debug('playlist idx', idx);

                if (idx >= 0 && idx <= stb.player.cur_media_item.playlist.length - 1) {

                    idx = idx + dir;

                    if (!stb.player.cur_media_item.playlist[idx]) {
                        return;
                    }

                    if (typeof (stb.player.cur_media_item.playlist[0]) == 'object') {
                        cur_media_item = stb.player.cur_media_item.playlist[idx].clone();
                        cur_media_item.playlist = stb.player.cur_media_item.playlist;
                        if (cur_media_item.is_audio) {
                            cur_media_item.number = null;
                        }
                    } else {
                        var cur_media_item = stb.player.cur_media_item.clone();

                        cur_media_item.cmd = cur_media_item.playlist[idx];

                        cur_media_item.name = cur_media_item.cmd.substr(stb.player.cur_media_item.cmd.lastIndexOf("/") + 1);
                    }

                    stb.player.play(cur_media_item);
                }

            }).bind(key.NEXT, this, 1).bind(key.PREV, this, -1);

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
            _debug('audio_widget._get_current_idx');

            _debug('stb.player.cur_media_item', stb.player.cur_media_item)

            if (typeof (stb.player.cur_media_item.playlist[0]) == 'object') {

                var idx = -1;

                for (var i = 0; i < stb.player.cur_media_item.playlist.length; i++) {
                    if (stb.player.cur_media_item.id == stb.player.cur_media_item.playlist[i].id) {
                        idx = i;
                        break;
                    }
                }
            } else {
                idx = stb.player.cur_media_item.playlist.indexOf(stb.player.cur_media_item.cmd);
            }

            return idx;
        },

        _get_total_playlist_items: function () {
            _debug('audio_widget._get_total_playlist_items');

            return stb.player.cur_media_item.playlist.length || 0;
        },

        shift_playlist: function (dir) {
            _debug('audio_widget.shift_playlist', dir);
        },

        _start_updating_seek_bar: function () {
            _debug('audio_widget._start_updating_seek_bar');

            var self = this;

            window.clearInterval(this.seek_bar_interval);

            this._update_seek_bar();

            this.seek_bar_interval = window.setInterval(function () {
                self._update_seek_bar();
            }, 1000);
        },

        _update_seek_bar: function () {

            var pos_time = stb.GetPosTime();
            var media_len = stb.GetMediaLen();

            _debug('pos_time', pos_time);
            _debug('media_len', media_len);
            _debug('this.seek_bar_obj.offsetWidth', this.seek_bar_obj.offsetWidth);

            var width = this.seek_bar_obj.offsetWidth * pos_time / media_len;

            _debug('width', width);

            if (width > this.seek_bar_obj.offsetWidth) {
                width = this.seek_bar_obj.offsetWidth;
            }

            _debug('width 2', width);

            this.seek_inner_obj.style.width = width + 'px';
        }

    };

    module.audio_widget.init();
    module.audio_widget.bind();

})();

loader.next();
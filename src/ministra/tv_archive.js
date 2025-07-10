/**
 * TV Archive module
 */

(function () {

    module.tv_archive = {

        cur_url: '',

        play: function (download, download_callback) {
            _debug('tv_archive.play');

            this.get_item().cmd = 'auto /media/' + this.get_item().id + '.ts';

            if (!this.get_item().hasOwnProperty('o_name')) {
                this.get_item().o_name = this.get_item().name;
            }

            this.get_item().name = this.get_channel_name() + ' — ' + this.get_item().o_name;

            var self = this;

            stb.player.on_create_link = function (result) {
                _debug('epg_simple.play_from_archive.on_create_link', result);

                if (result.error == 'limit') {
                    stb.notice.show(word['player_limit_notice']);
                } else if (result.error == 'nothing_to_play') {
                    stb.notice.show(word['player_file_missing']);
                } else if (result.error == 'link_fault') {
                    stb.notice.show(word['player_server_error']);
                } else {

                    if (!download) {

                        self.parent.hide(true);

                        stb.player.prev_layer = self.parent;
                        stb.player.need_show_info = 1;
                        module.tv_archive.cur_url = result.cmd;
                        _debug('self.cur_url', self.cur_url);
                        _debug('module.tv_archive.cur_url', module.tv_archive.cur_url);
                        stb.player.cur_media_item.cmd = result.cmd;
                        stb.player.play_now(result.cmd);
                    } else {
                        var url = /(http:\/\/[^\s]*)/.exec(result.download_cmd)[1];
                        //self.add_download.call(self, url, result.to_file);

                        download_callback && download_callback(url, result.to_file);
                        stb.player.on = false;
                    }
                }
            };

            var item = this.get_item().clone();

            if (download) {
                item.download = true;
            }

            stb.player.play(item);
        },

        get_part_by_pos: function (new_pos) {
            _debug('tv_archive.get_part_by_pos', new_pos);

            var start_date = new Date(parseInt(stb.player.cur_media_item.start_timestamp, 10) * 1000);

            /*start_date.setMinutes(0);
            start_date.setSeconds(0);*/

            var start_date_ts = start_date.getTime() / 1000;

            _debug('start_date_ts', start_date_ts);

            //var new_file_ts = new_pos + start_date_ts + parseInt(stb.player.cur_media_item.position, 10);
            //var new_file_ts = new_pos + start_date.getTime()/1000 + parseInt(stb.player.cur_media_item.position, 10);
            var new_file_ts = new_pos + start_date_ts;

            _debug('new_file_ts', new_file_ts);

            var new_file_date = new Date(new_file_ts * 1000);
            new_file_date.setMinutes(0);
            new_file_date.setSeconds(0);

            var new_file_name = this.get_filename_by_date(new_file_date);
            _debug('new_file_name', new_file_name);

            start_date.setMinutes(0);
            start_date.setSeconds(0);

            var diff_s = new_file_date.getTime() / 1000 - start_date.getTime() / 1000;
            _debug('diff_s', diff_s);

            var file_piece_num = Math.floor(diff_s / 3600);

            _debug('file_piece_num', file_piece_num);

            //var position = new_pos + (stb.player.cur_media_item.position ? parseInt(stb.player.cur_media_item.position, 10) : 0) - file_piece_num*3600;
            var position = new_pos + this.get_start_position() - file_piece_num * 3600;

            _debug('position', position);

            _debug('this.get_filename_by_date(this._get_file_date_by_url(stb.player.cur_media_item.cmd))', this.get_filename_by_date(this._get_file_date_by_url(stb.player.cur_media_item.cmd)));

            if (this.get_filename_by_date(this._get_file_date_by_url(stb.player.cur_media_item.cmd)) == new_file_name) {
                return false;
            }

            _debug('url 1', url);

            var url = stb.player.cur_media_item.cmd.replace(/([^\/]*)\.ts/, new_file_name).trim();

            _debug('url 2', url);

            if (!/position:(\d*)/.exec(url)) {
                url += ' position:' + position;
            } else {
                url = url.replace(/position:(\d*)/, 'position:' + position).trim();
            }

            _debug('stb.player.cur_media_item.cmd', stb.player.cur_media_item.cmd);
            _debug('url', url);

            return url;
        },

        get_next_part: function () {
            _debug('tv_archive.get_next_part');

            var cur_file_date = this._get_file_date_by_url(stb.player.cur_media_item.cmd);
            var cur_file_date_ts = cur_file_date.getTime();

            _debug('cur_file_date_ts', cur_file_date_ts);

            var next_file_date = new Date(cur_file_date_ts + 60 * 60 * 1000);

            var next_file_name = this.get_filename_by_date(next_file_date);

            _debug('next_file_name', next_file_name);

            var url = stb.player.cur_media_item.cmd.replace(/([^\/]*)\.ts/, next_file_name).replace(/position:(\d*)/, '').trim();

            _debug('stb.player.cur_media_item.cmd', stb.player.cur_media_item.cmd);
            _debug('url', url);

            return url;
        },

        get_filename_by_date: function (date) {
            _debug('tv_archive.get_filename_by_date', date.toString());

            _debug('stb.player.cur_tv_item', stb.player.cur_tv_item);

            return date.getFullYear() + ''
                + this.format_date(date.getMonth() + 1) + ''
                + this.format_date(date.getDate()) + '-'
                + this.format_date(date.getHours())
                + (parseInt(stb.player.cur_tv_item['wowza_dvr'], 10) ? '.mp4' : '.ts');
        },

        get_file_piece_num: function () {
            _debug('tv_archive.get_file_piece_num');

            var cur_file_date = this._get_file_date_by_url(stb.player.cur_media_item.cmd);
            var cur_file_ts = cur_file_date.getTime() / 1000;

            //var diff_s = cur_file_ts - stb.player.cur_media_item.start_timestamp;

            var start_date = new Date(parseInt(stb.player.cur_media_item.start_timestamp, 10) * 1000);
            start_date.setMinutes(0);
            start_date.setSeconds(0);

            var diff_s = cur_file_ts - start_date.getTime() / 1000;

            _debug('start_date', start_date);
            _debug('start_date.getTime()/1000', start_date.getTime() / 1000);
            _debug('diff_s', diff_s);

            var file_piece_num = Math.floor(diff_s / 3600);

            _debug('file_piece_num', file_piece_num);

            return file_piece_num;
        },

        _get_file_date_by_url: function (url) {
            _debug('tv_archive._get_file_date_by_url', url);

            var date_part = /([^\/]*)\.ts/.exec(url);

            _debug('date_part', date_part);

            if (!date_part) {
                return false;
            }

            var file_date_str = date_part[1];
            var true_file_date = file_date_str.replace(/(\d{4})(\d{2})(\d{2})-(\d{2})/, '$2/$3/$1 $4:00:00');

            if (!true_file_date) {
                return false;
            }

            _debug('true_file_date', true_file_date);

            return new Date(true_file_date);
        },

        get_start_position: function () {
            _debug('tv_archive.get_start_position');

            var start_file_date = new Date(parseInt(stb.player.cur_media_item.start_timestamp, 10) * 1000);
            var start_file_position = start_file_date.getMinutes() * 60;

            _debug('start_file_position', start_file_position);

            return start_file_position;
        },

        get_pos_time: function () {
            _debug('tv_archive.get_pos_time');

            var current_pos_time = stb.GetPosTime();

            _debug('current_pos_time', current_pos_time);

            /*if (stb.player.cur_media_item['wowza_dvr']){
                var file_num = 0;
            }else{*/
            file_num = this.get_file_piece_num();
            /*}*/

            _debug('file_num', file_num);

            if (file_num == 0) {
                var pos_time = current_pos_time - this.get_start_position();
            } else {
                pos_time = current_pos_time + (3600 - this.get_start_position()) + (file_num - 1) * 3600;
            }

            _debug('pos_time', pos_time);

            return pos_time;
        },

        format_date: function (param) {
            if (param < 10) {
                return '0' + param
            }
            return param
        },

        get_item: function () {
            _debug('tv_archive.get_item');

            delete this.parent.data_items[this.parent.cur_row].open;

            return this.parent.data_items[this.parent.cur_row];
        },

        get_channel_name: function () {
            _debug('tv_archive.get_channel_name');

            return this.parent.ch_name;
        },

        get_next_part_url: function () {
            _debug('tv_archive.get_next_part_url');

            stb.load(
                {
                    "type": "tv_archive",
                    "action": "get_next_part_url",
                    "id": stb.player.cur_media_item.real_id
                },
                function (result) {
                    _debug('on get_next_part_url', result);

                    if (!result) {
                        _debug('no url');
                        return;
                    }

                    stb.player.cur_media_item.playlist = [stb.player.cur_media_item.cmd, result];

                    _debug('stb.player.cur_media_item', stb.player.cur_media_item);
                }
            )
        },

        init_continue_dialog: function () {
            _debug('tv_archive.init_continue_dialog');

            if (this.continue_dialog) {
                return;
            }

            module.tv.hide_player();

            this.continue_dialog = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('archive_continue_playing_text')
            });
            this.continue_dialog.getTextDomObj().style.textAlign = "center";
            this.continue_dialog.enableOnExitClose();

            var continue_dialog = module.tv_archive.continue_dialog;

            this.continue_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("archive_yes"),
                    "onclick": function () {
                        module.tv.show_player();
                        continue_dialog.hide();
                        module.tv_archive.get_next_part_url();
                    }
                }
            ));

            this.continue_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("archive_no"),
                    "onclick": function () {
                        module.tv.show_player();
                        continue_dialog.hide();
                    }
                }
            ));

            stb.player.addCustomEventListener('stop', function () {
                if (continue_dialog.on) {
                    module.tv.show_player();
                    continue_dialog.hide();
                }
            });
        }

        /*,

        get_link_for_channel : function(ch_id){
            _debug('tv_archive.get_link_for_channel', ch_id);

            stb.load(
                {
                    "type"   : "tv_archive",
                    "action" : "get_link_for_channel",
                    "ch_id"  : ch_id
                },
                function(result){
                    _debug('get_link_for_channel result', result);

                    this.plt_link = result;
                },
                this
            )
        }*/
    }

})();

loader.next();
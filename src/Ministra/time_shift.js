/**
 * TV Time shifting module
 */

(function () {

    module.time_shift = {

        cur_media_item: {},
        in_process: false,

        get_link_for_channel: function () {
            _debug('time_shift.get_link_for_channel');

            this.in_process = true;

            stb.load(
                {
                    "type": "tv_archive",
                    "action": "get_link_for_channel",
                    "ch_id": this.cur_media_item.id
                },
                function (result) {
                    _debug('get_link_for_channel result', result);

                    result = result || {};

                    if (result.error) {

                        if (result.error == 'limit') {
                            stb.notice.show(word['player_limit_notice']);
                        } else if (result.error == 'server_error') {
                            stb.notice.show(word['player_server_error']);
                        }

                        stb.player.cur_media_item = module.time_shift.stored_media_item;
                        stb.player.cur_tv_item = this.cur_media_item;
                        stb.player.active_time_shift = false;
                        stb.player.play_last();
                        stb.player.diff_pos = 0;
                        return;
                    }

                    this.in_process = false;

                    this.plt_link = result.cmd;
                    this.cur_media_item.cmd = result.cmd;

                    if (this.program instanceof Array && this.program.length > 0) {
                        this.on_epg_and_link_available && this.on_epg_and_link_available();
                        this.on_epg_and_link_available = null;
                    }
                },
                this
            )
        },

        get_cur_media_length: function () {
            _debug('time_shift.get_cur_media_length');

            var now = new Date();

            //_debug('now.getTime()', now.getTime());
            //_debug('live_date', this.cur_media_item.live_date);

            ///var live_date = new Date(this.cur_media_item.live_date);

            //_debug('live_date.getTime()', live_date.getTime());

            _debug('this.cur_media_item[wowza_dvr]', this.cur_media_item['wowza_dvr']);

            /*if (this.cur_media_item['wowza_dvr'] == 1){
                
                var cur_piece_date = new Date();
                var len = stb.GetMediaLen();
                var cur_pos_time = stb.GetPosTime();
                _debug('media_len', len);
                _debug('cur_pos_time', cur_pos_time);

                cur_piece_date.setSeconds(cur_piece_date.getSeconds() - len + cur_pos_time);
                
            }else{*/
            var cur_piece_date = new Date(this.cur_piece_date);
            /*}*/

            _debug('this.cur_piece_date', this.cur_piece_date);
            _debug('typeof(this.cur_piece_date)', typeof (this.cur_piece_date));
            _debug('cur_piece_date', cur_piece_date);
            _debug('typeof(cur_piece_date)', typeof (cur_piece_date));
            _debug('cur_piece_date.getTime()', cur_piece_date.getTime());

            var live_date = cur_piece_date.getYear() + '-' + cur_piece_date.getMonth() + '-' + cur_piece_date.getDate();

            var now_date = now.getYear() + '-' + now.getMonth() + '-' + now.getDate();

            _debug('live_date', live_date);
            _debug('now_date', now_date);

            _debug('now', now);

            if (live_date == now_date) {
                var media_len = now.getHours() * 3600 + now.getMinutes() * 60 + now.getSeconds();
                if (media_len > 5) {
                    media_len = media_len - 5;
                }
            } else {
                media_len = 86400;
            }

            _debug('media_len', media_len);

            return media_len
        },

        get_pos_time: function () {
            _debug('time_shift.get_pos_time');

            _debug('stb.player.cur_media_item.cmd', stb.player.cur_media_item.cmd);

            if (/([^\/]*)\.ts/.exec(stb.player.cur_media_item.cmd) || this.cur_media_item['wowza_dvr'] == 1 || this.cur_media_item['flussonic_dvr'] == 1) {

                _debug('stb.player.play_initiated', stb.player.play_initiated);

                if (stb.player.play_initiated) {
                    var position_part = /position:(\d*)/.exec(stb.player.cur_media_item.cmd);

                    if (position_part) {
                        var current_pos_time = parseInt(position_part[1], 10);
                    } else {
                        current_pos_time = 0;
                    }

                    _debug('current_pos_time 1', current_pos_time);

                } else {
                    current_pos_time = stb.GetPosTime();
                    _debug('current_pos_time 2', current_pos_time);
                }

                /*}else if (this.cur_media_item['wowza_dvr'] == 1){

                    var cur_time = new Date();
                    var media_len = stb.GetMediaLen();
                    var cur_pos_time = stb.GetPosTime();
                    _debug('media_len', media_len);
                    _debug('cur_pos_time', cur_pos_time);

                    cur_time.setSeconds(cur_time.getSeconds() - media_len + cur_pos_time);

                    pos_time = cur_time.getHours() * 3600 + cur_time.getMinutes() * 60 + cur_time.getSeconds();

                    _debug('pos_time', pos_time);

                    return pos_time;*/

            } else {
                var now = new Date();
                current_pos_time = now.getMinutes() * 60 + now.getSeconds();
                _debug('current_pos_time 3', current_pos_time);
            }

            //var current_pos_time = stb.GetPosTime();

            _debug('current_pos_time', current_pos_time);

            if (this.cur_media_item['wowza_dvr'] == 1) {
                var cur_file_date = this._get_wowza_playlist_start_date_by_url(this.cur_media_item.cmd);
            } else if (this.cur_media_item['flussonic_dvr'] == 1) {
                cur_file_date = this._get_flussonic_playlist_start_date_by_url(this.cur_media_item.cmd);
            } else {
                cur_file_date = this._get_file_date_by_url(this.cur_media_item.cmd);
            }

            _debug('cur_file_date', cur_file_date);

            var pos_time = cur_file_date.getHours() * 3600 + current_pos_time;

            _debug('pos_time', pos_time);

            return pos_time;
        },

        _get_wowza_playlist_start_date_by_url: function (url) {
            _debug('time_shift._get_wowza_playlist_start_date_by_url', url);

            var date_part = /wowzadvrplayliststart=(\d+)/.exec(url);

            _debug('date_part', date_part);

            if (!date_part) {
                return new Date();
            }

            var file_date_str = date_part[1];
            var true_file_date = file_date_str.replace(/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})/, '$2/$3/$1 $4:00:00 GMT');

            if (!true_file_date) {
                return false;
            }

            _debug('true_file_date', true_file_date);

            return new Date(true_file_date);
        },

        _get_flussonic_playlist_start_date_by_url: function (url) {
            _debug('time_shift._get_flussonic_playlist_start_date_by_url', url);

            if (url.indexOf('/mpegts') != -1) {
                var date_part = /\/(\d{10})\//.exec(url);
            } else {
                date_part = /-(\d{10})-/.exec(url);
            }


            _debug('date_part', date_part);

            if (!date_part) {
                return new Date();
            }

            var file_date_str = date_part[1];

            _debug('file_date_str', file_date_str);

            return new Date(file_date_str * 1000);
        },

        _get_file_date_by_url: function (url) {
            _debug('time_shift._get_file_date_by_url', url);

            var date_part = /([^\/]*)\.ts/.exec(url);

            _debug('date_part', date_part);

            if (!date_part) {
                //return false;
                //return new Date(new Date().getTime() - stb.profile['timezone_diff']*1000);
                return new Date();
            }

            var file_date_str = date_part[1];
            var true_file_date = file_date_str.replace(/(\d{4})(\d{2})(\d{2})-(\d{2})/, '$2/$3/$1 $4:00:00');

            if (!true_file_date) {
                return false;
            }

            _debug('true_file_date', true_file_date);

            return new Date(new Date(true_file_date).getTime() - stb.profile['timezone_diff'] * 1000);
        },

        set_media_item: function (cur_tv_item) {
            _debug('time_shift.set_media_item', cur_tv_item);

            this.program = [];
            this.on_epg_and_link_available = null;
            this.cur_program = undefined;

            this.stored_media_item = cur_tv_item.clone();

            this.cur_media_item = cur_tv_item.clone();
            delete this.cur_media_item.open;
            this.cur_media_item.use_http_tmp_link = 0;
            this.cur_media_item.live_date = new Date();
            this.cur_piece_date = new Date();
            this.cur_piece_date.setHours(0);
            this.cur_piece_date.setMinutes(0);
            this.cur_piece_date.setSeconds(0);

            _debug('this.cur_piece_date.getTime()', this.cur_piece_date.getTime());

            _debug('this.cur_media_item', this.cur_media_item);
            _debug('this.cur_media_item.live_date.getTime()', this.cur_media_item.live_date.getTime());

            this.get_program(this.cur_media_item.id);
        },

        get_program: function (ch_id) {
            _debug('time_shift.get_program', ch_id);

            stb.load(
                {
                    "type": "epg",
                    "action": "get_all_program_for_ch",
                    "ch_id": ch_id
                },
                function (result) {
                    _debug('time_shift.get_program result', result);

                    this.program = result || [];

                    var now_ts = new Date().getTime() / 1000;

                    for (var i = 0; i < this.program.length; i++) {
                        if (this.program[i].start_timestamp < now_ts && this.program[i].stop_timestamp > now_ts) {
                            this.cur_program = this.program[i];
                            _debug('this.cur_program', this.cur_program);
                            break;
                        }
                    }

                    if (!this.in_process) {
                        this.on_epg_and_link_available && this.on_epg_and_link_available();
                        this.on_epg_and_link_available = null;
                    }
                },
                this
            );
        },

        get_program_name_by_pos: function (pos) {
            _debug('time_shift.get_program_name_by_pos', pos);

            this.program = this.program || [];

            var pos_time = new Date(this.cur_piece_date);
            pos_time.setSeconds(pos);

            var pos_timestamp = pos_time.getTime() / 1000;

            _debug('pos_timestamp', pos_timestamp);
            //_debug('this.program', this.program);

            for (var i = 0; i < this.program.length - 1; i++) {

                //_debug('this.program[i].start_timestamp', this.program[i].start_timestamp);

                if (parseInt(this.program[i].start_timestamp, 10) > pos_timestamp) {

                    if (this.program[i - 1]) {
                        _debug('this.program[i-1]', this.program[i - 1]);
                        return this.program[i - 1].name;
                    }
                }
            }

            return '';
        },

        update_media_item: function (url) {
            _debug('time_shift.update_media_item', url);

            this.cur_media_item.cmd = url;
        },

        get_current_date: function () {
            _debug('time_shift.get_current_date');

            //var file_date = this._get_file_date_by_url(this.cur_media_item.cmd);
            if (stb.player.pause.on) {
                //var file_date = this.cur_media_item.live_date;
                //var file_date = this.cur_piece_date.clone();
                var file_date = new Date(this.cur_piece_date);
                _debug('stb.player.new_pos_time', stb.player.new_pos_time);
                file_date.setSeconds(stb.player.new_pos_time + file_date.getSeconds());
            } else {
                file_date = this._get_file_date_by_url(this.cur_media_item.cmd);
            }

            var cur_date = file_date.getDate() + ' ' + get_word('month_arr')[file_date.getMonth()];

            _debug('cur_date', cur_date);

            return cur_date;
        },

        get_url_by_pos: function (pos) {
            _debug('time_shift.get_url_by_pos', pos);

            //var cur_file_date = this._get_file_date_by_url(this.cur_media_item.cmd);
            //var cur_file_date = this._get_file_date_by_url(this.cur_media_item.cmd);

            var cur_file_date = new Date(this.cur_piece_date);
            //cur_file_date.setHours(0);

            cur_file_date.setSeconds(pos + cur_file_date.getSeconds());

            //var new_file_date = new Date(cur_file_date.getTime());

            var position = pos - cur_file_date.getHours() * 3600;

            _debug('position', position);

            if (stb.player.cur_tv_item['wowza_dvr'] == 1) {

                var new_playlist_start = this.get_wowza_playlist_start(cur_file_date);

                _debug('new_playlist_start', new_playlist_start);

                var url = this.cur_media_item.cmd.replace(/wowzadvrplayliststart=(\d+)/, 'wowzadvrplayliststart=' + new_playlist_start).trim();

            } else if (this.cur_media_item['flussonic_dvr'] == 1) {

                new_playlist_start = this.get_flussonic_playlist_start(cur_file_date);

                _debug('new_playlist_start', new_playlist_start);

                if (this.cur_media_item.cmd.indexOf('/mpegts') != -1) {
                    url = this.cur_media_item.cmd.replace(/\/(\d{10})\//, '/' + new_playlist_start + '/').trim();
                } else {
                    url = this.cur_media_item.cmd.replace(/-(\d{10})-/, '-' + new_playlist_start + '-').trim();
                }


            } else {
                var new_file_name = this.get_filename_by_date(cur_file_date);

                _debug('new_file_name', new_file_name);

                url = this.cur_media_item.cmd.replace(/([^\/]*)\.ts/, new_file_name).trim();
            }

            _debug('url 1', url);

            if (!/position:(\d*)/.exec(url)) {
                url += ' position:' + position;
            } else {
                url = url.replace(/position:(\d*)/, 'position:' + position).trim();
            }

            _debug('this.cur_media_item.cmd', this.cur_media_item.cmd);
            _debug('url 2', url);

            return url;
        },

        get_wowza_playlist_start: function (date) {
            _debug('time_shift.get_wowza_playlist_start', date);

            _debug('date 1', date);

            /*date = new Date(date.getTime());

            _debug('date 2', date);*/

            return date.getUTCFullYear()
                + '' + this.format_date(date.getUTCMonth() + 1)
                + '' + this.format_date(date.getUTCDate())
                + '' + this.format_date(date.getUTCHours())
                + '0000';
        },

        get_flussonic_playlist_start: function (date) {
            _debug('time_shift.get_flussonic_playlist_start', date);
            date.setMinutes(0);
            date.setSeconds(0);
            date.setMilliseconds(0);

            return date.getTime() / 1000;
        },

        get_filename_by_date: function (date) {
            _debug('time_shift.get_filename_by_date', date);

            _debug('date 1', date);

            date = new Date(date.getTime() + stb.profile['timezone_diff'] * 1000);

            _debug('date 2', date);

            _debug('stb.player.cur_tv_item', stb.player.cur_tv_item);

            return date.getFullYear() + ''
                + this.format_date(date.getMonth() + 1) + ''
                + this.format_date(date.getDate()) + '-'
                + this.format_date(date.getHours())
                + '.ts';
        },

        format_date: function (param) {
            if (param < 10) {
                return '0' + param
            }
            return param
        },

        is_last_archive_day: function () {
            _debug('time_shift.is_last_archive_day');

            var today = new Date();
            var today_mark = today.getYear() + '' + today.getMonth() + '' + today.getDate();
            var cur_pos_mark = this.cur_piece_date.getYear() + '' + this.cur_piece_date.getMonth() + '' + this.cur_piece_date.getDate();

            _debug('today_mark', today_mark);
            _debug('cur_pos_mark', cur_pos_mark);

            return today_mark == cur_pos_mark;

        },

        get_next_part: function () {
            _debug('time_shift.get_next_part');

            if (stb.player.cur_tv_item['wowza_dvr'] == 1) {
                var cur_file_date = this._get_wowza_playlist_start_date_by_url(stb.player.cur_media_item.cmd);
            } else if (stb.player.cur_tv_item['flussonic_dvr'] == 1) {
                cur_file_date = this._get_flussonic_playlist_start_date_by_url(stb.player.cur_media_item.cmd);
            } else {
                cur_file_date = this._get_file_date_by_url(stb.player.cur_media_item.cmd);
            }
            var cur_file_date_ts = cur_file_date.getTime();

            _debug('cur_file_date_ts', cur_file_date_ts);

            var next_file_date = new Date(cur_file_date_ts + 60 * 60 * 1000);

            if (stb.player.cur_tv_item['wowza_dvr'] == 1) {

                var new_playlist_start = this.get_wowza_playlist_start(next_file_date);

                _debug('new_playlist_start', new_playlist_start);

                var url = this.cur_media_item.cmd.replace(/wowzadvrplayliststart=(\d+)/, 'wowzadvrplayliststart=' + new_playlist_start).replace(/position:(\d*)/, '').trim();

            } else if (stb.player.cur_tv_item['flussonic_dvr'] == 1) {

                new_playlist_start = this.get_flussonic_playlist_start(next_file_date);

                _debug('new_playlist_start', new_playlist_start);

                if (this.cur_media_item.cmd.indexOf('/mpegts') != -1) {
                    url = this.cur_media_item.cmd.replace(/\/(\d{10})\//, '/' + new_playlist_start + '/').replace(/position:(\d*)/, '').trim();
                } else {
                    url = this.cur_media_item.cmd.replace(/-(\d{10})-/, '-' + new_playlist_start + '-').replace(/position:(\d*)/, '').trim();
                }
            } else {
                var next_file_name = this.get_filename_by_date(next_file_date);

                _debug('next_file_name', next_file_name);

                url = stb.player.cur_media_item.cmd.replace(/([^\/]*)\.ts/, next_file_name).replace(/position:(\d*)/, '').trim();
            }

            this.cur_media_item.live_date = new Date();

            _debug('stb.player.cur_media_item.cmd', stb.player.cur_media_item.cmd);
            _debug('url', url);

            return url;
        },

        can_reduce_day: function () {
            _debug('time_shift.can_reduce_day');

            _debug('stb.player.cur_media_item.tv_archive_duration', stb.player.cur_media_item.tv_archive_duration);

            var seconds = stb.player.cur_media_item.tv_archive_duration * 3600;

            var from_date = new Date();
            from_date.setSeconds(from_date.getSeconds() - seconds);

            //var from_date = new Date(this.cur_piece_date);

            _debug('this.cur_piece_date.getTime()', this.cur_piece_date.getTime());
            _debug('from_date.getTime()', from_date.getTime());

            return from_date < this.cur_piece_date;
        },

        in_archive: function (position) {
            _debug('time_shift.in_archive', position);

            _debug('stb.player.cur_media_item.tv_archive_duration', stb.player.cur_media_item.tv_archive_duration);

            var seconds = stb.player.cur_media_item.tv_archive_duration * 3600;

            var from_date = new Date();
            from_date.setSeconds(from_date.getSeconds() - seconds);
            from_date.setMinutes(0);
            from_date.setSeconds(0);

            //var from_date = new Date(this.cur_piece_date);

            var cur_piece_date = new Date(this.cur_piece_date);
            cur_piece_date.setSeconds(position);

            _debug('cur_piece_date.getTime()', cur_piece_date.getTime());
            _debug('from_date.getTime()', from_date.getTime());
            _debug('from_date < cur_piece_date', from_date < cur_piece_date);
            _debug('cur_piece_date <= new Date()', cur_piece_date <= new Date());
            _debug('new Date().toTime()', new Date().getTime());

            return (from_date < cur_piece_date) && (cur_piece_date <= new Date());
        },

        get_first_piece_position: function () {
            _debug('time_shift.get_first_piece_position');

            _debug('stb.player.cur_media_item.tv_archive_duration', stb.player.cur_media_item.tv_archive_duration);

            var seconds = stb.player.cur_media_item.tv_archive_duration * 3600;

            var from_date = new Date();
            from_date.setSeconds(from_date.getSeconds() - seconds);

            return from_date.getHours() * 3600;
        },

        get_position_from_url: function () {
            _debug('time_shift.get_position_from_url');

            var position_part = /position:(\d*)/.exec(this.cur_media_item.cmd);

            if (position_part) {
                var position = parseInt(position_part[1], 10);
            } else {
                position = 0;
            }

            _debug('position', position);

            return position;
        },

        update_position_in_url: function (position) {
            _debug('time_shift.update_position_in_url', position);

            if (!/position:(\d*)/.exec(this.cur_media_item.cmd)) {
                this.cur_media_item.cmd += ' position:' + position;
            } else {
                this.cur_media_item.cmd = this.cur_media_item.cmd.replace(/position:(\d*)/, 'position:' + position).trim();
            }

            return this.cur_media_item.cmd;
        },

        pos_to_cur_program_begin: function () {
            _debug('time_shift.pos_to_cur_program_begin');

            var self = this;

            this.on_epg_and_link_available = function () {
                if (module.time_shift.program.length > 0) {

                    var now_ts = new Date().getTime() / 1000;

                    for (var i = 0; i < module.time_shift.program.length; i++) {
                        if (module.time_shift.program[i].start_timestamp < now_ts && module.time_shift.program[i].stop_timestamp > now_ts) {
                            self.cur_program = module.time_shift.program[i];
                            break;
                        }
                    }

                    _debug('module.time_shift.cur_program', self.cur_program);

                    if (!self.cur_program) {
                        return;
                    }

                    var date = new Date(self.cur_program.start_timestamp * 1000);

                    var new_pos_time = date.getHours() * 3600 + date.getMinutes() * 60 + date.getSeconds();

                    _debug('new_pos_time', new_pos_time);

                    if (stb.player.on && stb.player.active_time_shift) {

                        _debug('stb.player.info.on', stb.player.info.on);

                        if (!stb.player.info.on) {
                            stb.player.show_info();
                        }

                        window.clearTimeout(stb.player.info.hide_timeout);

                        stb.player.info.hide_timeout = window.setTimeout(function () {
                            stb.player.set_pos_and_play();
                        }, 4000);

                        stb.player.set_pos_button(new_pos_time);
                    }
                }
            }
        },

        pos_to_previous_program: function () {
            _debug('time_shift.pos_to_previous_program');

            _debug('this.cur_program', this.cur_program);

            if (!this.cur_program) {
                return;
            }

            var cur_program;

            var now_ts = new Date().getTime() / 1000;

            for (var i = 0; i < module.time_shift.program.length; i++) {
                if (module.time_shift.program[i].stop_timestamp == this.cur_program.start_timestamp) {
                    cur_program = module.time_shift.program[i];
                    break;
                }
            }

            _debug('cur_program', cur_program);

            if (!cur_program) {
                return;
            }

            var date = new Date(cur_program.start_timestamp * 1000);

            var new_pos_time = date.getHours() * 3600 + date.getMinutes() * 60 + date.getSeconds();

            if (new Date(this.cur_program.start_timestamp * 1000).getDate() != new Date(cur_program.start_timestamp * 1000).getDate()) {
                stb.player.cur_media_length = 86400;
                module.time_shift.cur_piece_date.setDate(module.time_shift.cur_piece_date.getDate() - 1);
                stb.player.info.pos_series.innerHTML = module.time_shift.get_current_date();
            }

            this.cur_program = cur_program;

            _debug('new_pos_time', new_pos_time);

            if (stb.player.on && stb.player.active_time_shift) {

                _debug('stb.player.info.on', stb.player.info.on);

                stb.player.set_pos_button(new_pos_time);
            }
        },

        pos_to_next_program: function () {
            _debug('time_shift.pos_to_previous_program');

            _debug('this.cur_program', this.cur_program);

            if (!this.cur_program) {
                return;
            }

            var cur_program;

            var now_ts = new Date().getTime() / 1000;

            for (var i = 0; i < module.time_shift.program.length; i++) {
                if (module.time_shift.program[i].start_timestamp == this.cur_program.stop_timestamp && now_ts > module.time_shift.program[i].start_timestamp) {
                    cur_program = module.time_shift.program[i];
                    break;
                }
            }

            _debug('cur_program', cur_program);

            if (!cur_program) {
                return;
            }

            var date = new Date(cur_program.start_timestamp * 1000);

            var new_pos_time = date.getHours() * 3600 + date.getMinutes() * 60 + date.getSeconds();

            if (new Date(this.cur_program.start_timestamp * 1000).getDate() != new Date(cur_program.start_timestamp * 1000).getDate()) {

                if (!module.time_shift.is_last_archive_day()) {
                    _debug('--------------------------------------------------------');
                    module.time_shift.cur_piece_date.setDate(module.time_shift.cur_piece_date.getDate() + 1);
                    stb.player.cur_media_length = module.time_shift.get_cur_media_length();
                    stb.player.info.pos_series.innerHTML = module.time_shift.get_current_date();
                }
            }

            this.cur_program = cur_program;

            _debug('new_pos_time', new_pos_time);

            if (stb.player.on && stb.player.active_time_shift) {

                _debug('stb.player.info.on', stb.player.info.on);

                stb.player.set_pos_button(new_pos_time);
            }
        }

    };


})();

loader.next();
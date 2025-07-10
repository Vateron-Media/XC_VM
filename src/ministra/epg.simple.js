/**
 * EPG simple modile (for dummies).
 */

(function () {

    function EpgSimpleConstructor() {

        this.layer_name = 'epg_simple';
        //this.buttons = {};

        if (loader.template == "xc_vm") {
            this.total_rows = 7;
        } else {
            this.total_rows = 10;
        }

        this.row_blocks = ['t_time', 'name', 'mark_memo', 'mark_rec', 'mark_archive'];

        this.load_params = {
            "type": "epg",
            "action": "get_simple_data_table"
        };

        this.superclass = ListLayer.prototype;

        this.ch_id = 0;
        this.ch_name = '';
        this.channel = {};

        this.week_days_map = [];

        this.epg_list_active = true;

        this.cur_week_row = 0;

        this.active_week_row = 0;

        this.fill_info_timer = 0;

        this.week = [];

        this.more_on = false;

        this.init = function () {
            _debug('epg_simple.init');

            this.superclass.init.call(this);

            var week = this.week_block = document.createElement('ul');
            week.addClass('week');

            for (var i = 0; i <= 6; i++) {
                var day = document.createElement('li');
                week.appendChild(day);
                this.week_days_map.push({ "dom_obj": day });
            }

            this.dom_obj.appendChild(week);

            this.program_info = create_block_element('program_info', this.dom_obj);

            this.full_program_info_container = create_block_element('full_program_info', this.dom_obj);
            this.full_program_info = new Scrollable(create_block_element("full_program_info_content", this.full_program_info_container), this.full_program_info_container)
            this.full_program_info_container.hide();
        };

        this.init_page_bar = function () {
        };

        this.set_total_items = function () {
        };

        this.init_active_row = function () {
        };

        this.show = function (do_not_load) {
            _debug('epg_simple.show');

            this.parent.on = false;

            this.superclass.show.call(this, 1);

            if (do_not_load) {
                return;
            }

            this.update_header_path([{ "alias": "name", "item": this.ch_name }]);

            this.epg_list_active = true;
            this.cur_page = 0;

            this.load_params['ch_id'] = this.channel.id;

            this.load_week();
        };

        this.hide = function (do_not_reset) {
            _debug('epg_simple.hide');

            this.set_passive_row();

            this.more_hide();

            this.superclass.hide.call(this, do_not_reset);
        };

        this.bind = function () {
            _debug('epg_simple.bind');

            this.shift_row.bind(key.UP, this, -1);
            this.shift_row.bind(key.DOWN, this, 1);

            this.shift_page.bind(key.PAGE_PREV, this, -1);
            this.shift_page.bind(key.PAGE_NEXT, this, 1);

            (function () {

                if (single_module.length) {
                    return;
                }

                this.hide();
                this.parent.hide();
                this.parent.auto_play = true;
                main_menu.show();
            }).bind(key.MENU, this);

            (function () {

                if (this.more_on) {
                    this.more_hide();
                } else {
                    this.parent.do_not_load = true;
                    this.parent._show.call(this.parent, this.parent.genre);
                    this.hide();
                }
            }).bind(key.EXIT, this);

            (function () {
                this.parent._show.call(this.parent, this.parent.genre);
                this.hide();
            }).bind(key.EPG, this);

            (function () {

                if (this.more_on) {
                    this.more_hide();
                } else if (!this.epg_list_active) {
                    this.week_day_action();
                } else {

                    _debug('this.channel.open', this.channel.open);

                    if (this.channel.open == 0) {
                        return;
                    }

                    var now = new Date().getTime() / 1000;

                    _debug('now', now);

                    if (this.data_items[this.cur_row].rec_id) {
                        this.play(this.data_items[this.cur_row].rec_id);
                    } else if (this.data_items[this.cur_row].mark_archive && this.tv_archive) {

                        if (!stb.player.cur_media_item.wowza_dvr) {
                            delete stb.player.cur_media_item.enable_tv_archive;
                            delete this.data_items[this.cur_row].media_len;
                        }

                        this.tv_archive.play();
                    } else if (this.data_items[this.cur_row].start_timestamp < now && this.data_items[this.cur_row].stop_timestamp > now) {

                        keydown_observer.emulate_key(key.MENU);

                        var ch_idx = stb.player.channels.getIdxById(parseInt(this.ch_id));

                        stb.player.ch_idx = ch_idx || 0;
                        stb.player.cur_media_item = stb.player.channels[stb.player.ch_idx];
                        stb.player.cur_tv_item = stb.player.channels[stb.player.ch_idx];
                        stb.player.last_not_locked_tv_item = stb.player.channels[stb.player.ch_idx];

                        main_menu.hide();
                        stb.player.play_last();

                    }
                }
            }).bind(key.OK, this);

            (function () {
                if (this.more_on) {
                    this.more_hide();
                } else if (this.epg_list_active) {
                    this.horizontal_shift(-1);
                } else {
                    this.parent.do_not_load = true;
                    this.parent._show.call(this.parent, this.parent.genre);
                    this.hide();
                }
            }).bind(key.LEFT, this);

            this.horizontal_shift.bind(key.RIGHT, this, 1);

            this.more_toggle.bind(key.INFO, this);
        };

        /*this.play_from_archive = function(){
            _debug('epg_simple.play_from_archive');
            _debug(this.data_items[this.cur_row]);

            this.data_items[this.cur_row].cmd  = 'auto /media/' + this.data_items[this.cur_row].id + '.mpg';

            if (!this.data_items[this.cur_row].hasOwnProperty('o_name')){
                this.data_items[this.cur_row].o_name = this.data_items[this.cur_row].name;
            }
            
            this.data_items[this.cur_row].name = this.ch_name + ' — ' + this.data_items[this.cur_row].o_name;

            var self = this;

            stb.player.on_create_link = function(result){
                _debug('epg_simple.play_from_archive.on_create_link', result);

                if (result.error == 'limit'){
                    stb.notice.show(word['player_limit_notice']);
                }else if(result.error == 'nothing_to_play'){
                    stb.notice.show(word['player_file_missing']);
                }else if(result.error == 'link_fault'){
                    stb.notice.show(word['player_server_error']);
                }else{

                    self.hide(true);

                    stb.player.prev_layer = self;
                    stb.player.need_show_info = 1;
                    stb.player.play_now(result.cmd);
                }
            };

            stb.player.play(this.data_items[this.cur_row]);
        };*/

        this.load_data = function () {
            _debug('epg_simple.load_data');

            if (this.channel.type == 'dvb') {

                if (typeof (dvbManager) === 'undefined' || !module.dvb) {
                    return;
                }

                var date_parts = this.load_params['date'].split('-');

                var date = new Date();
                date.setYear(date_parts[0]);
                date.setMonth(date_parts[1] - 1);
                date.setDate(date_parts[2]);
                date.setHours(0);
                date.setMinutes(0);
                date.setSeconds(0);

                _debug('date', date);

                this.set_passive_row();
                this.set_total_items(-1);

                var result = module.dvb.get_epg_for_page(this.channel['dvb_id'], date, this.cur_page, this.total_rows);

                this.break_filling_list = false;

                this.result = result;
                this.total_pages = Math.ceil(result.total_items / this.total_rows);

                if (result.selected_item != 0 || result.cur_page != 0) {
                    this.cur_row = result.selected_item - 1;
                    this.cur_page = result.cur_page;
                } else if (this.cur_page == 0) {
                    this.cur_page = 1
                }

                this.set_total_items(result.total_items);

                this.fill_list(result.data);
            } else {
                this.superclass.load_data.call(this);
            }
        };

        this.play = function (rec_id) {
            _debug('epg_simple.play', rec_id);

            var idx = stb.recordings.getIdxByVal('id', rec_id);

            if (stb.recordings[idx] && !stb.recordings[idx].started) {
                return;
            }

            var item = this.data_items[this.cur_row].clone();

            item.cmd = 'auto /media/' + rec_id + '.mpg';
            item.name = this.ch_name + ' — ' + this.data_items[this.cur_row].name;

            var self = this;

            stb.player.on_create_link = function (result) {
                _debug('epg_simple.play.on_create_link', result);

                if (result.error == 'limit') {
                    stb.notice.show(word['player_limit_notice']);
                } else if (result.error == 'nothing_to_play') {
                    stb.notice.show(word['player_file_missing']);
                } else if (result.error == 'link_fault') {
                    stb.notice.show(word['player_server_error']);
                } else {

                    if (result.local == 1) {
                        if (stb.IsFileExist && !stb.IsFileExist(result.cmd)) {
                            stb.notice.show(get_word('rec_file_missing'));
                            return;
                        }
                    }

                    self.hide(true);

                    stb.player.prev_layer = self;
                    stb.player.need_show_info = 1;
                    stb.player.play_now(result.cmd);
                }
            };

            var channel = stb.player.channels[this.ch_id];

            _debug('this.ch_id', this.ch_id);
            _debug('channel', channel);

            if (channel) {
                stb.player.cur_tv_item = channel;
            }

            stb.player.play(item);
        };

        this.set_active_epg_list = function () {
            _debug('epg_simple.set_active_epg_list');

            _debug('this.cur_row', this.cur_row);

            this.set_active_row(this.cur_row);
            this.set_passive_week_row();
        };

        this.set_passive_epg_list = function () {
            _debug('epg_simple.set_passive_epg_list');

            this.set_passive_row();
            this.set_active_week_row(this.cur_week_row);
        };

        this.shift_row = function (dir) {
            _debug('epg_simple.shift_row', dir);

            if (this.more_on) {
                this.full_program_info.scroll && this.full_program_info.scroll(dir);
                return;
            }

            _debug('this.epg_list_active', this.epg_list_active);

            if (this.epg_list_active) {
                this.superclass.shift_row.call(this, dir);
            } else {
                this.shift_week_day(dir);
            }
        };

        this.horizontal_shift = function (dir) {
            _debug('epg_simple.horizontal_shift', dir);

            if (this.more_on) {
                return;
            }

            if (dir > 0) {
                if (!this.epg_list_active) {
                    this.set_active_epg_list();
                    this.epg_list_active = true;
                }
            } else {
                if (this.epg_list_active) {
                    this.set_passive_epg_list();
                    this.epg_list_active = false;
                }
            }
        };

        this.shift_week_day = function (dir) {
            _debug('epg_simple.shift_week_day', dir);

            this.set_passive_week_row();

            if (dir > 0) {
                if (this.cur_week_row < 6) {
                    this.cur_week_row++;
                } else {
                    //this.cur_week_row = 0;
                    this.shift_week_page(1);
                }
            } else {
                if (this.cur_week_row > 0) {
                    this.cur_week_row--;
                } else {
                    //this.cur_week_row = 6;
                    this.shift_week_page(-1);
                }
            }

            this.set_active_week_row(this.cur_week_row);
        };

        this.shift_week_page = function (dir) {
            _debug('epg_simple.shift_week_page');

            if (dir > 0) {
                if (this.cur_week_page < this.total_week_pages) {
                    this.cur_week_page++;
                    this.cur_week_row = 0;
                    this.fill_week_page(this.cur_week_page);
                    this.set_active_week_row(this.cur_week_row);
                }
            } else {
                if (this.cur_week_page > 1) {
                    this.cur_week_page--;
                    this.cur_week_row = 6;
                    this.fill_week_page(this.cur_week_page);
                    this.set_active_week_row(this.cur_week_row);
                }
            }
        };

        this.set_passive_row = function () {
            _debug('epg_simple.set_passive_row');
            _debug('this.cur_row', this.cur_row);

            if (this.cur_row < 0) {
                return;
            }

            this.map[this.cur_row]['row'].setAttribute('active', '');
            this.program_info.innerHTML = '';
        };

        this.set_active_row = function (num) {
            _debug('epg_simple.set_active_row', num);

            if (this.loading || empty(this.data_items)) {
                return;
            }

            _debug('this.data_items[this.cur_row]', this.data_items[this.cur_row]);

            this.map[this.prev_row]['row'].setAttribute('active', '');
            this.map[num]['row'].setAttribute('active', 'active');

            this.fill_program_info();

            var now = (new Date().getTime()) / 1000;

            _debug('now', now);
            _debug('this.data_items[this.cur_row].start_timestamp', this.data_items[this.cur_row].start_timestamp);

            this.color_buttons.get('yellow').disable();

            _debug('this.channel.open', this.channel.open);

            if (this.channel.open == 0) {
                this.color_buttons.get('red').disable();
                this.color_buttons.get('green').disable();
            } else if (now > this.data_items[this.cur_row].start_timestamp) {
                this.color_buttons.get('red').disable();
                this.color_buttons.get('green').disable();

                _debug('this.data_items[this.cur_row].stop_timestamp', this.data_items[this.cur_row].stop_timestamp);
                _debug('this.data_items[this.cur_row].mark_archive', !!this.data_items[this.cur_row].mark_archive);
                _debug('!stb.player.cur_media_item.wowza_dvr', !stb.player.cur_media_item.wowza_dvr);
                _debug('module.downloads', !!module.downloads);
                _debug('this.tv_archive', !!this.tv_archive);
                _debug('now >= this.data_items[this.cur_row].stop_timestamp', (now >= this.data_items[this.cur_row].stop_timestamp));

                if (now >= this.data_items[this.cur_row].stop_timestamp && !!module.downloads && !!this.data_items[this.cur_row].mark_archive && !!this.tv_archive && !parseInt(stb.player.cur_media_item.wowza_dvr, 10)) {
                    this.color_buttons.get('yellow').enable();
                }

            } else {
                if (module.remote_pvr && this.channel.allow_pvr || module.pvr_local && this.channel.allow_local_pvr) {
                    this.color_buttons.get('red').enable();
                } else {
                    this.color_buttons.get('red').disable();
                }
                this.color_buttons.get('green').enable();
            }
        };

        this.set_active_week_row = function (num) {
            _debug('epg_simple.set_active_week_row', num);

            this.week_days_map[num].dom_obj.setAttribute('active', 'active');
        };

        this.set_passive_week_row = function () {
            _debug('epg_simple.set_passive_week_row');

            this.week_days_map[this.cur_week_row].dom_obj.setAttribute('active', '');
        };

        this.set_active_current_week_row = function () {
            _debug('epg_simple.set_current_week_row');

            this.set_passive_all_current_week_row();

            this.week_days_map[this.cur_week_row].dom_obj.setAttribute('rel', '');

            this.active_week_row = this.cur_week_row;
        };

        this.set_passive_current_week_row = function () {
            _debug('epg_simple.set_passive_current_week_row');

            this.week_days_map[this.cur_week_row].dom_obj.setAttribute('rel', 'close');
        };

        this.set_passive_all_current_week_row = function () {
            _debug('epg_simple.set_passive_current_week_row');

            this.week_days_map[this.active_week_row].dom_obj.setAttribute('rel', 'close');
        };

        this.fill_program_info = function () {
            _debug('epg_simple.fill_program_info');

            window.clearTimeout(this.fill_info_timer);

            var self = this;

            this.fill_info_timer = window.setTimeout(function () {

                if (!empty(self.data_items) && !empty(self.data_items[self.cur_row])) {

                    self.program_info.innerHTML = '<span class="time">'
                        + self.data_items[self.cur_row]['t_time'] + ' - '
                        + self.data_items[self.cur_row]['t_time_to']
                        + '</span> - ' + (self.data_items[self.cur_row]['o_name'] ? self.data_items[self.cur_row]['o_name'] : self.data_items[self.cur_row]['name'])
                        + (self.data_items[self.cur_row]['descr'] ? ' (' + self.data_items[self.cur_row]['descr'] + ')' : '');
                } else {
                    self.program_info.innerHTML = '';
                }
            },

                300);
        };

        this.week_day_action = function () {
            _debug('epg_simple.week_day_action');

            this.page_dir = 1;
            this.cur_page = 0;

            this.set_active_current_week_row();

            this.load_params['date'] = this.week[this.cur_week_row + (this.cur_week_page - 1) * 7].f_mysql;

            this.load_data();
        };

        this.load_week = function () {
            _debug('epg_simple.load_week');

            stb.load(
                {
                    "type": "epg",
                    "action": "get_week"
                },

                function (result) {
                    this.fill_week(result);
                },

                this
            )
        };

        this.fill_week = function (data) {
            _debug('epg_simple.feel_week', data);

            this.week = data;

            _debug('this.week', this.week);

            this.total_week_pages = Math.ceil(this.week.length / 7);

            _debug('this.total_week_pages', this.total_week_pages);

            var today_idx = this.week.getIdxByVal('today', 1);

            _debug('today_idx', today_idx);

            if (today_idx === null) {
                var page = 1;
            } else {
                page = Math.ceil((today_idx + 1) / 7)
            }

            this.cur_week_page = page;

            this.fill_week_page(page);
        };

        this.fill_week_page = function (page) {
            _debug('epg_simple.fill_week_page', page);

            if (this.week.length <= 7) {
                return;
            }

            var start_idx = (page - 1) * 7 + 0;
            var stop_idx = (page - 1) * 7 + 7;

            var week = this.week.slice(start_idx, stop_idx);

            _debug('week', week);

            for (var i = 0; i < week.length; i++) {
                this.week_days_map[i].dom_obj.innerHTML = week[i].f_human;

                this.week_days_map[i].dom_obj.setAttribute('active', '');

                if (week[i].today) {

                    this.week_days_map[i].dom_obj.setAttribute('rel', '');
                    //this.week_days_map[i].dom_obj.setAttribute('active', 'active');

                    this.load_params['date'] = week[i].f_mysql;

                    this.cur_week_row = i;
                    this.active_week_row = i;
                } else {
                    this.week_days_map[i].dom_obj.setAttribute('rel', 'close');
                    //this.week_days_map[i].dom_obj.setAttribute('active', '');
                }
            }

            this.load_data();
        };

        this.fill_list = function (data) {
            _debug('epg_simple.fill_list');

            if (this.cur_row == -1) {
                this.cur_row = 0;
            }

            for (var i = 0; i < data.length; i++) {
                if (data[i]['mark_archive'] == 1 && !module.tv_archive) {
                    data[i]['mark_archive'] = 0;
                }
            }

            this.superclass.fill_list.call(this, data);

            if (this.epg_list_active) {
                this.set_active_row(this.cur_row);
            } else {
                this.set_passive_row();
            }

        };

        this.shift_page = function (dir) {

            if (this.more_on) {
                this.full_program_info.scrollPage && this.full_program_info.scrollPage(dir);
                return;
            }

            if (this.epg_list_active) {

                this.page_dir = dir;

                if (dir > 0) {
                    if (this.cur_page < this.total_pages) {
                        this.cur_page++;
                        this.load_data();
                    } else {
                        this.set_active_row(this.cur_row);
                        //this.cur_page = 1;
                    }
                } else {
                    if (this.cur_page > 1) {
                        this.cur_page--;
                        this.load_data();
                    } else {
                        this.set_active_row(this.cur_row);
                        //this.cur_page = this.total_pages;
                    }
                }
            } else {
                this.shift_week_page(dir);
            }


            //this.load_data();
        };

        this.add_download = function (url, to_filename) {
            _debug('epg_simple.add_download', url, to_filename);

            if (module.downloads) {
                _debug('downloads');
                module.downloads.dialog.show({ "secure_url": true, "parent": this, "url": url, "name": to_filename });
            }
        };

        this.more_toggle = function () {
            _debug('epg_simple.more_toggle');

            if (this.more_on) {
                this.more_hide();
            } else {
                this.more_show();
            }
        };

        this.more_show = function () {
            _debug('epg_simple.more_show');

            this.main_container.hide();
            this.week_block.hide();
            this.program_info.hide();

            if (this.data_items && this.data_items[this.cur_row]) {
                this.full_program_info.dom_obj.innerHTML = '<span class="time">'
                    + this.data_items[this.cur_row]['t_time'] + ' - '
                    + this.data_items[this.cur_row]['t_time_to']
                    + '</span> - ' + (this.data_items[this.cur_row]['o_name'] ? this.data_items[this.cur_row]['o_name'] : this.data_items[this.cur_row]['name']) + '<br>'
                    + (this.data_items[this.cur_row]['category'] ? ' <br><span class="time">' + get_word('epg_category') + '</span>: ' + this.data_items[this.cur_row]['category'] + '' : '')
                    + (this.data_items[this.cur_row]['director'] ? ' <br><span class="time">' + get_word('epg_director') + '</span>: ' + this.data_items[this.cur_row]['director'] + '' : '')
                    + (this.data_items[this.cur_row]['actor'] ? ' <br><span class="time">' + get_word('epg_actors') + '</span>: ' + this.data_items[this.cur_row]['actor'] + '' : '')
                    + (this.data_items[this.cur_row]['descr'] ? ' <br><span class="time">' + get_word('epg_desc') + '</span>: ' + this.data_items[this.cur_row]['descr'] + '' : '');
            }

            this.full_program_info_container.show();
            this.full_program_info.scrollTop();

            this.color_buttons.get('red').disable();
            this.color_buttons.get('green').disable();

            this.more_on = true;
        };

        this.more_hide = function () {
            _debug('epg_simple.more_hide');

            this.full_program_info_container.hide();
            this.full_program_info.scrollbar.reset();
            this.full_program_info.dom_obj.innerHTML = '';

            this.week_block.show();
            this.program_info.show();
            this.main_container.show();

            this.set_active_row(this.cur_row);

            this.more_on = false;
        };
    }

    EpgSimpleConstructor.prototype = new ListLayer();

    var epg_simple = new EpgSimpleConstructor();

    epg_simple.parent = module.tv;

    if (module.epg_reminder) {
        epg_simple.reminder = function () {
        };

        epg_simple.reminder.prototype = module.epg_reminder;
        epg_simple.reminder = new epg_simple.reminder;
        epg_simple.reminder.parent = epg_simple;

        epg_simple.reminder.get_ch_id = function () {
            _debug('epg_simple.reminder.get_ch_id');

            return this.parent.ch_id;
        };

        epg_simple.reminder.get_item = function () {
            _debug('epg_simple.reminder.get_item');

            return this.parent.data_items[this.parent.cur_row];
        };

        epg_simple.reminder.show_mark = function () {
            _debug('epg_simple.reminder.show_mark');

            this.parent.map[this.parent.cur_row]['mark_memo_block'].show();
            this.parent.data_items[this.parent.cur_row].mark_memo = 1;
        };

        epg_simple.reminder.hide_mark = function () {
            _debug('epg_simple.reminder.hide_mark');

            this.parent.map[this.parent.cur_row]['mark_memo_block'].hide();
            this.parent.data_items[this.parent.cur_row].mark_memo = 0;
        }
    }

    if (module.epg_recorder) {
        epg_simple.recorder = function () {
        };

        epg_simple.recorder.prototype = module.epg_recorder;
        epg_simple.recorder = new epg_simple.recorder;
        epg_simple.recorder.parent = epg_simple;

        epg_simple.recorder.get_ch_id = function () {
            _debug('epg_simple.recorder.get_ch_id');

            return this.parent.ch_id;
        };

        epg_simple.recorder.get_item = function () {
            _debug('epg_simple.recorder.get_item');

            return this.parent.data_items[this.parent.cur_row];
        };

        epg_simple.recorder.show_mark = function () {
            _debug('epg_simple.recorder.show_mark');

            this.parent.map[this.parent.cur_row]['mark_rec_block'].show();
            this.parent.data_items[this.parent.cur_row].mark_rec = 1;
        };

        epg_simple.recorder.hide_mark = function () {
            _debug('epg_simple.recorder.hide_mark');

            this.parent.map[this.parent.cur_row]['mark_rec_block'].hide();
            this.parent.data_items[this.parent.cur_row].mark_rec = 0;
        }
    }

    if (module.tv_archive) {
        module.tv_archive.init_continue_dialog();

        epg_simple.tv_archive = function () {
        };

        epg_simple.tv_archive.prototype = module.tv_archive;
        epg_simple.tv_archive = new epg_simple.tv_archive;
        epg_simple.tv_archive.parent = epg_simple;
    }

    epg_simple.bind();
    epg_simple.init();

    epg_simple.init_header_path(word['epg_title']);

    epg_simple.init_left_ear(word['ears_back']);

    if (loader.template == "xc_vm") {
        epg_simple.init_color_buttons([
            { "label": "", "cmd": (function () { }) },
            { "label": "", "cmd": (function () { }) },
            { "label": "", "cmd": (function () { }) },
            { "label": word['epg_more'], "cmd": epg_simple.more_toggle },
        ]);
    } else {
        epg_simple.init_color_buttons([
            {
                "label": word['epg_record'], "cmd": (function () {
                    if (epg_simple.recorder) {
                        return function () {
                            epg_simple.recorder.add_del()
                        }
                    } else {
                        return ''
                    }
                })()
            },
            {
                "label": word['epg_remind'], "cmd": (function () {
                    if (epg_simple.reminder) {
                        return function () {
                            epg_simple.reminder.add_del()
                        }
                    } else {
                        return ''
                    }
                })()
            },
            {
                "label": word['downloads_download'], "cmd": stb.all_modules.indexOf('downloads') >= 0 ? function () {
                    epg_simple.tv_archive.play.call(epg_simple.tv_archive, true, function (url, to_file) {
                        epg_simple.add_download.call(epg_simple, url, to_file)
                    })
                } : ''
            },
            { "label": word['epg_more'], "cmd": epg_simple.more_toggle }
        ]);
    }

    epg_simple.hide();

    module.epg_simple = epg_simple;

})();

loader.next();
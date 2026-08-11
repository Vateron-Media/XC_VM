/*    Epg reminder    */

(function () {

    var reminder = {

        memos: [],

        get_list: function () {
            _debug('epg.reminder.get_list');

            stb.load(
                {
                    "type": "tvreminder",
                    "action": "get_all_active"
                },

                function (result) {
                    _debug('reminder.get_list result', result);

                    if (!result) {
                        return;
                    }

                    this.memos = result;

                    var timestamp = (new Date().getTime()) / 1000;

                    for (var i = 0; i < this.memos.length; i++) {

                        var diff = (this.memos[i].fire_ts - timestamp);

                        if (diff > 0) {

                            this.memos[i]['timer'] = window.setTimeout((function (context, memo) {

                                return function () {
                                    stb.msg.push(function () {
                                        _debug('return show_notification');
                                        return context.show_notification.call(context, memo)
                                    });
                                }

                            })(this, this.memos[i]), diff * 1000);
                        }
                    }
                },

                this
            )
        },

        add_del: function () {
            _debug('add_del');

            var program_id = this.get_item().real_id;

            _debug('this.memos', this.memos);

            var memo_idx = this.memos.getIdxByVal('tv_program_real_id', program_id);

            if (memo_idx !== null) {
                this.del(memo_idx);
            } else {
                this.add();
            }
        },

        add: function () {
            _debug('epg.reminder.add');

            var ch_id = this.get_ch_id();
            var program_id = this.get_item().id;
            var program_real_id = this.get_item().real_id;
            var program_name = this.get_item().name;
            var program_time = this.get_item().t_time;
            var fire_ts = this.get_item().start_timestamp;
            var channel = this.get_channel();

            _debug('ch_id', ch_id);
            _debug('program_id', program_id);
            _debug('program_real_id', program_real_id);
            _debug('fire_ts', fire_ts);
            _debug('channel', channel);

            stb.load(
                {
                    "type": "tvreminder",
                    "action": "add",
                    "ch_id": ch_id,
                    "program_id": program_real_id,
                    "fire_ts": fire_ts,
                    "program_name": channel.type == 'dvb' ? encodeURIComponent(program_name) : ''
                },

                function (memo) {
                    _debug('epg.reminder.add result', memo);

                    if (channel.type == 'dvb') {

                        memo = {
                            fire_ts: fire_ts,
                            t_fire_time: program_time,
                            itv_name: channel.name,
                            ch_id: ch_id,
                            program_name: program_name,
                            tv_program_id: program_id,
                            tv_program_real_id: program_real_id
                        };

                    } else if (empty(memo)) {
                        return;
                    }

                    _debug('memo', memo);

                    var timestamp = (new Date().getTime()) / 1000;

                    var diff = (fire_ts - timestamp);

                    _debug('diff', diff);

                    if (diff > 0) {

                        this.show_mark(program_id);

                        memo.timer = window.setTimeout((function (context, memo) {

                            return function () {
                                stb.msg.push(function () {
                                    return context.show_notification.call(context, memo)
                                });
                            }

                        })(this, memo), diff * 1000);

                        this.memos.push(memo);

                        _debug('this.memos', this.memos);
                    }

                },

                this
            )
        },

        del: function (memo_idx) {
            _debug('epg.reminder.del', memo_idx);

            var memo = this.memos[memo_idx];

            var program_id = memo.tv_program_id;

            stb.load(
                {
                    "type": "tvreminder",
                    "action": "del",
                    "program_id": memo.tv_program_real_id
                },

                function (result) {
                    _debug('epg.reminder.del result', result);
                },

                this
            );

            this.hide_mark(program_id);

            window.clearTimeout(memo.timer);

            this.memos.splice(memo_idx, 1);

            _debug('this.memos', this.memos);
        },

        show_notification: function (memo) {
            _debug('epg.reminder.show_notification', memo);

            var timestamp = (new Date().getTime()) / 1000;

            var msg = word['epg_memo'] + ' - ' + word['epg_on_ch'] + ' ' + memo['itv_name'] + ' ';

            if ((timestamp - memo.fire_ts) > 60) {
                msg += word['epg_on_time'] + ' ' + memo['t_fire_time'] + ' ' + word['epg_started'] + ' ';
            } else {
                msg += word['epg_now_begins'] + ' ';
            }

            msg += memo['program_name'];

            msg += '<br>OK - ' + word['epg_goto_ch'];

            _debug('msg', msg);

            stb.msg.set_confirm_callback(function () {
                _debug('stb.msg ok callback');

                var fav_ch_idx = null;

                if (stb.user.fav_itv_on) {
                    fav_ch_idx = stb.player.fav_channels.getIdxByVal('id', parseInt(memo['ch_id']));
                }

                _debug('fav_ch_idx', fav_ch_idx);

                if (fav_ch_idx === null) {

                    var ch_idx = stb.player.channels.getIdxByVal('id', parseInt(memo['ch_id']));

                    if (ch_idx !== null) {

                        _debug('ch_idx', ch_idx);

                        stb.user.fav_itv_on = 0;

                        stb.player.ch_idx = ch_idx;
                        stb.player.cur_media_item = stb.player.channels[stb.player.ch_idx];
                        stb.player.cur_tv_item = stb.player.channels[stb.player.ch_idx];
                        stb.player.last_not_locked_tv_item = stb.player.channels[stb.player.ch_idx];

                        keydown_observer.emulate_key(key.MENU);
                        //keydown_observer.emulate_key(key.EXIT);
                        main_menu.hide();
                        stb.player.play_last();
                    }

                } else {

                    stb.player.f_ch_idx = fav_ch_idx;

                    _debug('stb.player.f_ch_idx', stb.player.f_ch_idx);

                    stb.player.cur_media_item = stb.player.fav_channels[stb.player.f_ch_idx];
                    stb.player.cur_tv_item = stb.player.fav_channels[stb.player.f_ch_idx];
                    stb.player.last_not_locked_tv_item = stb.player.fav_channels[stb.player.f_ch_idx];

                    keydown_observer.emulate_key(key.MENU);
                    //keydown_observer.emulate_key(key.EXIT);
                    main_menu.hide();
                    stb.player.play_last();
                }
            });

            return msg;
        },

        get_ch_id: function () {
            _debug('epg_reminder.get_ch_id');

            return this.parent.data_items[this.parent.cur_row].ch_id;
        },

        get_channel: function () {
            _debug('epg_reminder.get_channel');

            return this.parent.channel;
        },

        get_item: function () {
            _debug('epg_reminder.get_item');

            return this.parent.data_items[this.parent.cur_row].epg[this.parent.cur_cell_col];
        },

        show_mark: function (program_id) {
            _debug('epg_reminder.show_mark', program_id);

            var mark_idx = this.parent.marks_map.getIdxByVal('program_id', program_id);

            if (mark_idx !== null) {
                this.parent.marks_map[mark_idx].mark_memo.show();

                this.get_item().mark_memo = 1;
                this.parent.set_active_row(this.parent.cur_row);
            }
        },

        hide_mark: function (program_id) {
            _debug('epg_reminder.hide_mark', program_id);

            var mark_idx = this.parent.marks_map.getIdxByVal('program_id', program_id);

            if (mark_idx !== null) {
                this.parent.marks_map[mark_idx].mark_memo.hide();

                this.get_item().mark_memo = 0;
                this.parent.set_active_row(this.parent.cur_row);
            }
        }
    };

    module.epg_reminder = reminder;
    module.epg_reminder.get_list();

})();

loader.next();
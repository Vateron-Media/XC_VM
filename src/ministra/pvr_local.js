/**
 * Virtual USB PVR module
 */

(function () {

    if (typeof (pvrManager) === "undefined") {
        _debug('pvrManager not found');
        return;
    }

    var allow_local_recording = stb.user['allowed_stb_types_for_local_recording'].some(function (element) {
        return stb.type.toLowerCase().indexOf(element) != -1;
    });

    _debug('allow_local_recording', allow_local_recording);

    if (!allow_local_recording) {
        _debug('local pvr not allowed for ' + stb.type);
        return;
    }

    function PvrLocal() {

        //this.deferred_epg_records = [];

        this.error_codes = {
            "-1": get_word('pvr_error_wrong_param'),
            "-2": get_word('pvr_error_memory'),
            "-3": get_word('pvr_error_duration'),
            "-4": get_word('pvr_error_not_found'),
            "-5": get_word('pvr_error_wrong_filename'),
            "-6": get_word('pvr_error_record_exist'),
            "-7": get_word('pvr_error_url_open_error'),
            "-8": get_word('pvr_error_file_open_error'),
            "-9": get_word('pvr_error_rec_limit'),
            "-10": get_word('pvr_error_end_of_stream'),
            "-11": get_word('pvr_error_file_write_error')
        };

        this.duration_input = new DurationInputBox({ "max_val": 180 });

        this.stop_confirm = new ModalForm({
            "title": get_word('confirm_form_title'),
            "text": get_word('remote_pvr_stop_confirm')
        });
        this.stop_confirm.getTextDomObj().style.textAlign = "center";
        this.stop_confirm.enableOnExitClose();

        var scope = this;

        this.stop_confirm.addItem(new ModalFormButton(
            {
                "value": get_word("cancel_btn"),
                "onclick": function () {
                    scope.stop_confirm.hide();
                }
            }
        ));

        this.stop_confirm.addItem(new ModalFormButton(
            {
                "value": get_word("yes_btn"),
                "onclick": function () {
                    scope.stop_confirm.hide();
                    scope.stop_rec.call(scope, scope.stop_confirm.rec_id);
                }
            }
        ));

        this.rec_switch = function (ch) {
            _debug('pvr_local.rec_switch', ch);

            if (stb.player.prev_layer.on) {
                return;
            }

            var idx = stb.recordings.getIdxByVal('ch_id', ch.id);

            _debug('idx', idx);
            _debug('stb.recordings[idx]', stb.recordings[idx]);

            if (idx !== null && stb.recordings[idx].local == 1) {

                var now = new Date().getTime() / 1000;

                if ((now - stb.recordings[idx].t_start_ts) < 120) {

                    if (this.duration_input.on) {
                        this.duration_input.hide();
                    } else {

                        var self = this;
                        var rec_id = stb.recordings[idx].id;

                        this.duration_input.callback = function (duration) {
                            _debug('callback duration', duration);

                            var t_stop_ts = parseInt(stb.recordings[idx].t_start_ts, 10) + duration * 60;

                            var result = pvrManager.ChangeEndTime(stb.recordings[idx].internal_id, t_stop_ts);

                            if (self.is_error(result)) {
                                return;
                            }

                            stb.recordings[idx].t_stop_ts = t_stop_ts;

                            window.clearTimeout(stb.recordings[idx].del_timeout);

                            var del_timeout = (stb.recordings[idx].t_stop_ts - stb.recordings[idx].t_start_ts) * 1000;

                            _debug('del_timeout', del_timeout);

                            stb.recordings[idx].del_timeout = window.setTimeout(function () {
                                _debug('delete rec');
                                self.send_stop_rec(rec_id, true);
                            }, del_timeout);

                            stb.load(
                                {
                                    "type": "remote_pvr",
                                    "action": "update_record_on_stb_end_time",
                                    "rec_id": stb.recordings[idx].id,
                                    "stop_ts": stb.recordings[idx].t_stop_ts
                                },
                                function (result) {
                                    _debug('update_record_on_stb_end_time', result);
                                },
                                self
                            );
                        };

                        this.duration_input.show();
                    }
                }
            }
        };

        this.create_for_program = function (program, path, callback) {
            _debug('pvr_local.create_for_program', program, path);

            var ch_idx = stb.player.channels.getIdxByVal("id", program.ch_id);

            if (ch_idx === null) {
                stb.notice.show(get_word('Channel not found'));
                return;
            }

            this.create(stb.player.channels[ch_idx], path, program.start_timestamp, program.stop_timestamp, program, callback);
        };

        this.create = function (channel, path, start, end, program, callback) {
            _debug('pvr_local.create', channel, path);

            var self = this;

            this._get_link_for_channel(
                channel,

                function (url) {
                    channel.url = url;
                    self.start(channel, path, start, end, program, callback);
                }
            )
        };

        this._get_link_for_channel = function (channel, callback) {
            _debug('pvr_local._get_link_for_channel', channel);

            if (parseInt(channel.use_http_tmp_link) == 1 || parseInt(channel.use_load_balancing) == 1 || stb.user['force_ch_link_check']) {

                stb.load(
                    {
                        "type": "itv",
                        "action": "create_link",
                        "cmd": channel.cmd,
                        "for_pvr": 1
                    },
                    function (result) {

                        _debug('on pvr_local.create_link', result);

                        if (result.error == 'limit') {
                            stb.notice.show(word['player_limit_notice']);
                        } else if (result.error == 'nothing_to_play') {
                            stb.notice.show(word['player_file_missing']);
                        } else if (result.error == 'link_fault') {
                            stb.notice.show(word['player_server_error']);
                        } else {
                            callback && callback(this.get_clear_url(result.cmd));
                        }
                    },
                    this
                );

            } else {
                callback && callback(this.get_clear_url(channel.cmd));
            }
        };

        this.start = function (channel, path, start, end, program, callback) {
            _debug('pvr_local.start', channel, path, start, end);

            var url = channel.url;

            if (parseInt(channel.use_http_tmp_link) != 1 && parseInt(channel.use_load_balancing) != 1 && !stb.user['force_ch_link_check']
                && (!url || url.indexOf('http://') == -1 && url.indexOf('rtp://') == -1 && url.indexOf('udp://') == -1)) {

                stb.notice.show(get_word('rec_not_supported_format'));
                //return false;
                callback(false);
            }

            var now = Math.ceil(new Date().getTime() / 1000);

            start = start || now;
            end = end || now + 3 * 3600;

            if (!program) {
                var rec_id = pvrManager.CreateTask(url, path, start, end);
                _debug('rec_id', rec_id);

                if (this.is_error(rec_id)) {
                    callback(false);
                }
            }

            if (program && program.id != 0) {
                cur_prog = program.name
            } else {
                var epg = stb.epg_loader.get_curr_and_next(channel.id, start);

                if (epg && epg.length > 0) {
                    var cur_prog = epg[0].name || '';
                } else {
                    cur_prog = '';
                }
            }

            var record = {
                "id": 0,
                "real_id": 0,
                "ch_id": channel.id,
                "local": 1,
                "t_start_ts": start,
                "t_stop_ts": end,
                "program": cur_prog,
                "internal_id": rec_id,
                "file": path
            };

            var self = this;
            record['del_timeout'] = window.setTimeout(function () {
                _debug('delete rec');
                //self.remove_record_from_list(record.id, true);
                self.send_stop_rec(record.id, true);
            }, record['t_stop_ts'] * 1000 - new Date().getTime());

            /*stb.recordings.push(record);*/
            /* stb.player.show_rec_icon(record);*/

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": program ? "start_deferred_record_on_stb" : "start_record_on_stb",
                    "ch_id": channel.id,
                    "file": path,
                    "start_ts": start,
                    "stop_ts": end,
                    "internal_id": rec_id ? rec_id : 0,
                    "deferred": !!start,
                    "program_id": program ? program.id : 0,
                    "program_real_id": program && program.real_id ? program.real_id : ''
                },
                function (result) {
                    _debug('on start_record_on_stb', result);

                    var real_id = result || 0;

                    _debug('real_id', real_id);

                    callback && callback(real_id);

                    /*var idx = stb.recordings.getIdxByVal('internal_id', rec_id);

                    stb.recordings[idx].id = stb.recordings[idx].real_id = real_id;*/

                    record.id = record.real_id = real_id;

                    var rm_idx = stb.recordings.getIdxByVal('id', real_id);

                    if (rm_idx !== null) {
                        stb.recordings.splice(rm_idx, 1);
                    }

                    stb.recordings.push(record);

                    var self = this;

                    if (program) {
                        record['start_timeout'] = window.setTimeout(function () {
                            _debug('start rec');

                            self._get_link_for_channel(
                                channel,
                                function (url) {

                                    var idx = stb.recordings.getIdxByVal('id', real_id);

                                    _debug('idx', idx);

                                    if (idx === null) {
                                        return;
                                    }

                                    stb.recordings[idx].internal_id = pvrManager.CreateTask(url, path, start, end);

                                    if (self.is_error(stb.recordings[idx].internal_id)) {
                                        self.remove_record_from_list(real_id);
                                        return;
                                    }

                                    if (stb.recordings[idx].ch_id == stb.player.cur_tv_item.id) {
                                        stb.player.show_rec_icon(stb.recordings[idx]);
                                    }

                                    stb.load(
                                        {
                                            "type": "remote_pvr",
                                            "action": "set_internal_id",
                                            "rec_id": real_id,
                                            "internal_id": stb.recordings[idx].internal_id
                                        },
                                        function (result) {
                                            _debug('on set_internal_id', result);
                                        }
                                    )
                                }
                            );

                        }, record['t_start_ts'] * 1000 - new Date().getTime());
                    } else {
                        stb.player.show_rec_icon(record);
                    }
                },
                this
            );

            //return rec_id;
        };

        this.remove_record_from_list = function (rec_id, show_msg) {
            _debug('pvr_local.remove_record_from_list', rec_id);

            var idx = stb.recordings.getIdxByVal('id', rec_id);

            _debug('idx', idx);

            if (idx === null) {
                return;
            }

            window.clearTimeout(stb.recordings[idx].start_timeout);
            window.clearTimeout(stb.recordings[idx].del_timeout);

            if (stb.player.is_tv) {
                if (stb.player.cur_tv_item.id == stb.recordings[idx].ch_id) {
                    stb.player.hide_rec_icon();
                }
            }

            if (show_msg) {

                var ch_idx = stb.player.channels.getIdxByVal("id", stb.recordings[idx].ch_id);

                if (ch_idx !== null) {
                    var channel_name = stb.player.channels[ch_idx].name;
                } else {
                    channel_name = '';
                }

                var program_name = stb.recordings[idx].program;
                stb.msg.push({
                    msg: get_word('rec_stop_msg').format(program_name, channel_name)
                })
            }

            pvrManager.RemoveTask(stb.recordings[idx].internal_id, 1);

            _debug('stb.recordings before', stb.recordings);
            stb.recordings.splice(idx, 1);
            _debug('stb.recordings after', stb.recordings);
        };

        this.get_clear_url = function (url) {
            _debug('pvr_local.get_clear_url', url);

            var arr = /(\S+:\/\/\S+)/.exec(url);

            if (arr && arr.length == 2) {
                return arr[1];
            }

            return false;
        };

        this.get_record_filename_for_program = function (program) {
            _debug('pvr_local.get_record_filename_for_program', program);

            var cur_prog = program.name;

            _debug('cur_prog', cur_prog);

            var start = new Date(parseInt(program.start_timestamp, 10) * 1000);

            var ch_idx = stb.player.channels.getIdxByVal("id", program.ch_id);

            if (ch_idx !== null) {
                var channel_name = stb.player.channels[ch_idx].name;
            } else {
                channel_name = '';
            }

            return start.getFullYear()
                + '' + this.format_date(start.getMonth() + 1)
                + '' + this.format_date(start.getDate())
                + '-' + this.format_date(start.getHours())
                + '' + this.format_date(start.getMinutes())
                + '' + this.format_date(start.getSeconds())
                + '_' + (channel_name + '_' + cur_prog).toTranslit()
                + '.mpg';
        };

        this.get_record_filename = function (channel) {
            _debug('pvr_local.get_record_filename', channel);

            var epg = stb.epg_loader.get_curr_and_next(channel.id);

            _debug('epg', epg);
            _debug('epg.length', epg.length);

            if (epg && epg.length > 0) {
                var cur_prog = epg[0].name || '';
            } else {
                cur_prog = '';
            }

            _debug('cur_prog', cur_prog);

            var now = new Date();

            return now.getFullYear()
                + '' + this.format_date(now.getMonth() + 1)
                + '' + this.format_date(now.getDate())
                + '-' + this.format_date(now.getHours())
                + '' + this.format_date(now.getMinutes())
                + '' + this.format_date(now.getSeconds())
                + '_' + (channel.name + '_' + cur_prog).toTranslit()
                + '.mpg';
        };

        this.format_date = function (param) {
            if (param < 10) {
                return '0' + param
            }
            return param
        };

        this.stop_channel_rec = function (ch) {
            _debug('pvr_local.stop_channel_rec', ch);

            var idx = stb.recordings.getIdxByVal('ch_id', ch.id);

            _debug('idx', idx);
            _debug('stb.recordings[idx]', stb.recordings[idx]);

            if (idx === null) {
                return;
            }

            var rec_id = stb.recordings[idx].id;

            if (idx !== null) {
                this.stop_confirm.rec_id = rec_id;
                this.stop_confirm.show();
            }
        };

        this.stop_rec = function (rec_id, callback) {
            _debug('pvr_local.stop_rec', rec_id);

            var idx = stb.recordings.getIdxByVal('id', rec_id);

            _debug('idx', idx);

            if (idx === null) {
                return;
            }

            stb.player.hide_rec_icon();

            pvrManager.RemoveTask(stb.recordings[idx].internal_id, 1);

            this.send_stop_rec(rec_id, false, callback);
        };

        this.send_stop_rec = function (rec_id, show_msg, callback) {
            _debug('pvr_local.send_stop_rec', rec_id);

            var idx = stb.recordings.getIdxByVal('id', rec_id);

            _debug('idx', idx);

            if (idx === null) {
                return;
            }

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "stop_record_on_stb",
                    "rec_id": stb.recordings[idx].id
                },
                function (result) {
                    _debug('on stop_record_on_stb', result);
                    this.remove_record_from_list(rec_id, show_msg);

                    if (callback) {
                        callback();
                    }
                },
                this
            );
        };

        this.del = function (real_rec_id, file, callback) {
            _debug('pvr_local.del', real_rec_id);

            var idx = stb.recordings.getIdxByVal('id', real_rec_id);

            if (idx !== null) {
                pvrManager.RemoveTask(stb.recordings[idx].internal_id, 3);
                this.remove_record_from_list(real_rec_id);
            } else {
                var rm_result = stb.RDir('RemoveFile "' + file + '"');
                var rm_tmp_result = stb.RDir('RemoveFile "' + file + '.tmp.ts"');
                _debug('rm_result', rm_result);
                _debug('rm_tmp_result', rm_tmp_result);
            }

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "del_record_on_stb",
                    "rec_id": real_rec_id
                },
                function (result) {
                    _debug('on del_record_on_stb', result);

                    if (callback) {
                        callback();
                    }
                },
                this
            );
        };

        this.is_error = function (result) {
            _debug('pvr_local.check_for_errors', result);

            if (result < 0) {
                if (this.error_codes.hasOwnProperty(result)) {
                    stb.notice.show(this.error_codes[result]);
                } else {
                    stb.notice.show(get_word('pvr_error_unknown'));
                }
                return true;
            }

            return false;
        };

        this.handle_error = function (rec_id) {
            _debug('pvr_local.handle_error', rec_id);

            var task = pvrManager.GetTaskByID(rec_id);

            _debug('task', task);

            task = JSON.parse(task);

            if (task && task.hasOwnProperty('errorCode')) {
                this.is_error(task.errorCode);
            }
        };

        this.remove_all_with_errors = function () {
            _debug('pvr_local.remove_all_active');

            var active_tasks = JSON.parse(pvrManager.GetAllTasks()) || [];
            _debug('active_tasks', active_tasks);

            var need_ro_remove = active_tasks.filter(function (task) {
                return task.state == 3;
            });

            _debug('need_ro_remove', need_ro_remove);
            _debug('stb.recordings', stb.recordings);

            var self = this;

            need_ro_remove.map(function (task) {

                var idx = stb.recordings.getIdxByVal('file', task.fileName);

                _debug('idx', idx);

                if (idx !== null) {
                    self.send_stop_rec(stb.recordings[idx].id);
                }

                return task;
            });
        };
    }

    module.pvr_local = new PvrLocal();

    if (typeof (pvrManager) != "undefined") {
        _debug('stb.user[max_local_recordings]', stb.user['max_local_recordings']);
        pvrManager.SetMaxRecordingCnt(parseInt(stb.user['max_local_recordings'], 10));
        var active_tasks = JSON.parse(pvrManager.GetAllTasks()) || [];
    } else {
        active_tasks = [];
    }

    _debug('active_tasks', active_tasks);

    _debug('stb.recordings before', stb.recordings);

    stb.recordings.map(function (record) {

        var now_ts = Math.ceil(new Date().getTime() / 1000);

        if (record.local == 1 && active_tasks.getIdxByVal('fileName', record.file) === null && now_ts > record.t_start_ts) { // remove old
            _debug('remove old', record);

            module.pvr_local.send_stop_rec(record.id);

        } else if (record.local == 1 && now_ts < record.t_start_ts) { // deferred
            _debug('restore deferred', record);

            var ch_idx = stb.player.channels.getIdxByVal("id", record.ch_id);

            module.pvr_local.remove_record_from_list(record.id);

            if (ch_idx !== null) {
                module.pvr_local.create(
                    stb.player.channels[ch_idx],
                    record.file,
                    record.t_start_ts,
                    record.t_stop_ts,
                    {
                        "name": record.program,
                        "id": record.program_id,
                        "real_id": record.program_real_id
                    }
                );
            }
        } else if (record.local == 1 && now_ts > record.t_start_ts && now_ts < record.t_stop_ts) { // restore current
            _debug('restore current', record);

            var idx = stb.recordings.getIdxByVal('id', record.id);

            if (idx === null) {
                return record;
            }

            var del_timeout = (record.t_stop_ts - record.t_start_ts) * 1000;

            _debug('del_timeout', del_timeout);

            stb.recordings[idx].del_timeout = window.setTimeout(function () {
                _debug('delete rec');
                module.pvr_local.send_stop_rec(record.id, true);
            }, del_timeout);
        }

        return record;
    });

    _debug('stb.recordings after', stb.recordings);

})();

loader.next();
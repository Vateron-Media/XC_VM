/**
 * Remote PVR module
 */

(function () {

    function RemotePvr() {

        this.stop_timeouts = {};

        this.duration_input = new DurationInputBox({ "max_val": stb.user['record_max_length'] });

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
            _debug('remote_pvr.rec_switch', ch);

            _debug('stb.player.prev_layer.on', stb.player.prev_layer.on);

            if (stb.player.prev_layer.on) {
                return;
            }

            var idx = stb.recordings.getIdxByVal('ch_id', ch.id);

            _debug('idx', idx);
            _debug('stb.recordings[idx]', stb.recordings[idx]);

            if (idx !== null) {

                var now = new Date().getTime() / 1000;

                if ((now - stb.recordings[idx].t_start_ts) < 120) {

                    if (this.duration_input.on) {
                        this.duration_input.hide();
                    } else {

                        var self = this;
                        var rec_id = stb.recordings[idx].id;

                        this.duration_input.callback = function (duration) {
                            _debug('callback duration', duration);

                            stb.load(
                                {
                                    "type": "remote_pvr",
                                    "action": "stop_rec_deferred",
                                    "rec_id": rec_id,
                                    "duration": duration
                                },
                                function (result) {
                                    _debug('stop_rec_deferred result', result);

                                    if (result) {

                                        var stop_time = parseInt(result);

                                        var now = new Date().getTime() / 1000;

                                        var stop_t = (stop_time - now) * 1000;

                                        _debug('now', now);
                                        _debug('stop_t', stop_t);

                                        if (stop_t < 0) stop_t = 0;

                                        window.clearTimeout(self.stop_timeouts[rec_id]);

                                        self.stop_timeouts[rec_id] = window.setTimeout(function () {
                                            _debug('delete rec');
                                            _debug('rec_id', rec_id);
                                            var idx = stb.recordings.getIdxByVal('id', rec_id);
                                            _debug('idx', idx);

                                            if (idx === null) {
                                                return;
                                            }

                                            if (stb.player.is_tv) {
                                                if (stb.player.cur_tv_item.id == stb.recordings[idx].ch_id) {
                                                    stb.player.hide_rec_icon();
                                                }
                                            }
                                            _debug('stb.recordings before', stb.recordings);
                                            stb.recordings.splice(idx, 1);
                                            _debug('stb.recordings after', stb.recordings);
                                        }, stop_t);

                                    } else {
                                        stb.notice.show(word['recorder_server_error']);
                                    }

                                },
                                self
                            );
                        };

                        this.duration_input.show();
                    }
                }
            } else {
                this.start_rec(ch.id);
            }

            _debug('stb.recordings', stb.recordings);
        };

        this.start_rec = function (ch_id) {
            _debug('remote_pvr.start_rec', ch_id);

            var self = this;

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "start_rec_now",
                    "ch_id": ch_id

                },
                function (result) {
                    _debug('result', result);

                    if (result) {

                        result = result || {};

                        if (result.error) {
                            stb.notice.show(result.error);
                            return;
                        }

                        var rec_ids = result.data || [];

                        var local_recordings = stb.recordings.filter(function (record) {
                            return record.local == 1;
                        });

                        stb.recordings = local_recordings.concat(rec_ids);
                        _debug('stb.recordings', stb.recordings);

                        var rec_idx = stb.recordings.getIdxByVal('ch_id', ch_id);

                        var record = stb.recordings[rec_idx];

                        _debug('record', record);

                        stb.player.show_rec_icon(record);

                        self.stop_timeouts[record.id] = window.setTimeout(function () {
                            _debug('delete rec');
                            _debug('record.id', record.id);
                            var idx = stb.recordings.getIdxByVal('id', record.id);
                            _debug('idx', idx);

                            if (idx === null) {
                                return;
                            }

                            if (stb.player.is_tv) {
                                if (stb.player.cur_tv_item.id == stb.recordings[idx].ch_id) {
                                    stb.player.hide_rec_icon();
                                }
                            }
                            _debug('stb.recordings before', stb.recordings);
                            stb.recordings.splice(idx, 1);
                            _debug('stb.recordings after', stb.recordings);
                        }, record['t_stop_ts'] * 1000 - new Date().getTime());
                    }
                },
                this
            )
        };

        this.start_rec_deferred = function (program_id) {
            _debug('remote_pvr.start_rec_deferred', program_id);

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "start_rec_deferred",
                    "program_id": program_id

                },

                function (result) {
                    _debug('result', result);

                    result = result || {};

                    if (result.error) {
                        stb.notice.show(result.error);
                    }
                },
                this
            )
        };

        this.stop_rec = function (rec_id, callback) {
            _debug('remote_pvr.stop_rec', rec_id);

            stb.player.hide_rec_icon();

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "stop_rec",
                    "rec_id": rec_id

                },
                function (result) {
                    _debug('result', result);

                    if (result) {
                        var idx = stb.recordings.getIdxByVal('id', rec_id);

                        if (idx !== null) {
                            stb.recordings.splice(idx, 1);
                        }
                    }

                    if (callback) {
                        callback();
                    }
                },
                this
            )
        };

        this.stop_channel_rec = function (ch) {
            _debug('remote_pvr.stop_channel_rec', ch);

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

        this.del = function (rec_id, callback) {
            _debug('remote_pvr.del');

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "del_rec",
                    "rec_id": rec_id
                },
                function (result) {
                    _debug('remote_pvr.del result', result);

                    //this.load_data();
                    if (callback) {
                        callback()
                    }
                },
                this
            )
        };
    }

    module.remote_pvr = new RemotePvr();

    if (module.records) {
        module.records.rest_length_block.show();
    }

})();

loader.next();
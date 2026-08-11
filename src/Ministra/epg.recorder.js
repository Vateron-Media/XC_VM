/*    Epg recorder    */

(function () {

    module.epg_recorder = {

        add_del: function () {
            _debug('epg_recorder.add_del');

            var program = this.get_item();

            _debug('program', program);

            _debug('program.mark_rec', program.mark_rec);

            if (program.mark_rec == 1) {
                if (program.rec_id) {
                    this.del(program.rec_id);
                }
            } else {
                this.add();
            }
        },

        add: function () {
            _debug('epg_recorder.add');

            var ch_id = this.get_ch_id();

            _debug('ch_id', ch_id);

            var ch_idx = stb.player.channels.getIdxById(ch_id);

            _debug('ch_idx', ch_idx);

            if (ch_idx !== null) {

                var channel = stb.player.channels[ch_idx];

            } else {
                ch_idx = module.tv.data_items.getIdxById(ch_id);

                channel = module.tv.data_items[ch_idx];
            }

            _debug('channel', channel);

            stb.player.init_pvr_dialogs();

            if (loader.template == "xc_vm") {
                if (module.pvr_local) {
                    if (channel['allow_local_pvr']) {
                        // show confirm
                        stb.player.local_pvr_confirm.deferred = true;
                        stb.player.local_pvr_confirm.program = this.get_item();
                        stb.player.local_pvr_confirm.channel = channel;
                        stb.player.local_pvr_confirm.show({ parent: this.parent });
                    } else {
                        stb.notice.show(get_word('channel_recording_restricted'));
                    }
                } else {
                    stb.notice.show(get_word('recording_disabled'));
                }
            } else {
                var allow_remote_pvr = module.remote_pvr && channel['allow_pvr'];

                var allow_local_pvr = module.pvr_local && channel['allow_local_pvr'];

                _debug('allow_remote_pvr', allow_remote_pvr);
                _debug('allow_local_pvr', allow_local_pvr);

                if (allow_remote_pvr && allow_local_pvr) {
                    stb.player.pvr_target_select.deferred = true;
                    stb.player.pvr_target_select.program = this.get_item();
                    stb.player.pvr_target_select.channel = channel;
                    stb.player.pvr_target_select.show({ parent: this.parent });
                } else if (allow_remote_pvr) {
                    // show confirm
                    stb.player.remote_pvr_confirm.deferred = true;
                    stb.player.remote_pvr_confirm.program = this.get_item();
                    stb.player.remote_pvr_confirm.show({ parent: this.parent });
                } else if (allow_local_pvr) {
                    // show confirm
                    stb.player.local_pvr_confirm.deferred = true;
                    stb.player.local_pvr_confirm.program = this.get_item();
                    stb.player.local_pvr_confirm.channel = channel;
                    stb.player.local_pvr_confirm.show({ parent: this.parent });
                } else {
                    stb.notice.show(get_word('channel_recording_restricted'));
                }
            }
        },

        del: function (rec_id) {
            _debug('epg_recorder.del', rec_id);

            var idx = stb.recordings.getIdxByVal('id', rec_id);

            _debug('idx', idx);

            if (idx === null) {
                module.remote_pvr.stop_rec(rec_id);
                this.hide_mark(this.get_item().id);
                return;
            }

            if (stb.recordings[idx].local == 1) {
                this.del_local(stb.recordings[idx]);
            } else {
                module.remote_pvr.stop_rec(rec_id);
                this.hide_mark(this.get_item().id);
            }
        },

        add_local: function (programm, path) {
            _debug('epg_recorder.add_local', programm, path);

            var program_id = programm.id;
            var self = this;

            if (program_id != 0) {
                var epg_item = this.get_item();
            } else {
                epg_item = {};
            }

            module.pvr_local.create_for_program(programm, path,
                function (result) {
                    _debug('on create_for_program', result);

                    if (result && program_id != 0) {
                        self.show_mark(program_id);
                        epg_item.rec_id = result;
                    }
                }
            );
        },

        del_local: function (recording) {
            _debug('epg_recorder.del_local', recording);

            module.pvr_local.del(recording.id, recording.file);
            this.hide_mark(this.get_item().id);
        },

        add_remote: function (programm) {
            _debug('epg_recorder.add_remote', programm);

            var program_id = programm.id;
            var program_real_id = programm.real_id;
            var self = this;
            var epg_item = this.get_item();

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "start_rec_deferred",
                    "program_id": program_real_id

                },

                function (result) {
                    _debug('result', result);

                    result = result || {};

                    if (result.error) {
                        stb.notice.show(result.error);
                        return;
                    }

                    if (result.data) {
                        this.show_mark(program_id);
                        epg_item.rec_id = result.data;
                    } else {
                        stb.notice.show(word['recorder_server_error']);
                    }
                },
                this
            )
        },

        del_remote: function () {
            _debug('epg_recorder.del_remote');

            var rec_id = this.get_item().rec_id;

            _debug('rec_id', rec_id);

            stb.load(
                {
                    "type": "remote_pvr",
                    "action": "del_rec",
                    "rec_id": rec_id

                },
                function (result) {
                    _debug('result', result);

                    var rm_idx = stb.recordings.getIdxByVal('id', rec_id);

                    if (rm_idx !== null) {
                        stb.recordings.splice(rm_idx, 1);
                    }
                },
                this
            );

            this.hide_mark(this.get_item().id);
        },

        get_item: function () {
            _debug('epg_recorder.get_item');

            return this.parent.data_items[this.parent.cur_row].epg[this.parent.cur_cell_col];
        },

        get_ch_id: function () {
            _debug('epg_recorder.get_ch_id');

            return this.parent.data_items[this.parent.cur_row].ch_id;
        },

        show_mark: function (program_id) {
            _debug('epg_recorder.show_mark', program_id);

            var mark_idx = this.parent.marks_map.getIdxByVal('program_id', program_id);

            if (mark_idx !== null) {
                this.parent.marks_map[mark_idx].mark_rec.show();

                this.get_item().mark_rec = 1;
                this.parent.set_active_row(this.parent.cur_row);
            }
        },

        hide_mark: function (program_id) {
            _debug('epg_recorder.hide_mark', program_id);

            var mark_idx = this.parent.marks_map.getIdxByVal('program_id', program_id);

            if (mark_idx !== null) {
                this.parent.marks_map[mark_idx].mark_rec.hide();

                this.get_item().mark_rec = 0;
                this.parent.set_active_row(this.parent.cur_row);
            }
        }
    };


})();

loader.next();
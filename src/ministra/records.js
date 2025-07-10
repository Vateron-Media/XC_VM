/**
 * Records module
 */

(function () {

    /* RECORDS */
    function Records() {

        this.layer_name = 'records';

        this.row_blocks = ['ch_name', 't_start', 'length'];

        this.load_params = {
            "type": "remote_pvr",
            "action": "get_ordered_list"
        };

        this.superclass = ListLayer.prototype;

        this.init = function () {
            _debug('records.init');

            this.superclass.init.call(this);

            this.rest_length_block = create_block_element('rest_length_block', this.dom_obj);

            create_inline_element('rest_length_title', this.rest_length_block).innerHTML = get_word('rest_length_title') + ': ';
            this.rest_length = create_inline_element('rest_length', this.rest_length_block);

            if (!module.remote_pvr) {
                this.rest_length_block.hide();
            }

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
                        if (scope.stop_confirm.record.local == 1) {
                            module.pvr_local.stop_rec(scope.stop_confirm.record.id, function () {
                                scope.load_data()
                            });
                        } else {
                            module.remote_pvr.stop_rec(scope.stop_confirm.record.id, function () {
                                scope.load_data()
                            });
                        }
                    }
                }
            ));

            this.delete_comfirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('remote_pvr_del_confirm')
            });
            this.delete_comfirm.getTextDomObj().style.textAlign = "center";
            this.delete_comfirm.enableOnExitClose();

            this.delete_comfirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.delete_comfirm.hide();
                    }
                }
            ));

            this.delete_comfirm.addItem(new ModalFormButton(
                {
                    "value": get_word("yes_btn"),
                    "onclick": function () {
                        scope.delete_comfirm.hide();
                        if (scope.delete_comfirm.record.local == 1) {
                            module.pvr_local.del(scope.delete_comfirm.record.id, scope.delete_comfirm.record.file, function () {
                                scope.load_data()
                            });
                        } else {
                            module.remote_pvr.del(scope.delete_comfirm.record.id, function () {
                                scope.load_data()
                            });
                        }
                    }
                }
            ));
        };

        this.hide = function (do_not_reset) {
            _debug('records.hide', do_not_reset);

            try {

                if (this.on && !do_not_reset) {
                    if (stb.player.on) {
                        stb.player.stop();
                    }
                }

                this.superclass.hide.call(this, do_not_reset);

            } catch (e) {
                _debug(e);
            }
        };

        this.play = function (play_url) {
            _debug('records.play', play_url);

            var self = this;

            if (!this.data_items[this.cur_row].started) {
                return;
            }

            if (this.data_items[this.cur_row].local == 1) {

                if (stb.IsFileExist && stb.IsFileExist(this.data_items[this.cur_row].file)) {

                    this.hide(true);

                    stb.player.prev_layer = this;
                    stb.player.need_show_info = 1;
                    stb.player.play(this.data_items[this.cur_row]);
                } else if (this.data_items[this.cur_row].ended == 0) {
                    stb.notice.show(get_word('rec_not_ended'));
                } else {
                    stb.notice.show(get_word('rec_file_missing'));
                }

                return;
            }

            var current_record = this.data_items[this.cur_row];

            stb.player.on_create_link = function (result) {
                _debug('records.on_create_link', result);

                if (result.error == 'limit') {
                    stb.notice.show(word['player_limit_notice']);
                } else if (result.error == 'nothing_to_play') {
                    stb.notice.show(word['player_file_missing']);
                } else if (result.error == 'link_fault') {
                    stb.notice.show(word['player_server_error']);
                } else {

                    if (play_url) {
                        self.hide(true);

                        stb.player.prev_layer = self;
                        stb.player.need_show_info = 1;
                        stb.player.play_now(result.cmd);
                    } else {

                        self.add_download.call(self, result.cmd, result.to_file);
                    }
                }
            };

            var channel = stb.player.channels[stb.player.channels.getIdxById(this.data_items[this.cur_row].ch_id)];

            _debug('this.data_items[this.cur_row].ch_id', this.data_items[this.cur_row].ch_id);
            _debug('channel', channel);

            if (channel) {
                stb.player.cur_tv_item = channel;
            }

            var item = this.data_items[this.cur_row].clone();

            if (!play_url) {
                item.download = true;
            }

            stb.player.play(item);
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.play.bind(key.OK, this, true);

            (function () {

                this.hide();
                main_menu.show();
            }).bind(key.EXIT, this).bind(key.LEFT, this).bind(key.MENU, this);
        };

        this.get_epg_item = function () {
            _debug('epg_reminder.get_item');

            return this.parent.data_items[this.parent.cur_row].epg[this.parent.cur_cell_col];
        };

        this.set_active_row = function (num) {
            _debug('records.set_active_row', num);

            try {
                this.superclass.set_active_row.call(this, num);
            } catch (e) {

            }

            if (!this.data_items[this.cur_row]) {
                this.color_buttons.get('red').disable();
                this.color_buttons.get('green').disable();
                this.color_buttons.get('yellow').disable();
            } else {

                if (!parseInt(this.data_items[this.cur_row].ended)) {
                    if (parseInt(this.data_items[this.cur_row].started)) {
                        this.color_buttons.get('red').disable();
                        this.color_buttons.get('green').enable();
                        this.color_buttons.get('yellow').disable();
                    } else {
                        this.color_buttons.get('red').enable();
                        this.color_buttons.get('green').disable();
                        if (module.downloads && this.data_items[this.cur_row].local != 1) {
                            this.color_buttons.get('yellow').enable();
                        } else {
                            this.color_buttons.get('yellow').disable();
                        }
                    }
                } else {
                    this.color_buttons.get('red').enable();
                    this.color_buttons.get('green').disable();

                    if (module.downloads && this.data_items[this.cur_row].local != 1) {
                        this.color_buttons.get('yellow').enable();
                    } else {
                        this.color_buttons.get('yellow').disable();
                    }
                }
            }
        };

        this.del_confirm_toggle = function () {
            _debug('records.del_confirm');

            this.delete_comfirm.record = this.data_items[this.cur_row];
            this.delete_comfirm.show();
        };

        this.stop_confirm_toggle = function () {
            _debug('remote_pvr.stop_confirm');

            this.stop_confirm.record = this.data_items[this.cur_row];
            this.stop_confirm.show();
        };

        this.fill_list = function (data) {
            _debug('records.fill_list');

            this.rest_length.innerText = stb.clock.convert_sec_to_human_hours(this.result.records_rest_length);

            data = data.map(function (record) {

                if (record.local == 1 && stb.IsFileExist && !stb.IsFileExist(record.file)) {
                    record.open = true;
                }

                return record;
            });

            this.superclass.fill_list.call(this, data);
        };

        this.get_link = function (cmd, dummy, callback) {
            _debug('records.get_link', cmd);

            if (cmd.indexOf('://') < 0) {

                stb.player.on_create_link = function (result) {
                    _debug('records.on_create_link', result);

                    if (result.cmd) {
                        var match;
                        if (match = /[\s]([^\s]*)$/.exec(result.cmd)) {
                            result.cmd = match[1];
                        }
                    }
                    callback && callback(result.cmd);
                }

            } else {
                callback(cmd);
                return;
            }

            stb.player.create_link('remote_pvr', cmd);
        };

        this.add_download = function (url, to_filename) {
            _debug('records.add_download', url, to_filename);

            if (module.downloads) {
                _debug('downloads');
                var dialog_options = { "parent": this, "secure_url": true, "name": to_filename };
                dialog_options.url = url;
                module.downloads.dialog.show(dialog_options);
            }
        }
    }

    Records.prototype = new ListLayer();

    var records = new Records();

    records.bind();
    records.init();

    records.set_wide_container();

    records.init_left_ear(word['ears_back']);

    records.init_color_buttons([
        { "label": word['remote_pvr_del'], "cmd": records.del_confirm_toggle },
        { "label": word['remote_pvr_stop'], "cmd": records.stop_confirm_toggle },
        {
            "label": word['downloads_download'], "cmd": function () {
                module.downloads ? records.play.call(records, false) : ''
            }
        },
        { "label": word['empty'], "cmd": '' }
    ]);

    records.init_header_path(word['records_title']);

    records.hide();

    module.records = records;

    /* END RECORDS */

    main_menu.add(word['records_title'], [], 'mm_ico_rec.png', function () {
        main_menu.hide();
        module.records.show();
    },
        module.records);

})();

loader.next();
(function () {

    if (typeof (dvbManager) === "undefined" || JSON.parse(dvbManager.GetSupportedScanTypes()).length == 0) {
        return;
    }

    module.dvb = {

        init: function () {
            this.init_channels();

            this.scan_promt = new ModalForm({
                "title": get_word('no_dvb_channels_title'),
                "parent": main_menu,
                "text": get_word('go_to_dvb_settings')
            });
            this.scan_promt.enableOnExitClose();

            var scope = this;

            this.scan_promt.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.scan_promt.hide();
                    }
                }
            ));
        },

        scan: function (dvb_params) {
            _debug('dvb.scan', dvb_params);

            dvbManager.StopChannelScan();

            if (typeof (dvb_params) == 'object') {
                dvbManager.SetScanParams(JSON.stringify(dvb_params));
                dvbManager.StartChannelScan(dvb_params.type);
            }
        },

        manual_scan: function (dvb_params) {
            _debug('dvb.scan', dvb_params);

            dvbManager.StopChannelScan();

            if (typeof (dvb_params) == 'object') {
                dvbManager.SetScanParams(JSON.stringify(dvb_params));
                dvbManager.StartChannelScanManual(dvb_params.frequency, dvb_params.frequency, dvb_params.type, dvb_params.bandwidth, 1000);
            }
        },

        on_scan_result: function (state, e) {
            _debug('dvb.on_scan_result', state, e);

            stbWebWindow && stbWebWindow.messageBroadcast && stbWebWindow.messageBroadcast(e);
        },

        stop_scan: function () {
            _debug('dvb.stop_scan');
            dvbManager.StopChannelScan();
        },

        init_channels: function () {
            _debug('dvb.get_channels');

            var channels = dvbManager.GetChannelList() || "[]";
            _debug('channels', channels);

            channels = JSON.parse(channels).channels || [];

            if (channels.length == 0 && stb.player.channels.length == 0) {
                var self = this;
                stb.on_first_menu_show = function () {
                    self.scan_promt.show();
                };
                return;
            }

            this.channels = channels.filter(function (channel) {
                return channel.isRadio == "false";
            });

            stb.load(
                {
                    "type": "itv",
                    "action": "save_dvb_channels",
                    "channels": encodeURIComponent(JSON.stringify(this.channels))
                },
                function (result) {
                    _debug('on save_dvb_channels', result);

                    stb.load_channels();
                    stb.load_fav_channels();
                    stb.load_fav_itv();
                },
                this,
                'POST'
            );
        },

        clear_dvb_channels: function () {
            dvbManager.ClearChannelList();
            this.init_channels();
        },

        get_channels: function () {
            return this.channels;
        },

        update_epg: function (dvb_ch_id) {
            _debug('dvb.update_epg', dvb_ch_id);

            var ch_id = this.dvb_id_to_ch_id(dvb_ch_id);

            _debug('ch_id', ch_id);

            if (typeof (dvbManager) === 'undefined' || !stb.player.channels) {
                return;
            }

            var epg = dvbManager.GetEPGSchedule(dvb_ch_id);

            _debug('epg', epg);

            try {
                epg = JSON.parse(epg);
                epg = epg.events || [];
            } catch (e) {
                _debug(e);
                epg = [];
            }

            if (epg.length > 0) {
                epg = epg.map(function (program) {
                    var end_timestamp = parseInt(program.start, 10) + parseInt(program.duration, 10);

                    return {
                        'ch_id': ch_id,
                        'real_id': ch_id + '_' + program.start,
                        't_time': stb.clock.convert_timestamp_to_human_time(program.start),
                        't_time_to': stb.clock.convert_timestamp_to_human_time(end_timestamp),
                        'start_timestamp': parseInt(program.start, 10),
                        'stop_timestamp': end_timestamp,
                        'name': program.name,
                        'descr': program.details,
                        'duration': program.duration
                    }
                });

                stb.epg_loader.epg[ch_id] = epg;
            }
        },

        get_epg_for_page: function (dvb_ch_id, date, page_number, items_per_page) {
            _debug('dvb.get_epg_for_page', dvb_ch_id, date, page_number, items_per_page);

            if (page_number == 0) {
                var default_selected = true;
                page_number = 1;
            } else {
                default_selected = false;
            }

            _debug('default_selected', default_selected);

            page_number = page_number || 1;
            items_per_page = items_per_page || false;

            var ch_id = this.dvb_id_to_ch_id(dvb_ch_id);

            _debug('ch_id', ch_id);

            var from_ts = date.getTime() / 1000;
            date.setDate(date.getDate() + 1);
            var to_ts = date.getTime() / 1000;
            var now_ts = new Date().getTime() / 1000;

            var epg = [];

            if (stb.epg_loader.epg[ch_id]) {

                var length = stb.epg_loader.epg[ch_id].length;

                _debug('from_ts', from_ts);
                _debug('to_ts', to_ts);
                _debug('epg length', length);

                var selected_item_idx = 0;

                var memo = module.epg_reminder && module.epg_reminder.memos || [];

                for (var i = 0; i < length; i++) {

                    if (stb.epg_loader.epg[ch_id][i].start_timestamp < from_ts) {
                        continue;
                    }

                    if (stb.epg_loader.epg[ch_id][i].start_timestamp > to_ts) {
                        _debug('break', i);
                        break;
                    }

                    if (default_selected && stb.epg_loader.epg[ch_id][i].start_timestamp < now_ts && stb.epg_loader.epg[ch_id][i].stop_timestamp > now_ts) {
                        selected_item_idx = epg.length;
                    }

                    var idx = epg.length;

                    epg[idx] = stb.epg_loader.epg[ch_id][i];

                    epg[idx].open = now_ts > stb.epg_loader.epg[ch_id][i].start_timestamp ? 0 : 1;

                    if (epg[idx].open) {
                        var memo_idx = memo.getIdxByVal('tv_program_real_id', epg[idx].real_id);

                        epg[idx].mark_memo = memo_idx !== null ? 1 : 0;
                    }
                }
            }

            _debug('epg.length', epg.length);

            _debug('selected_item_idx', selected_item_idx);

            var result = {};
            result.total_items = epg.length;

            if (default_selected && selected_item_idx > 0) {
                result.cur_page = Math.ceil(selected_item_idx / items_per_page);
                result.selected_item = selected_item_idx % items_per_page + 1;
                var page = result.cur_page - 1;
            } else {
                result.selected_item = 0;
                result.cur_page = 0;
                page = page_number - 1;
            }

            _debug('result.cur_page', result.cur_page);
            _debug('result.selected_item', result.selected_item);
            _debug('page', page);

            if (items_per_page !== false) {
                epg = epg.slice(page * items_per_page, page * items_per_page + items_per_page);
            }

            result.data = epg;

            return result;
        },

        get_epg_for_table: function (dvb_ch_id, from_ts, to_ts) {
            _debug('dvb.get_epg_for_table', dvb_ch_id, from_ts, to_ts);

            var epg = [];

            var ch_id = this.dvb_id_to_ch_id(dvb_ch_id);

            _debug('ch_id', ch_id);

            _debug('!stb.epg_loader.epg[ch_id]', !stb.epg_loader.epg[ch_id]);

            if (!stb.epg_loader.epg[ch_id]) {
                return epg;
            }

            var length = stb.epg_loader.epg[ch_id].length;

            _debug('length', length);

            for (var i = 0; i < length; i++) {
                if (stb.epg_loader.epg[ch_id][i].start_timestamp < to_ts && stb.epg_loader.epg[ch_id][i].stop_timestamp > from_ts) {
                    var program = stb.epg_loader.epg[ch_id][i];

                    program['display_duration'] = program['duration'];
                    program['larr'] = 0;
                    program['rarr'] = 0;

                    if (program['start_timestamp'] < from_ts) {
                        program['larr'] = 1;
                        program['display_duration'] = program['duration'] - (from_ts - program['start_timestamp']);
                    }

                    if (program['stop_timestamp'] > to_ts) {
                        program['rarr'] = 1;
                        program['display_duration'] = program['duration'] - (program['stop_timestamp'] - to_ts);
                    }

                    program['mark_rec'] = 0;
                    program['mark_memo'] = 0;
                    program['mark_archive'] = 0;

                    var start_date = new Date(parseInt(program['start_timestamp'] * 1000, 10));

                    var date = start_date.getDate();
                    if (date < 10) {
                        date = '0' + date
                    }

                    var month = start_date.getMonth() + 1;
                    if (month < 10) {
                        month = '0' + month
                    }

                    program['on_date'] = get_word('week_arr')[start_date.getDay() - 1] + ' ' + date + '.' + month + '.' + start_date.getFullYear();

                    epg[epg.length] = program;
                }
            }

            _debug('epg', epg);

            return epg;
        },

        dvb_id_to_ch_id: function (dvb_ch_id) {
            return dvb_ch_id.replace('T', '').replace('C', '').replace(/_/g, '');
        },

        set_scan_type: function (scan_type) {
            _debug('dvb.set_scan_type', scan_type);

            stb.RDir('setenv dvb_type ' + scan_type);
        },

        set_antenna_power: function (enable_power) {
            _debug('dvb.set_antenna_power', enable_power);

            stb.RDir('setenv dvb_power ' + (enable_power ? 'true' : 'false'));

            dvbManager.SetAntennaPower(enable_power);
        }
    };

    module.dvb.init();

})();

loader.next();
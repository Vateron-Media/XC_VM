/*
    Document   : Downloads
    Created on : 12.07.2011, 11:26:16
    Author     : Affect
*/

(function () {
    /* Downloads */
    function downloads_constructor() {
        this.layer_name = 'downloads';
        this.row_blocks = ['number', 'd_name', 'd_progress', 'd_status'];
        this.superclass = ListLayer.prototype;
        this.dialog = new downloads_dialog_constructor();
        this.dialog.hide();
        this._queue = [];

        this.states = [
            get_word("download_stopped"),
            get_word("download_waiting_queue"),
            get_word("download_running"),
            get_word("download_completed"),
            get_word("download_temporary_error"),
            get_word("download_permanent_error")
        ];

        this.init = function () {
            this.superclass.init.call(this);

            var self = this;

            stb.usbdisk.add_onmount_callback(function () {
                self.every_interval.call(self);
            });

            this.load_queue();
        };

        this.save_queue = function () {
            _debug('downloads.save_queue');

            //_debug('this._queue before', this._queue);

            var prepared_queue = this._queue.clone().map(function (item) {

                if (typeof (item.url) == 'string') {
                    item.url = encodeURIComponent(item.url)
                }

                return item;
            });

            //_debug('prepared_queue', prepared_queue);
            //_debug('this._queue after', this._queue);

            stb.load(
                {
                    "type": "downloads",
                    "action": "save",
                    "downloads": JSON.stringify(prepared_queue)
                },
                function (result) {
                    _debug('on save_queue', result);
                },
                this
            )
        };

        this.load_queue = function () {
            _debug('downloads.load_queue');

            stb.load(
                {
                    "type": "downloads",
                    "action": "get_all"
                },
                function (result) {
                    _debug('on load_queue', result);

                    try {
                        this._queue = result && JSON.parse(result) || [];
                    } catch (e) {
                        _debug("Parse error", e);
                        this._queue = [];
                    }

                    this._queue = this._queue.map(function (item) {
                        //if (item.state != 3 && item.state != 0){
                        if (item.state == 1 || item.state == 2) {
                            item.state = 1;
                        }

                        if (typeof (item.url) == 'string') {
                            //item.url = decodeURIComponent(item.url)
                        }

                        return item;
                    });

                    _debug('saved this._queue', this._queue);

                    var internal_queue = [];
                    var self = this;

                    var active_queue = eval(stbDownloadManager.GetQueueInfo());

                    _debug('active_queue', active_queue);

                    active_queue.every(function (download) {
                        //stbDownloadManager.DeleteJob(download.id, false);

                        //download.id          = 'f_' + Math.ceil(Math.random() * 10000);
                        //download.state       = 1;
                        //download.progressPct = -1;
                        //download.fake        = true;

                        var idx = self._queue.getIdxByVal('filePath', download.filePath);

                        if (idx !== null) {
                            self._queue.splice(idx, 1);
                        }

                        internal_queue.push(download);
                    });

                    _debug('internal_queue', internal_queue);

                    this._queue = internal_queue.concat(this._queue);
                    this.save_queue();

                    if (this._queue.length > 0) {
                        window.clearInterval(this.interval);
                        this.every_interval();
                        this.interval = window.setInterval(function () {
                            self.every_interval.call(self)
                        }, 3500);
                    }
                },
                this
            )
        };

        /**
         * @param {object} download
         */
        this.add = function (download) {

            download.id = 'f_' + Math.ceil(Math.random() * 10000);
            download.state = 1;
            download.progressPct = -1;
            download.fake = true;

            var download_exist = this._queue.some(function (item) {
                return download.filePath == item.filePath;
            });

            if (download_exist) {
                return;
            }

            stbDownloadManager.AddJob(download.url, this.get_full_file_path(download));

            window.clearInterval(this.interval);
            this.every_interval();
            this.interval = window.setInterval(function () {
                self.every_interval.call(self)
            }, 3500);
        };

        this.adjust_priority = function (id, rise) {
            _debug('downloads.adjust_priority', id, rise);

            //var id = row_item.id;

            var idx = this._queue.getIdxByVal('id', id);
            _debug('idx', idx);

            /*if (idx !== null){
                return;
            }*/

            var active_queue = eval(stbDownloadManager.GetQueueInfo());
            var in_active_queue_idx = active_queue.getIdxByVal('filePath', this._queue[idx].filePath);

            _debug('in_active_queue_idx', in_active_queue_idx);

            if (idx === null && in_active_queue_idx === null) {
                return;
            }

            if (rise) {
                if (in_active_queue_idx !== null) {
                    stbDownloadManager.AdjustJobPriority(active_queue[in_active_queue_idx].id, true);
                }

                if (idx > 0) {
                    var item = this._queue.splice(idx, 1);
                    this._queue.splice(idx - 1, 0, item[0]);
                }
            } else {
                if (in_active_queue_idx !== null) {
                    stbDownloadManager.AdjustJobPriority(active_queue[in_active_queue_idx].id, false);
                }

                if (idx < this._queue.length - 1) {
                    item = this._queue.splice(idx, 1);
                    this._queue.splice(idx + 1, 0, item[0]);
                }
            }

            this.save_queue();
            this.every_interval();
        };

        this.del = function (id, del_file) {
            _debug('del', id, del_file);

            var idx = this._queue.getIdxByVal('id', id);
            _debug('idx', idx);

            if (idx !== null) {

                if (del_file) {
                    stb.RDir('RemoveFile "' + this.get_full_file_path(this._queue[idx]) + '.temp"');
                    var remove_result = stb.RDir('RemoveFile "' + this.get_full_file_path(this._queue[idx]) + '"');
                    _debug('remove_result', remove_result);
                }

                var scope = this;

                eval(stbDownloadManager.GetQueueInfo()).every(function (download) {
                    if (download.filePath == scope._queue[idx]['filePath']) {
                        stbDownloadManager.DeleteJob(download.id, false);
                    }
                });

                this._queue.splice(idx, 1);
                this.save_queue();
            }
        };

        this.start_stop_job = function (row_item) {
            _debug('downloads.start_stop_job', row_item);

            var idx = this._queue.getIdxByVal('id', row_item.id);

            _debug('idx', idx);

            if (idx == null) {
                return;
            }

            var active_queue = eval(stbDownloadManager.GetQueueInfo());

            var active_idx = active_queue.getIdxByVal('filePath', this._queue[idx].filePath);

            _debug('active_idx', active_idx);

            var self = this;

            if (this._queue[idx].state == 3) {
                return;
            }

            if ([0, 4, 5].indexOf(parseInt(this._queue[idx].state, 10)) >= 0) {
                // start job

                if (active_idx !== null) {
                    stbDownloadManager.StartJob(active_queue[active_idx].id);
                }

                _debug('set waiting');
                this._queue[idx].state = 1;

                window.clearInterval(this.interval);
                this.every_interval();
                this.interval = window.setInterval(function () {
                    self.every_interval.call(self)
                }, 3500);

            } else {
                // stop job

                if (active_idx !== null) {
                    stbDownloadManager.StopJob(active_queue[active_idx].id);
                    //stbDownloadManager.DeleteJob(active_queue[active_idx].id, false);
                }

                this._queue[idx].state = 0;
            }

            this.save_queue();
        };

        this.hide = function (do_not_reset) {
            _debug('downloads.hide', do_not_reset);
            this.exit();
            //this.save_queue();
            this.superclass.hide.call(this, do_not_reset);
        };

        this.show = function () {
            this.superclass.show.call(this);
            var self = this;
            /*this.interval = setInterval(function(){self.every_interval.call(self);}, 3500);*/
            this.every_interval(false);
        };

        this.get_url = function (download, callback) {
            _debug('downloads.get_url');

            var url = download.url;

            _debug('url', url);

            if (typeof (url) == 'object') {

                var exec = eval(url.exec);

                if (typeof (exec) == 'function') {
                    _debug('url.options', url.options);
                    url.options[2] = function (cmd) {

                        callback(cmd);
                    };

                    exec.apply(null, url.options);
                }

            } else {
                callback(url)
            }
        };

        this.update_file_path = function (file_path, url) {
            _debug('downloads.update_file_path', file_path, url);

            if (url.lastIndexOf('.') > url.length - 6) {

                var new_extension = url.substr(url.lastIndexOf('.') + 1);

                file_path = file_path.slice(0, file_path.lastIndexOf('.') + 1) + new_extension;
            }

            return file_path;
        };

        this.get_full_file_path = function (download) {
            _debug('download.get_full_file_path', download);

            if (download.hasOwnProperty('mountPoint')) {
                return download.mountPoint + '/' + download.filePath
            }

            return download.filePath
        };

        this.every_interval = function (do_load_data) {
            //_debug('downloads.every_interval');

            var obj = [];
            var self = this;
            if (stbDownloadManager) {

                var active_queue = eval(stbDownloadManager.GetQueueInfo());

                _debug('active_queue', active_queue);

                //obj = this._queue;
                //obj = active_queue.concat(this._queue);

                var active_download_exits = active_queue.some(function (item) {
                    return [1, 2].indexOf(parseInt(item.state, 10)) != -1;
                });

                _debug('active_download_exits', active_download_exits);

                var need_to_save = false;

                //if (active_download_exits){

                var diff = active_queue.filter(function (active_download) {
                    return !self._queue.some(function (download) {
                        return active_download.filePath == download.filePath;
                    });
                });

                _debug('diff', diff);

                if (diff && diff.length > 0) {
                    need_to_save = true;
                    this._queue = diff.concat(this._queue);
                }
                //}

                obj = this._queue;

                active_queue.every(function (item) {
                    var idx = self._queue.getIdxByVal('filePath', item.filePath);

                    _debug('filePath idx', idx);

                    if (idx === null) {
                        return;
                    }

                    if ([1, 2].indexOf(parseInt(item.state, 10)) == -1) {
                        stbDownloadManager.DeleteJob(item.id, false);
                    }

                    if (self._queue[idx].state != item.state) {
                        need_to_save = true;
                    }

                    self._queue[idx].state = item.state;
                    self._queue[idx].progressPct = item.progressPct;
                });

                if (need_to_save) {
                    this.save_queue();
                }

                var clear_queue = this._queue.filter(function (item) {
                    return item.state == 1
                });

                _debug('clear_queue', clear_queue);

                if (!active_download_exits && clear_queue.length == 0) {
                    window.clearInterval(this.interval);
                }

                if (clear_queue.length > 0 && !active_download_exits) {

                    var download = clear_queue[0];

                    var idx = this._queue.getIdxByVal('id', download.id);

                    _debug('idx', idx);

                    this.get_url(clear_queue[0], function (cmd) {

                        _debug('downloads.on_create_link', cmd);

                        if (!cmd) {
                            if (idx !== null) {
                                self._queue[idx].state = 5;
                            }
                            return;
                        }

                        _debug('idx', idx);
                        _debug('self._queue', self._queue);
                        _debug('self._queue.length', self._queue.length);
                        _debug('self._queue[idx]', self._queue[idx]);

                        self._queue[idx].filePath = self.update_file_path(self._queue[idx].filePath, cmd);

                        _debug('stbDownloadManager.AddJob', cmd, self._queue[idx].filePath);

                        var result = stbDownloadManager.AddJob(cmd, self.get_full_file_path(self._queue[idx]));

                        _debug('AddJob result', result);

                        if (!result) {
                            self._queue[idx].state = 5;
                        }
                    });
                }
            }

            if (!this.on) {
                return;
            }

            this.forFill = null;
            this.forFill = [];
            for (var i = 0; i < obj.length; i++) {

                _debug('obj[i].progressPct', obj[i].progressPct);
                _debug('typeof obj[i].progressPct', typeof (obj[i].progressPct));

                if (typeof (obj[i].progressPct) == 'number') {
                    obj[i].progressPct = obj[i].progressPct.toString();
                }

                this.forFill.push({
                    'number': (i + 1).toString(),
                    'd_name': obj[i].filePath.split('/')[obj[i].filePath.split('/').length - 1],
                    'd_progress': (obj[i].progressPct != '-1') ?
                        '<span class="pb"><span style="width:' + (obj[i].progressPct * 110 / 100) + 'px;"></span></span><span class="txt">' + obj[i].progressPct.substr(0, 4) + '%</span>' :
                        '<span class="pb"><span style="width:0;"></span></span><span class="txt">0%</span>',

                    'd_status': this.states[parseInt(obj[i].state)],
                    'state': obj[i].state,
                    'id': obj[i].id,
                    'fake': obj[i].hasOwnProperty('fake') ? obj[i].fake : false
                });
            }
            if (!do_load_data || do_load_data != false) {
                this.load_data(true);
            }
        };

        this.load_data = function (true_arg) {

            this.total_pages = Math.ceil(this.forFill.length / 14);
            this.set_total_items(this.forFill.length);

            var begin = (this.cur_page - 1) * 14;
            var end = this.cur_page * 14;
            this.data_items = this.forFill.slice(begin, end);

            this.result = {
                selected_item: (true_arg) ? this.cur_row : 0,
                cur_page: 0
            };
            this.fill_list(this.data_items);
        };

        this.reset = function () {
            this.cur_row = 0;
            this.total_pages = 1;
            this.set_total_items(0);
        };

        this.bind = function () {
            this.superclass.bind.apply(this);
            (function () {
                /*if(this.data_items[this.cur_row].state == 0 || this.data_items[this.cur_row].state == 5 || this.data_items[this.cur_row].state == 4 ){
                    stbDownloadManager.StartJob(this.data_items[this.cur_row].id)
                }
                else{
                    stbDownloadManager.StopJob(this.data_items[this.cur_row].id)
                }
                var self=this;*/
                this.start_stop_job(this.data_items[this.cur_row]);
                setTimeout(function () {
                    self.every_interval.call(self);
                }, 250);
            }).bind(key.OK, this);
            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.LEFT, this).bind(key.MENU, this).bind(key.EXIT, this);
        };

        this.exit = function () {
            /*clearInterval(this.interval);
            this.interval = null;*/
        };
        this.drawCreateDialog = function () {
            this.dialog.show({ "parent": this });//,"url":'http://cs13112.vkontakte.ru/u72912054/video/a372ac588e.720.mp4',"name":'Part 9 "И чо".mp4'
        };

        this.layer_z_index = 0;
        this.interval = null;
        this.forFill = [];
        this.reset();
        this.delete_switcher = function () {
            if (this.delete_menu && this.delete_menu.on) {
                this.delete_menu.hide();
            } else {
                this.delete_menu.show();
            }
        };
        this.init_delete_menu = function (map, options) {
            this.delete_menu = new bottom_menu(this, options);
            this.delete_menu.init(map);
            this.delete_menu.bind();
        };

        this.identical_download_exist = function (url, queue) {
            _debug('downloads.identical_download_exist', url);

            var normalized_url = url.substr(0, url.lastIndexOf('/'));

            _debug('normalized_url', normalized_url);

            if (typeof (stbDownloadManager) == 'undefined') {
                return false;
            }

            if (!queue) {
                queue = JSON.parse(stbDownloadManager.GetQueueInfo());
            }

            _debug('queue', queue);

            return queue.some(function (item) {
                _debug('item.url', item.url);
                _debug('normalized_url', normalized_url);
                _debug('item.url.indexOf(normalized_url)', item.url.indexOf(normalized_url));

                return item.url.indexOf(normalized_url) != -1 && item.state != 3;
            });
        }
    }

    downloads_constructor.prototype = new ListLayer();
    var downloads = new downloads_constructor();
    downloads.bind();
    downloads.init();
    downloads.init_left_ear(word['ears_back']);
    downloads.init_header_path(get_word('downloads_title'));
    var self = downloads;
    downloads.init_color_buttons([
        {
            "label": get_word('downloads_create'), "cmd": (function () {
                this.drawCreateDialog();
            })
        },
        {
            "label": get_word('downloads_move_up'), "cmd": (function () {
                //if (downloads.data_items[downloads.cur_row].fake){
                downloads.adjust_priority(downloads.data_items[downloads.cur_row].id, true);
                /*}else{
                    stbDownloadManager.AdjustJobPriority(downloads.data_items[downloads.cur_row].id, true);
                }*/
                clearInterval(downloads.interval);
                downloads.interval = null;
                downloads.interval = setInterval(function () {
                    self.every_interval.call(self);
                }, 3500);
            })
        },
        {
            "label": get_word('downloads_move_down'), "cmd": (function () {
                //if (downloads.data_items[downloads.cur_row].fake){
                downloads.adjust_priority(downloads.data_items[downloads.cur_row].id, false);
                /*}else{
                    stbDownloadManager.AdjustJobPriority(downloads.data_items[downloads.cur_row].id, false);
                }*/
                clearInterval(downloads.interval);
                downloads.interval = null;
                downloads.interval = setInterval(function () {
                    self.every_interval.call(self);
                }, 3500);
            })
        },
        { "label": get_word('downloads_delete'), "cmd": downloads.delete_switcher }
    ]);
    downloads.init_delete_menu(
        [
            {
                "label": get_word('downloads_record'),

                "cmd": function () {
                    _debug(downloads.data_items[downloads.cur_row]);
                    //if (downloads.data_items[downloads.cur_row].fake){
                    downloads.del(downloads.data_items[downloads.cur_row].id, false);
                    /*}else{
                        stbDownloadManager.DeleteJob(downloads.data_items[downloads.cur_row].id, false);
                    }*/

                    setTimeout(function () {
                        downloads.every_interval.call(downloads)
                    }, 250);
                }
            },
            {
                "label": get_word('downloads_record_and_file'),
                "cmd": function () {
                    _debug(downloads.data_items[downloads.cur_row]);
                    //if (downloads.data_items[downloads.cur_row].fake){
                    downloads.del(downloads.data_items[downloads.cur_row].id, true);
                    /*}else{
                        stbDownloadManager.DeleteJob(downloads.data_items[downloads.cur_row].id, true);
                    }*/

                    setTimeout(function () {
                        downloads.every_interval.call(downloads)
                    }, 250);
                }
            }
        ],
        {
            "offset_x": 470,
            "color": 'blue',
            "need_reset_load_data": false,
            "need_update_header": false
        }
    );
    downloads.hide();
    module.downloads = downloads;
    /* END DOWNLOADS */
    main_menu.add(get_word('downloads_title'), [], 'mm_ico_dm.png', function () {
        main_menu.hide();
        module.downloads.show();
    },
        module.downloads
    );
    loader.next();
})();

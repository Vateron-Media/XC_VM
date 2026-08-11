/**
 * Media browser.
 */

(function () {

    /* MEDIA BROWSER */
    function media_browser_constructor() {

        this.layer_name = 'media_browser';

        this.row_blocks = ["dir", "back", "playing", "paused", "fav", "name", "seek_bar"];
        this.mounted = false;
        this.cur_dir_list = [];
        this.data_items = [];
        this.is_audio = false;
        this.play_all = true;
        this.smb_auth_history = [];
        this.favorites = [];
        this.change_level = true;
        this.sort_by_date = false;
        this.mount_points = {};

        this.superclass = ListLayer.prototype;

        this.dir_hist = [{ "path": "", "page": 1, "row": 1 }];

        this.image_extensions = stb.usbdisk.image_ext.split(' ') || [];
        this.audio_extensions = stb.usbdisk.audio_ext.split(' ') || [];
        this.video_extensions = stb.usbdisk.video_ext.split(' ') || [];

        this.init = function () {

            this.superclass.init.call(this);

            var self = this;

            this.init_smb_auth_dialog();

            this.init_share_form();

            this.get_smb_passwords();

            this.get_favorites();

            stb.usbdisk.add_onmount_callback(function () {
                self.load_data();
            });

            stb.usbdisk.add_onumount_callback(function () {
                _debug('media_browser onunmount');
                _debug('self.on', self.on);

                if (stb.player.prev_layer == self || self.on || module.image_viewer.on) {

                    if (stb.player.on) {
                        stb.player.stop();
                    }

                    if (module.image_viewer.on) {
                        module.image_viewer.hide();
                    }

                    self.hide();
                    main_menu.show();
                }
            });

            stb.player.addCustomEventListener("audiostart", function (item) {
                _debug('media_browser.audiostart', item);

                if (self.on) {

                    var cur_idx = self.data_items.getIdxByVal("cmd", stb.player.cur_media_item.cmd);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();
                    }

                    _debug('item.cmd', item.cmd);

                    var idx = self.data_items.getIdxByVal("cmd", item.cmd);

                    if (idx == -1) {
                        return;
                    }

                    _debug('idx', idx);

                    self.data_items[idx].playing = 1;
                    self.map[idx].playing_block.show();

                    self.data_items[cur_idx].paused = 0;
                    self.map[cur_idx].paused_block.hide();

                    if (self.cur_row == idx) {
                        self.active_row.playing_block.show();
                        self.active_row.paused_block.hide();
                    }

                    self.set_active_row(self.cur_row);
                }
            });

            stb.player.addCustomEventListener("audiostop", function (item) {
                _debug('media_browser.audiostop', item);

                if (self.on) {

                    var cur_idx = self.data_items.getIdxByVal("cmd", stb.player.cur_media_item.cmd);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();

                        self.data_items[cur_idx].paused = 0;
                        self.map[cur_idx].paused_block.hide();

                        if (self.cur_row == cur_idx) {
                            self.active_row.playing_block.hide();
                            self.active_row.paused_block.hide();
                        }
                    }

                    window.clearInterval(self.seek_bar_interval);
                    self.set_active_row(self.cur_row);
                }
            });

            stb.player.addCustomEventListener("audiopause", function (item) {
                _debug('media_browser.audiopause', item);

                if (self.on) {

                    var cur_idx = self.data_items.getIdxByVal("cmd", stb.player.cur_media_item.cmd);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].playing = 0;
                        self.map[cur_idx].playing_block.hide();

                        self.data_items[cur_idx].paused = 1;
                        self.map[cur_idx].paused_block.show();

                        if (self.cur_row == cur_idx) {
                            self.active_row.playing_block.hide();
                            self.active_row.paused_block.show();
                        }
                    }
                }
            });

            stb.player.addCustomEventListener("audiocontinue", function (item) {
                _debug('media_browser.audiocontinue', item);

                if (self.on) {

                    var cur_idx = self.data_items.getIdxByVal("cmd", stb.player.cur_media_item.cmd);

                    _debug('cur_idx', cur_idx);

                    if (cur_idx >= 0) {
                        self.data_items[cur_idx].paused = 0;
                        self.map[cur_idx].paused_block.hide();

                        self.data_items[cur_idx].playing = 1;
                        self.map[cur_idx].playing_block.show();

                        if (self.cur_row == cur_idx) {
                            self.active_row.paused_block.hide();
                            self.active_row.playing_block.show();
                        }
                    }
                }
            });

        };

        this.init_share_form = function () {
            _debug('media_browser.init_share_form');

            var scope = this;

            this.manage_fav_form = new ModalForm({
                "title": get_word('network_folder'),
                "parent": this,
                "id": "network_folder"
            });
            this.manage_fav_form.enableOnExitClose();

            this.manage_fav_form.addItem(new ModalFormInput({
                "label": get_word('server_ip'),
                "name": "server_ip",
                "placeholder": get_word('server_ip_placeholder')
            }));

            this.manage_fav_form.addItem(new ModalFormInput({
                "label": get_word('server_path'),
                "name": "server_path",
                "placeholder": get_word('server_path_placeholder'),
                "onchange": function () {
                    var server_path = scope.manage_fav_form.getItemByName("server_path").getValue();
                    var parts = server_path.split('/');
                    scope.manage_fav_form.getItemByName("local_folder").setValue(parts[parts.length - 1]);
                }
            }));

            this.manage_fav_form.addItem(new ModalFormInput({
                "label": get_word('local_folder'),
                "name": "local_folder",
                "placeholder": get_word('local_folder_placeholder')
            }));

            this.manage_fav_form.addItem(new ModalFormSelect({
                "label": get_word('server_type'),
                "name": "server_type",
                "options": [
                    {
                        "text": "SMB",
                        "value": "smb"
                    },
                    {
                        "text": "NFS",
                        "value": "nfs"
                    }
                ]
            }));

            /*this.manage_fav_form.addItem(new ModalFormInput({
                "label" : get_word('server_login'),
                "name" : "login"
            }));

            this.manage_fav_form.addItem(new ModalFormInput({
                "label" : get_word('server_password'),
                "type" : "password",
                "name" : "password"
            }));*/

            this.manage_fav_form.addItem(new ModalFormButton({
                "value": get_word("cancel_btn"),
                "name": "cancel_btn",
                "onclick": function () {
                    scope.manage_fav_form.hide();
                }
            }));

            this.manage_fav_form.addItem(new ModalFormButton({
                "value": get_word("add_btn"),
                "name": "add_btn",
                "onclick": function () {
                    /*
                    {
                        type:media_favorites,
                        action:save,
                        favorites:[
                            {
                            "name":"for_test_stb",
                            "dir":1,
                            "dir_name":"for_test_stb/",
                            "fav":1,
                            "_id":"smb://WORKGROUP/WOMBAT/pub/for_test_stb",
                            "full_path":"smb://WORKGROUP/WOMBAT/pub/for_test_stb"
                    }
                     */

                    var server_ip = scope.manage_fav_form.getItemByName("server_ip").getValue();
                    var server_path = scope.manage_fav_form.getItemByName("server_path").getValue();
                    var local_folder = scope.manage_fav_form.getItemByName("local_folder").getValue();
                    var server_type = scope.manage_fav_form.getItemByName("server_type").getValue();

                    _debug('server_ip', server_ip);
                    _debug('server_path', server_path);
                    _debug('local_folder', local_folder);
                    _debug('server_type', server_type);

                    if (server_path.indexOf('/') === 0) {
                        server_path = server_path.substr(1, server_path.length - 1);
                    }

                    if (server_path.lastIndexOf('/') == server_path.length - 1) {
                        server_path = server_path.substr(0, server_path.length - 1);
                    }

                    if (!server_ip || !server_path || !local_folder || local_folder.indexOf('/') != -1) {
                        return scope.manage_fav_form.setStatus(get_word("error"))
                    }

                    var path = server_type + '://' + server_ip + '/' + server_path;

                    _debug('path', path);

                    var fav_item = {
                        "name": local_folder,
                        "dir": 1,
                        "dir_name": local_folder + (local_folder.lastIndexOf('/') == local_folder.length - 1 ? '' : '/'),
                        "fav": 1,
                        "do_not_resolve": 1,
                        "_id": path,
                        "full_path": path
                    };

                    _debug('fav_item', fav_item);

                    scope.favorites.push(fav_item);
                    scope.save_favorites();

                    scope.load_data();

                    scope.manage_fav_form.hide();

                    return true;
                }
            }));
        };

        this.get_smb_passwords = function () {
            _debug('media_browser.get_smb_passwords');

            if (!stb.LoadUserData) {
                return;
            }

            if (gSTB.SetScreenSaverImplUrl || gSTB.SetScreenSaverInitAttr) { // check if firmware version 0.2.18 and upper
                var smb_data = "[]";
            } else {
                smb_data = Utf8.decode(stb.LoadUserData('smb_data')) || "[]";
            }

            _debug('smb_data', smb_data);

            smb_data = eval(smb_data);

            if (typeof (smb_data) == 'object') {
                this.smb_auth_history = smb_data;
            }
        };

        this.save_smb_passwords = function () {
            _debug('media_browser.save_smb_passwords');

            var smb_passwords = JSON.stringify(this.smb_auth_history);

            _debug('smb_passwords', smb_passwords);

            if (!gSTB.SetScreenSaverImplUrl) {
                stb.SaveUserData('smb_data', Utf8.encode(smb_passwords));
            }
        };

        this.init_smb_auth_dialog = function () {

            var self = this;

            this.smb_auth_dialog = new ModalForm({ "title": get_word('smb_auth') });
            this.smb_auth_dialog.addItem(new ModalFormInput({ "label": get_word('smb_username'), "name": "login" }));
            this.smb_auth_dialog.addItem(new ModalFormInput({ "label": get_word('smb_password'), "name": "password" }));
            this.smb_auth_dialog.enableOnExitClose();
            this.smb_auth_dialog.addCustomEventListener("hide", function () {
                _debug('smb_auth_dialog hide');
                self.on = true
            });

            this.smb_auth_dialog.addItem(new ModalFormButton(
                {
                    "value": "OK",
                    "onclick": function () {

                        var login = self.smb_auth_dialog.getItemByName("login").getValue();
                        var password = self.smb_auth_dialog.getItemByName("password").getValue();

                        _debug("login", login);
                        _debug("password", password);

                        self.smb_auth_dialog.hide();
                        self.mount_share('smb', login, password);
                    }
                }
            ));

            this.smb_auth_dialog.addItem(new ModalFormButton(
                {
                    "value": "Cancel",
                    "onclick": function () {

                        self.smb_auth_dialog.hide();
                    }
                }
            ));
        };

        this.show = function (do_not_load) {
            _debug('media_browser.show', do_not_load);

            this.change_level = true;

            this.superclass.show.call(this, do_not_load);

            this.update_breadcrumbs();

            this.refresh_play_all_switch();
        };

        this.hide = function (do_not_reset) {
            _debug('media_browser.hide', do_not_reset);

            try {

                /*if (this.on){*/
                if (stb.player.on && !this.is_audio) {
                    stb.player.stop();
                }
                /*}*/

                this.superclass.hide.call(this, do_not_reset);

                if (!do_not_reset) {
                    this.dir_hist.splice(1, this.dir_hist.length - 1);

                    this.reset();
                }

            } catch (e) {
                _debug(e);
            }
        };

        this.reset = function () {
            this.cur_row = 0;
            this.cur_page = 1;
            this.total_pages = 1;

            window.clearInterval(this.seek_bar_interval);
        };

        this.load_data = function (item) {
            _debug('load_data');

            var cur_hist_item = this.dir_hist[this.dir_hist.length - 1];

            var cur_dir = cur_hist_item.path;
            var smb_param = cur_hist_item.param;

            _debug('cur_dir', cur_dir);
            _debug('smb_param', smb_param);

            if (cur_dir == 'SMB') {
                this.load_smb_groups();
                return;
            } else if (cur_dir == 'SMB_GROUP') {
                this.load_smb_servers(smb_param);
                return;
            } else if (cur_dir == 'SMB_SERVER') {
                this.load_smb_shares(smb_param);
                return;
            } else if (cur_dir == 'FAV') {
                var list = [{ "name": "..", "back": 1 }];
                list = list.concat(this.favorites);
                this.fill_page(list);
                return;
            }

            this.load_mount_data(item || cur_hist_item);
        };

        this.load_mount_data = function (item) {
            _debug('media_browser.load_mount_data', item);

            if (item && item.hasOwnProperty('full_path')) {

                if (item.full_path.indexOf('smb://') == 0 || item.full_path.indexOf('nfs://') == 0) {

                    this.net_path = this.parse_net_path(item).path || '';

                    path = '/ram/mnt/smb/' + this.net_path;
                } else {
                    path = item.full_path;
                }

            } else {
                var path = this.compile_path();
            }

            this.path = path;

            _debug('path', path);

            var dirs = [];
            var files = [];
            var devices = {};

            if (this.dir_hist.length == 1) {

                this.mount_points = {};

                dirs.push("UPnP/");
                devices['UPnP'] = { "mountPath": '/media/UPnP/' };
                this.mount_points['/media/UPnP/'] = 'UPnP';

                var storage_info = stb.usbdisk.storage_info;

                if (storage_info) {
                    for (var i = 0; i < storage_info.length; i++) {

                        var dev_name = storage_info[i].vendor
                            + ' ' + storage_info[i].model
                            + (storage_info[i].label ? '(' + storage_info[i].label + ')' : '')
                            + (storage_info.length > 1 ? ' #' + storage_info[i].partitionNum : '');

                        devices[dev_name] = { "mountPath": storage_info[i].mountPath + '/' };

                        this.mount_points[storage_info[i].mountPath + '/'] = dev_name;

                        dirs.push(dev_name + '/');
                    }
                }

            } else if (this.change_level) {
                stb.usbdisk.read_dir(path);
                dirs = stb.usbdisk.dirs;
                files = stb.usbdisk.files;
            } else {
                this.fill_page(this.cur_dir_list);
                return;
            }

            _debug('dirs', dirs);
            _debug('devices', devices);

            if (this.dir_hist.length == 1) {
                dirs = dirs.filter(function (el) {
                    return !stb.storages.hasOwnProperty(el.substr(0, el.length - 1))
                });
            }

            _debug('dirs 2', dirs);

            var new_dirs = [];

            for (var i = 0; i < dirs.length; i++) {
                if (!empty(dirs[i])) {

                    var name = dirs[i].substring(0, dirs[i].length - 1);

                    if (name.indexOf('USB-') == 0 || name.indexOf('tmp-smb') == 0 || name.indexOf('SAMBA') == 0) {
                        continue;
                    }

                    var dir = { "name": name, "dir": 1, "dir_name": devices[name] ? devices[name].mountPath : dirs[i] };

                    if (this.dir_hist.length > 1) {
                        var is_playable = this.is_playable_folder(path + '/' + name);

                        _debug('is_playable', is_playable);

                        if (is_playable) {
                            dir.cmd = "extBDDVD " + path + '/' + name
                        }
                    }

                    new_dirs.push(dir);
                }
            }

            var new_files = [];

            for (var i = 0; i < files.length; i++) {
                if (!empty(files[i]) && ['srt', 'sub', 'ass'].indexOf(files[i].name.substring(files[i].name.lastIndexOf('.') + 1)) == -1) {

                    if (files[i].name.toLowerCase().indexOf('.iso') == files[i].name.length - 4) {
                        var solution = 'extBDDVD';
                    } else {
                        solution = 'auto';
                    }

                    new_files.push({
                        "name": files[i].name,
                        "cmd": (solution + " " + path + files[i].name),
                        "size": files[i].size,
                        "last_modified": (files[i].last_modified || 0)
                    });
                }
            }

            if (new_files && new_files.length && new_files[0].hasOwnProperty('last_modified') && new_files[0].last_modified) {

                if (this.sort_by_date) {
                    new_files = this.sort_list_by_date(new_files);
                }

                this.color_buttons.get('green').enable();
            } else {
                this.color_buttons.get('green').disable();
            }

            var list = new_dirs.concat(new_files);

            if (this.dir_hist.length == 1) {
                var clear_arr = [];
            } else {
                clear_arr = [{ "name": "..", "back": 1 }];
            }

            for (var i = 0; i < list.length; i++) {
                if (!empty(list[i])) {
                    clear_arr.push(list[i]);
                }
            }

            if (this.dir_hist.length == 1 && stb.GetSmbGroups) {

                var lan_item = {
                    "name": "LAN",
                    "dir": 1,
                    "dir_name": "SMB"/*, "_id" : this.get_id({"name" : "LAN", "dir_name" : "SMB"})*/
                };

                if (clear_arr.length >= 1) {
                    clear_arr.splice(1, 0, lan_item);
                } else {
                    clear_arr.push(lan_item);
                }
            }

            if (this.dir_hist.length == 1) {

                var fav_item = { "name": get_word('media_favorites'), "dir": 1, "dir_name": "FAV" };

                if (clear_arr.length >= 2) {
                    clear_arr.splice(2, 0, fav_item);
                } else {
                    clear_arr.push(fav_item);
                }
            }

            this.fill_page(clear_arr);
        };

        this.is_playable_folder = function (path) {
            _debug('media_browser.is_playable_folder', path);

            return stb.IsFolderExist(Utf8.encode(path + '/VIDEO_TS/'))
                || stb.IsFolderExist(Utf8.encode(path + '/BDMV/'))
                || stb.IsFileExist(Utf8.encode(path + '/VIDEO_TS.IFO'))
                || stb.IsFolderExist(Utf8.encode(path + '/video_ts/'))
                || stb.IsFolderExist(Utf8.encode(path + '/bdmv/'))
                || stb.IsFileExist(Utf8.encode(path + '/video_ts.ifo'));
        };

        this.load_smb_groups = function () {
            _debug('media_browser.load_smb_groups');

            if (!this.change_level) {
                this.fill_page(this.cur_dir_list);
                return;
            }

            var groups = JSON.parse(stb.GetSmbGroups());

            _debug('groups', groups);

            groups.result = groups.result || [];

            if (!groups || !groups.result || groups.errMsg) {
                return;
            }

            var self = this;

            groups = groups.result.map(function (group) {
                return {
                    "name": group,
                    "dir": 1,
                    "dir_name": "SMB_GROUP"/*, "_id" : self.get_id({"name" : group, "dir_name" : "SMB_GROUP"})*/
                }
            });

            groups.unshift({ "name": "..", "back": 1 });

            _debug('groups', groups);

            this.fill_page(groups);
        };

        this.load_smb_servers = function (group) {
            _debug('media_browser.load_smb_servers', group);

            if (!this.change_level) {
                this.fill_page(this.cur_dir_list);
                return;
            }

            var args = '{"group":"' + group + '"}';

            _debug('args', args);

            var servers = JSON.parse(stb.GetSmbServers(args));

            _debug('servers', servers);

            if (!servers || !servers.result || servers.errMsg) {
                return;
            }

            servers = servers.result.filter(function (server) {
                return server;
            }).map(function (server) {
                return {
                    "name": server,
                    "dir": 1,
                    "dir_name": "SMB_SERVER"/*, "_id" : self.get_id({"name" : server, "dir_name" : "SMB_SERVER"})*/
                }
            });

            servers.unshift({ "name": "..", "back": 1 });

            _debug('servers', servers);

            this.fill_page(servers);
        };

        this.load_smb_shares = function (server) {
            _debug('media_browser.load_smb_shares', server);

            if (!this.change_level) {
                this.fill_page(this.cur_dir_list);
                return;
            }

            var args = '{"server":"' + server + '"}';

            _debug('args', args);

            var shares = JSON.parse(stb.GetSmbShares(args));

            _debug('shares', shares);

            if (!shares || !shares.result || shares.errMsg) {
                return;
            }

            shares.result.shares = shares.result.shares || [];

            this.net_server_ip = shares.result.serverIP;

            var self = this;

            shares = shares.result.shares.map(function (share) {
                return {
                    "name": share,
                    "dir": 1,
                    "dir_name": "SMB_SHARE"/*, "_id" : self.get_id({"name" : share, "dir_name" : "SMB_SHARE"})*/
                }
            });

            shares.unshift({ "name": "..", "back": 1 });

            _debug('shares', shares);

            this.fill_page(shares);
        };

        this.mount_share = function (type, login, password) {
            _debug('media_browser.mount_share', type, login, password);

            /*login    = login    || "guest";
            password = password || "";*/

            type = type || "smb";

            if (login == undefined) {
                var auth_params = this.get_auth_params(this.net_server_ip, this.net_share);
                login = auth_params.login;
                password = auth_params.pass;
            }

            _debug('this.net_server_ip', this.net_server_ip);
            _debug('this.net_share', this.net_share);
            _debug('this.net_path', this.net_path);
            _debug('password', password);
            _debug('login', login);

            if (type == 'smb') {
                var mount_str = 'mount cifs //' + Utf8.encode(this.net_server_ip + '/' + this.net_share) + ' /ram/mnt/smb username=' + login + ',password=' + password + ',iocharset=utf8';
            } else {
                mount_str = 'mount nfs ' + this.net_server_ip + ':/' + Utf8.encode(this.net_share + (this.net_path ? '/' + this.net_path : '')) + ' /ram/mnt/smb ro,nolock';
            }

            _debug('mount_str', mount_str);

            try {
                var smb_mount_result = stb.RDir(mount_str);
            } catch (e) {
                _debug(e);
            }
            _debug('smb_mount_result', smb_mount_result);

            if (smb_mount_result == "Error: mount failed") {

                if (type == 'smb') {
                    this.on = false;
                    this.smb_auth_dialog.show();
                } else {
                    stb.notice.show(get_word('mount_failed'));
                }
            } else {

                if (login != "guest") {
                    this.save_auth_params(this.net_server_ip, this.net_share, login, password)
                }

                if (type == 'nfs') {
                    this.last_mounted = this.active_item.full_path;
                }

                this.in_dir(this.active_item);
            }
        };

        this.save_auth_params = function (server, share, login, password) {
            _debug('media_browser.save_auth_params', server, share, login, password);

            var url = '//' + server + '/' + share;

            var idx = this.smb_auth_history.getIdxByVal("url", url);

            var save_obj = { "url": url, "login": login, "pass": password, "automount": 0 };

            if (idx != null) {
                this.smb_auth_history[idx] = save_obj;
            } else {
                this.smb_auth_history.push(save_obj);
            }

            this.save_smb_passwords();
        };

        this.get_auth_params = function (server, share) {
            _debug('media_browser.save_auth_params', server, share);

            var url = '//' + server + '/' + share;

            var idx = this.smb_auth_history.getIdxByVal("url", url);

            if (idx == null) {
                return { "login": "guest", "pass": "" };
            }

            return { "login": this.smb_auth_history[idx].login, "pass": this.smb_auth_history[idx].pass };
        };

        this.fill_page = function (data) {
            _debug('fill_page');

            this.total_pages = Math.ceil(data.length / 14);

            this.cur_dir_list = data;

            if (stb.player.cur_media_item && this.change_level) {
                var contain_playing_item_idx = this.cur_dir_list.getIdxByVal("cmd", stb.player.cur_media_item.cmd);

                _debug('contain_playing_item_idx', contain_playing_item_idx);
            } else {
                contain_playing_item_idx = null;
            }

            if (contain_playing_item_idx !== null) {
                this.cur_page = Math.ceil((contain_playing_item_idx + 1) / 14);

                this.cur_row = contain_playing_item_idx - (this.cur_page - 1) * 14
            }

            _debug('this.cur_page', this.cur_page);
            _debug('this.cur_row', this.cur_row);

            if (this.dir_hist.length > 1) {
                this.set_total_items(data.length - 1);
            } else {
                this.set_total_items(data.length);
            }

            var begin = (this.cur_page - 1) * 14;
            var end = this.cur_page * 14;

            this.data_items = this.cur_dir_list.slice(begin, end);

            var self = this;

            this.fill_list(this.data_items);
        };

        this.compile_path = function () {
            _debug('media_browser.compile_path');
            _debug('this.dir_hist', this.dir_hist);

            if (this.dir_hist[this.dir_hist.length - 1].path == 'SMB_SHARE') {
                return '/ram/mnt/smb/'
            }

            var path = '';

            for (var i = 0; i < this.dir_hist.length; i++) {
                if (['SMB_GROUP', 'SMB_SERVER', 'SMB_SHARE'].indexOf(this.dir_hist[i].path) >= 0) {
                    continue;
                } else if (this.dir_hist[i].path == 'SMB') {
                    path = '/ram/mnt/smb/';
                } else if (this.dir_hist[i].hasOwnProperty('full_path')) {
                    path = this.dir_hist[i].full_path;
                } else {
                    path += '/' + this.dir_hist[i].path;
                }
            }

            return path;
        };

        this.action = function () {

            if (this.data_items[this.cur_row].hasOwnProperty('dir') && !this.data_items[this.cur_row].hasOwnProperty('cmd')) {

                this.check_for_mount(this.data_items[this.cur_row]);

            } else if (this.data_items[this.cur_row].hasOwnProperty('back')) {
                this.out_dir();
            } else {

                var self = this;

                var is_image = this.image_extensions.some(
                    function (item) {
                        return self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) >= 0 && self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) == self.data_items[self.cur_row].name.length - item.length;
                    });

                _debug('is_image', is_image);

                var is_audio = this.audio_extensions.some(
                    function (item) {
                        return self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) >= 0 && self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) == self.data_items[self.cur_row].name.length - item.length;
                    });

                _debug('is_audio', is_audio);

                var item = this.data_items[this.cur_row].clone();

                if (is_audio && this.play_all) {
                    item.playlist = this.audio_list;
                }

                var is_video = this.video_extensions.some(
                    function (item) {
                        return self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) >= 0 && self.data_items[self.cur_row].name.toLowerCase().lastIndexOf(item) == self.data_items[self.cur_row].name.length - item.length;
                    });

                _debug('is_video', is_video);

                if (is_video && this.play_all) {
                    item.playlist = this.video_list;
                }


                _debug('item', item);

                if (is_image) {

                    this.on = false;

                    module.image_viewer.show(this.image_list, this.data_items[this.cur_row].name, this.compile_path(), this);
                } else {
                    //this.play(this.data_items[this.cur_row]);
                    this.play(item);
                }
            }
        };

        this.play = function (item) {
            _debug('media_browser.play');

            if (stb.player.get_file_type(item) == 'audio') {
                this.is_audio = true;
                stb.player.need_show_info = 0;
            } else {
                this.is_audio = false;
                this.hide(true);
                stb.player.prev_layer = this;
                stb.player.need_show_info = 1;
            }

            _debug('this.dir_hist', this.dir_hist);

            _debug('stb.player.on', stb.player.on);

            if (stb.player.on) {
                stb.player.stop();
            }

            item['subtitles'] = this.find_subtitles(item['cmd']);

            stb.player.play(item);
        };

        this.find_subtitles = function (cmd) {
            _debug('media_browser.find_subtitles', cmd);

            var filename = cmd.substring(cmd.lastIndexOf('/') + 1);

            if (!filename) {
                return null;
            }

            var base = filename.substring(0, filename.lastIndexOf('.'));

            _debug('base', base);

            if (!base) {
                return null;
            }

            _debug('stb.usbdisk.files', stb.usbdisk.files);

            var subtitle_files = stb.usbdisk.files.filter(function (item) {
                _debug('item', item);
                _debug('item.name', item.name);
                if (item.name) {
                    _debug('item.name.indexOf(base)', item.name.indexOf(base));
                    _debug('indexOf...', ['srt', 'sub', 'ass'].indexOf(item.name.substring(item.name.lastIndexOf('.') + 1)));
                }
                return item && item.name && item.name.indexOf(base) === 0 && ['srt', 'sub', 'ass'].indexOf(item.name.substring(item.name.lastIndexOf('.') + 1)) != -1;
            });

            _debug('subtitle_files', subtitle_files);

            var self = this;

            var subtitles = subtitle_files.map(function (item, idx) {

                var lang = item.name.substring(base.length, item.name.lastIndexOf('.'));

                if (lang[0] == '_' || lang[0] == '.') {
                    lang = lang.substr(1);
                }

                return { "pid": 'external_' + idx, "lang": [lang, ''], "file": self.path + item.name };
            });

            _debug('subtitles', subtitles);

            return subtitles;
        };

        this.out_dir = function () {
            _debug('out_dir');

            if (this.dir_hist.length > 1) {
                this.dir_hist.pop();

                this.reset();

                this.cur_row = this.dir_hist[this.dir_hist.length - 1].row;
                this.cur_page = this.dir_hist[this.dir_hist.length - 1].page;

                this.change_level = true;

                this.update_breadcrumbs();

                this.load_data();
            }
        };

        this.check_for_mount = function (item) {
            _debug('media_browser.check_for_mount', item);

            //_debug('item.name', item.name);

            //_debug('item.name.split()', item.name.split(''));

            //item.name.split('').map(function(letter){
            //    _debug('letter code', letter.charCodeAt(0));
            //});

            this.active_item = item;


            var is_smb = this.dir_hist.some(function (dir) {
                return ['SMB_GROUP', 'SMB_SERVER', 'SMB_SHARE'].indexOf(dir.path) >= 0 || dir.full_path && (dir.full_path.indexOf('smb://') == 0 || dir.full_path.indexOf('nfs://') == 0);
            });

            _debug('is_smb', is_smb);

            if (is_smb) {
                var smb_full_path = this.get_full_smb_path(item);
                _debug('smb_full_path', smb_full_path);
                item.full_path = item._id = smb_full_path;
            }


            if (item.dir_name == 'SMB_SHARE' || item.hasOwnProperty('full_path') && (item.full_path.indexOf('smb://') == 0 || item.full_path.indexOf('nfs://') == 0 && (!this.last_mounted || item.full_path.indexOf(this.last_mounted) == -1)) && this.parse_net_path(item).hasOwnProperty('share')) {

                try {
                    stb.ExecAction('make_dir /ram/mnt/smb/');

                    stb.ExecAction('umount_dir /ram/mnt/smb/');
                    this.last_mounted = '';
                } catch (e) {
                    _debug(e);
                }

                if (item.hasOwnProperty('full_path')) {
                    var path = this.parse_net_path(item);

                    _debug('net path', path);

                    if (path.hasOwnProperty('server')) {
                        if (path.server.match(/^\d+\.\d+\.\d+\.\d+$/)) {
                            this.net_server_ip = path.server;
                        } else {
                            this.net_server_ip = this.get_smb_server_ip_by_name(path.server);
                        }
                    }

                    if (path.hasOwnProperty('path')) {
                        this.net_path = path.path;
                    } else {
                        this.net_path = '';
                    }

                    if (path.hasOwnProperty('share')) {
                        this.net_share = path.share;
                    }

                } else {
                    this.net_share = item.name;
                }


                if (path && path.hasOwnProperty("proto")) {
                    this.mount_share(path.proto);
                } else {
                    this.mount_share();
                }

                return;
            }

            this.in_dir(item);
        };

        this.in_dir = function (item) {

            var dir = item.dir_name;

            _debug('in_dir', dir);

            this.dir_hist[this.dir_hist.length - 1].page = this.cur_page;
            this.dir_hist[this.dir_hist.length - 1].row = this.cur_row;

            var hist_item = { 'path': dir, 'param': item.name, 'page': 1, 'row': 1 };

            if (item.hasOwnProperty('full_path')) {
                hist_item.full_path = item.full_path;
            }

            this.dir_hist.push(hist_item);

            this.reset();

            this.change_level = true;

            this.update_breadcrumbs();

            this.load_data(item);
        };

        this.update_breadcrumbs = function () {
            _debug('media_browser.update_breadcrumbs');

            var breadcrumbs = '';

            for (var i = 1; i < this.dir_hist.length; i++) {
                if (this.dir_hist[i].path == 'SMB_GROUP') {

                } else if (['SMB', 'SMB_SERVER', 'SMB_SHARE', 'FAV'].indexOf(this.dir_hist[i].path) >= 0 || this.dir_hist[i].path.indexOf('USB-') === 0 || this.mount_points[this.dir_hist[i].path]) {
                    breadcrumbs += this.dir_hist[i].param + '/';
                } else {
                    breadcrumbs += this.dir_hist[i].path;
                }
            }

            if (breadcrumbs.lastIndexOf('/') == breadcrumbs.length - 1) {
                breadcrumbs = breadcrumbs.substr(0, breadcrumbs.length - 1);
            }

            this.update_header_path([{ "alias": "breadcrumbs", "item": breadcrumbs }]);
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.action.bind(key.OK, this);

            /*(function(){
                
                this.hide();
                main_menu.show();
            }).bind(key.EXIT, this);*/

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.LEFT, this).bind(key.MENU, this);

            (function () {
                if (this.dir_hist.length == 1) {
                    this.hide();
                    main_menu.show();
                } else {
                    this.out_dir();
                }
            }).bind(key.BACK, this).bind(key.EXIT, this);


        };

        this.fill_list = function (data) {
            _debug('fill_list');

            this.data_items = data;

            var self = this;

            this.data_items = this.data_items.map(function (item) {

                if (stb.player.cur_media_item && item.cmd == stb.player.cur_media_item.cmd && stb.player.on) {
                    if (stb.player.pause.on) {
                        item.paused = 1;
                    } else {
                        item.playing = 1;
                    }
                }

                if (item.dir) {
                    item.fav = self.favorites.some(function (favorite) {
                        return favorite._id == (item._id || self.get_id(item));
                    }) ? 1 : 0;
                }

                return item;
            });

            if (!this.change_level) {

                if (this.page_dir > 0) {
                    this.cur_row = 0;
                } else {
                    this.cur_row = this.data_items.length - 1;
                }
            } else {
                this.get_image_list();

                this.get_audio_list();

                this.get_video_list();
            }

            this.change_level = false;

            this.superclass.fill_list.call(this, data);
        };

        this.get_image_list = function () {

            var self = this;

            this.image_list = this.cur_dir_list.filter(function (item) {
                return self.image_extensions.some(function (ext) {
                    return item.name.toLowerCase().lastIndexOf(ext) >= 0 && item.name.toLowerCase().lastIndexOf(ext) == item.name.length - ext.length;
                });
            });

            _debug('this.image_list', this.image_list);
        };

        this.get_audio_list = function () {
            _debug('media_browser.get_audio_list');

            var self = this;

            var path = this.compile_path();

            _debug('path', path);

            _debug('this.cur_dir_list', this.cur_dir_list);

            this.audio_list = this.cur_dir_list.filter(function (item) {
                return new RegExp("(" + self.audio_extensions.join("|") + ")$").test(item.name);
            });

            this.audio_list = this.audio_list.map(function (item) {
                //return 'auto ' + path + item.name;
                return item.cmd;
            });

            _debug('this.audio_list', this.audio_list);
        };


        this.get_video_list = function () {

            var self = this;

            var path = this.compile_path();

            this.video_list = this.cur_dir_list.filter(function (item) {
                return new RegExp("(" + self.video_extensions.join("|") + ")$").test(item.name);
            });

            this.video_list = this.video_list.map(function (item) {
                //return 'auto ' + path + item.name;
                return item.cmd;
            });

            _debug('this.video_list', this.video_list);
        };

        this.set_active_row = function (num) {
            _debug('media_browser.set_active_row', num);

            this.superclass.set_active_row.call(this, num);

            _debug('this.data_items[this.cur_row].cmd', this.data_items[this.cur_row].cmd);
            _debug('stb.player.on', stb.player.on);

            if (stb.player.cur_media_item && this.data_items[this.cur_row].cmd == stb.player.cur_media_item.cmd && stb.player.on && stb.player.file_type == 'audio') {

                this.active_row['row'].setAttribute("status", "playing");

                this.active_row['row'].addClass("playing");

                if (this.active_row.seek_bar_block.childNodes.length > 0) {
                    this.active_row.seek_bar_block.childNodes[0].style.width = 0;
                }

                this.active_row.seek_bar_block.show();
                this._start_updating_seek_bar();
            } else {
                this.active_row['row'].setAttribute("status", "");
                this.active_row['row'].removeClass("playing");
                this.active_row.seek_bar_block.hide();
                if (this.active_row.seek_bar_block.childNodes.length > 0) {
                    this.active_row.seek_bar_block.childNodes[0].style.width = 0;
                }
                window.clearInterval(this.seek_bar_interval);
            }

            if (this.data_items[this.cur_row].dir && this.dir_hist.length > 1) {
                this.color_buttons.get('yellow').enable();
            } else {
                this.color_buttons.get('yellow').disable();
            }

            if (this.dir_hist.length == 2 && this.dir_hist[this.dir_hist.length - 1].path == 'FAV') {
                this.color_buttons.get('blue').enable();
            } else {
                this.color_buttons.get('blue').disable();
            }

            _debug('this.active_row[row].getAttribute(status)', this.active_row['row'].getAttribute("status"));
        };

        this._start_updating_seek_bar = function () {
            _debug('media_browser._start_updating_seek_bar');

            var self = this;

            window.clearInterval(this.seek_bar_interval);

            this._update_seek_bar();

            this.seek_bar_interval = window.setInterval(function () {
                self._update_seek_bar();
            }, 1000);
        };

        this._update_seek_bar = function () {
            if (this.active_row.seek_bar_block.childNodes.length == 0) {
                var inner = create_block_element("seek_bar_inner", this.active_row.seek_bar_block);
            } else {
                inner = this.active_row.seek_bar_block.childNodes[0];
            }

            var pos_time = stb.GetPosTime();
            var media_len = stb.GetMediaLen();

            _debug('pos_time', pos_time);
            _debug('media_len', media_len);
            _debug('this.active_row.seek_bar_block.offsetWidth', this.active_row.seek_bar_block.offsetWidth);

            var width = this.active_row.seek_bar_block.offsetWidth * pos_time / media_len;

            _debug('width', width);

            if (width > this.active_row.seek_bar_block.offsetWidth) {
                width = this.active_row.seek_bar_block.offsetWidth;
            }

            if (media_len == 0) {
                width = 0;
            }

            _debug('width 2', width);

            inner.style.width = width + 'px';
        };

        this.play_all_switch = function () {
            _debug('media_browser.play_all_switch');

            this.play_all = !this.play_all;

            _debug('this.play_all', this.play_all);

            this.refresh_play_all_switch();
        };

        this.refresh_play_all_switch = function () {
            _debug('media_browser.refresh_play_all_switch');

            _debug('this.play_all', this.play_all);

            this.color_buttons.get("red").text_obj.innerHTML = get_word('play_all') + ": " + (this.play_all ? get_word('on') : get_word('off'));
        };

        this.add_del_fav = function () {
            _debug('media_browser.add_del_fav');

            _debug('this.data_items[this.cur_row]', this.data_items[this.cur_row]);

            if (!this.data_items[this.cur_row].hasOwnProperty('dir') || this.data_items[this.cur_row].dir != '1') {
                return;
            }

            var _id = this.data_items[this.cur_row]._id || this.get_id(this.data_items[this.cur_row]);

            _debug('_id', _id);

            var idx = this.favorites.getIdxByVal('_id', _id);

            _debug('idx', idx);

            this.data_items[this.cur_row]._id = _id;
            this.data_items[this.cur_row].full_path = this.data_items[this.cur_row].full_path || this.get_full_path(this.data_items[this.cur_row]);

            if (this.data_items[this.cur_row].fav) {
                this.del_from_fav(this.data_items[this.cur_row]);
            } else {
                this.add_to_fav(this.data_items[this.cur_row]);
            }

            this.save_favorites();
        };

        this.add_to_fav = function (item) {
            _debug('media_browser.add_to_fav');

            this.data_items[this.cur_row].fav = 1;
            this.map[this.cur_row].fav_block.show();
            this.active_row.fav_block.show();

            this.favorites.push(item);
        };

        this.del_from_fav = function (item) {
            _debug('media_browser.del_from_fav');

            this.data_items[this.cur_row].fav = 0;
            this.map[this.cur_row].fav_block.hide();
            this.active_row.fav_block.hide();

            var idx = this.favorites.getIdxByVal('_id', item._id);

            _debug('idx', idx);

            if (idx !== null) {
                this.favorites.splice(idx, 1);
            }
        };

        this.get_full_path = function (item) {
            _debug('media_browser.get_full_path');

            _debug('this.dir_hist', this.dir_hist);

            _debug('this.full_path', this.full_path);
            _debug('this.change_level', this.change_level);

            //if (this.full_path && (!this.change_level || this.last_dir_hist && (JSON.stringify(this.last_dir_hist) == JSON.stringify(this.dir_hist)))){
            if (this.full_path && !this.change_level) {
                return this.full_path + '/' + item.dir_name;
            }

            //this.last_dir_hist = this.dir_hist.slice(0);

            var is_smb = this.dir_hist.some(function (dir) {
                return ['SMB', 'SMB_GROUP', 'SMB_SERVER', 'SMB_SHARE'].indexOf(dir.path) >= 0;
            }) || item.dir_name == 'SMB';

            _debug('is_smb', is_smb);

            if (is_smb) {
                var smb_full_path = this.get_full_smb_path(item);
                _debug('smb_full_path', smb_full_path);
                return smb_full_path;
            }

            var full_path = this.compile_path();

            _debug('full_path 1', full_path);

            if (full_path[full_path.length - 1] == '/') {
                //full_path = full_path.substring(0, full_path.length-1);
            }

            _debug('full_path 2', full_path);

            this.full_path = full_path;

            return full_path + '/' + item.dir_name;
        };

        this.get_full_smb_path = function (item) {
            _debug('media_browser.get_full_smb_path');

            _debug('this.dir_hist', this.dir_hist);

            var path = 'smb:/';

            for (var i = 0; i < this.dir_hist.length; i++) {

                /*if (this.dir_hist[i].path == 'SMB'){
                    path = 'smb:/';*/
                if (this.dir_hist[i].hasOwnProperty('full_path')) {
                    path = this.dir_hist[i].full_path;
                } else if (this.dir_hist[i].path == 'SMB') {
                    path = 'smb:/';
                } else /*if (['SMB_GROUP', 'SMB_SERVER', 'SMB_SHARE'].indexOf(this.dir_hist[i].path) >= 0)*/ {
                    path += '/' + this.dir_hist[i].param;
                }
            }

            return path + '/' + item.name;
        };

        this.parse_net_path = function (item) {
            _debug('media_browser.parse_net_path', item);

            var full_path = item.full_path;

            _debug('full_path', full_path);

            var direct_link = false;

            var path = {};

            var match = full_path.match("://([^\/]*)\/?([^\/]*)\/?([^\/]*)\/?(.*)");

            _debug('match', match);

            if (match) {

                if (match[1] && match[1].match(/^\d+\.\d+\.\d+\.\d+$/)) {
                    direct_link = true;
                }

                _debug('direct_link', direct_link);

                path.proto = full_path.substr(0, full_path.indexOf("://"));

                _debug('path.proto', path.proto);

                _debug('this.last_mounted', this.last_mounted);

                if (path.proto == 'nfs') {

                    if (match[1]) {
                        path.server = match[1];
                    }

                    if (this.last_mounted && full_path.indexOf(this.last_mounted) === 0) {
                        path.path = full_path.replace(this.last_mounted, '') + '/';
                    }

                    path.share = '';

                    for (var i = 2; i < match.length; i++) {
                        if (match[i]) {
                            path.share += match[i] + '/';
                        }
                    }

                } else if (!direct_link) {
                    if (match[1]) {
                        path.workgroup = match[1];
                    }

                    if (match[2]) {
                        path.server = match[2];
                    }

                    if (match[3]) {
                        path.share = match[3];
                    }

                    if (match[4]) {
                        path.path = match[4] + '/';
                    }
                } else {
                    if (match[1]) {
                        path.server = match[1];
                    }

                    if (match[2]) {
                        path.share = match[2];
                    }

                    if (match[3]) {
                        path.path = match[3] + '/';
                    }

                    if (match[4]) {
                        path.path += match[4] + '/';
                    }
                }
            }

            _debug('path', path);

            return path;
        };

        this.get_smb_server_ip_by_name = function (name) {
            _debug('media_browser.get_smb_server_ip_by_name', name);

            var args = '{"server":"' + name + '"}';

            _debug('args', args);

            var shares = JSON.parse(stb.GetSmbShares(args));

            _debug('shares', shares);

            if (!shares || !shares.result || shares.errMsg) {
                return null;
            }

            shares.result.shares = shares.result.shares || [];

            return shares.result.serverIP;
        };

        this.get_id = function (item) {
            _debug('media_browser.get_id', item);

            return this.get_full_path(item);
        };

        this.get_favorites = function () {
            _debug('media_browser.get_favorites');

            stb.load(
                {
                    "type": "media_favorites",
                    "action": "get_all"
                },
                function (result) {
                    _debug('on get_favorites', result);

                    this.favorites = result && JSON.parse(result) || [];
                },
                this
            )
        };

        this.save_favorites = function () {
            _debug('media_browser.save_favorites');

            stb.load(
                {
                    "type": "media_favorites",
                    "action": "save",
                    "favorites": JSON.stringify(this.favorites)
                },
                function (result) {
                    _debug("on save_favorites", result);
                },
                this
            );
        };

        this.init_sort_menu = function (map, options) {
            _debug('media_browser.init_sort_menu');

            this.sort_menu = new bottom_menu(this, options);
            this.sort_menu.init(map);
            this.sort_menu.bind();
        };

        this.sort_menu_switcher = function () {
            _debug('media_browser.sort_menu_switcher');

            if (this.sort_menu && this.sort_menu.on) {
                this.sort_menu.hide();
            } else {
                this.sort_menu.show();
            }
        };

        this.sort_list_by_date = function (list) {
            _debug('media_browser.sort_list_by_date', list);

            var times = list.map(function (item) {
                return (item.last_modified || 0) + '_' + item.name;
            });

            times.sort(function (a, b) {
                return parseInt(b, 10) - parseInt(a, 10)
            });

            _debug('times', times);

            var sorted_list = [];

            list.every(function (item) {
                var idx = times.indexOf(item.last_modified + '_' + item.name);
                sorted_list[idx] = item;
                return true;
            });

            _debug('sorted_list', sorted_list);

            return sorted_list;
        };
    }

    media_browser_constructor.prototype = new ListLayer();

    var media_browser = new media_browser_constructor();

    media_browser.bind();
    media_browser.init();

    media_browser.set_wide_container();

    media_browser.init_left_ear(word['ears_back']);

    media_browser.init_header_path(word['mbrowser_title']);

    media_browser.init_color_buttons([
        { "label": word['play_all'], "cmd": media_browser.play_all_switch },
        { "label": word['tv_sort'], "cmd": media_browser.sort_menu_switcher },
        { "label": get_word('favorite'), "cmd": media_browser.add_del_fav },
        {
            "label": get_word('add_folder'), "cmd": function () {
                media_browser.manage_fav_form.show()
            }
        }
    ]);

    var sort_menu_map = [
        {
            "label": get_word("mbrowser_sort_by_name"),
            "cmd": function () {
                this.parent.sort_by_date = false;
                this.parent.change_level = true;
            },
            "selector": "*"
        },
        {
            "label": get_word("mbrowser_sort_by_date"),
            "cmd": function () {
                this.parent.sort_by_date = true;
                this.parent.change_level = true;
            }
        }
    ];

    media_browser.init_sort_menu(
        sort_menu_map,
        {
            "color": "green",
            "need_update_header": false
        }
    );

    module.media_browser = media_browser;

    /* END MEDIA BROWSER */

    main_menu.add(word['mbrowser_title'], [], 'mm_ico_mb.png', function () {
        main_menu.hide();
        module.media_browser.show();
    },
        module.media_browser
    );

    media_browser.hide();
})();

loader.next();
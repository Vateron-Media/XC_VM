(function () {

    if (!stb.startAplication) {
        loader.next();
        return;
    }

    function ApplicationsMenuConstructor() {

        this.layer_name = 'apps';

        this.superclass = SimpleLayer.prototype;

        this.map = [];
        this.apps_data = [];
        this.items_in_row = 5;


        this.init = function () {
            _debug('apps.init');

            this.superclass.init.apply(this);

            if (!stb.startAplication) {
                this.load();
            } else {
                var apps = stb.appsList();
                try {
                    apps = JSON.parse(apps);
                    this.init_menu(apps);
                } catch (e) {
                    _debug(e);
                }
            }
        };

        this.show = function () {
            _debug('apps.show');

            this.superclass.show.apply(this);

            this.set_active_item(0);
        };

        // for debug
        this.load = function () {
            _debug('apps.load');

            var req = new XMLHttpRequest();
            req.open("GET", '/STBDebug.json', true);

            var self = this;

            req.onreadystatechange = function () {
                if (req.readyState == 4) {
                    if (req.status == 200) {
                        try {
                            var result = JSON.parse(req.responseText);
                            self.init_menu(result);
                        } catch (er) {
                            _debug('req.responseText', req.responseText);
                            if (req.responseText == 'Authorization failed.') {
                                authentication_problem.show();
                            }
                            throw new Error(er);
                        }
                    } else if (req.status == 0) {
                        console.log('Abort request');
                    } else {
                        connection_problem.show();
                        console.log('req.status: ' + req.status);
                        console.log(req.responseText);
                    }
                    req = null;
                }
            };

            req.send(null);
        };

        this.init_menu = function (apps_data) {
            _debug('apps.init_menu', apps_data);

            this.apps_data = apps_data;

            for (var i = 0; i < apps_data.length; i++) {
                var block = create_block_element('app_block', this.container);
                block.setAttribute('tabindex', '1');

                var icon_block = create_block_element('app_icon_block', block);
                var icon = this.get_icon(apps_data[i].image, icon_block);
                icon_block.style.background = 'url(' + icon + ') center no-repeat';

                var title_block = create_block_element('app_title_block', block);
                title_block.innerHTML = apps_data[i].name;

                this.map.push({
                    block: block,
                    icon: icon_block,
                    title: title_block
                })
            }
        };

        this.get_icon = function (icons_data, dom_obj) {
            _debug('apps.get_icon');

            var priority = [160, 240, 320, 120];

            for (var i = 0; i < priority.length; i++) {
                if (icons_data[priority[i]]) {
                    dom_obj.setAttribute('data-dpi', priority[i]);
                    return icons_data[priority[i]];
                }
            }
        };

        this.set_active_item = function (idx) {
            _debug('apps.set_active_item');

            if (this.map[idx]) {
                this.cur_idx = idx;

                this.map[idx].block.focus();
            }
        };

        this.shift_horizontal = function (dir) {
            _debug('apps.shift_horizontal', dir);

            if (Math.floor(this.cur_idx / this.items_in_row) == Math.floor((this.cur_idx + dir) / this.items_in_row)) {
                this.set_active_item(this.cur_idx + dir);
            }
        };

        this.shift_vertical = function (dir) {
            _debug('apps.shift_vertical', dir);

            var idx = this.cur_idx + this.items_in_row * dir;

            if (dir < 0 && this.cur_idx < this.items_in_row) {

            } else {
                while (!this.map[idx]) {
                    idx = idx - dir;
                }
            }

            this.set_active_item(idx);
        };

        this.action = function () {
            _debug('apps.action');

            _debug('this.apps_data[this.cur_idx].packageName', this.apps_data[this.cur_idx].packageName);

            stb.startAplication(this.apps_data[this.cur_idx].packageName);
        };

        this.bind = function () {
            _debug('apps.bind');

            var self = this;

            keydown_observer.addCustomEventListener("keypress", function (event) {
                if (self.on) {
                    event.preventDefault();
                }
                return true;
            });

            this.shift_horizontal.bind(key.LEFT, this, -1).bind(key.RIGHT, this, 1);

            this.shift_vertical.bind(key.UP, this, -1).bind(key.DOWN, this, 1);

            this.action.bind(key.OK, this);

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.MENU, this).bind(key.EXIT, this);
        };

    }

    ApplicationsMenuConstructor.prototype = new SimpleLayer();

    var apps = new ApplicationsMenuConstructor();

    apps.bind();
    apps.init();
    apps.init_header_path(get_word('apps_title'));
    apps.hide();

    module.apps = apps;

    main_menu.add(get_word('apps_title'), [], 'mm_ico_apps.png', function () {

        main_menu.hide();
        module.apps.show();

    }, module.apps);

    loader.next();
})();
/**
 * Network info.
 */

(function () {

    if (!stb.profile['use_embedded_settings']) {
        return;
    }

    function NetworkStatusConstructor() {

        this.layer_name = 'network_status';

        this.superclass = SimpleLayer.prototype;

        this.show = function () {
            _debug('network_status.show');

            this.superclass.show.call(this);

            this.refresh();
        };

        this.init = function () {
            _debug('network_status.init');

            this.superclass.init.call(this);

            this.list = new HTMLDefinitionList("network_status_list", this.container);

            this.list.setSeparator(':');

            this.list.addRow(get_word('LAN'), '');
            this.list.addRow(get_word('WLAN'), '');

            if (stb.profile['test_download_url']) {
                this.list.addRow(get_word('test_speed'), '');
            }
        };

        this.bind = function () {

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.MENU, this).bind(key.EXIT, this).bind(key.LEFT, this);

            this.refresh.bind(key.REFRESH, this);
        };

        this.refresh = function () {
            _debug('network_status.refresh');

            this.list.updateValueByTitle(get_word('LAN'),
                stb.GetLanLinkStatus() ? '<span class="if_up">' + get_word('lan_up') + '</span>' : '<span class="if_down">' + get_word('lan_down') + '</span>');

            this.list.updateValueByTitle(get_word('WLAN'),
                stb.GetWifiLinkStatus() ? '<span class="if_up">' + get_word('lan_up') + '</span>' : '<span class="if_down">' + get_word('lan_down') + '</span>');

            if (!stb.profile['test_download_url']) {
                return;
            }

            var self = this;

            var speedtest = new Speedtest(stb.profile['test_download_url']);

            speedtest.onSuccess(function (speed) {
                _debug('speed', speed);
                self.list.updateValueByTitle(get_word('test_speed'), speed);
            });

            speedtest.onCheck(function (result) {
                if (result.state == 2) {
                    self.list.updateValueByTitle(get_word('test_speed'), get_word('speedtest_testing'));
                } else if (result.state == 4 || result.state == 5) {
                    self.list.updateValueByTitle(get_word('test_speed'), get_word('speedtest_error'));
                } else if (result.state == 1) {
                    self.list.updateValueByTitle(get_word('test_speed'), get_word('speedtest_waiting'));
                }
            });

            speedtest.start();
        };
    }

    NetworkStatusConstructor.prototype = new SimpleLayer();

    var network_status = new NetworkStatusConstructor();

    network_status.init();
    network_status.init_left_ear(get_word('ears_back'));
    network_status.bind();

    network_status.init_color_buttons([
        {
            "label": get_word('network_status_refresh'), "cmd": function () {
                network_status.refresh()
            }
        },
        { "label": get_word('empty'), "cmd": '' },
        { "label": get_word('empty'), "cmd": '' },
        { "label": get_word('empty'), "cmd": '' }
    ]);

    network_status.init_header_path(get_word('network_status_title'));

    network_status.hide();

    module.network_status = network_status;

    if (!module.settings_sub) {
        module.settings_sub = [];
    }

    module.settings_sub.push({
        "title": get_word('network_status_title'),
        "cmd": function () {
            main_menu.hide();
            module.network_status.show();
        }
    })

})();

loader.next();
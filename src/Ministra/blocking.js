(function () {
    var blocking = {

        on: false,

        init: function () {
            this.bind.call(this);
        },

        init_layer: function () {
            if (!this.dom_obj) {

                var _style = document.createElement('link');
                _style.type = "text/css";
                _style.rel = "stylesheet";

                _style.href = 'template/' + loader.template + '/blocking' + resolution_prefix + ".css";
                document.getElementsByTagName("head")[0].appendChild(_style);

                this.dom_obj = create_block_element('cut_off', document.body);
                this.text_msg = create_block_element('cut_off_text', this.dom_obj);
                this.blocking_buttons = create_block_element('blocking_buttons', this.dom_obj);

                this.blocking_account_reboot = create_block_element('blocking_account_reboot', this.blocking_buttons);
                this.blocking_account_reboot.innerHTML = '<div class="color_btn red"></div> ' + get_word('blocking_account_reboot');

                this.hide();
            }
        },

        show: function (msg) {
            _debug('blocking.show');
            this.init_layer();
            try {
                this.mac = stb.GetDeviceMacAddress().toUpperCase().clearnl();
            } catch (e) {
                try {
                    this.mac = stb.RDir('MACAddress').toUpperCase().clearnl();
                } catch (e) {
                    this.mac = "";
                }
            }
            this.text_msg.innerHTML = (msg || get_word('cut_off_msg')) + this.mac;
            this.blocking_account_reboot.innerHTML = '<div class="color_btn red"></div> ' + get_word('blocking_account_reboot');
            this.dom_obj.show();
            this.on = true;
            stb.load_account_modules();
        },

        hide: function () {
            this.dom_obj.hide();
            this.on = false;
        },

        bind: function () {
            (function () {
                _debug('window.referrer', window.referrer);
                if (window.referrer) {
                    window.location = window.referrer
                }
            }).bind(key.EXIT, this);

            (function () {
                _debug('blocking key.red');
                window.location = window.location;
            }).bind(key.RED, this);
        }
    };

    blocking.init();

    window.module = window.module || {};

    window.module.blocking = blocking;
})();
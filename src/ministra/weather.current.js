/**
 * Current weather widget.
 * Displayed in the main menu.
 */

(function () {

    var curweather = {

        dom_obj: {},

        init: function () {

            this.dom_obj = create_block_element('curweather_block', main_menu.dom_obj);
            this.start_load();
        },

        set: function (weather) {
            _debug('curweather.set', weather);
            this.current = weather;

            if (main_menu) {
                this.render();
            }
        },

        render: function () {
            _debug('curweather.render');

            var self = this;

            if (!this.current) {
                this.dom_obj.innerHTML = '<div class="curweather_descr"><span class="curweather_title">' + word['current_weather_unavailable'] + '</span></div>';

                if (!this.dom_obj.isHidden()) {
                    window.setTimeout(function () {
                        self.dom_obj.hide();
                    }, 60000);
                }

                return;
            }

            if (this.current.error && this.current.error == 'not_configured') {
                this.dom_obj.innerHTML = '<div class="curweather_descr"><span class="curweather_title">' + get_word('current_weather_not_configured') + '</span></div>';
                return;
            }

            if (this.current.repeat_time) {
                window.clearInterval(this.load_interval);

                window.setTimeout(function () {
                    self.start_load()
                }, this.current.repeat_time * 1000);

                return;
            }

            if (this.dom_obj.isHidden()) {
                this.dom_obj.show();
            }

            var cur = '<div class="curweather_img"><img src="template/' + loader.template + '/i' + resolution_prefix + '/' + this.current.pict + '"/></div>';
            cur += '<div class="city">' + this.current.city + '</div>';
            cur += '<div class="curweather_descr">' + this.current.t + '&deg; ' + (this.current.t_units ? this.current.t_units : 'C') + '<br>';
            cur += this.current.cloud_str + '<br>';
            if (this.current.t_flik) {
                cur += '<span class="curweather_title">' + word['weather_comfort'] + ':</span> ' + this.current.t_flik + '&deg; ' + (this.current.t_units ? this.current.t_units : 'C') + '<br>';
            }
            cur += '<span class="curweather_title">' + word['weather_pressure'] + ':</span> ' + this.current.p + ' ' + word['weather_mmhg'] + '<br>';
            cur += '<div class="curweather_wind"><div class="curweather_title" style="float: left">' + word['weather_wind'] + ':</div>' + (this.current.w_rumb_str ? '<div class="wind_direction_' + this.current.w_rumb_str + '">&uarr;</div>' : '') + '<div style="float: left;"> ' + this.current.w + ' ' + word['weather_speed'] + '</div></div><br>';
            cur += '<span class="curweather_title">' + word['weather_humidity'] + ':</span> ' + this.current.h + '%<br>';
            cur += '</div>';

            this.dom_obj.innerHTML = cur;
        },

        load: function () {
            _debug('curweather.load');

            stb.load(
                {
                    "type": "weather",
                    "action": "get_current"
                },
                function (result) {
                    _debug('on curweather.load');

                    this.set(result);
                },
                this
            )

        },

        start_load: function () {
            _debug('curweather.start_load');

            this.load();

            var self = this;

            window.clearInterval(this.load_interval);
            this.load_interval = window.setInterval(function () {
                self.load()
            }, 10 * 60 * 1000);
        }
    };

    curweather.init();

    module.curweather = curweather;

    loader.next();

})();
/**
 * Day weather in infoportal.
 *
 */

(function () {

    function WeatherForecastConstructor() {

        this.layer_name = 'weather_forecast';

        this.dom_obj = this.create_block();
        document.body.appendChild(this.dom_obj);

        this.superclass = BaseLayer.prototype;

        this.map = [];

        this.reload_timeout = 30000;

        this.init = function () {
            _debug('weather_forecast.init');

            this.superclass.init.call(this);

            var container = create_block_element('weather_forecast', this.dom_obj);

            for (var i = 0; i < 4; i++) {
                this.map[i] = {};

                var col = create_block_element('weather_row_1 weather_col_' + (i + 1), container);
                this.map[i].text = create_block_element('day_weather_text', col);
                this.map[i].descr = create_block_element('day_weather_descr', col);

                var layer_1_container = create_block_element('day_weather_layer_1', col);
                this.map[i].layer_1 = document.createElement('img');
                layer_1_container.appendChild(this.map[i].layer_1);

                /*var layer_2_container = create_block_element('day_weather_layer_2', col);
                 this.map[i].layer_2 = document.createElement('img');
                 layer_2_container.appendChild(this.map[i].layer_2);

                 var layer_3_container = create_block_element('day_weather_layer_3', col);
                 this.map[i].layer_3 = document.createElement('img');
                 layer_3_container.appendChild(this.map[i].layer_3);*/

                this.map[i].temp = create_block_element('day_weather_temp', col);
            }

        };

        this.show = function () {
            _debug('weather_forecast.show');

            this.superclass.show.call(this);

            this.load();

            this.t_load();
        };

        this.hide = function () {
            _debug('weather_forecast.hide');

            this.superclass.hide.call(this);

            window.clearInterval(this.reload_timer);
        };

        this.t_load = function () {
            _debug('weather_forecast.t_load');

            var self = this;

            this.reload_timer = window.setInterval(function () {
                self.load()
            }, this.reload_timeout);
        };

        this.load = function () {
            _debug('weather_forecast.load');

            stb.load(
                {
                    "type": "weather",
                    "action": "get_forecast"
                },

                function (result) {
                    this.fill(result);
                },

                this
            )
        };

        this.fill = function (data) {
            _debug('weather_forecast.fill', data);

            if (data.error && data.error == 'not_configured') {
                this.map[0].descr.innerHTML = get_word('current_weather_not_configured');
                this.map[1].descr.innerHTML = get_word('current_weather_not_configured');
                this.map[2].descr.innerHTML = get_word('current_weather_not_configured');
                this.map[3].descr.innerHTML = get_word('current_weather_not_configured');
                return;
            }

            if (data.repeat_time) {
                var self = this;
                window.setTimeout(function () {
                    self.load()
                }, data.repeat_time * 1000);

                return;
            }

            this.update_header_path([{ "alias": "city", "item": data.city }]);

            data = data['forecast'];

            for (var i = 0; i < data.length; i++) {

                this.map[i].text.innerHTML = data[i].title;

                var descr = data[i].cloud_str + '<br><span class="day_weather_sub">' + word['dayweather_pressure'] + '</span> ';
                descr += (data[i].p.hasOwnProperty('min') ? data[i].p.min + '...' + data[i].p.max : data[i].p) + ' <span class="day_weather_sub">' + word['dayweather_mmhg'] + '</span><br>';
                if (data[i].h) {
                    descr += '<div class="day_weather_sub">' + word['weather_humidity'].toLowerCase() + ': ' + (data[i].h.hasOwnProperty('min') ? data[i].h.min + '-' + data[i].h.max : data[i].h) + '%</div> ';
                }
                descr += '<div class="day_weather_sub" style="margin-left: 5px">' + word['dayweather_wind'];
                descr += '<span class="wind_direction_' + data[i].w_rumb_str + '">&uarr;</span>';
                descr += '<span style="margin-left: 2px"> ' + (data[i].wind.hasOwnProperty('min') ? data[i].wind.min + '-' + data[i].wind.max : data[i].wind) + ' <span class="day_weather_sub">' + word['dayweather_speed'] + '</span></span>';
                descr += '</div>';

                this.map[i].descr.innerHTML = descr;

                this.map[i].layer_1.src = 'template/' + loader.template + '/i' + resolution_prefix + '/' + data[i].pict;

                this.map[i].temp.innerHTML = data[i].temperature;
            }
        };

        this.bind = function () {

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.EXIT, this).bind(key.LEFT, this).bind(key.MENU, this);

            this.load.bind(key.REFRESH, this);
        };
    }

    WeatherForecastConstructor.prototype = new BaseLayer();

    var forecast = new WeatherForecastConstructor();

    forecast.init();

    forecast.bind();

    forecast.init_left_ear(word['ears_back']);

    forecast.init_header_path(word['dayweather_title']);

    forecast.hide();

    module.weather_forecast = forecast;

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": word['dayweather_title'],
        "cmd": function () {
            main_menu.hide();
            module.weather_forecast.show();
        }
    })

})();

loader.next();
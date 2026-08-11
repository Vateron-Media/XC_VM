/**
 * Playback settings module.
 */

(function () {

    if (!stb.profile['use_embedded_settings']) {
        return;
    }

    function PlaybackSettingsConstructor() {

        this.layer_name = 'playback_settings';

        this.save_params = { "type": "stb", "action": "set_playback_settings" };

        this.superclass = SettingLayer.prototype;

        this.min_buffer_time = 0;
        this.max_buffer_time = 15;

        this.audio_out_map = [
            {
                "label": get_word('audio_out_analog'),
                "value": 0
            },
            {
                "label": get_word('audio_out_analog_spdif'),
                "value": 1
            },
            {
                "label": get_word('audio_out_spdif'),
                "value": 2
            }
        ].map(function (item) {
            if (item.value == stb.user['audio_out']) {
                item.selected = 1;
            }
            return item;
        });

        this.playback_limit_map = [
            {
                "label": get_word('playback_limit_off'),
                "value": 0
            },
            {
                "label": 3 + get_word('playback_hours'),
                "value": 3
            },
            {
                "label": 4 + get_word('playback_hours'),
                "value": 4
            },
            {
                "label": 5 + get_word('playback_hours'),
                "value": 5
            },
            {
                "label": 6 + get_word('playback_hours'),
                "value": 6
            }
        ].map(function (item) {
            if (item.value == stb.user['playback_limit']) {
                item.selected = 1;
            }
            return item;
        });

        this.save_callback = function (result) {
            _debug('playback_settings.save_callback', result);

            if (!result) {
                stb.notice.push(word['settings_saving_error']);
                return;
            }

            this.buffer_changer.saved();

            stb.msg.push(word['settings_saved']);

            stb.SetBufferSize(this.save_params.playback_buffer_size * 1000, this.save_params.playback_buffer_bytes);
            stb.SetupSPdif(this.save_params.audio_out);
            stb.user['playback_limit'] = this.save_params.playback_limit;
        };

        this.save = function () {
            _debug('playback_settings.save');

            var playback_buffer_time = parseInt(this.buffer_changer.get_value());

            var max_buffer_bytes = 1024 * 1000 * 10;

            this.save_params.playback_buffer_bytes = Math.round(max_buffer_bytes * playback_buffer_time / this.max_buffer_time);

            this.superclass.save.apply(this);
        };

        this.hide = function () {
            _debug('playback_settings.hide');

            this.buffer_changer.set_default();

            this.superclass.hide.call(this);
        };

        this.init = function () {
            _debug('playback_settings.init');

            this.superclass.init.call(this);

            this.buffer_changer = this.add_control(new VisualValuePickerInput(this,
                {
                    "label": get_word('playback_settings_buffer_size'),
                    "name": "playback_buffer_size",
                    "default_val": parseInt(stb.user['playback_buffer_size']) || 0,
                    "min_val": this.min_buffer_time,
                    "max_val": this.max_buffer_time,
                    "hint_title": get_word('playback_settings_time')
                }
            ));

            _debug('this.buffer_changer', this.buffer_changer);

            var self = this;

            this.buffer_changer.onchange = function () {
                self.buffer_changer.hint_text.innerHTML = self.buffer_changer.get_value();
            };

            this.audio_out = this.add_control(new OptionInput(this, {
                "name": "audio_out",
                "label": get_word('audio_out'),
                "map": this.audio_out_map
            }));

            this.playback_limit = this.add_control(new OptionInput(this, {
                "name": "playback_limit",
                "label": get_word('playback_limit_title'),
                "map": this.playback_limit_map
            }));
        };
    }

    PlaybackSettingsConstructor.prototype = new SettingLayer();

    var playback_settings = new PlaybackSettingsConstructor();

    playback_settings.init();
    playback_settings.bind();

    playback_settings.init_color_buttons([
        {
            "label": word['playback_settings_cancel'], "cmd": function () {
                playback_settings.hide();
                main_menu.show()
            }
        },
        { "label": word['playback_settings_save'], "cmd": playback_settings.save },
        { "label": word['empty'], "cmd": '' },
        { "label": word['empty'], "cmd": '' }
    ]);

    playback_settings.init_header_path(get_word('playback_settings_title'));

    playback_settings.hide();

    module.playback_settings = playback_settings;

    if (!module.settings_sub) {
        module.settings_sub = [];
    }

    module.settings_sub.push({
        "title": get_word('playback_settings_title'),
        "cmd": function () {
            main_menu.hide();
            module.playback_settings.show();
        }
    })


})();

loader.next();
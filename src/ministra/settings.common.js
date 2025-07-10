/**
 * Common settings module.
 */

(function () {

    if (!stb.profile['use_embedded_settings']) {
        return;
    }

    function CommonSettingsConstructor() {

        this.layer_name = 'common_settings';

        this.save_params = { "type": "stb", "action": "set_common_settings" };

        this.superclass = SettingLayer.prototype;

        this.screensaver_delay_map = [
            {
                "label": get_word('screensaver_off'),
                "value": 0
            },
            {
                "label": 10 + get_word('screensaver_minutes'),
                "value": 10
            },
            {
                "label": 20 + get_word('screensaver_minutes'),
                "value": 20
            },
            {
                "label": 30 + get_word('screensaver_minutes'),
                "value": 30
            }
        ].map(function (item) {
            if (item.value == stb.user['screensaver_delay']) {
                item.selected = 1;
            }
            return item;
        });

        this.save_callback = function (result) {
            _debug('common_settings.save_callback', result);

            if (!result) {
                stb.notice.push(word['settings_saving_error']);
                return;
            }

            stb.msg.push(word['settings_saved']);

            stb.user['screensaver_delay'] = this.save_params.screensaver_delay;
            screensaver.restart_timer();
        };

        /*this.save = function(){
            _debug('playback_settings.save');

            this.superclass.save.apply(this);
        };*/

        /*this.hide = function(){
            _debug('playback_settings.hide');

            this.buffer_changer.set_default();

            this.superclass.hide.call(this);
        };*/

        this.init = function () {
            _debug('common_settings.init');

            this.superclass.init.call(this);

            /*this.buffer_changer = this.add_control(new VisualValuePickerInput(this,
                {
                    "label"       : get_word('playback_settings_buffer_size'),
                    "name"        : "playback_buffer_size",
                    "default_val" : parseInt(stb.user['playback_buffer_size']) || 0,
                    "min_val"     : this.min_buffer_time,
                    "max_val"     : this.max_buffer_time,
                    "hint_title"  : get_word('playback_settings_time')
                }
            ));

            _debug('this.buffer_changer', this.buffer_changer);

            var self = this;

            this.buffer_changer.onchange = function(){
                self.buffer_changer.hint_text.innerHTML = self.buffer_changer.get_value();
            };

            this.audio_out = this.add_control(new OptionInput(this , {"name" : "audio_out", "label" : get_word('audio_out'), "map" : this.audio_out_map}));
            */

            this.screensaver_delay = this.add_control(new OptionInput(this, {
                "name": "screensaver_delay",
                "label": get_word('screensaver_delay_title'),
                "map": this.screensaver_delay_map
            }));
        };
    }

    CommonSettingsConstructor.prototype = new SettingLayer();

    var common_settings = new CommonSettingsConstructor();

    common_settings.init();
    common_settings.bind();

    common_settings.init_color_buttons([
        {
            "label": word['settings_cancel'], "cmd": function () {
                common_settings.hide();
                main_menu.show()
            }
        },
        { "label": word['settings_save'], "cmd": common_settings.save },
        { "label": word['empty'], "cmd": '' },
        { "label": word['empty'], "cmd": '' }
    ]);

    common_settings.init_header_path(get_word('common_settings_title'));

    common_settings.hide();

    module.common_settings = common_settings;

    if (!module.settings_sub) {
        module.settings_sub = [];
    }

    module.settings_sub.push({
        "title": get_word('common_settings_title'),
        "cmd": function () {
            main_menu.hide();
            module.common_settings.show();
        }
    })


})();

loader.next();
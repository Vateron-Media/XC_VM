/**
 * Parent settings module.
 */

(function () {

    if (!stb.profile['use_embedded_settings']) {
        return;
    }

    /* SETTINGS */

    function parent_settings_constructor() {

        this.layer_name = 'parent_settings';

        this.save_params = { "type": "stb", "action": "set_parent_password" };

        this.superclass = SettingLayer.prototype;

        this.save_callback = function () {
            stb.notice.show(word['settings_saved'])
        };

        this.load_default = function () {
            _debug('parent_settings_constructor.load_default');

            this.set_default({});
        };

        this.save = function () {
            _debug('parent_settings_constructor.save');
            if ((stb.user.parent_password == this.get_input_value('parent_password')) && (this.get_input_value('pass') == this.get_input_value('repeat_pass'))) {
                stb.user.parent_password = this.get_input_value('pass');
                this.triggerCustomEventListener("onpasswordchange", stb.user.parent_password);
                this.superclass.save.apply(this);
            } else {
                stb.notice.show(word['settings_check_error']);
            }
        };
    }

    parent_settings_constructor.prototype = new SettingLayer();

    var parent_settings = new parent_settings_constructor();

    parent_settings.init();

    parent_settings.add_control(new TextInput(parent_settings, {
        "name": "parent_password",
        "label": word['parent_settings_old_pass'],
        "type": "password"
    }));
    parent_settings.add_control(new TextInput(parent_settings, {
        "name": "pass",
        "label": word['parent_settings_new_pass'],
        "type": "password"
    }));
    parent_settings.add_control(new TextInput(parent_settings, {
        "name": "repeat_pass",
        "label": word['parent_settings_repeat_new_pass'],
        "type": "password"
    }));

    parent_settings.bind();

    parent_settings.init_left_ear(word['ears_back']);

    parent_settings.init_color_buttons([
        { "label": word['parent_settings_cancel'], "cmd": parent_settings.cancel },
        { "label": word['parent_settings_save'], "cmd": parent_settings.save },
        { "label": word['empty'], "cmd": '' },
        { "label": word['empty'], "cmd": '' }
    ]);

    //parent_settings.color_buttons[parent_settings.color_buttons.getIdxByVal('color', 'yellow')].text_obj.setClass('disable_color_btn_text');
    //parent_settings.color_buttons[parent_settings.color_buttons.getIdxByVal('color', 'blue')].text_obj.setClass('disable_color_btn_text');

    parent_settings.init_header_path(word['parent_settings_title']);

    parent_settings.hide();

    module.parent_settings = parent_settings;

    /* END SETTINGS */

    if (!module.settings_sub) {
        module.settings_sub = [];
    }

    module.settings_sub.push({
        "title": word['parent_settings_title_short'],
        "cmd": function () {
            main_menu.hide();
            module.parent_settings.show();
        }
    })

})();

loader.next();
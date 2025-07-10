/**
 * Setting constructor.
 * @constructor
 */

function SettingLayer() {


    this.controls = [];

    this.cur_control_idx = 0;

    this.dom_obj = this.create_block();
    document.body.appendChild(this.dom_obj);

    this.container = document.createElement('div');
    this.container.addClass('setting_table');
    this.dom_obj.appendChild(this.container);

    this.save_callback = function () {
    };

    this.load_params = {};
    this.save_params = {};

    this.base_layer = BaseLayer.prototype;

    this.save_callback = function () {
    };
}

SettingLayer.prototype = new BaseLayer();

SettingLayer.prototype.show = function () {
    _debug('SettingLayer.show');

    //this.reset();

    this.load_default();

    this.base_layer.show.call(this);

    this.reset();
};

SettingLayer.prototype.hide = function () {
    _debug('SettingLayer.hide');

    this.base_layer.hide.call(this);
};

SettingLayer.prototype.add_control = function (obj) {
    _debug('SettingLayer.add_control', obj);

    if (this.controls.length == 0) {
        obj && obj.input && obj.input.setClass && obj.input.setClass('active_input');
    } else {
        obj && obj.input && obj.input.setClass && obj.input.setClass('passive_input');
    }

    var length = this.controls.push(obj);

    return this.controls[length - 1];

    //return this;
};

SettingLayer.prototype.reset = function () {
    _debug('SettingLayer.reset');

    this.set_passive_input();

    this.cur_control_idx = 0;

    this.set_active_input();
};

SettingLayer.prototype.set_active_input = function () {
    _debug('SettingLayer.set_active_input');

    if (empty(this.controls)) {
        return;
    }

    //this.controls[this.cur_control_idx].input.setClass('active_input');
    this.controls[this.cur_control_idx].set_active.call(this.controls[this.cur_control_idx]);
};

SettingLayer.prototype.set_passive_input = function () {
    _debug('SettingLayer.set_passive_input');

    if (empty(this.controls)) {
        return;
    }

    //this.controls[this.cur_control_idx].input.setClass('passive_input');
    this.controls[this.cur_control_idx].set_passive.call(this.controls[this.cur_control_idx]);
};

SettingLayer.prototype.shift = function (dir) {
    _debug('SettingLayer.shift', dir);

    /*if (this.controls[this.cur_control_idx] instanceof OptionInput){
        if(this.controls[this.cur_control_idx].shift.call(this.controls[this.cur_control_idx], dir)){
            _debug('SettingLayer.shift  return');
            return;
        }
    }*/

    if (this.controls[this.cur_control_idx] instanceof OptionInput && this.controls[this.cur_control_idx].suggests_list && !this.controls[this.cur_control_idx].suggests_list.isHidden()) {
        this.controls[this.cur_control_idx].shift_suggests(dir);
        return;
    }

    _debug('shift SettingLayer');

    _debug('this.controls.length', this.controls.length);

    this.set_passive_input();

    if (dir > 0) {
        if (this.cur_control_idx < this.controls.length - 1) {
            this.cur_control_idx++;
        } else {
            this.cur_control_idx = 0;
        }
    } else {
        if (this.cur_control_idx > 0) {
            this.cur_control_idx--;
        } else {
            this.cur_control_idx = this.controls.length - 1;
        }
    }

    this.set_active_input();
};

SettingLayer.prototype.add_symbol = function (symbol) {
    _debug('SettingLayer.add_symbol', symbol);

    this.controls[this.cur_control_idx].add && this.controls[this.cur_control_idx].add(symbol);
};

SettingLayer.prototype.del_symbol = function () {
    _debug('SettingLayer.del_symbol');
    this.controls[this.cur_control_idx].del && this.controls[this.cur_control_idx].del();
};

SettingLayer.prototype.bind = function () {

    this.shift.bind(key.UP, this, -1);
    this.shift.bind(key.DOWN, this, 1);

    this.del_symbol.bind(key.BACK, this);

    this.add_symbol.bind(key.NUM0, this, 0);
    this.add_symbol.bind(key.NUM1, this, 1);
    this.add_symbol.bind(key.NUM2, this, 2);
    this.add_symbol.bind(key.NUM3, this, 3);
    this.add_symbol.bind(key.NUM4, this, 4);
    this.add_symbol.bind(key.NUM5, this, 5);
    this.add_symbol.bind(key.NUM6, this, 6);
    this.add_symbol.bind(key.NUM7, this, 7);
    this.add_symbol.bind(key.NUM8, this, 8);
    this.add_symbol.bind(key.NUM9, this, 9);

    (function () {
        if (this.controls[this.cur_control_idx] instanceof OptionInput && this.controls[this.cur_control_idx].suggest_input_dom_obj && !this.controls[this.cur_control_idx].suggest_input_dom_obj.isHidden()) {
            this.controls[this.cur_control_idx].hide_suggest_input();
        } else {
            this.hide();
            main_menu.show();
        }
    }).bind(key.EXIT, this);

    (function () {
        if (this.controls[this.cur_control_idx] instanceof OptionInput && this.controls[this.cur_control_idx].suggest_input_dom_obj && !this.controls[this.cur_control_idx].suggest_input_dom_obj.isHidden()) {
            this.controls[this.cur_control_idx].hide_suggest_input();
        }
        this.hide();
        main_menu.show();
    }).bind(key.MENU, this);

    (function () {
        if (this.left_ear) {
            this.hide();
            main_menu.show();
        } else if (this.controls && (this.controls[this.cur_control_idx] instanceof OptionInput || this.controls[this.cur_control_idx] instanceof VisualValuePickerInput)) {
            this.controls[this.cur_control_idx].shift.call(this.controls[this.cur_control_idx], -1);
        }
    }).bind(key.LEFT, this);

    (function () {
        if (this.controls && (this.controls[this.cur_control_idx] instanceof OptionInput || this.controls[this.cur_control_idx] instanceof VisualValuePickerInput)) {
            this.controls[this.cur_control_idx].shift.call(this.controls[this.cur_control_idx], 1);
        }
    }).bind(key.RIGHT, this);

    (function () {
        if (this.controls && this.controls[this.cur_control_idx] instanceof OptionInput) {
            this.controls[this.cur_control_idx].shift_page.call(this.controls[this.cur_control_idx], 1);
        }
    }).bind(key.PAGE_NEXT, this);

    (function () {
        if (this.controls && this.controls[this.cur_control_idx] instanceof OptionInput) {
            this.controls[this.cur_control_idx].shift_page.call(this.controls[this.cur_control_idx], -1);
        }
    }).bind(key.PAGE_PREV, this);

    (function () {

        if (this.controls[this.cur_control_idx] instanceof OptionInput) {
            this.controls[this.cur_control_idx].switch_suggest_box();
        } else if (this.controls[this.cur_control_idx] instanceof TextInput) {
            stb.ShowVirtualKeyboard();
        } else {
            this.save();
        }

    }).bind(key.OK, this);
};

SettingLayer.prototype.cancel = function () {
    _debug('SettingLayer.cancel');

    this.reset();

    this.load_default();
};

SettingLayer.prototype.save = function () {
    _debug('SettingLayer.save');

    if (!empty(this.save_params)) {

        for (var i = 0; i < this.controls.length; i++) {

            this.save_params[this.controls[i].name] = this.controls[i].get_value();
        }
    }

    stb.load(
        this.save_params,

        function (result) {
            _debug(result);

            //stb.notice.show(word['settings_saved']);

            this.save_callback && this.save_callback(result);
        },

        this
    )
};

SettingLayer.prototype.load_default = function () {
    _debug('SettingLayer.load_default');

    if (!empty(this.load_params)) {

        stb.load(
            this.load_params,

            function (result) {
                this.set_default(result);
            },

            this
        )
    }
};

SettingLayer.prototype.set_default = function (params) {
    _debug('SettingLayer.set_default', params);

    for (var i = 0; i < this.controls.length; i++) {

        if (params.hasOwnProperty(this.controls[i].name)) {
            this.controls[i].input.value = params[this.controls[i].name];
        } else {
            this.controls[i].reset();
        }
    }
};

SettingLayer.prototype.get_input_value = function (name) {

    var idx = this.controls.getIdxByVal('name', name);

    if (idx !== null) {
        return this.controls[idx].input.value;
    } else {
        return null;
    }
};

loader.next();
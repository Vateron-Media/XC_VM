/**
 * Base layer constructor.
 * @constructor
 */

function BaseLayer() {

    this.layer_name = '';

    this.on = false;

    this.header_path_map = [];

    /*this.dom_obj = this.create_block();
    document.body.appendChild(this.dom_obj);*/
    this.dom_obj = {};

    /*this.color_buttons = [
        {"color" : "red"},
        {"color" : "green"},
        {"color" : "yellow"},
        {"color" : "blue"}
    ];*/

    //this.color_buttons_map = {};
    //this.buttons = {};

    //this.color_buttons.parent = this;
}

BaseLayer.prototype.show = function () {
    _debug('BaseLayer.show');

    this.dom_obj.show();
    this.on = true;

    stb.set_cur_place(this.layer_name);
    stb.set_cur_layer(this);
};

BaseLayer.prototype.hide = function () {
    _debug('BaseLayer.hide');

    this.dom_obj.hide();

    this.on = false;
};

BaseLayer.prototype.init = function () {
    _debug('BaseLayer.init');

    if (!this.class_name) {
        this.class_name = 'layer_bg';
    }

    this.dom_obj.addClass(this.class_name);

    this.dom_obj.id = this.layer_name;

    this.logo_dom_obj = create_block_element('main_logo', this.dom_obj);

    if (stb.user && stb.user.portal_logo_url) {
        this.logo_dom_obj.style.background = 'url(' + stb.user.portal_logo_url + ') no-repeat';
    }
};

BaseLayer.prototype.create_block = function (class_name, is_active) {

    var prefix = '';

    if (is_active) {
        prefix = 'active_';
    }

    var block = document.createElement('div');

    if (class_name) {
        block.addClass(prefix + class_name);
    }

    return block;
};

BaseLayer.prototype.init_color_buttons = function (map, target) {
    this.color_buttons = new ColorButtonsBar(map, this.dom_obj, target);

    this.color_buttons.bind.call(this);
};

function ColorButtonsBar(map, parent_dom_obj, target) {

    this.buttons_bar = {};
    this.map = map;
    this.target = target;

    this.parent_dom_obj = parent_dom_obj;

    this.color_map = [
        { "color": "red" },
        { "color": "green" },
        { "color": "yellow" },
        { "color": "blue" }
    ],

        this.buttons = {};

    this.init(map);
}

ColorButtonsBar.prototype.init = function (map) {
    this.buttons_bar = create_block_element('color_button_bar');

    var table = document.createElement('table');
    this.table = table;

    var row = document.createElement('tr');
    table.appendChild(row);

    for (var i = 0; i <= 3; i++) {

        var cell = document.createElement('td');
        var cell_inner = document.createElement('section');
        cell_inner.className = "cell_inner";
        row.appendChild(cell);

        if (i != 0) {
            separator = document.createElement('div');
            separator.addClass('separator');
            cell_inner.appendChild(separator);
        }

        var color = this.color_map[i].color;

        this.buttons[color] = new ColorButton(color, cell_inner);
        this.buttons[color].setText(map[i].label);

        cell.appendChild(cell_inner);

        if (typeof (map[i].cmd) !== 'function') {
            this.buttons[color].disable();
        }
    }

    this.buttons_bar.appendChild(table);

    this.parent_dom_obj.appendChild(this.buttons_bar);
};

ColorButtonsBar.prototype.bind = function () {

    (function () {
        _debug(this.color_buttons.buttons['red']);
        if (!this.color_buttons.buttons['red'].disabled) {
            this.color_buttons.map[0].cmd.call(this);
        }
    }).bind(key.RED, this);

    (function () {
        _debug(this.color_buttons.buttons['green']);
        if (!this.color_buttons.buttons['green'].disabled) {
            this.color_buttons.map[1].cmd.call(this);
        }
    }).bind(key.GREEN, this);

    (function () {
        _debug(this.color_buttons.buttons['yellow']);
        if (!this.color_buttons.buttons['yellow'].disabled) {
            this.color_buttons.map[2].cmd.call(this);
        }
    }).bind(key.YELLOW, this);

    (function () {
        _debug(this.color_buttons.buttons['blue']);
        if (!this.color_buttons.buttons['blue'].disabled) {
            this.color_buttons.map[3].cmd.call(this);
        }
    }).bind(key.BLUE, this);
};

ColorButtonsBar.prototype.get = function (color) {
    _debug('color_buttons.get', color);

    var button = this.buttons[color];

    _debug('button', button);

    if (!button || !button.hasOwnProperty('enable')) {
        _debug('return dummy');

        return {
            enable: function () {
            },
            disable: function () {
            },
            setText: function () {
            }
        };
    }

    return button;
};

ColorButtonsBar.prototype.disableAll = function () {
    this.buttons_bar.addClass('disabled_all_buttons');
};

ColorButtonsBar.prototype.enableAll = function () {
    this.buttons_bar.delClass('disabled_all_buttons');
};


function ColorButton(color, parent) {

    this.disabled = false;
    this.color = color;
    this.cell = parent;

    this.img_obj = create_block_element('btn_' + color, parent);
    this.text_obj = create_inline_element('', parent);

    this.enable = function () {
        _debug('enable');
        this.disabled = false;
        this.text_obj.delClass();
    };

    this.disable = function () {
        _debug('disable');
        this.disabled = true;
        this.text_obj.setClass('disable_color_btn_text');
    };

    this.setText = function (txt) {
        _debug('setText', txt);
        this.text_obj.innerHTML = txt;
    }
}

BaseLayer.prototype.init_left_ear = function (txt) {

    var ears_left_container = create_block_element('ears_left_container');

    this.left_ear = create_block_element('ears_left');

    var left_arrow = create_block_element('ears_arrow_left');
    var text_element = create_block_element();
    text_element.innerHTML = txt;

    this.left_ear.appendChild(left_arrow.addClass('top_arrow'));
    this.left_ear.appendChild(text_element);
    this.left_ear.appendChild(left_arrow.cloneNode(true).replaceClass('top_arrow', 'bottom_arrow'));

    ears_left_container.appendChild(this.left_ear);

    this.dom_obj.appendChild(ears_left_container);
};

BaseLayer.prototype.init_right_ear = function (txt) {

    var ears_right_container = create_block_element('ears_right_container');

    this.right_ear = create_block_element('ears_right');

    var right_arrow = create_block_element('ears_arrow_right');
    var text_element = create_block_element();
    text_element.innerHTML = txt;
    this.right_ear.appendChild(right_arrow.addClass('top_arrow'));
    this.right_ear.appendChild(text_element);
    this.right_ear.appendChild(right_arrow.cloneNode(true).replaceClass('top_arrow', 'bottom_arrow'));

    ears_right_container.appendChild(this.right_ear);

    this.dom_obj.appendChild(ears_right_container);
};

BaseLayer.prototype.init_header_path = function (begin) {

    this.header_path = this.create_block('mb_header_first');
    this.path_container = document.createElement('span');
    this.header_path.innerHTML = begin + ' &nbsp; ';
    this.header_path.appendChild(this.path_container);
    this.dom_obj.appendChild(this.header_path);
};

BaseLayer.prototype.update_header_path = function (map) {

    var path = '';
    var i;

    for (i = 0; i < map.length; i++) {

        var idx = this.header_path_map.getIdxByVal('alias', map[i].alias);

        if (this.header_path_map.hasOwnProperty(idx)) {

            this.header_path_map.splice(idx, 1);
        }

        if (map[i].item != '*' && map[i].item != '') {
            this.header_path_map.push({ "alias": map[i].alias, "title": map[i].item, "item": map[i].item });
        }
    }

    for (i = 0; i < this.header_path_map.length; i++) {
        if (i < this.header_path_map.length - 1) {
            path += '<span>' + this.header_path_map[i].title + ' - </span>';
        } else {
            path += '<span>' + this.header_path_map[i].title + '</span>';
        }
    }

    this.path_container.innerHTML = path;
};

loader.next();
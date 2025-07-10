/**
 * Bottom menu constructor.
 * @constructor
 */

function bottom_menu(parent, options) {

    this.on = false;
    this.parent = parent;
    this.dependency = [];

    this.dom_obj = {};
    this.main_container = {};

    this.need_reset_load_data = true;

    this.need_update_header = true;

    this.cur_row_idx = 0;

    this.items = [];

    this.offset_x = 190;

    if (options) {
        if (options.offset_x) {
            this.offset_x = options.offset_x;
        }

        if (typeof (options.need_reset_load_data) != "undefined") {
            this.need_reset_load_data = options.need_reset_load_data;
        }

        if (typeof (options.need_update_header) != "undefined") {
            this.need_update_header = options.need_update_header;
        }

        if (typeof (options.color) != "undefined") {
            this.color = options.color;
        }
    }
}

bottom_menu.prototype.show = function () {
    _debug('bottom_menu.show');

    _debug('this.dependency.length', this.dependency.length);

    for (var i = 0; i < this.dependency.length; i++) {
        this.dependency[i].on && this.dependency[i].hide && this.dependency[i].hide();
    }

    //this.dom_obj.moveY(576 - this.dom_obj.clientHeight - 36);
    this.dom_obj.show();
    //this.dom_obj.moveY(this.parent.dom_obj.offsetHeight - this.dom_obj.clientHeight - 36);
    this.dom_obj.moveY(this.parent.dom_obj.offsetHeight - this.dom_obj.clientHeight - this.parent.color_buttons.buttons_bar.offsetHeight);
    if (this.color) {
        var button = this.parent.color_buttons.get(this.color).cell;

        var offset_left = button.offsetLeft + this.parent.color_buttons.table.offsetLeft + button.offsetWidth / 2 - this.dom_obj.offsetWidth / 2 + 7;

        if ((offset_left + this.dom_obj.offsetWidth) > this.parent.dom_obj.offsetWidth) {
            offset_left = this.parent.dom_obj.offsetWidth - this.dom_obj.offsetWidth;
        }

        this.dom_obj.moveX(offset_left);
    }
    this.on = true;
};

bottom_menu.prototype.hide = function () {
    _debug('bottom_menu.hide');

    //this.dom_obj.moveY(576);
    this.dom_obj.hide();
    this.dom_obj.moveY(this.parent.dom_obj.offsetHeight);
    this.on = false;
};

bottom_menu.prototype.init = function (map) {
    _debug('bottom_menu.init');

    this.items = map;

    this.dom_obj = create_block_element('bottom_menu', this.parent.dom_obj);

    this.dom_obj.moveX(this.offset_x);

    create_block_element('bottom_menu_corners', this.dom_obj);

    this.main_container = document.createElement('ul');
    this.main_container.addClass('bottom_menu_container');

    var item;

    for (var i = 0; i < map.length; i++) {

        item = document.createElement('li');
        var label = create_block_element('', item);
        label.style.cssFloat = "left";
        //item.innerHTML = map[i].label;
        label.innerHTML = map[i].label;

        if (map[i].type == "checkbox") {
            var checkbox = create_block_element('', item);
            checkbox.addClass('bull');
            /*checkbox.style.cssFloat = "right";
            checkbox.style.color = "#f00";*/
            checkbox.innerHTML = "&bull;";

            this.items[i].checked = map[i].checked || false;

            if (!map[i].checked) {
                checkbox.hide();
            }

            this.items[i].checkbox_dom_obj = checkbox;
        }

        this.items[i].dom_obj = item;

        this.main_container.appendChild(item);
    }

    this.dom_obj.appendChild(this.main_container);

    this.set_active_row();

    this.hide();
};

bottom_menu.prototype.get_by_name = function (name) {
    _debug('bottom_menu.get_by_name', name);

    var idx = this.items.getIdxByVal('name', name);

    if (idx === null) {
        return null;
    }

    return this.items[idx];
};

bottom_menu.prototype.disable_by_name = function (name) {
    _debug('bottom_menu.disable_by_name', name);

    var item = this.get_by_name(name);

    if (item === null) {
        return false;
    }

    item.dom_obj.setAttribute("rel", "disabled");
};

bottom_menu.prototype.enable_by_name = function (name) {
    _debug('bottom_menu.enable_by_name', name);

    var item = this.get_by_name(name);

    if (item === null) {
        return false;
    }

    item.dom_obj.setAttribute("rel", "enabled");
};

bottom_menu.prototype.check_by_name = function (name) {
    _debug('bottom_menu.check_by_name', name);

    var item = this.get_by_name(name);

    if (item === null) {
        return false;
    }

    this.uncheck_group(item.group);
    item.checked = true;
    item.checkbox_dom_obj.show();
};

bottom_menu.prototype.uncheck_group = function (group) {
    _debug('bottom_menu.uncheck_group', group);

    this.items.map(function (item) {
        if (item.group == group) {
            item.checked = false;
            item.checkbox_dom_obj.hide();
        }
    });
};

bottom_menu.prototype.shift_row = function (dir) {
    _debug('bottom_menu.shift_row', dir);

    this.set_passive_row();

    if (dir > 0) {
        if (this.cur_row_idx < this.items.length - 1) {
            this.cur_row_idx++;
        } else {
            this.cur_row_idx = 0;
        }
    } else {
        if (this.cur_row_idx > 0) {
            this.cur_row_idx--;
        } else {
            this.cur_row_idx = this.items.length - 1;
        }
    }

    this.set_active_row();
};

bottom_menu.prototype.set_active_row = function () {
    _debug('bottom_menu.set_active_row');

    this.items[this.cur_row_idx].dom_obj.setClass('bottom_menu_active_row');
};

bottom_menu.prototype.set_passive_row = function () {
    _debug('bottom_menu.set_passive_row');

    this.items[this.cur_row_idx].dom_obj.delClass();
};

bottom_menu.prototype.action = function () {
    _debug('bottom_menu.action');

    _debug('this.items', this.items);

    if (this.items[this.cur_row_idx].dom_obj.getAttribute("rel") == "disabled") {
        return;
    }

    _debug('this.items[this.cur_row_idx].cmd', this.items[this.cur_row_idx].cmd);

    try {
        this.items[this.cur_row_idx].cmd.call(this);

        if (this.items[this.cur_row_idx].type == "checkbox") {
            this.check_by_name(this.items[this.cur_row_idx].name);
        }

        if (this.need_update_header) {
            var item = this.items[this.cur_row_idx].selector || this.items[this.cur_row_idx].label;
            this.parent.update_header_path([{ "alias": "sortby", "item": item }]);
        }

        if (this.parent.on && this.need_reset_load_data) {
            this.parent.reset();
            this.parent.load_data();
        }

        this.hide();
    } catch (e) {
        _debug(e);
    }
};

bottom_menu.prototype.bind = function () {

    this.shift_row.bind(key.UP, this, -1);
    this.shift_row.bind(key.DOWN, this, 1);

    this.hide.bind(key.EXIT, this);

    this.action.bind(key.OK, this);
};

loader.next();
function context_menu(map) {
    _debug('context_menu');

    this.on = false;
    this.map = map || [];
    this.container;
    this.parent = document.body;
    this.menu_windows = [];
    this.cur_win;

    if (arguments.length > 1) {
        if (arguments[1].hasOwnProperty('parent')) {
            this.parent = arguments[1].parent;
        }
    }
}

context_menu.prototype.destroy_container = function () {
    _debug('context_menu.destroy_container');

    _debug('remove DOM element - container');

    try {
        if (this.container) {
            this.container.parentNode.removeChild(this.container);
        }
    } catch (e) {
        _debug(e);
    }
};

context_menu.prototype.reset = function () {
    _debug('context_menu.reset');

    if (this.on) {
        //this.hide();
    }

    this.destroy_container();

    //delete this.map;
    this.menu_windows = [];
};

context_menu.prototype.show = function () {
    _debug('context_menu.show');

    _debug('this.map.length', this.map.length);

    try {
        if (this.map.length > 0) {
            this.on = true;
            this.container.show();
            this.container.style.height = 0;
            this.set_active_row();
        }
    } catch (e) {
        _debug(e);
    }
};

context_menu.prototype.hide = function () {
    _debug('context_menu.hide');

    this.on = false;
    this.container.hide();
    this.set_passive_row();

    try {
        for (var i = 0; i < this.menu_windows.length; i++) {
            //if (!this.menu_windows[i].dom_obj.isHidden()){

            if (!this.menu_windows[i].main) {
                this.menu_windows[i].dom_obj.hide();
                this.cur_win = this.menu_windows[i];
                this.set_passive_row();
                this.cur_win.cur_row = 0;
            }
            //}
        }

        var idx = this.menu_windows.getIdxByVal('main', true);
        this.cur_win = this.menu_windows[idx];
        this.set_passive_row();
        this.cur_win.cur_row = 0;
        this.cur_win.dom_obj.setClass('con_menu_main_active');

    } catch (e) {
        _debug(e);
    }

};

/**
 * @param {Array} map = [{'title' : String, 'cmd' : String | Array map, 'type' : 'switch|options'}]
 * @param {Object}
 *
 */
context_menu.prototype.construct = function (map) {
    _debug('context_menu.construct', map);

    this.map = map || [];

    this.reset();

    //if (!this.container){
    this.container = create_block_element('context_menu_container', this.parent);
    this.container.hide();
    //}

    var dom_obj = create_block_element('con_menu_main_active', this.container);

    var main_table = document.createElement("table");
    this.main_table = main_table;

    /* MAIN MENU TOP ROW */
    var main_top_tr = document.createElement("tr");

    var main_top_left_td = document.createElement("td");
    main_top_left_td.setClass('v_menu1_top_l');
    main_top_tr.appendChild(main_top_left_td);

    var main_top_right_td = document.createElement("td");
    main_top_right_td.setClass('v_menu1_top_r');
    main_top_tr.appendChild(main_top_right_td);

    main_table.appendChild(main_top_tr);
    /* END MAIN MENU TOP ROW */

    /* MAIN MENU CENTER ROW */
    var main_center_tr = document.createElement("tr");

    var main_center_td = document.createElement("td");
    main_center_tr.appendChild(main_center_td);

    var main_container_table = document.createElement("table");
    main_container_table.setClass('con_menu_container');
    main_center_td.appendChild(main_container_table);

    for (var i = 0; i < map.length; i++) {
        var container_tr = document.createElement("tr");
        map[i].dom_obj = document.createElement("td");
        container_tr.appendChild(map[i].dom_obj);
        main_container_table.appendChild(container_tr);

        if (i == 0) {
            map[i].dom_obj.setClass('active');
        }

        map[i].title_dom_obj = create_inline_element('', map[i].dom_obj);

        map[i].title_dom_obj.innerHTML = map[i].title;

        if (typeof (map[i].cmd) == 'object') {
            map[i].sub_dom_obj = this.construct_sub_menu(i, map[i].cmd, map[i].type);
        }
    }

    this.cur_win = {
        "win_idx": this.menu_windows.length,
        "parent_win": -1,
        "main": true,
        "from_row": -1,
        "dom_obj": dom_obj,
        "cur_row": 0,
        "prev_row": 0,
        "map": map
    };

    this.menu_windows.push(this.cur_win);

    main_table.appendChild(main_center_tr);

    var main_center_right_cell = document.createElement("td");
    main_center_right_cell.setClass('v_menu1_center_r');
    main_center_right_cell.innerHTML = '&nbsp;';

    main_center_tr.appendChild(main_center_right_cell);
    /* END MAIN MENU CENTER ROW */

    /* MAIN MENU BOTTOM ROW */
    var main_bottom_tr = document.createElement("tr");

    var main_bottom_left_td = document.createElement("td");
    main_bottom_left_td.setClass('v_menu1_bottom_l');
    main_bottom_tr.appendChild(main_bottom_left_td);

    var main_bottom_right_td = document.createElement("td");
    main_bottom_right_td.setClass('v_menu1_bottom_r');
    main_bottom_tr.appendChild(main_bottom_right_td);

    main_table.appendChild(main_bottom_tr);
    /* END MAIN MENU BOTTOM ROW */

    dom_obj.appendChild(main_table);
};

context_menu.prototype.construct_sub_menu = function (from_row, map_item, type) {

    var sub_menu = create_block_element('sub_con_menu', this.container);

    if (from_row != 0) {
        sub_menu.hide();
    }

    var top = from_row * 30 - 5;

    sub_menu.offsetY(top);

    var sub_table = document.createElement("table");

    /* SUB MENU TOP ROW */
    var sub_top_tr = document.createElement("tr");

    var sub_top_left_td = document.createElement("td");
    sub_top_left_td.setClass('v_menu2_top_l');
    sub_top_tr.appendChild(sub_top_left_td);

    var sub_top_right_td = document.createElement("td");
    sub_top_right_td.setClass('v_menu2_top_r');
    sub_top_tr.appendChild(sub_top_right_td);

    sub_table.appendChild(sub_top_tr);
    /* END SUB MENU TOP ROW */

    /* SUB MENU CENTER ROW */
    var sub_center_tr = document.createElement("tr");

    var sub_center_left_cell = document.createElement("td");
    sub_center_left_cell.setClass('v_menu2_center_l');
    sub_center_left_cell.innerHTML = '&nbsp;';
    sub_center_tr.appendChild(sub_center_left_cell);

    var sub_center_td = document.createElement("td");
    sub_center_tr.appendChild(sub_center_td);

    var sub_container_table = document.createElement("table");
    sub_container_table.setClass('sub_menu_container');
    sub_center_td.appendChild(sub_container_table);

    for (var i = 0; i < map_item.length; i++) {
        var container_tr = document.createElement("tr");
        map_item[i].dom_obj = document.createElement("td");
        container_tr.appendChild(map_item[i].dom_obj);
        sub_container_table.appendChild(container_tr);

        if (i == 0) {
            //map_item[i].dom_obj.setClass('active');
        }

        map_item[i].space = create_block_element('space', map_item[i].dom_obj);
        map_item[i].space.innerHTML = '&nbsp;';
        map_item[i].bull = create_block_element('bull', map_item[i].dom_obj);
        map_item[i].bull.innerHTML = '&bull;';
        map_item[i].bull.hide();

        map_item[i].title_dom_obj = create_inline_element('', map_item[i].dom_obj);

        if (map_item[i].hasOwnProperty('active')) {
            if (map_item[i].active && (this.map[from_row].type == 'switch' || this.map[from_row].type == 'options')) {
                map_item[i].bull.show();
                map_item[i].space.hide();
            }
        }

        map_item[i].title_dom_obj.innerHTML = map_item[i].title;
    }

    this.menu_windows.push(
        {
            "win_idx": this.menu_windows.length,
            "parent_win": 0,
            "main": false,
            "from_row": from_row,
            "dom_obj": sub_menu,
            "cur_row": 0,
            "prev_row": 0,
            "map": map_item,
            "type": type
        });

    sub_table.appendChild(sub_center_tr);
    /* END SUB MENU CENTER ROW */

    /* SUB MENU BOTTOM ROW */
    var sub_bottom_tr = document.createElement("tr");

    var sub_bottom_left_td = document.createElement("td");
    sub_bottom_left_td.setClass('v_menu2_bottom_l');
    sub_bottom_tr.appendChild(sub_bottom_left_td);

    var sub_bottom_right_td = document.createElement("td");
    sub_bottom_right_td.setClass('v_menu2_bottom_r');
    sub_bottom_tr.appendChild(sub_bottom_right_td);

    sub_table.appendChild(sub_bottom_tr);
    /* END SUB MENU BOTTOM ROW */

    sub_menu.appendChild(sub_table);

    return sub_menu;
};

context_menu.prototype.bind = function () {

    this.shift_row.bind(key.UP, this, -1);
    this.shift_row.bind(key.DOWN, this, 1);

    this.goto_sub_menu.bind(key.RIGHT, this);

    this.action.bind(key.OK, this);

    this.goto_main_menu.bind(key.LEFT, this);

    this.hide.bind(key.EXIT, this).bind(key.NULL, this).bind(key.WEB, this).bind(key.AUDIO, this);
};

context_menu.prototype.goto_sub_menu = function () {
    if (this.cur_win.main) {

        var idx = this.menu_windows.getIdxByVal('from_row', this.cur_win.cur_row);

        this.cur_win.dom_obj.setClass('con_menu_main_passive');

        this.cur_win = this.menu_windows[idx];
        this.set_active_row();
    }
};

context_menu.prototype.goto_main_menu = function () {
    if (!this.cur_win.main) {

        this.set_passive_row();

        var idx = this.menu_windows.getIdxByVal('main', true);

        this.cur_win = this.menu_windows[idx];

        this.cur_win.dom_obj.setClass('con_menu_main_active');
    }
};

context_menu.prototype.action = function () {
    _debug('context_menu.action');

    if (this.cur_win.type == 'switch') {

        this.reset_switch(this.cur_win.cur_row);

    } else if (this.cur_win.type == 'options') {
        this.set_active_option(this.cur_win.cur_row);
    }

    _debug('title', this.cur_win.map[this.cur_win.cur_row].title);
    _debug('cmd', this.cur_win.map[this.cur_win.cur_row].cmd);

    try {
        this.cur_win.map[this.cur_win.cur_row].cmd();
    } catch (e) {
        _debug(e);
    }

    this.hide();
};

context_menu.prototype.set_active_option = function (row) {

    this.cur_win.map[row].space.hide();
    this.cur_win.map[row].bull.show();
};

context_menu.prototype.reset_switch = function (exept) {
    for (var i = 0; i < this.cur_win.map.length; i++) {

        if (i != exept) {
            this.cur_win.map[i].space.show();
            this.cur_win.map[i].bull.hide();
        } else {
            this.cur_win.map[i].space.hide();
            this.cur_win.map[i].bull.show();
        }

    }
};

context_menu.prototype.shift_row = function (dir) {
    _debug('context_menu.shift_row', dir);

    this.cur_win.prev_row = this.cur_win.cur_row;

    if (dir > 0) {
        if (this.cur_win.cur_row < this.cur_win.map.length - 1) {
            this.cur_win.cur_row++;
        } else {
            this.cur_win.cur_row = 0;
        }
    } else {
        if (this.cur_win.cur_row > 0) {
            this.cur_win.cur_row--;
        } else {
            this.cur_win.cur_row = this.cur_win.map.length - 1;
        }
    }

    this.set_active_row();
};

context_menu.prototype.set_active_row = function () {
    _debug('context_menu.set_active_row');

    try {
        this.cur_win.map[this.cur_win.prev_row].dom_obj.delClass();
        this.cur_win.map[this.cur_win.cur_row].dom_obj.setClass('active');

        if (this.cur_win.main) {
            this.cur_win.map[this.cur_win.prev_row].sub_dom_obj.hide();
            this.cur_win.map[this.cur_win.cur_row].sub_dom_obj.moveX(this.cur_win.dom_obj.childNodes[0].offsetWidth);
            this.cur_win.map[this.cur_win.cur_row].sub_dom_obj.show();
        }

    } catch (e) {
        _debug(e)
    }
};

context_menu.prototype.set_passive_row = function () {
    _debug('context_menu.set_passive_row');

    try {
        this.cur_win.map[this.cur_win.cur_row].dom_obj.delClass();
    } catch (e) {
        _debug(e)
    }
};

context_menu.prototype.set_x_offset = function (x) {
    _debug('context_menu.set_x_offset', x);

    x = parseInt(x);

    if (this.container) {
        this.container.style.left = parseInt(x) + 'px';
    }
};

context_menu.prototype.set_y_offset = function (y) {
    _debug('context_menu.set_y_offset', y);

    y = parseInt(y);

    if (this.container) {
        this.container.style.top = parseInt(y) + 'px';
    }
};

context_menu.prototype.set_z_index = function (z) {
    _debug('context_menu.set_z_index', z);

    z = parseInt(z);

    if (this.container) {
        this.container.style.zIndex = parseInt(z);
    }
};

loader.next();
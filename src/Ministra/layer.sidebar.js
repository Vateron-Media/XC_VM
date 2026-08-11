/**
 * Sidebar constructor.
 * @constructor
 */

function sidebar(parent, options) {

    this.on = false;
    this.parent = parent;
    this.dependency = [];

    this.dom_obj = {};
    this.main_container = {};

    this.arrows = {};

    this.lists = [];

    this.cur_list_idx = 0;
}

sidebar.prototype.show = function () {
    _debug('sidebar.show');

    for (var i = 0; i < this.dependency.length; i++) {
        this.dependency[i].on && this.dependency[i].hide && this.dependency[i].hide();
    }

    this.dom_obj.show();
    this.dom_obj.moveX(this.parent.dom_obj.offsetWidth - this.dom_obj.offsetWidth);
    this.on = true;
    this.set_active_list();
};

sidebar.prototype.hide = function () {
    _debug('sidebar.hide');

    this.dom_obj.hide();
    this.dom_obj.moveX(this.parent.dom_obj.offsetWidth);
    this.on = false;
};

sidebar.prototype.reset = function () {
    _debug('sidebar.reset');

    for (var i = 0; i < this.lists.length; i++) {
        this.lists[i].selected = '*';
        this.lists[i].selected_title = '*';
        this.parent.update_header_path([{ "alias": this.lists[i].alias, "item": this.lists[i].selected_title }]);
    }

    this.cur_list_idx = 0;
    this.set_active_list();
};

sidebar.prototype.full_reset = function () {
    _debug('sidebar.full_reset');

    this.reset();

    try {
        for (var i = 0; i < this.lists.length; i++) {
            //_debug('i', i);

            for (var j = 0; j < this.lists[i].map.length; j++) {
                //_debug('j', j);

                this.lists[i].map.unshift(this.lists[i].map.pop());

                if (this.lists[i].map[0].title == '*') {
                    //_debug('*');

                    for (var k = 1; k <= 4; k++) {
                        this.lists[i].map.unshift(this.lists[i].map.pop());
                    }


                    this.render_items(i);
                    break;
                }

            }
        }

        this.action();
    } catch (e) {
        _debug(e);
    }
};

sidebar.prototype.init = function () {

    this.dom_obj = create_block_element('footer_sidepanel', this.parent.dom_obj);

    create_block_element('footer_sidepanel_r', this.dom_obj);

    this.arrows = create_block_element('sidebar_arrows', this.dom_obj);

    var container = create_block_element('sidepanel_container', this.dom_obj);

    this.main_container = document.createElement('ul');
    this.main_container.addClass('t_row');

    container.appendChild(this.main_container);

    this.hide();
};

sidebar.prototype.init_items = function (alias) {

    var item;
    var items = [];
    var list = document.createElement('ul');

    var options = {};

    if (arguments.length = 2) {
        options = arguments[1];
    }

    for (var i = 0; i < 9; i++) {
        item = document.createElement('li');

        if (i == 4) {
            item.setAttribute("rel", "active");
        }

        items.push(item);
        list.appendChild(item);

        if (options && options.align) {
            item.style.textAlign = options.align;
        }

        if (options && options.width) {
            item.style.width = options.width + 'px';
        }
    }

    if (this.lists.length > 0) {

        this.main_container.appendChild(this.create_separator());
        list.addClass('items_container_passive');
    } else {
        list.addClass('items_container_active');
    }

    var cell = document.createElement('li');

    cell.appendChild(list);

    this.main_container.appendChild(cell);

    this.lists.push({
        "header": options.header,
        "alias": alias,
        "dom_obj": list,
        "items": items,
        "selected": "*",
        "selected_title": "*"
    });
};

sidebar.prototype.fill_items = function (alias, map) {
    _debug('sidebar.fill_items', alias, map);

    var idx = this.lists.getIdxByVal("alias", alias);

    if (idx === null) {
        return;
    }

    if (map.length > 0) {
        for (var j = 1; j <= 4; j++) {
            map.unshift(map.pop());
        }
    }

    this.lists[idx].map = map;

    this.render_items(idx);
};

sidebar.prototype.vshift = function (dir) {

    _debug('vshift', dir);

    if (dir > 0) {
        this.lists[this.cur_list_idx].map.push(this.lists[this.cur_list_idx].map.shift());
    } else {
        var menu_length = this.lists[this.cur_list_idx].map.length;
        this.lists[this.cur_list_idx].map.unshift(this.lists[this.cur_list_idx].map[menu_length - 1]);
        this.lists[this.cur_list_idx].map.splice(menu_length, 1);
    }

    this.render_items(this.cur_list_idx);
};

sidebar.prototype.shift_page = function (dir) {

    _debug('shift_page', dir);


    if (this.lists[this.cur_list_idx].map.length <= 9) {
        return;
    }

    if (dir > 0) {

        for (var i = 0; i < 9; i++) {
            this.lists[this.cur_list_idx].map.push(this.lists[this.cur_list_idx].map.shift());
        }

    } else {
        var menu_length = this.lists[this.cur_list_idx].map.length;

        for (var i = 0; i < 9; i++) {
            this.lists[this.cur_list_idx].map.unshift(this.lists[this.cur_list_idx].map[menu_length - 1]);
            this.lists[this.cur_list_idx].map.splice(menu_length, 1);
        }
    }

    this.render_items(this.cur_list_idx);
};

sidebar.prototype.hshift = function (dir) {

    _debug('hshift', dir);

    if (dir > 0) {
        if (this.cur_list_idx < this.lists.length - 1) {
            this.cur_list_idx++;
        }
    } else {
        if (this.cur_list_idx > 0) {
            this.cur_list_idx--;
        }
    }

    this.set_active_list();
};

sidebar.prototype.set_active_list = function () {

    for (var i = 0; i < this.lists.length; i++) {

        if (i != this.cur_list_idx) {
            this.lists[i].dom_obj.setClass('items_container_passive');
        } else {
            this.lists[i].dom_obj.setClass('items_container_active');
            this.arrows.moveX(this.lists[i].dom_obj.clientWidth / 2 - 6 + this.lists[i].dom_obj.offsetLeft);
        }
    }
};

sidebar.prototype.render_items = function (item_idx) {

    _debug('render_items item_idx', item_idx);

    for (var i = 0; i < 9; i++) {

        try {

            if (!this.lists[item_idx].map[i]) {

                this.lists[item_idx].items[i].innerHTML = '';

                continue;
            }

            this.lists[item_idx].items[i].innerHTML = this.lists[item_idx].map[i].title;

            if (this.lists[item_idx].map[i].id == this.lists[item_idx].selected) {
                this.lists[item_idx].items[i].setClass('selected_item');
            } else {
                this.lists[item_idx].items[i].setClass('passive_item');
            }

        } catch (e) {
            _debug(e);
        }
    }
};

sidebar.prototype.create_separator = function () {

    var separator = document.createElement('div');
    separator.addClass('separator');
    this.dom_obj.appendChild(separator);

    return separator;
};

sidebar.prototype.set_selected_item = function () {
    _debug('set_selected_item');

    try {

        for (var i = 0; i < 9; i++) {

            if (!this.lists[this.cur_list_idx].map[i]) {
                continue;
            }

            if (this.lists[this.cur_list_idx].map[i].id == this.lists[this.cur_list_idx].selected) {
                this.lists[this.cur_list_idx].items[i].setClass('passive_item');
            }
        }

        this.lists[this.cur_list_idx].selected = this.lists[this.cur_list_idx].map[4].id;
        this.lists[this.cur_list_idx].selected_title = this.lists[this.cur_list_idx].map[4].title;
        this.lists[this.cur_list_idx].items[4].setClass('selected_item');

    } catch (e) {
        _debug(e)
    }

    this.action();
};

sidebar.prototype.action = function () {
    _debug('action');

    for (var i = 0; i < this.lists.length; i++) {
        this.parent.load_params[this.lists[i].alias] = this.lists[i].selected;

        this.parent.reset();

        this.parent.update_header_path([{
            "alias": this.lists[i].alias,
            "title": this.lists[i].header,
            "item": this.lists[i].selected_title
        }]);
    }

    this.parent.load_data();
};

sidebar.prototype.bind = function () {
    this.vshift.bind(key.UP, this, -1);
    this.vshift.bind(key.DOWN, this, 1);

    this.shift_page.bind(key.PAGE_PREV, this, -1);
    this.shift_page.bind(key.PAGE_NEXT, this, 1);

    this.hshift.bind(key.LEFT, this, -1);
    this.hshift.bind(key.RIGHT, this, 1);

    this.set_selected_item.bind(key.OK, this);

    this.hide.bind(key.EXIT, this);

    this.full_reset.bind(key.NUM0, this)
};

loader.next();
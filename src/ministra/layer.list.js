/**
 * List Layer constructor.
 * @constructor
 */

function ListLayer() {

    /*  required properties  */
    this.layer_name = '';
    this.row_blocks = [];
    this.load_params = {};
    this.class_name = '';

    this.on = false;
    //this.dom_obj = {};
    this.dom_obj = this.create_block();
    document.body.appendChild(this.dom_obj);

    if (loader.template == "xc_vm") {
        this.active_row_offset = 0;
        this.total_rows = 10;
    } else {
        this.active_row_offset = 15;
        this.total_rows = 14;
    }
    this.total_items = 0;
    this.total_pages = 0;
    this.cur_row = 0;
    this.prev_row = 0;
    this.cur_page = 1;
    this.data_items = [];
    this.map = [];
    this.loading = false;
    this.total_vclub_items_obj = {};
    this.buttons_bar = {};
    this.left_ear = {};
    this.right_ear = {};
    this.header_path = {};
    this.path_container = {};
    this.header_path_map = [];
    this.main_container = {};
    this.shift_row_callback;

    this.cur_view = 'wide';

    this.sidebar = {};

    this.page_dir = 1;

    /*this.color_buttons = [
        {"color" : "red"},
        {"color" : "green"},
        {"color" : "yellow"},
        {"color" : "blue"}
    ];*/

    //this.color_buttons_map = {};
    //this.buttons = {};

    this.loader = {};

    this.base_layer = BaseLayer.prototype;
}

ListLayer.prototype = new BaseLayer();

ListLayer.prototype.show = function (do_not_load) {
    _debug('ListLayer.show', do_not_load);

    this.base_layer.show.call(this);

    if (!do_not_load) {


        this.load_data();
    } else {
        this.set_active_row(this.cur_row);
    }
};

ListLayer.prototype.hide = function (do_not_reset) {
    _debug('ListLayer.hide');

    this.set_passive_row();

    if (!do_not_reset) {
        this.reset();

        this.load_params = this.load_params_pattern.clone();

        this.sidebar && this.sidebar.reset && this.sidebar.reset();

        if (this.sidebar && this.sidebar.on) {
            this.sidebar.hide();
        }
    }

    this.base_layer.hide.call(this);
};

ListLayer.prototype.reset = function () {
    _debug('ListLayer.reset');

    this.cur_row = 0;
    this.cur_page = 1;
    this.page_dir = 1;
    this.total_pages = 0;

    this.clear_list();
};

ListLayer.prototype.clear_list = function () {
    _debug('ListLayer.clear_list');

    for (var j = 0; j < this.total_rows; j++) {
        this.clear_row(this.map[j].row);
    }

    this.data_items = [];

    this.set_total_items(-1);
};

ListLayer.prototype.init = function () {

    this.load_params_pattern = this.load_params.clone();

    this.base_layer.init.call(this);

    this.init_page_bar();
    this.init_list();
};

ListLayer.prototype.init_page_bar = function () {

    this.total_vclub_items_obj = this.create_block();
    this.total_vclub_items_obj.addClass('mb_header_info');

    this.dom_obj.appendChild(this.total_vclub_items_obj);
};

ListLayer.prototype.init_list = function () {
    _debug('ListLayer.init_list');

    var item;
    var offset;

    this.init_page_bar();

    this.main_container = create_block_element('wide_container', this.dom_obj);

    for (var i = 0; i < this.total_rows; i++) {
        item = this.create_block('blue_row_bg');
        //item = document.createElement('ul');
        //item.setClass('blue_row_bg');

        if (i > 0) {
            offset = this.map[i - 1].row.clientHeight + this.map[i - 1].row.offsetTop;
            item.moveY(offset);
        }

        this.map[i] = this.init_blocks(item);

        this.map[i]['row'] = item;
        this.main_container.appendChild(item);
    }

    this.init_active_row();
};

ListLayer.prototype.set_wide_container = function () {
    _debug('set_wide_container');

    this.cur_view = 'wide';
    this.main_container.setClass('wide_container');

    this.info_box && this.info_box.hide && this.info_box.hide();
};

ListLayer.prototype.set_middle_container = function () {
    _debug('set_middle_container');

    this.cur_view = 'middle';
    this.main_container.setClass('middle_container');

    this.info_box && this.info_box.show && this.info_box.show();
};

ListLayer.prototype.set_short_container = function () {
    _debug('set_short_container');

    this.cur_view = 'short';
    this.main_container.setClass('short_container');

    this.info_box && this.info_box.show && this.info_box.show();
};

ListLayer.prototype.init_blocks = function (parent, is_active_row) {

    var block_name;
    var block_map = {};

    for (var j = 0; j < this.row_blocks.length; j++) {

        block_name = this.row_blocks[j] + '_block';

        block_map[block_name] = this.create_block(block_name, is_active_row);

        parent.appendChild(block_map[block_name]);
    }

    return block_map;
};

ListLayer.prototype.init_active_row = function () {

    var active_row = document.createElement('div');
    active_row.addClass('active_row_bg');

    active_row.hide();

    this.active_row = this.init_blocks(active_row, true);

    this.active_row['row'] = active_row;

    this.main_container.appendChild(active_row);
};

ListLayer.prototype.init_sidebar = function (options) {
    this.sidebar = new sidebar(this, options);
    this.sidebar.init();
};

ListLayer.prototype.load_data = function () {
    _debug('ListLayer.load_data');

    this.set_passive_row();

    //this.clear_list();
    this.set_total_items(-1);

    this.load_params['p'] = this.cur_page;

    this.loading = true;

    _debug('typeof(this.loader)', typeof (this.loader));
    _debug('this.loader.abort', this.loader.abort);

    this.loader && this.loader.abort && this.loader.abort();

    this.break_filling_list = true;

    this.loader = stb.load(
        this.load_params,

        function (result) {
            _debug('callback run');
            _debug('result', result);

            this.break_filling_list = false;

            this.result = result;
            this.total_pages = Math.ceil(result.total_items / result.max_page_items);

            if (result.selected_item != 0 || result.cur_page != 0) {
                this.cur_row = result.selected_item - 1;
                this.cur_page = result.cur_page;
            } else if (this.cur_page == 0) {
                this.cur_page = 1
            }

            this.set_total_items(result.total_items);

            this.fill_list(result.data);
        },

        this
    )
};

ListLayer.prototype.set_total_items = function (count) {
    _debug('ListLayer.set_total_items: ', count);

    var str = '';

    if (this.total_pages != 0 && count >= 0) {
        str += word['layer_page'] + ' <span class="text20_white bold">' + this.cur_page.toLocaleString() + '</span> ' + word['layer_from'] + ' <span class="text20_white bold">' + this.total_pages.toLocaleString() + '</span>.';
    }

    if (count >= 0) {
        str += ' ' + word['layer_found'] + ' <span class="text20_white bold">' + count.toLocaleString() + '</span> ' + word['layer_records'] + '.';
    } else {
        str += ' ' + word['layer_loading'];
    }

    this.total_vclub_items_obj.innerHTML = str;
};

ListLayer.prototype.fill_list = function (data) {
    _debug('ListLayer.fill_list', data);

    this.total_items = data.length;
    this.data_items = data;

    for (var i = 0; i < data.length; i++) {

        if (this.break_filling_list) {
            return;
        }

        for (var j = 0; j < this.row_blocks.length; j++) {
            this.handling_block(data[i][this.row_blocks[j]], this.map[i], this.row_blocks[j]);
        }

        _debug('data[i]', data[i]);

        if (data[i].hasOwnProperty('open') && (!data[i].open || data[i].only_for_moderator == 1)) {
            this.map[i]['row'].addClass('close');
            //this.map[i]['row'].setAttribute('rel', 'close');
        } else {
            this.map[i]['row'].removeClass('close');
            //this.map[i]['row'].setAttribute('rel', '');
        }
    }

    if (i < this.total_rows) {
        for (var j = i; j < this.total_rows; j++) {
            this.clear_row(this.map[j].row);
        }
    }

    if (this.result && this.result.hasOwnProperty("selected_item") && this.result.hasOwnProperty("cur_page") && this.result.selected_item == 0 && this.result.cur_page == 0) {
        if (this.page_dir > 0) {
            this.cur_row = 0;
        } else {
            this.cur_row = this.total_items - 1;
        }
    }

    this.set_active_row(this.cur_row);

    this.loading = false;
};

ListLayer.prototype.handling_block = function (data, row_items, block_name) {

    var block_obj = row_items[block_name + '_block'];

    if (data === 1) {
        if (block_obj.isHidden()) {
            block_obj.show();
        }
    } else if (data === 0 || typeof (data) == "undefined") {
        if (!block_obj.isHidden()) {
            block_obj.hide();
        }
    } else {
        if (block_obj.isHidden()) {
            block_obj.show();
        }

        block_obj.innerHTML = data;
    }
};

ListLayer.prototype.clear_row = function (row_obj) {

    for (var i = 0; i < row_obj.childNodes.length; i++) {
        if (!row_obj.childNodes[i].isHidden()) {

            if (row_obj.childNodes[i].innerHTML) {
                row_obj.childNodes[i].innerHTML = '&nbsp;';
                row_obj.childNodes[i].hide();
            } else {
                row_obj.childNodes[i].hide();
            }
        }
    }
};

ListLayer.prototype.set_active_row = function (num) {

    _debug('set_active_row', num);

    try {

        if (!this.data_items[num]) {
            if (this.data_items[num - 1]) {
                num = num - 1;
            } else {
                num = 0;
            }
            this.cur_row = num;
        }

        if (num == 0) {
            if (!this.data_items[num]) {
                if (!this.active_row['row'].isHidden()) {
                    this.active_row['row'].hide();
                }
            } else {
                if (this.active_row['row'].isHidden()) {
                    this.active_row['row'].show();
                }
            }
        }

        var offset = this.map[num]['row'].offsetTop - this.active_row_offset;

        this.active_row['row'].moveY(offset);

        if (this.active_row['row'].isHidden()) {
            this.active_row['row'].show();
        }

        _debug('this.data_items[num]', this.data_items[num]);

        if (this.data_items[num]) {

            if (this.data_items[num].hasOwnProperty('open') && (!this.data_items[num].open || this.data_items[num].only_for_moderator == 1)) {
                this.active_row['row'].addClass('close');
                //this.active_row['row'].setAttribute('rel', 'close');
            } else {
                this.active_row['row'].removeClass('close');
                //this.active_row['row'].setAttribute('rel', '');
            }
        }

        if (!this.fav_manage_mode) {

            if (this.data_items.length != 0) {

                for (var j = 0; j < this.row_blocks.length; j++) {
                    this.handling_block(this.data_items[num][this.row_blocks[j]], this.active_row, this.row_blocks[j]);
                }

                if (this.shift_row_callback && (this.cur_view == 'middle' || this.cur_view == 'short')) {
                    this.shift_row_callback.call(this, this.data_items[num]);
                }
            } else {
                this.active_row['row'].hide();
            }
        }

        _debug('ListLayer.set_active_row end')
    } catch (e) {
        _debug(e);
    }
};

ListLayer.prototype.set_passive_row = function () {
    this.active_row['row'].hide();
};

ListLayer.prototype.shift_row = function (dir) {
    _debug('this.loading', this.loading);

    window.clearTimeout(this.row_callback_timer);

    if (this.loading) {
        return;
    }

    this.prev_row = this.cur_row;

    if (dir > 0) {
        if (this.cur_row < this.total_items - 1) {
            this.cur_row++;
            this.set_active_row(this.cur_row);
        } else {
            this.set_passive_row();
            this.shift_page(1);
        }
    } else {
        if (this.cur_row > 0) {
            this.cur_row--;
            this.set_active_row(this.cur_row);
        } else {
            this.set_passive_row();
            this.shift_page(-1);
        }
    }
};

ListLayer.prototype.shift_page = function (dir) {

    this.page_dir = dir;

    if (dir > 0) {
        if (this.cur_page < this.total_pages) {
            this.cur_page++;
        } else {
            this.cur_page = 1;
        }
    } else {
        if (this.cur_page > 1) {
            this.cur_page--;
        } else {
            this.cur_page = this.total_pages;
        }
    }

    this.load_data();
};

ListLayer.prototype.sidebar_switcher = function () {

    if (this.sidebar && this.sidebar.on) {
        this.sidebar.hide();
    } else {
        this.sidebar.show();
    }
};

ListLayer.prototype.bind = function () {

    this.shift_row.bind(key.UP, this, -1);
    this.shift_row.bind(key.DOWN, this, 1);

    this.shift_page.bind(key.PAGE_PREV, this, -1);
    this.shift_page.bind(key.PAGE_NEXT, this, 1);

    (function () {
        this.hide();
        main_menu.show();
    }).bind(key.MENU, this).bind(key.EXIT, this).bind(key.LEFT, this);
};

loader.next();
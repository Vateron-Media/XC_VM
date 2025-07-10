/**
 * Video club info layer constructor.
 * @constructor
 */

function sclub_info(parent) {

    this.on = false;

    this.parent = parent;

    this.dom_obj = create_block_element('layer_bg', document.body);

    this.main_container = {};

    this.film_title = {};
    this.full_info = {};
    this.film_cover = {};

    this.scrollbar = {};
}

sclub_info.prototype.show = function (item) {
    _debug('sclub_info.show');

    this.item = item;

    if (!module.downloads) {
        this.color_buttons.get('red').disable();
    } else {
        this.color_buttons.get('red').enable();
    }

    this.dom_obj.show();
    this.on = true;

    this.fill(item);
};

sclub_info.prototype.hide = function () {
    _debug('sclub_info.hide');

    this.parent.on = true;

    this.full_info.scrollTop = 0;
    this.reset();
    this.dom_obj.hide();
    this.on = false;

    this.scrollbar && this.scrollbar.reset && this.scrollbar.reset();

    this.parent.password_input.on && this.parent.password_input.hide && this.parent.password_input.hide();
    this.parent.rent_password_input.on && this.parent.rent_password_input.hide && this.parent.rent_password_input.hide();
    this.parent.series_switch.on && this.parent.series_switch.hide && this.parent.series_switch.hide();
};

sclub_info.prototype.reset = function () {
    _debug('sclub_info.reset');

    this.film_title.innerHTML = '';
    this.full_info.innerHTML = '';
    this.film_cover.innerHTML = '';
};

sclub_info.prototype.init = function () {

    this.logo_dom_obj = create_block_element('main_logo', this.dom_obj);

    if (stb.user && stb.user.portal_logo_url) {
        this.logo_dom_obj.style.background = 'url(' + stb.user.portal_logo_url + ') no-repeat';
    }

    this.header_path = create_block_element('mb_header_first', this.dom_obj);
    this.path_container = document.createElement('span');
    this.header_path.innerHTML = word['sclub_title'] + ' / ';
    this.header_path.appendChild(this.path_container);

    this.path_container.innerHTML = word['sclub_info'];

    var ears_left_container = create_block_element('ears_left_container');

    this.left_ear = create_block_element('ears_left');

    var left_arrow = create_block_element('ears_arrow_left');
    var text_element = create_block_element();
    text_element.innerHTML = word['ears_back'];

    this.left_ear.appendChild(left_arrow);
    this.left_ear.appendChild(text_element);
    this.left_ear.appendChild(left_arrow.cloneNode(true));

    ears_left_container.appendChild(this.left_ear);

    this.dom_obj.appendChild(ears_left_container);


    this.main_container = create_block_element('mb_main_filminfo', this.dom_obj);

    this.film_title = create_block_element('mb_filminfo_name', this.main_container);

    var info_container = create_block_element('mb_filminfo_fullinfo', this.main_container);

    this.full_info = create_block_element('mb_hidden_overflow', info_container);

    this.scrollbar = new scrollbar(info_container, this.full_info, { "height": 350 });

    info_container.insertBefore(this.scrollbar.dom_obj, this.full_info);

    //create_block_element('mb_filminfo_trans', this.main_container);
    create_block_element('mb_filminfo_trans', info_container);

    this.film_cover = create_block_element('mb_filminfo_prev', this.main_container);

    this.hide();
};

sclub_info.prototype.fill = function (item) {
    _debug('sclub_info.fill');

    this.film_title.innerHTML = (item.name == item.o_name) ? item.name : item.name + ' / ' + item.o_name;

    var full_info = '<span>' + word['vclub_year'] + ':</span> ' + item.year.substring(0, 4) + ' &nbsp; ';
    full_info += '<span>' + word['vclub_genre'] + ':</span> ' + item.genres_str + '<br>';
    full_info += '<div class="hr_filminfo"></div>' +
        '<span>' + word['vclub_director'] + ':</span> ' + item.director + '<br>' +
        '<span>' + word['vclub_cast'] + ':</span> ' + item.actors +
        '<div class="hr_filminfo"></div>' + item.description + '<br><br>';

    this.full_info.innerHTML = full_info;

    if (item.screenshot_uri) {
        this.film_cover.innerHTML = '<img src="' + item.screenshot_uri + '">';
    } else {
        this.film_cover.innerHTML = '';
    }

    this.scrollbar.render();
};

sclub_info.prototype.shift = function (dir) {
    _debug('sclub_info.shift', dir);

    var top = this.full_info.scrollTop;

    _debug('top before: ', top);

    if (dir > 0) {
        top = top + 40;
    } else {
        top = top - 40;
    }

    this.full_info.scrollTop = top;

    _debug('top after: ', top);
    _debug('this.full_info.scrollTop: ', this.full_info.scrollTop);

    this.scrollbar.refresh();
};

sclub_info.prototype.shift_page = function (dir) {
    _debug('sclub_info.shift_page', dir);

    if (dir > 0) {
        this.full_info.scrollTop = this.full_info.scrollTop + 200;
    } else {
        this.full_info.scrollTop = this.full_info.scrollTop - 200;
    }

    this.scrollbar.refresh();
};

sclub_info.prototype.bind = function () {

    (function () {
        if (this.parent.series_switch.on) {
            this.parent.series_switch.shift.call(this.parent.series_switch, -1);
        } else {
            this.hide();
        }
    }).bind(key.LEFT, this);

    (function () {
        if (this.parent.password_input.on) {
            this.parent.password_input.hide();
        } else if (this.parent.series_switch.on) {
            this.parent.series_switch.hide();
        } else {
            this.hide();
        }
    }).bind(key.EXIT, this);

    (function () {

        if (single_module.length) {
            return;
        }

        this.hide();
        this.parent.hide();
        main_menu.show();
    }).bind(key.MENU, this);

    this.shift.bind(key.UP, this, -1);
    this.shift.bind(key.DOWN, this, 1);

    this.shift_page.bind(key.PAGE_PREV, this, -1);
    this.shift_page.bind(key.PAGE_NEXT, this, 1);

    this.action.bind(key.OK, this, true);
};

sclub_info.prototype.action = function (play_url) {
    _debug('sclub_info.action');

    if (this.parent.password_input.on) {
        this.parent.password_input.check.call(this.parent.password_input);
    } else if (this.parent.rent_password_input.on) {
        this.parent.rent_password_input.check.call(this.parent.rent_password_input);
    } else if (this.parent.series_switch.on) {
        this.parent.series_switch.set.call(this.parent.series_switch);
    } else {
        this.parent.check_for_pass.call(this.parent, play_url);
    }
};

loader.next();
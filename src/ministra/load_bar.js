/**
 * Load bar constructor.
 * @constructor
 */

function load_bar() {

    this.on = true;
    this.dom_obj = {};
    this.bar_dom_obj = {};
    this.log_on = false;
    this.log_dom_obj = {};
    this.callback = function () {
    };

    this.cur_pos = 0;

    this.px_in_percent = 248 / 100;

    this.init();
}

load_bar.prototype.init = function () {

    var _style = document.createElement('link');
    _style.type = "text/css";
    _style.rel = "stylesheet";
    _style.href = 'template/default/load_bar' + resolution_prefix + ".css";
    document.getElementsByTagName("head")[0].appendChild(_style);

    this.dom_obj = create_block_element('loader');

    var loader_bar_bg = create_block_element('loader_bar_bg', this.dom_obj);

    this.bar_dom_obj = create_block_element('loader_bar', loader_bar_bg);

    this.log_dom_obj = create_block_element('loader_log', this.dom_obj);
    this.log_dom_obj.hide();
};

load_bar.prototype.show = function () {
    _debug('load_bar.show');

    this.dom_obj.show();
    this.on = true;
};

load_bar.prototype.t_hide = function () {
    _debug('load_bar.t_hide');

    var self = this;

    window.clearTimeout(this.hide_timer);

    this.hide_timer = window.setTimeout(
        function () {
            main_menu.build();
            self.hide();
        },

        3000
    );
};

load_bar.prototype.hide = function () {
    _debug('load_bar.hide');

    this.dom_obj.hide();
    this.on = false;

    this.callback && this.callback();
    this.callback = null;
};

load_bar.prototype.stop = function () {
    _debug('load_bar.stop');

    this.dom_obj.hide();
    this.on = false;
};

load_bar.prototype.show_log = function () {
    _debug('load_bar.show_log');

    this.log_dom_obj.show();
    this.log_on = true;
};

load_bar.prototype.hide_log = function () {
    _debug('load_bar.hide_log');

    this.log_dom_obj.hide();
    this.log_on = false;
};

load_bar.prototype.switch_log = function () {
    _debug('load_bar.switch_log');

    if (this.log_on) {
        this.hide_log();
    } else {
        this.show_log();
    }
};

load_bar.prototype.add_log = function (txt) {
    _debug('load_bar.add_log');

    this.log_dom_obj.innerHTML = this.log_dom_obj.innerHTML + txt + '<br>';

    if (this.log_dom_obj.clientHeight < this.log_dom_obj.scrollHeight) {
        this.log_dom_obj.scrollTop = this.log_dom_obj.scrollHeight - this.log_dom_obj.clientHeight;
    }
};

load_bar.prototype.set_pos = function (percent, txt) {
    _debug('load_bar.set_pos', percent);

    this.add_log(txt);

    var width = Math.ceil(percent * this.px_in_percent);

    if (width > 248) {
        width = 248;
    }

    this.bar_dom_obj.style.width = width + 'px';

    if (percent >= 100) {
        this.t_hide();
    }
};

load_bar.prototype.add_pos = function (percent, txt) {
    _debug('load_bar.add_pos', percent);

    this.cur_pos = this.cur_pos + percent;

    this.set_pos(this.cur_pos, txt);
};

load_bar.prototype.set_callback = function (fn) {

    this.callback = fn;
};

load_bar.prototype.bind = function () {

    this.switch_log.bind(key.FRAME, this);
};

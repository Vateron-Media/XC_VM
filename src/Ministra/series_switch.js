/**
 * Series switch box.
 * @constructor
 */

function series_switch(options) {
    this.on = false;
    this.dom_obj = {};
    this.series_box = {};
    this.series = [];
    this.cur_idx = 0;
    this.callback = function () {
    };
    this.parent = {};
    this.box_input_format = '{0} / {1}';
    this.continuously_box_enable = true;

    if (options && options.hasOwnProperty('parent')) {
        this.parent = options.parent;
    }

    this.init();
}

series_switch.prototype.init = function () {
    _debug('series_switch.init');

    this.dom_obj = create_block_element('series_switch');
    this.series_box = create_block_element('series_switch_input', this.dom_obj);

    this.continuously_box = create_block_element('continuously', this.dom_obj);
    this.continuously_box.innerHTML = word['series_by_one_play'];

    this.hide();
};

series_switch.prototype.show = function (series, cur_series) {
    _debug('series_switch.show', series, cur_series);

    cur_series = cur_series || "1";

    this.series = series || [];

    if (this.series.indexOf(cur_series) < 0) {
        cur_series = this.series[0];
    }

    this.cur_idx = this.series.indexOf(cur_series);
    _debug('cur_series', cur_series);
    _debug('this.cur_idx', this.cur_idx);

    this.update_series_box();

    if (!this.continuously_box_enable) {
        this.continuously_box.hide();
    } else {
        this.continuously_box.show();
    }

    this.dom_obj.show();
    this.on = true;

    if (this.parent && this.parent.on) {
        this.parent.on = false;
    }
};

series_switch.prototype.hide = function () {
    _debug('series_switch.hide');

    /*this.series   = [];
    this.cur_idx  = 0;
    this.callback = function(){};*/

    this.dom_obj.hide();
    this.on = false;

    if (this.parent) {
        this.parent.on = true;
    }
};

series_switch.prototype.reset = function () {
    _debug('series_switch.reset');

    this.series = [];
    this.cur_idx = 0;
    this.callback = function () {
    };
};

series_switch.prototype.set = function () {
    _debug('series_switch.set');

    this.hide();

    this.callback(this.series[this.cur_idx]);

    this.reset();
};

series_switch.prototype.shift = function (dir) {
    _debug('series_switch.shift', dir);

    if (dir > 0) {
        if (this.cur_idx < this.series.length - 1) {
            this.cur_idx++;
        } else {
            this.cur_idx = 0;
        }
    } else {
        if (this.cur_idx > 0) {
            this.cur_idx--;
        } else {
            this.cur_idx = this.series.length - 1;
        }
    }

    this.update_series_box();
};

series_switch.prototype.vshift = function (dir) {
    _debug('series_switch.vshift', dir);

    if (!this.continuously_box_enable) {
        return;
    }

    if (stb && stb.player && stb.player.hasOwnProperty('play_continuously')) {

        if (stb.player.play_continuously === true) {
            stb.player.play_continuously = false;
            this.continuously_box.innerHTML = word['series_by_one_play'];
        } else {
            stb.player.play_continuously = true;
            this.continuously_box.innerHTML = word['series_continuously_play'];
        }
    }
};

series_switch.prototype.update_series_box = function () {
    _debug('series_switch.update_series_box');

    var out = this.box_input_format.format(this.series[this.cur_idx], this.series.length);

    if (this.decorate_fun) {
        out = this.decorate_fun(out);
    }

    this.series_box.innerHTML = out;
};

series_switch.prototype.bind = function () {
    _debug('series_switch.bind');

    this.shift.bind(key.RIGHT, this, 1);
    this.shift.bind(key.LEFT, this, -1);

    this.vshift.bind(key.DOWN, this, 1);
    this.vshift.bind(key.UP, this, -1);

    this.set.bind(key.OK, this);

    this.hide.bind(key.EXIT, this);
};

loader.next();
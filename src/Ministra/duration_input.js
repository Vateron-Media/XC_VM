/**
 * Duration input box.
 * @constructor
 */

function DurationInputBox(options) {

    this.on = false;
    this.dom_obj = {};
    this.duration_box = {};
    this.callback = function () {
    };
    this.parent = {};
    this.max_val = 90;
    this.step = 10;

    options = options || {};

    for (var key in options) {
        if (options.hasOwnProperty(key)) {
            this[key] = options[key];
        }
    }

    this.duration = this.step;

    this.init();
}

DurationInputBox.prototype.init = function () {
    _debug('DurationInputBox.init');

    this.dom_obj = create_block_element('duration_box');
    this.duration_box = create_block_element('duration_box_input', this.dom_obj);
    var title = create_block_element('duration_box_title', this.dom_obj);
    title.innerHTML = get_word('record_duration');
    this.hide();

    this.bind.call(this);
};

DurationInputBox.prototype.show = function () {
    _debug('DurationInputBox.show');

    this.render_duration_box();

    this.dom_obj.show();
    this.on = true;

    if (this.parent && this.parent.on) {
        this.parent.on = false;
    }
};

DurationInputBox.prototype.hide = function () {
    _debug('DurationInputBox.hide');

    this.dom_obj.hide();
    this.on = false;

    if (this.parent) {
        this.parent.on = true;
    }
};

DurationInputBox.prototype.set = function () {
    _debug('DurationInputBox.set');

    this.callback(this.duration);
    this.hide();
};

DurationInputBox.prototype.shift = function (dir) {
    _debug('DurationInputBox.shift');

    if (dir > 0) {

        if ((this.duration + this.step) < this.max_val) {
            this.duration += this.step;
        } else {
            this.duration = this.max_val;
        }
    } else {

        if ((this.duration - this.step) > this.step) {
            this.duration -= this.step;
        } else {
            this.duration = this.step;
        }
    }

    this.render_duration_box();
};

DurationInputBox.prototype.render_duration_box = function () {
    _debug('DurationInputBox.render_duration_box');

    _debug('this.duration', this.duration);

    this.duration_box.innerHTML = this._convert_min_to_human_time(this.duration);
};

DurationInputBox.prototype._convert_min_to_human_time = function (minutes) {
    _debug('DurationInputBox._convert_min_to_human_time', minutes);

    var h = Math.floor(minutes / 60);

    var m = Math.floor(minutes - h * 60);

    var time = '';

    //if(h){
    time += h + ':';
    //}

    if (m < 10) {
        m = '0' + m;
    }

    time += m;

    return time;
};

DurationInputBox.prototype.bind = function () {

    this.shift.bind(key.RIGHT, this, 1).bind(key.UP, this, 1);
    this.shift.bind(key.LEFT, this, -1).bind(key.DOWN, this, -1);

    this.set.bind(key.OK, this);
    this.hide.bind(key.EXIT, this).bind(key.REC, this);
};

loader.next();
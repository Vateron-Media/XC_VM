/**
 * Base input constructor.
 * @constructor
 */
function BaseInput() {

    this.label = '';
    this.name = '';
    this.default_val = '';
    this.parent = parent;
    //this.type = 'text';

    /*if (options){
        for (prop in options){
            if (options.hasOwnProperty(prop)){
                this[prop] = options[prop]
            }
        }
    }*/
}

/**
 * Text input constructor.
 * @constructor
 */
function TextInput(parent, options) {

    this.type = 'text';

    this.parent = parent;

    this.options = options;

    if (options) {
        for (var prop in options) {
            if (options.hasOwnProperty(prop)) {
                this[prop] = options[prop]
            }
        }
    }

    this.input = {};

    this.build();
}

TextInput.prototype = new BaseInput();

TextInput.prototype.build = function () {
    _debug('TextInput.build');

    var container = create_block_element('text_input_container', this.parent.container);

    var label = create_block_element('text_input_label', container);
    label.innerHTML = this.label;

    this.input = document.createElement('input');
    this.input.type = this.type;
    this.input.value = this.default_val;

    container.appendChild(this.input);
};

TextInput.prototype.add = function (symbol) {
    _debug('TextInput.add', symbol);

    //this.input.value = this.input.value + '' + symbol;
};

TextInput.prototype.del = function () {
    _debug('TextInput.del');

    //this.input.value = this.input.value.substr(0 ,this.input.value.length-1)
};

TextInput.prototype.reset = function () {
    _debug('TextInput.reset');

    this.input.value = '';
};

TextInput.prototype.set_active = function () {
    _debug('TextInput.set_active');

    this.input.setClass('active_input');
    this.input.focus();
};

TextInput.prototype.set_passive = function () {
    _debug('TextInput.set_passive');

    this.input.setClass('passive_input');
    this.input.blur();
};

TextInput.prototype.get_value = function () {
    _debug('TextInput.get_value');

    return this.input.value;
};

/* END Text input */

/**
 * Option input constructor.
 * @constructor
 */
function OptionInput(parent, options) {

    this.parent = parent;

    this.options = options;

    if (options) {
        for (var prop in options) {
            if (options.hasOwnProperty(prop)) {
                this[prop] = options[prop]
            }
        }
    }

    var self = this;

    this.input = {
        "parent": self,
        "value": '',
        "setClass": function (class_name) {
            if (class_name == 'active_input') {
                this.parent.set_active();
            } else {
                this.parent.set_passive();
            }
        }
    };

    this.map = this.map || [];

    //this.map.options = this.map.options || [];

    this.onchange = function () {
    };
    this.onselect = function () {
    };

    this.default_checked_idx = -1;
    this.cur_idx = 0;
    this.active_suggest_idx = -1;

    this.build();
}

OptionInput.prototype = new BaseInput();

OptionInput.prototype.build = function () {
    _debug('OptionInput.build');

    var container = create_block_element('option_container', this.parent.container);

    var label = create_block_element('option_label', container);
    label.innerHTML = this.label;

    this.larr = create_block_element('larr', container);
    this.larr.innerHTML = '&lsaquo;';

    this.option = create_block_element('option_value', container);

    this.rarr = create_block_element('rarr', container);
    this.rarr.innerHTML = '&rsaquo;';

    var idx = this.map.getIdxByVal('selected', 1);

    if (idx !== null) {
        this.default_checked_idx = this.cur_idx = idx;
    }

    this._set_option(this.cur_idx);
};

OptionInput.prototype.set_active = function () {
    _debug('OptionInput.set_passive', this.cur_idx);

    this.option.setAttribute('active', 'active');
};

OptionInput.prototype.set_passive = function () {
    _debug('OptionInput.set_active', this.cur_idx);

    if (this.suggest_input_dom_obj && !this.suggest_input_dom_obj.isHidden()) {
        this.hide_suggest_input();
    }

    this.option.setAttribute('active', '');
};

OptionInput.prototype.set_default = function () {
    _debug('OptionInput.set_default');

    if (this.default_checked_idx < 0) {
        return;
    }

    this.cur_idx = this.default_checked_idx;

    this._set_option(this.cur_idx);
};

OptionInput.prototype.fill = function (data) {
    _debug('OptionInput.fill', data);

    this.map = data;

    var idx = this.map.getIdxByVal('selected', 1);

    if (idx !== null) {
        this.default_checked_idx = this.cur_idx = idx;
    } else {
        this.default_checked_idx = -1;
        this.cur_idx = 0;
    }

    this._set_option(this.cur_idx);
};

OptionInput.prototype._set_option = function (idx) {
    _debug('OptionInput._set_option', idx);

    this.cur_idx = idx;

    window.clearTimeout(this.onchange_timer);

    var self = this;

    this.onchange_timer = window.setTimeout(function () {
        self.onchange()
    }, 500);

    _debug('this.cur_idx', this.cur_idx);

    if (this.map[this.cur_idx] !== undefined) {
        this.option.innerHTML = this.map[this.cur_idx].label || this.map[this.cur_idx].value || '...';
    } else {
        this.option.innerHTML = '...';
    }

    if (this.cur_idx == this.map.length - 1 || this.map.length == 0) {
        this.rarr.style.visibility = 'hidden';
    } else {
        this.rarr.style.visibility = 'visible';
    }

    if (this.cur_idx == 0) {
        this.larr.style.visibility = 'hidden';
    } else {
        this.larr.style.visibility = 'visible';
    }
};

OptionInput.prototype.get_value = function () {
    _debug('OptionInput.get_value');

    return this.map[this.cur_idx].value;
};

OptionInput.prototype.get_selected = function () {
    _debug('OptionInput.get_value');

    return this.map[this.cur_idx];
};

OptionInput.prototype.set_selected_by_value = function (value) {
    _debug('OptionInput.set_selected_by_value');

    //if (this.default_checked_idx >= 0){
    //    return;
    //}

    var idx = this.map.getIdxByVal('value', value);

    if (idx !== null) {
        return this._set_option(idx);
    }

    return false;
};

OptionInput.prototype.shift = function (dir) {
    _debug('OptionInput.shift', dir);

    if (this.suggest_input_dom_obj && !this.suggest_input_dom_obj.isHidden()) return;

    _debug('this.cur_idx', this.cur_idx);
    _debug('this.map.length', this.map.length);

    window.clearTimeout(this.onselect_timer);
    var self = this;
    this.onselect_timer = window.setTimeout(function () {
        self.onselect()
    }, 500);

    if (dir > 0) {

        if (this.cur_idx < this.map.length - 1) {
            this.cur_idx++;
        } else {
            return false;
        }
    } else {

        if (this.cur_idx > 0) {
            this.cur_idx--;
        } else {
            return false;
        }
    }

    this._set_option(this.cur_idx);

    return true;
};

OptionInput.prototype.shift_page = function (dir) {
    _debug('OptionInput.shift_page', dir);

    if (this.suggest_input_dom_obj && !this.suggest_input_dom_obj.isHidden()) return;

    window.clearTimeout(this.onselect_timer);
    var self = this;
    this.onselect_timer = window.setTimeout(function () {
        self.onselect()
    }, 500);

    if (dir > 0) {
        if (this.cur_idx < this.map.length - 11) {
            this.cur_idx = this.cur_idx + 10;
        } else {
            this.cur_idx = this.map.length - 1;
        }
    } else {
        if (this.cur_idx > 10) {
            this.cur_idx = this.cur_idx - 10;
        } else {
            this.cur_idx = 0;
        }
    }

    this._set_option(this.cur_idx);

    return true;
};

OptionInput.prototype._create_suggest_box = function () {
    _debug('OptionInput.prototype._create_suggest_box');

    this.suggest_input_dom_obj = document.createElement('input');

    this.suggest_input_dom_obj.style.width = this.option.offsetWidth + 'px';
    this.suggest_input_dom_obj.style.height = this.option.offsetHeight + 'px';
    this.suggest_input_dom_obj.style.top = this.option.offsetHeight - 2 + 'px';
    this.suggest_input_dom_obj.style.left = this.option.offsetLeft + 'px';
    this.suggest_input_dom_obj.addClass('suggest_input');

    this.suggest_input_dom_obj.onfocus = function () {
        _debug('onfocus');
        window.setTimeout(function () {
            stb.ShowVirtualKeyboard && stb.ShowVirtualKeyboard()
        }, 10);
    };

    var self = this;

    this.suggest_input_dom_obj.addEventListener('input', function () {
        _debug('this.value', this.value);
        if (this.value == self.prev_value) return;
        self._get_suggests(this.value);
        self.prev_value = this.value;
    }, false);

    /*this.suggest_input_dom_obj.onkeyup = function(){
        _debug('this.value', this.value);
        if (this.value == self.prev_value) return;
        self._get_suggests(this.value);
        self.prev_value = this.value;
    };*/

    //this.parent.container.appendChild(this.suggest_input_dom_obj);
    this.option.parentNode.insertBefore(this.suggest_input_dom_obj, this.option.nextSibling);
    this.hide_suggest_input();
};

OptionInput.prototype.hide_suggest_input = function () {
    _debug('OptionInput.prototype.hide_suggest_input');

    this.suggest_input_dom_obj.blur();
    this.suggest_input_dom_obj.hide();

    if (this.suggests_list && !this.suggests_list.isHidden()) {
        this._clear_suggests();
    }
};

OptionInput.prototype._get_suggests = function (search) {
    _debug('OptionInput.prototype._get_suggests', search);

    if (!this.suggests_target) {
        return;
    }

    this.suggests_target.search = search;

    stb.load(
        this.suggests_target,

        function (result) {
            this._show_suggests(result)
        },

        this
    )
};

OptionInput.prototype._clear_suggests = function () {
    _debug('OptionInput.prototype._clear_suggests');

    this.suggests_list && this.suggests_list.parentNode.removeChild(this.suggests_list);
    this.suggests_list = null;
};

OptionInput.prototype._show_suggests = function (suggests) {
    _debug('OptionInput.prototype._show_suggests', suggests);

    this._clear_suggests();

    this.suggests_list = document.createElement('ul');
    this.suggests_list.style.left = this.option.offsetLeft + 'px';
    //this.suggests_list.style.bottom = this.option.offsetHeight + 'px';
    this.suggests_list.style.width = this.option.offsetWidth + 'px';
    this.suggests_list.addClass('suggests_list');

    var items = [];

    for (var i = 0; i < suggests.length; i++) {
        var item = document.createElement('li');
        item.innerHTML = suggests[i].label;
        item.setAttribute('value', suggests[i].value);
        this.suggests_list.appendChild(item);
        items.push(item);
    }

    this.active_suggest_idx = this.suggests_list.childNodes.length;

    //this.parent.container.appendChild(this.suggests_list);
    this.option.parentNode.insertBefore(this.suggests_list, this.option.nextSibling);
};

OptionInput.prototype.shift_suggests = function (dir) {
    _debug('OptionInput.shift_suggests', dir);

    this._set_passive_suggest();

    if (dir > 0) {

        if (this.active_suggest_idx < this.suggests_list.childNodes.length - 1) {
            this.active_suggest_idx++;
        } else {
            this.active_suggest_idx = 0;
        }
    } else {

        if (this.active_suggest_idx > 0) {
            this.active_suggest_idx--;
        } else {
            this.active_suggest_idx = this.suggests_list.childNodes.length - 1;
        }
    }

    this._set_active_suggest();
};

OptionInput.prototype._set_active_suggest = function () {
    _debug('OptionInput._set_active_suggest', this.active_suggest_idx);

    if (this.active_suggest_idx < 0 || this.active_suggest_idx > this.suggests_list.childNodes.length - 1) return;

    this.suggests_list.childNodes[this.active_suggest_idx].setAttribute('active', 'active')
};

OptionInput.prototype._set_passive_suggest = function () {
    _debug('OptionInput._set_passive_suggest', this.active_suggest_idx);

    if (this.active_suggest_idx < 0 || this.active_suggest_idx > this.suggests_list.childNodes.length - 1) return;

    this.suggests_list.childNodes[this.active_suggest_idx].setAttribute('active', '')
};

OptionInput.prototype.switch_suggest_box = function () {
    _debug('OptionInput.show_suggest_box');

    if (!this.suggests_target) {
        return false;
    }

    if (this.suggests_list && !this.suggests_list.isHidden()) {

        if (this.suggests_list.childNodes.length) {

            var label = this.suggests_list.childNodes[this.active_suggest_idx].innerHTML;
            var value = this.suggests_list.childNodes[this.active_suggest_idx].getAttribute('value');

            var idx = this.map.getIdxByVal('value', value);

            this._set_option(idx);

            this._clear_suggests();
        } else {
            this._clear_suggests();
        }
    }

    if (!this.suggest_input_dom_obj) {
        this._create_suggest_box();
    }

    if (this.suggest_input_dom_obj.isHidden()) {
        this.suggest_input_dom_obj.value = '';
        this.suggest_input_dom_obj.show();
        this.suggest_input_dom_obj.focus();
    } else {
        this.hide_suggest_input();
    }
};

/**
 * Visual value picker constructor.
 * @constructor
 */

function VisualValuePickerInput(parent, options) {

    this.parent = parent;
    this.options = options;
    this.onchange = function () {
    };

    if (options) {
        for (var prop in options) {
            if (options.hasOwnProperty(prop)) {
                this[prop] = options[prop]
            }
        }
    }

    this.min_val = this.min_val || 0;
    this.max_val = this.max_val || 0;
    this.step = this.step || 1;
    this.default_val = this.default_val || this.min_val;

    this.value = this.default_val;

    this.build();
}

VisualValuePickerInput.prototype = new BaseInput();

VisualValuePickerInput.prototype.build = function () {
    _debug('VisualValuePickerInput.build');

    var container = create_block_element('visual_value_picker_container', this.parent.container);

    var label = create_block_element('label', container);
    label.innerHTML = this.label;

    this.larr = create_block_element('larr', container);
    this.larr.innerHTML = '&lsaquo;';

    this.bar_container = create_block_element('bar_container', container);

    this.bar = create_block_element('bar', this.bar_container);
    this.runner = create_block_element('runner', this.bar_container);

    this.rarr = create_block_element('rarr', container);
    this.rarr.innerHTML = '&rsaquo;';

    _debug('this.bar.offsetWidth', this.bar.offsetWidth);
    _debug('this.runner.offsetWidth', this.runner.offsetWidth);

    this.max_runner_offset = this.bar.offsetWidth - this.runner.offsetWidth;

    _debug('this.max_runner_offset', this.max_runner_offset);

    if (this.hint_title) {
        this.hint_container = create_block_element('hint', container);
        create_inline_element(null, this.hint_container).innerHTML = this.hint_title + ': ';
        this.hint_text = create_inline_element('hint_text', this.hint_container);
        this.hint_text.innerHTML = this.default_val;
    }

    this.set_default();
};

VisualValuePickerInput.prototype.set_active = function () {
    _debug('VisualValuePickerInput.set_active');

    this.bar_container.setAttribute('active', 'active');
};

VisualValuePickerInput.prototype.set_passive = function () {
    _debug('VisualValuePickerInput.set_passive');

    this.bar_container.setAttribute('active', '');
};

VisualValuePickerInput.prototype.set_default = function () {
    _debug('VisualValuePickerInput.set_passive');

    this._set_val(this.default_val);
};

VisualValuePickerInput.prototype.saved = function () {
    _debug('VisualValuePickerInput.saved');

    this.default_val = this.get_value();
};

VisualValuePickerInput.prototype.shift = function (dir) {
    _debug('VisualValuePickerInput.shift', dir);

    _debug('this.value', this.value);
    _debug('this.step', this.step);

    if (dir > 0) {
        if ((this.value + this.step) < this.max_val) {
            this.value += this.step;
        } else {
            this.value = this.max_val;
        }
    } else {
        if ((this.value - this.step) > this.min_val) {
            this.value -= this.step;
        } else {
            this.value = this.min_val;
        }
    }

    this._set_val(this.value);
};

VisualValuePickerInput.prototype._set_val = function (val) {
    _debug('VisualValuePickerInput.set_val', val);

    this.value = val;

    var offset_left = ((this.value - this.min_val) * this.max_runner_offset) / this.max_val;

    _debug('offset_left', offset_left);

    if (offset_left > (this.bar.offsetWidth - this.runner.offsetWidth)) {
        offset_left = this.bar.offsetWidth - this.runner.offsetWidth;
    }

    _debug('offset_left', offset_left);

    this.runner.moveX(offset_left);

    if (this.value == this.max_val) {
        this.rarr.style.visibility = 'hidden';
    } else {
        this.rarr.style.visibility = 'visible';
    }

    if (this.value == this.min_val) {
        this.larr.style.visibility = 'hidden';
    } else {
        this.larr.style.visibility = 'visible';
    }

    this.onchange();
};

VisualValuePickerInput.prototype.get_value = function () {
    _debug('VisualValuePickerInput.get_value');

    return this.value
};

loader.next();
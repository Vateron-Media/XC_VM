/**
 * Binding observer.fire to keydown event.
 */
document.addEventListener("keydown", function (event) {
    keydown_observer.fire(event)
}, false);

/**
 * KeyDown event observer.
 */
var keydown_observer = new function () {

    this.listeners = {};

    this.emulate_key = function (key_code) {

        this.fire({ "keyCode": key_code });
    };

    this.fire = function (e) {

        var code = e.keyCode || e.which;

        if (stb && stb.key_lock === true && code != key.FRAME) {
            _debug('key locked');
            return;
        }

        if (e.shiftKey) {
            code += 1000;
        }

        if (e.altKey) {
            code += 2000;
        }

        e.key = code;

        _debug('code:', code);

        if ([0, 1000, 2000, 3000].indexOf(code) >= 0) {
            return;
        }

        var item;
        var priority_item;
        var normal_item;

        if (!this.triggerCustomEventListener('keypress', e)) {
            return;
        }

        if (this.listeners.hasOwnProperty(code)) {
            for (var i = 0; i < this.listeners[code].length; i++) {

                item = this.listeners[code][i];

                if (item.c.hasOwnProperty('con_menu')) {
                    if (item.c.con_menu.on) {
                        continue;
                    }
                }

                if (item.c.on && item.priority) {
                    priority_item = item;
                    break;
                } else if (item.c.on || item.c === window) {
                    if (!normal_item) {
                        normal_item = item;
                    }
                }
            }

            if (priority_item) {
                priority_item.f.apply(priority_item.c, priority_item.a);
            } else if (normal_item) {
                normal_item.f.apply(normal_item.c, normal_item.a);
            }

        }

        if ([9, 1009].indexOf(code) != -1) {
            e.preventDefault && e.preventDefault();
        }

        _debug('keydown handler exit');
    }
};

/**
 * Binding function to key.
 * @param {number} key
 * @param {Object} context The object to be used as the value of 'this' within 'f'
 * @param {*} args
 * @return {Function}
 */
Function.prototype.bind = function (key, context, args) {
    context = context || window;
    keydown_observer.listeners[key] = keydown_observer.listeners[key] || [];
    args = Array.prototype.splice.apply(arguments, [2, arguments.length]);
    keydown_observer.listeners[key].unshift({ "f": this, "c": context, "a": args });
    return this;
};

Function.prototype.priority_bind = function (key, context, args) {
    context = context || window;
    keydown_observer.listeners[key] = keydown_observer.listeners[key] || [];
    args = Array.prototype.splice.apply(arguments, [2, arguments.length]);
    keydown_observer.listeners[key].unshift({ "f": this, "c": context, "a": args, "priority": true });
    return this;
};

window.oncontextmenu = function () {
    if (typeof (keydown_observer) != 'undefined') {
        keydown_observer.emulate_key(key.EXIT);
    }
    return false;
};
/**
 * Alias for document.getElementById.
 * @param {Number} id
 * @return {*} DOMElement
 */
var $ = function (id) {
    return document.getElementById(id);
};

/**
 * Debug function.
 */
function _debug() {
    if (debug) {

        var text = '';

        for (var i = 0; i < arguments.length; i++) {
            if (arguments[i] === null) {
                text += ' null';
            } else if (typeof (arguments[i]) === "undefined") {
                text += ' undefined';
            } else if (arguments[i] instanceof Date) {
                text += ' ' + arguments[i].toString();
            } else if (arguments[i].message && arguments[i].name) {
                text += ' ' + arguments[i].name + ' ' + arguments[i].message;
            } else {
                text += ' ' + arguments[i].toSource();
            }
        }

        try {
            gSTB.Debug(text);
        } catch (e) {
            console && console.log && console.log(text);
        }
    }
}

function _log(action, param, content_id) {

    param = param || '';
    content_id = content_id || 0;

    if (typeof (param) == 'object') {
        var add_data = param;
    } else {
        param = encodeURIComponent(param);
    }


    var data = {
        "type": "stb",
        "action": "log",
        "real_action": action,
        "param": (typeof param == 'string' || param instanceof String) ? encodeURIComponent(param) : param,
        "content_id": content_id,
        "tmp_type": stb.get_current_place()
    };

    if (_log.hasOwnProperty('last_data')
        && _log.last_data.type == data.type
        && _log.last_data.action == data.action
        && _log.last_data.real_action == data.real_action
        && _log.last_data.param == data.param
        && _log.last_data.tmp_type == data.tmp_type
    ) {
        return;
    }

    _log.last_data = data;

    for (var prop in add_data) {
        if (add_data.hasOwnProperty(prop)) {
            data[prop] = add_data[prop];
        }
    }

    stb.load(
        data,

        function (result) {
            _debug('on log', result);
        }
    )
}

/**
 * Module loader.
 */

/*function load_module(module){
    
    _debug('load_module', module);
    
    try{
        
        var head = document.getElementsByTagName("head")[0];
        
        var _script = document.createElement('script');
        _script.type = "text/javascript";
        _script.src  = module + ".js";
        
        var _style = document.createElement('link');
        _style.type = "text/css";
        _style.rel = "stylesheet";
        _style.href = module + ".css";
        
        head.appendChild(_script);
        _debug('append', _script.src);
        head.appendChild(_style);
        _debug('append', _style.href);
    }catch(e){
        _debug(e);
    }
}*/

/**
 * DOM element creating functions
 */

function create_dom_element(type, class_name, parent) {

    var dom_element = document.createElement(type);

    if (class_name) {
        dom_element.addClass(class_name);
    }

    parent = parent || document.body;

    if (parent) {
        parent.appendChild(dom_element);
    }

    return dom_element;
}

function create_inline_element(class_name, parent) {

    return create_dom_element('span', class_name, parent);
}

function create_block_element(class_name, parent) {

    return create_dom_element('div', class_name, parent);
}

/**
 * Custom prototype metods.
 */

HTMLElement.prototype.show = function () {
    try {
        switch (this.tagName.toLowerCase()) {
            case "span":
            case "strong":
            case "b":
            case "em":
            case "i":
                this.style.display = 'inline';
                break;
            default:
                this.style.display = 'block';
        }
    } catch (e) {
        _debug(e);
    }
    return this;
};

HTMLElement.prototype.hide = function () {
    try {
        this.style.display = 'none';
    } catch (e) {
        _debug(e);
    }
    return this;
};

HTMLElement.prototype.isHidden = function () {
    try {
        return this.style.display == 'none';
    } catch (e) {
        _debug(e);
    }
};

HTMLElement.prototype.moveX = function (to_x) {
    try {
        this.style.left = parseInt(to_x) + 'px';
    } catch (e) {
        _debug(e);
    }
};

HTMLElement.prototype.moveY = function (to_y) {
    try {
        this.style.top = parseInt(to_y) + 'px';
    } catch (e) {
        _debug(e);
    }
};

HTMLElement.prototype.offsetX = function (offset_x) {
    try {
        this.style.left = parseInt(this.offsetLeft) + offset_x + 'px';
    } catch (e) {
        _debug(e);
    }
};

HTMLElement.prototype.offsetY = function (offset_y) {
    try {
        this.style.top = parseInt(this.offsetTop) + offset_y + 'px';
    } catch (e) {
        _debug(e);
    }
};

HTMLElement.prototype.setClass = function (class_name) {
    try {
        this.className = class_name;
    } catch (e) {
        _debug(e);
    }

    return this;
};

HTMLElement.prototype.delClass = function () {
    try {
        this.className = '';
    } catch (e) {
        _debug(e);
    }

    return this;
};

HTMLElement.prototype.haveClass = function (class_name) {
    var classes = this.className.replace(/\s+/g, ' ').split(' ');

    return classes.indexOf(class_name) != -1;
};

HTMLElement.prototype.addClass = function (class_name) {
    try {
        if (!this.className) {
            this.setClass(class_name);
        } else {
            var classes = this.className.replace(/\s+/g, ' ').split(' ');

            if (classes.indexOf(class_name) == -1) {
                classes.push(class_name);
            }

            this.setClass(classes.join(' '));
        }
    } catch (e) {
        _debug(e);
    }

    return this;
};

HTMLElement.prototype.removeClass = function (class_name) {
    try {
        if (this.className.indexOf(class_name) >= 0) {
            this.className = this.className.replace(eval('/(' + class_name + ')/g'), '').replace(/((\s)+)/g, ' ');
        }
    } catch (e) {
        _debug(e);
    }

    return this;
};

HTMLElement.prototype.replaceClass = function (from, to) {
    try {
        if (this.className.indexOf(from) >= 0) {
            this.className = this.className.replace(eval('/(' + from + ')/g'), to);
        }
    } catch (e) {
        _debug(e);
    }

    return this;
};

String.prototype.clearnl = function () {
    return this.replace(/(\n(\r)?)/g, '');
};

if (typeof Object.prototype.toSource != 'function') {

    Object.prototype.toSource = function (level) {

        var con = this.constructor;

        var l = level || 0;
        l++;

        //console.log('level', level);
        //console.log(l);
        //_debug('l', l);

        if (l > 4) {
            //console.log('[Max level exceeded]');
            return '';
        }

        if (con == String) {
            //return '"' + this + '"';
            return this;
        } else if (con == Number) {
            return this;
        } else if (con == Array) {
            var res = '[';
            for (var i = 0, len = this.length; i < len; i++) {
                if (i == len - 1) {
                    if (this[i] === null) {
                        res += 'null]';
                    } else if (this[i] === undefined) {
                        res += 'undefined';
                    } else {
                        //console.log(this[i]);
                        res += this[i].toSource(l) + ']';
                    }
                } else {
                    if (this[i] === null) {
                        res += 'null, ';
                    } else if (this[i] === undefined) {
                        res += 'undefined, ';
                    } else {
                        res += this[i].toSource(l) + ', ';
                    }
                }
            }
            return res + ']';
        } else if (con == RegExp) {
            return this;
        } else if (con == Function) {
            return "[function]";
        } else if (con == Object) {
            var res = '{';
            var i = 0;
            for (var j in this) {
                if (this.hasOwnProperty(j)) {
                    if (j != 'toSource') {
                        if (i == 0) {
                            if (this[j] === null) {
                                res += j + ': null';
                            } else if (typeof (this[j]) == 'undefined') {
                                res += ', ' + j + ': undefined';
                            } else {
                                res += j + ':' + this[j].toSource(l);
                            }
                        } else {
                            if (this[j] === null) {
                                res += ', ' + j + ': null';
                            } else if (typeof (this[j]) == 'undefined') {
                                res += ', ' + j + ': undefined';
                            } else {
                                res += ', ' + j + ':' + this[j].toSource(l);
                            }
                        }
                        i++;
                    }
                }
            }
            res += '}';
            if (arguments.length) {
                return res;
            } else {
                return '(' + res + ')';
            }
        } else if (con == Boolean) {
            return this;
        }
    }

}

Object.prototype.clone = function () {

    var newObj = (this instanceof Array) ? [] : {};

    for (var i in this) {

        if (this.hasOwnProperty(i)) {
            //_debug(i);

            if (i == 'clone') continue;

            if (this[i] && typeof this[i] == "object") {
                newObj[i] = this[i].clone();
            } else {
                newObj[i] = this[i];
            }
        }
    }
    return newObj;
};

Object.prototype.addCustomEventListener = function (type, listener) {
    this._listeners = this._listeners || {};

    if (!this._listeners.hasOwnProperty(type)) {
        this._listeners[type] = [];
    }

    this._listeners[type].push(listener);
};

Object.prototype.triggerCustomEventListener = function (type, param) {

    var result = true;

    if (this._listeners && this._listeners.hasOwnProperty(type)) {
        for (var i = 0; i < this._listeners[type].length; i++) {
            try {
                result = !!this._listeners[type][i](param) && result;
            } catch (e) {
                _debug(e)
            }
        }
    }

    return result;
};

Array.prototype.getIdxById = function (id) {
    for (var i = 0; i < this.length; i++) {
        if (this[i].hasOwnProperty('id')) {
            if (this[i].id == id) {
                return i;
            }
        }
    }
    return null;
};

Array.prototype.getIdxByVal = function (what, eq) {
    for (var i = 0; i < this.length; i++) {
        if (this[i].hasOwnProperty(what)) {
            if (this[i][what] == eq) {
                return i;
            }
        }
    }
    return null;
};

Array.prototype.getIdxByNumber = function (number) {
    for (var i = 0; i < this.length; i++) {
        if (this[i].hasOwnProperty('number')) {
            if (this[i].number == number) {
                return i;
            }
        }
    }
    return null;
};

Array.prototype.inArray = function (value) {
    for (var i = 0; i < this.length; i++) {
        if (this[i] === value) {
            return true;
        }
    }
    return false;
};

Array.prototype.sortBy = function (field) {
    if (empty(field)) {
        return;
    }
    return this.sort(function (a, b) {
        var _a = (field == 'number' || field == 'snumber') ? parseInt(a[field], 10) : '' + a[field].toLowerCase() + '';
        var _b = (field == 'number' || field == 'snumber') ? parseInt(b[field], 10) : '' + b[field].toLowerCase() + '';
        return _a < _b ? -1 : (_a > _b ? 1 : 0);
    });
};

Math.__proto__.isEven = function (x) {
    return !(x % 2);
};

Math.__proto__.isOdd = function (x) {
    return !Math.isEven(x);
};

function empty(val) {

    var type = typeof (val);

    if (type == 'string' && val == '') {
        return true;
    } else if (type == 'undefined') {
        return true;
    } else {
        if (!val) {
            return true;
        } else if (type == 'object') {
            for (var p in val) {
                if (val.hasOwnProperty(p)) {
                    return false;
                }
            }

            return true;
        }
    }

    return false;
}

function get_params() {
    var get = new String(window.location);
    var x = get.indexOf('?');
    if (x != -1) {
        var l = get.length;
        get = get.substr(x + 1, l - x);
        l = get.split('&');
        x = 0;
        for (var i in l) {
            if (l.hasOwnProperty(i)) {
                get = l[i].split('=');
                _GET[get[0]] = get[1];
                x++;
            }
        }
    }
}

function get_word(alias) {
    return word[alias] || alias;
}

String.prototype.format = function () {
    var args = arguments;
    return this.replace(/{(\d+)}/g, function (match, number) {
        return typeof args[number] != 'undefined' ? args[number] : '{' + number + '}';
    });
};

String.prototype.toTranslit = function () {

    var map = {
        'а': 'a',
        'б': 'b',
        'в': 'v',
        'г': 'g',
        'д': 'd',
        'е': 'e',
        'ж': 'g',
        'з': 'z',
        'и': 'i',
        'й': 'y',
        'к': 'k',
        'л': 'l',
        'м': 'm',
        'н': 'n',
        'о': 'o',
        'п': 'p',
        'р': 'r',
        'с': 's',
        'т': 't',
        'у': 'u',
        'ф': 'f',
        'ы': 'i',
        'э': 'e',
        'А': 'A',
        'Б': 'B',
        'В': 'V',
        'Г': 'G',
        'Д': 'D',
        'Е': 'E',
        'Ж': 'G',
        'З': 'Z',
        'И': 'I',
        'Й': 'Y',
        'К': 'K',
        'Л': 'L',
        'М': 'M',
        'Н': 'N',
        'О': 'O',
        'П': 'P',
        'Р': 'R',
        'С': 'S',
        'Т': 'T',
        'У': 'U',
        'Ф': 'F',
        'Ы': 'I',
        'Э': 'E',
        'ё': "yo", 'х': "h", 'ц': "ts", 'ч': "ch", 'ш': "sh",
        'щ': "shch", 'ъ': '', 'ь': '', 'ю': "yu", 'я': "ya",
        'Ё': "Yo", 'Х': "H", 'Ц': "Ts", 'Ч': "Ch", 'Ш': "Sh",
        'Щ': "Shch", 'Ъ': '', 'Ь': '', 'Ю': "Yu", 'Я': "Ya",
        ' ': "_", '!': "", '?': "", ',': "", '.': "", '"': "",
        '\'': "", '\\': "", '/': "", ';': "", ':': "", '«': "", '»': "", '`': "", '-': "-", '—': "-"
    };

    var arr = this.split('').map(function (letter) {
        if (map.hasOwnProperty(letter)) {
            letter = map[letter];
        }

        return letter;
    });

    return arr.join('').replace(/[^a-z0-9_-]/ig, '');
};

/**
 * @example
 * var obj ={
 *     'tag':'div',
 *     'attrs':{
 *         'id':'id',
 *         'class': 'new_class',
 *         'style':'"width:50px'
 *     },
 *     'child':[
 *         {
 *             'tag':'div',
 *             'attrs':{
 *                 'id':'id',
 *                 'class': 'new_class',
 *                 'style':'"width:50px'
 *             },
 *             'child':[
 *
 *             ]
 *         },
 *         {
 *             'tag':'div',
 *
 *             'attrs':{
 *                 'id':'id',
 *                 'class': 'new_class',
 *                 'style':'"width:50px'
 *             },
 *             'child':[
 *
 *             ]
 *         }
 *    ]
 * };
 * createHTMLTree(obj);
 *
 * @param obj
 */
function createHTMLTree(obj) {
    var el = document.createElement(obj.tag);
    for (var key in obj.attrs) {
        if (obj.attrs.hasOwnProperty(key)) {
            el.setAttribute(key, obj.attrs[key]);
        }
    }
    if (typeof obj.child != 'undefined') {
        for (var i = 0; i < obj.child.length; i++) {
            el.appendChild(createHTMLTree(obj.child[i]));
        }
    }
    return el;
}

function HTMLDefinitionList(class_name, parent) {

    this._class = class_name;
    this._parent = parent;
    this._rows = [];
    this._separator = '';

    this._init();
}

HTMLDefinitionList.prototype._init = function () {

    this.dom_obj = document.createElement('dl');
    this.dom_obj.addClass('definition_list');
    this.dom_obj.addClass(this._class);
    this._parent.appendChild(this.dom_obj);
};

HTMLDefinitionList.prototype.addRow = function (title, value) {

    var dt = document.createElement('dt');
    dt.innerHTML = title + this._separator;

    var dd = document.createElement('dd');
    dd.innerHTML = value;

    this._rows.push({ "title": title, "value": value, "title_dom_obj": dt, "value_dom_obj": dd });

    this.dom_obj.appendChild(dt);
    this.dom_obj.appendChild(dd);
};

HTMLDefinitionList.prototype.setSeparator = function (separator) {

    this._separator = separator;
};

HTMLDefinitionList.prototype.getRowByTitle = function (title) {

    var idx = this._rows.getIdxByVal('title', title);

    if (idx === null) {
        return undefined;
    }

    return this._rows[idx];
};

HTMLDefinitionList.prototype.updateValueByTitle = function (title, value) {

    var row = this.getRowByTitle(title);

    if (!row) {
        return false;
    }

    row.value = value;
    row.value_dom_obj.innerHTML = value;
};

function md5cycle(x, k) {
    var a = x[0], b = x[1], c = x[2], d = x[3];

    a = ff(a, b, c, d, k[0], 7, -680876936);
    d = ff(d, a, b, c, k[1], 12, -389564586);
    c = ff(c, d, a, b, k[2], 17, 606105819);
    b = ff(b, c, d, a, k[3], 22, -1044525330);
    a = ff(a, b, c, d, k[4], 7, -176418897);
    d = ff(d, a, b, c, k[5], 12, 1200080426);
    c = ff(c, d, a, b, k[6], 17, -1473231341);
    b = ff(b, c, d, a, k[7], 22, -45705983);
    a = ff(a, b, c, d, k[8], 7, 1770035416);
    d = ff(d, a, b, c, k[9], 12, -1958414417);
    c = ff(c, d, a, b, k[10], 17, -42063);
    b = ff(b, c, d, a, k[11], 22, -1990404162);
    a = ff(a, b, c, d, k[12], 7, 1804603682);
    d = ff(d, a, b, c, k[13], 12, -40341101);
    c = ff(c, d, a, b, k[14], 17, -1502002290);
    b = ff(b, c, d, a, k[15], 22, 1236535329);

    a = gg(a, b, c, d, k[1], 5, -165796510);
    d = gg(d, a, b, c, k[6], 9, -1069501632);
    c = gg(c, d, a, b, k[11], 14, 643717713);
    b = gg(b, c, d, a, k[0], 20, -373897302);
    a = gg(a, b, c, d, k[5], 5, -701558691);
    d = gg(d, a, b, c, k[10], 9, 38016083);
    c = gg(c, d, a, b, k[15], 14, -660478335);
    b = gg(b, c, d, a, k[4], 20, -405537848);
    a = gg(a, b, c, d, k[9], 5, 568446438);
    d = gg(d, a, b, c, k[14], 9, -1019803690);
    c = gg(c, d, a, b, k[3], 14, -187363961);
    b = gg(b, c, d, a, k[8], 20, 1163531501);
    a = gg(a, b, c, d, k[13], 5, -1444681467);
    d = gg(d, a, b, c, k[2], 9, -51403784);
    c = gg(c, d, a, b, k[7], 14, 1735328473);
    b = gg(b, c, d, a, k[12], 20, -1926607734);

    a = hh(a, b, c, d, k[5], 4, -378558);
    d = hh(d, a, b, c, k[8], 11, -2022574463);
    c = hh(c, d, a, b, k[11], 16, 1839030562);
    b = hh(b, c, d, a, k[14], 23, -35309556);
    a = hh(a, b, c, d, k[1], 4, -1530992060);
    d = hh(d, a, b, c, k[4], 11, 1272893353);
    c = hh(c, d, a, b, k[7], 16, -155497632);
    b = hh(b, c, d, a, k[10], 23, -1094730640);
    a = hh(a, b, c, d, k[13], 4, 681279174);
    d = hh(d, a, b, c, k[0], 11, -358537222);
    c = hh(c, d, a, b, k[3], 16, -722521979);
    b = hh(b, c, d, a, k[6], 23, 76029189);
    a = hh(a, b, c, d, k[9], 4, -640364487);
    d = hh(d, a, b, c, k[12], 11, -421815835);
    c = hh(c, d, a, b, k[15], 16, 530742520);
    b = hh(b, c, d, a, k[2], 23, -995338651);

    a = ii(a, b, c, d, k[0], 6, -198630844);
    d = ii(d, a, b, c, k[7], 10, 1126891415);
    c = ii(c, d, a, b, k[14], 15, -1416354905);
    b = ii(b, c, d, a, k[5], 21, -57434055);
    a = ii(a, b, c, d, k[12], 6, 1700485571);
    d = ii(d, a, b, c, k[3], 10, -1894986606);
    c = ii(c, d, a, b, k[10], 15, -1051523);
    b = ii(b, c, d, a, k[1], 21, -2054922799);
    a = ii(a, b, c, d, k[8], 6, 1873313359);
    d = ii(d, a, b, c, k[15], 10, -30611744);
    c = ii(c, d, a, b, k[6], 15, -1560198380);
    b = ii(b, c, d, a, k[13], 21, 1309151649);
    a = ii(a, b, c, d, k[4], 6, -145523070);
    d = ii(d, a, b, c, k[11], 10, -1120210379);
    c = ii(c, d, a, b, k[2], 15, 718787259);
    b = ii(b, c, d, a, k[9], 21, -343485551);

    x[0] = add32(a, x[0]);
    x[1] = add32(b, x[1]);
    x[2] = add32(c, x[2]);
    x[3] = add32(d, x[3]);

}

function cmn(q, a, b, x, s, t) {
    a = add32(add32(a, q), add32(x, t));
    return add32((a << s) | (a >>> (32 - s)), b);
}

function ff(a, b, c, d, x, s, t) {
    return cmn((b & c) | ((~b) & d), a, b, x, s, t);
}

function gg(a, b, c, d, x, s, t) {
    return cmn((b & d) | (c & (~d)), a, b, x, s, t);
}

function hh(a, b, c, d, x, s, t) {
    return cmn(b ^ c ^ d, a, b, x, s, t);
}

function ii(a, b, c, d, x, s, t) {
    return cmn(c ^ (b | (~d)), a, b, x, s, t);
}

function md51(s) {
    txt = '';
    var n = s.length,
        state = [1732584193, -271733879, -1732584194, 271733878], i;
    for (i = 64; i <= s.length; i += 64) {
        md5cycle(state, md5blk(s.substring(i - 64, i)));
    }
    s = s.substring(i - 64);
    var tail = [0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0];
    for (i = 0; i < s.length; i++)
        tail[i >> 2] |= s.charCodeAt(i) << ((i % 4) << 3);
    tail[i >> 2] |= 0x80 << ((i % 4) << 3);
    if (i > 55) {
        md5cycle(state, tail);
        for (i = 0; i < 16; i++) tail[i] = 0;
    }
    tail[14] = n * 8;
    md5cycle(state, tail);
    return state;
}

/* there needs to be support for Unicode here,
 * unless we pretend that we can redefine the MD-5
 * algorithm for multi-byte characters (perhaps
 * by adding every four 16-bit characters and
 * shortening the sum to 32 bits). Otherwise
 * I suggest performing MD-5 as if every character
 * was two bytes--e.g., 0040 0025 = @%--but then
 * how will an ordinary MD-5 sum be matched?
 * There is no way to standardize text to something
 * like UTF-8 before transformation; speed cost is
 * utterly prohibitive. The JavaScript standard
 * itself needs to look at this: it should start
 * providing access to strings as preformed UTF-8
 * 8-bit unsigned value arrays.
 */
function md5blk(s) { /* I figured global was faster.   */
    var md5blks = [], i; /* Andy King said do it this way. */
    for (i = 0; i < 64; i += 4) {
        md5blks[i >> 2] = s.charCodeAt(i)
            + (s.charCodeAt(i + 1) << 8)
            + (s.charCodeAt(i + 2) << 16)
            + (s.charCodeAt(i + 3) << 24);
    }
    return md5blks;
}

var hex_chr = '0123456789abcdef'.split('');

function rhex(n) {
    var s = '', j = 0;
    for (; j < 4; j++)
        s += hex_chr[(n >> (j * 8 + 4)) & 0x0F]
            + hex_chr[(n >> (j * 8)) & 0x0F];
    return s;
}

function hex(x) {
    for (var i = 0; i < x.length; i++)
        x[i] = rhex(x[i]);
    return x.join('');
}

function md5(s) {
    return hex(md51(s));
}

/* this function is much faster,
 so if possible we use it. Some IEs
 are the only ones I know of that
 need the idiotic second function,
 generated by an if clause.  */

function add32(a, b) {
    return (a + b) & 0xFFFFFFFF;
}

if (md5('hello') != '5d41402abc4b2a76b9719d911017c592') {
    function add32(x, y) {
        var lsw = (x & 0xFFFF) + (y & 0xFFFF),
            msw = (x >> 16) + (y >> 16) + (lsw >> 16);
        return (msw << 16) | (lsw & 0xFFFF);
    }
}
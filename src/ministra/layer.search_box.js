/**
 * Search box constructor.
 * @constructor
 */

function search_box(parent, options) {

    this.on = false;
    this.parent = parent;
    this.dependency = [];

    this.languages = ['ru', 'en'];

    this.layers = [];

    this.offset_x = 190;

    this.dom_obj = {};

    this.cur_layer_idx = 0;

    this.cur_row = 2;
    this.cur_cell = 3;

    this.search_input = {};

    if (options) {

        if (options.languages) {
            this.languages = options.languages;
        }

        if (options.offset_x) {
            this.offset_x = options.offset_x;
        }

        if (options.color) {
            this.color = options.color;
        }
    }

    this.lang_map = [
        {
            "lang": "ru",
            "map": [
                {
                    "letter": "1",
                    "cells": 1,
                    "cmd": function () {
                        this.add("1")
                    }
                },
                {
                    "letter": "2",
                    "cells": 1,
                    "cmd": function () {
                        this.add("2")
                    }
                },
                {
                    "letter": "3",
                    "cells": 1,
                    "cmd": function () {
                        this.add("3")
                    }
                },
                {
                    "letter": "4",
                    "cells": 1,
                    "cmd": function () {
                        this.add("4")
                    }
                },
                {
                    "letter": "5",
                    "cells": 1,
                    "cmd": function () {
                        this.add("5")
                    }
                },
                {
                    "letter": "6",
                    "cells": 1,
                    "cmd": function () {
                        this.add("6")
                    }
                },
                {
                    "letter": "7",
                    "cells": 1,
                    "cmd": function () {
                        this.add("7")
                    }
                },
                {
                    "letter": "8",
                    "cells": 1,
                    "cmd": function () {
                        this.add("8")
                    }
                },
                {
                    "letter": "9",
                    "cells": 1,
                    "cmd": function () {
                        this.add("9")
                    }
                },
                {
                    "letter": "0",
                    "cells": 1,
                    "cmd": function () {
                        this.add("0")
                    }
                },
                {
                    "letter": "&larr;",
                    "cells": 2,
                    "cmd": function () {
                        this.del()
                    }
                },
                {
                    "letter": "Й",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Й")
                    }
                },
                {
                    "letter": "Ц",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ц")
                    }
                },
                {
                    "letter": "У",
                    "cells": 1,
                    "cmd": function () {
                        this.add("У")
                    }
                },
                {
                    "letter": "К",
                    "cells": 1,
                    "cmd": function () {
                        this.add("К")
                    }
                },
                {
                    "letter": "Е",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Е")
                    }
                },
                {
                    "letter": "Н",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Н")
                    }
                },
                {
                    "letter": "Г",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Г")
                    }
                },
                {
                    "letter": "Ш",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ш")
                    }
                },
                {
                    "letter": "Щ",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Щ")
                    }
                },
                {
                    "letter": "З",
                    "cells": 1,
                    "cmd": function () {
                        this.add("З")
                    }
                },
                {
                    "letter": "Х",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Х")
                    }
                },
                {
                    "letter": "Ъ",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ъ")
                    }
                },
                {
                    "letter": "Ф",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ф")
                    }
                },
                {
                    "letter": "Ы",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ы")
                    }
                },
                {
                    "letter": "В",
                    "cells": 1,
                    "cmd": function () {
                        this.add("В")
                    }
                },
                {
                    "letter": "А",
                    "cells": 1,
                    "cmd": function () {
                        this.add("А")
                    }
                },
                {
                    "letter": "П",
                    "cells": 1,
                    "cmd": function () {
                        this.add("П")
                    }
                },
                {
                    "letter": "Р",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Р")
                    }
                },
                {
                    "letter": "О",
                    "cells": 1,
                    "cmd": function () {
                        this.add("О")
                    }
                },
                {
                    "letter": "Л",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Л")
                    }
                },
                {
                    "letter": "Д",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Д")
                    }
                },
                {
                    "letter": "Ж",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ж")
                    }
                },
                {
                    "letter": "English",
                    "cells": 2,
                    "cmd": function () {
                        this.change_lang()
                    }
                },
                {
                    "letter": "Я",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Я")
                    }
                },
                {
                    "letter": "Ч",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ч")
                    }
                },
                {
                    "letter": "С",
                    "cells": 1,
                    "cmd": function () {
                        this.add("С")
                    }
                },
                {
                    "letter": "М",
                    "cells": 1,
                    "cmd": function () {
                        this.add("М")
                    }
                },
                {
                    "letter": "И",
                    "cells": 1,
                    "cmd": function () {
                        this.add("И")
                    }
                },
                {
                    "letter": "Т",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Т")
                    }
                },
                {
                    "letter": "Ь",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ь")
                    }
                },
                {
                    "letter": "Б",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Б")
                    }
                },
                {
                    "letter": "Ю",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ю")
                    }
                },
                {
                    "letter": "Э",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Э")
                    }
                },
                {
                    "letter": "Пробел",
                    "cells": 2,
                    "cmd": function () {
                        this.add(" ")
                    }
                }
            ]
        },

        {
            "lang": "uk",
            "map": [
                {
                    "letter": "1",
                    "cells": 1,
                    "cmd": function () {
                        this.add("1")
                    }
                },
                {
                    "letter": "2",
                    "cells": 1,
                    "cmd": function () {
                        this.add("2")
                    }
                },
                {
                    "letter": "3",
                    "cells": 1,
                    "cmd": function () {
                        this.add("3")
                    }
                },
                {
                    "letter": "4",
                    "cells": 1,
                    "cmd": function () {
                        this.add("4")
                    }
                },
                {
                    "letter": "5",
                    "cells": 1,
                    "cmd": function () {
                        this.add("5")
                    }
                },
                {
                    "letter": "6",
                    "cells": 1,
                    "cmd": function () {
                        this.add("6")
                    }
                },
                {
                    "letter": "7",
                    "cells": 1,
                    "cmd": function () {
                        this.add("7")
                    }
                },
                {
                    "letter": "8",
                    "cells": 1,
                    "cmd": function () {
                        this.add("8")
                    }
                },
                {
                    "letter": "9",
                    "cells": 1,
                    "cmd": function () {
                        this.add("9")
                    }
                },
                {
                    "letter": "0",
                    "cells": 1,
                    "cmd": function () {
                        this.add("0")
                    }
                },
                {
                    "letter": "&larr;",
                    "cells": 2,
                    "cmd": function () {
                        this.del()
                    }
                },
                {
                    "letter": "Й",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Й")
                    }
                },
                {
                    "letter": "Ц",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ц")
                    }
                },
                {
                    "letter": "У",
                    "cells": 1,
                    "cmd": function () {
                        this.add("У")
                    }
                },
                {
                    "letter": "К",
                    "cells": 1,
                    "cmd": function () {
                        this.add("К")
                    }
                },
                {
                    "letter": "Е",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Е")
                    }
                },
                {
                    "letter": "Н",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Н")
                    }
                },
                {
                    "letter": "Г",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Г")
                    }
                },
                {
                    "letter": "Ш",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ш")
                    }
                },
                {
                    "letter": "Щ",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Щ")
                    }
                },
                {
                    "letter": "З",
                    "cells": 1,
                    "cmd": function () {
                        this.add("З")
                    }
                },
                {
                    "letter": "Х",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Х")
                    }
                },
                {
                    "letter": "Ї",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ї")
                    }
                },
                {
                    "letter": "Ф",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ф")
                    }
                },
                {
                    "letter": "І",
                    "cells": 1,
                    "cmd": function () {
                        this.add("І")
                    }
                },
                {
                    "letter": "В",
                    "cells": 1,
                    "cmd": function () {
                        this.add("В")
                    }
                },
                {
                    "letter": "А",
                    "cells": 1,
                    "cmd": function () {
                        this.add("А")
                    }
                },
                {
                    "letter": "П",
                    "cells": 1,
                    "cmd": function () {
                        this.add("П")
                    }
                },
                {
                    "letter": "Р",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Р")
                    }
                },
                {
                    "letter": "О",
                    "cells": 1,
                    "cmd": function () {
                        this.add("О")
                    }
                },
                {
                    "letter": "Л",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Л")
                    }
                },
                {
                    "letter": "Д",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Д")
                    }
                },
                {
                    "letter": "Ж",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ж")
                    }
                },
                {
                    "letter": "English",
                    "cells": 2,
                    "cmd": function () {
                        this.change_lang()
                    }
                },
                {
                    "letter": "Я",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Я")
                    }
                },
                {
                    "letter": "Ч",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ч")
                    }
                },
                {
                    "letter": "С",
                    "cells": 1,
                    "cmd": function () {
                        this.add("С")
                    }
                },
                {
                    "letter": "М",
                    "cells": 1,
                    "cmd": function () {
                        this.add("М")
                    }
                },
                {
                    "letter": "И",
                    "cells": 1,
                    "cmd": function () {
                        this.add("И")
                    }
                },
                {
                    "letter": "Т",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Т")
                    }
                },
                {
                    "letter": "Ь",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ь")
                    }
                },
                {
                    "letter": "Б",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Б")
                    }
                },
                {
                    "letter": "Ю",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Ю")
                    }
                },
                {
                    "letter": "Є",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Є")
                    }
                },
                {
                    "letter": "Пробел",
                    "cells": 2,
                    "cmd": function () {
                        this.add(" ")
                    }
                }
            ]
        },

        {
            "lang": "en",
            "map": [
                {
                    "letter": "1",
                    "cells": 1,
                    "cmd": function () {
                        this.add("1")
                    }
                },
                {
                    "letter": "2",
                    "cells": 1,
                    "cmd": function () {
                        this.add("2")
                    }
                },
                {
                    "letter": "3",
                    "cells": 1,
                    "cmd": function () {
                        this.add("3")
                    }
                },
                {
                    "letter": "4",
                    "cells": 1,
                    "cmd": function () {
                        this.add("4")
                    }
                },
                {
                    "letter": "5",
                    "cells": 1,
                    "cmd": function () {
                        this.add("5")
                    }
                },
                {
                    "letter": "6",
                    "cells": 1,
                    "cmd": function () {
                        this.add("6")
                    }
                },
                {
                    "letter": "7",
                    "cells": 1,
                    "cmd": function () {
                        this.add("7")
                    }
                },
                {
                    "letter": "8",
                    "cells": 1,
                    "cmd": function () {
                        this.add("8")
                    }
                },
                {
                    "letter": "9",
                    "cells": 1,
                    "cmd": function () {
                        this.add("9")
                    }
                },
                {
                    "letter": "0",
                    "cells": 1,
                    "cmd": function () {
                        this.add("0")
                    }
                },
                {
                    "letter": "&larr;",
                    "cells": 2,
                    "cmd": function () {
                        this.del()
                    }
                },
                {
                    "letter": "Q",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Q")
                    }
                },
                {
                    "letter": "W",
                    "cells": 1,
                    "cmd": function () {
                        this.add("W")
                    }
                },
                {
                    "letter": "E",
                    "cells": 1,
                    "cmd": function () {
                        this.add("E")
                    }
                },
                {
                    "letter": "R",
                    "cells": 1,
                    "cmd": function () {
                        this.add("R")
                    }
                },
                {
                    "letter": "T",
                    "cells": 1,
                    "cmd": function () {
                        this.add("T")
                    }
                },
                {
                    "letter": "Y",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Y")
                    }
                },
                {
                    "letter": "U",
                    "cells": 1,
                    "cmd": function () {
                        this.add("U")
                    }
                },
                {
                    "letter": "I",
                    "cells": 1,
                    "cmd": function () {
                        this.add("I")
                    }
                },
                {
                    "letter": "O",
                    "cells": 1,
                    "cmd": function () {
                        this.add("O")
                    }
                },
                {
                    "letter": "P",
                    "cells": 1,
                    "cmd": function () {
                        this.add("P")
                    }
                },
                {
                    "letter": "_",
                    "cells": 1,
                    "cmd": function () {
                        this.add("_")
                    }
                },
                {
                    "letter": "-",
                    "cells": 1,
                    "cmd": function () {
                        this.add("-")
                    }
                },
                {
                    "letter": "A",
                    "cells": 1,
                    "cmd": function () {
                        this.add("A")
                    }
                },
                {
                    "letter": "S",
                    "cells": 1,
                    "cmd": function () {
                        this.add("S")
                    }
                },
                {
                    "letter": "D",
                    "cells": 1,
                    "cmd": function () {
                        this.add("D")
                    }
                },
                {
                    "letter": "F",
                    "cells": 1,
                    "cmd": function () {
                        this.add("F")
                    }
                },
                {
                    "letter": "G",
                    "cells": 1,
                    "cmd": function () {
                        this.add("G")
                    }
                },
                {
                    "letter": "H",
                    "cells": 1,
                    "cmd": function () {
                        this.add("H")
                    }
                },
                {
                    "letter": "J",
                    "cells": 1,
                    "cmd": function () {
                        this.add("J")
                    }
                },
                {
                    "letter": "K",
                    "cells": 1,
                    "cmd": function () {
                        this.add("K")
                    }
                },
                {
                    "letter": "L",
                    "cells": 1,
                    "cmd": function () {
                        this.add("L")
                    }
                },
                {
                    "letter": ":",
                    "cells": 1,
                    "cmd": function () {
                        this.add(":")
                    }
                },
                {
                    "letter": (function (self) {
                        if (self.languages.indexOf('ru') >= 0) {
                            return "Русский"
                        } else if (self.languages.indexOf('uk') >= 0) {
                            return "Укр"
                        }
                        return '';
                    })(this),
                    "cells": 2,
                    "cmd": (function (self) {
                        if (self.languages.indexOf('ru') >= 0 || self.languages.indexOf('uk') >= 0) {
                            return function () {
                                self.change_lang()
                            }
                        }
                    })(this)
                },
                {
                    "letter": "Z",
                    "cells": 1,
                    "cmd": function () {
                        this.add("Z")
                    }
                },
                {
                    "letter": "X",
                    "cells": 1,
                    "cmd": function () {
                        this.add("X")
                    }
                },
                {
                    "letter": "C",
                    "cells": 1,
                    "cmd": function () {
                        this.add("C")
                    }
                },
                {
                    "letter": "V",
                    "cells": 1,
                    "cmd": function () {
                        this.add("V")
                    }
                },
                {
                    "letter": "B",
                    "cells": 1,
                    "cmd": function () {
                        this.add("B")
                    }
                },
                {
                    "letter": "N",
                    "cells": 1,
                    "cmd": function () {
                        this.add("N")
                    }
                },
                {
                    "letter": "M",
                    "cells": 1,
                    "cmd": function () {
                        this.add("M")
                    }
                },
                {
                    "letter": ",",
                    "cells": 1,
                    "cmd": function () {
                        this.add(",")
                    }
                },
                {
                    "letter": ".",
                    "cells": 1,
                    "cmd": function () {
                        this.add(".")
                    }
                },
                {
                    "letter": ";",
                    "cells": 1,
                    "cmd": function () {
                        this.add(";")
                    }
                },
                {
                    "letter": "Space",
                    "cells": 2,
                    "cmd": function () {
                        this.add(" ")
                    }
                }
            ]
        }
    ];

}

search_box.prototype.show = function () {
    _debug('search_box.show');

    for (var i = 0; i < this.dependency.length; i++) {
        this.dependency[i].on && this.dependency[i].hide && this.dependency[i].hide();
    }

    //this.dom_obj.moveY(576 - this.dom_obj.clientHeight - 36);
    this.dom_obj.show();
    this.dom_obj.moveY(this.parent.dom_obj.offsetHeight - this.dom_obj.clientHeight - this.parent.color_buttons.buttons_bar.offsetHeight);
    if (this.color) {
        var button = this.parent.color_buttons.get(this.color).cell;

        var offset_left = button.offsetLeft + this.parent.color_buttons.table.offsetLeft + button.offsetWidth / 2 - this.dom_obj.offsetWidth / 2 + 7;

        if ((offset_left + this.dom_obj.offsetWidth) > this.parent.dom_obj.offsetWidth) {
            offset_left = this.parent.dom_obj.offsetWidth - this.dom_obj.offsetWidth;
        }

        this.dom_obj.moveX(offset_left);
    }

    this.on = true;
};

search_box.prototype.hide = function () {
    _debug('search_box.hide');

    //this.dom_obj.moveY(576);
    this.dom_obj.hide();
    this.dom_obj.moveY(this.parent.dom_obj.offsetHeight);
    this.on = false;
};

search_box.prototype.reset = function () {
    _debug('search_box.reset');

    this.parent.update_header_path([{ "alias": "search", "item": "*" }]);

    this.search_input.innerHTML = '';

    this.parent.load_params.search = '';

    this.set_passive_cell();

    this.cur_row = 2;
    this.cur_cell = 3;

    this.set_active_cell();
};

search_box.prototype.init = function () {
    _debug('search_box.init');

    var dom_obj;
    var row;
    var row_items = [];
    var cell;
    var cur_cell = 0;

    this.dom_obj = create_block_element('search_menu', this.parent.dom_obj);

    this.dom_obj.moveX(this.offset_x);

    this.search_input = create_block_element('search_input', this.dom_obj);

    for (var i = 0; i < this.languages.length; i++) {

        var items = [];

        dom_obj = create_block_element('lang_container', this.dom_obj);

        var cur_idx = this.lang_map.getIdxByVal("lang", this.languages[i]);

        if (cur_idx === null) {
            _debug('language not found');
            continue;
        }

        if (i != 0) {
            dom_obj.hide();
        }

        for (var j = 0; j < this.lang_map[cur_idx].map.length; j++) {

            if (j == 0) {
                row = document.createElement('ul');
            }

            cell = document.createElement('li');
            cell.innerHTML = this.lang_map[cur_idx].map[j].letter;

            if (this.lang_map[cur_idx].map[j].cells == 2) {
                cell.setAttribute("rel", "double_cell");
                //cur_cell = cur_cell + 2;
            } else {
                //cur_cell++;
            }

            row_items.push({ "dom_obj": cell, "cmd": this.lang_map[cur_idx].map[j].cmd });

            row.appendChild(cell);

            if (this.lang_map[cur_idx].map[j].cells == 2) {
                cur_cell = cur_cell + 2;
            } else {
                cur_cell++;
            }

            if (cur_cell == 12) {
                dom_obj.appendChild(row);

                items.push(row_items);

                row_items = [];

                row = document.createElement('ul');

                cur_cell = 0;
            }
        }

        this.layers.push({ "dom_obj": dom_obj, "items": items, "lang": this.languages[i] });
    }

    this.set_active_cell();

    this.hide();
};

search_box.prototype.add = function (symbol) {
    _debug('search_box.add', symbol);

    this.search_input.innerHTML = this.search_input.innerHTML + '' + symbol;
};

search_box.prototype.del = function () {
    _debug('search_box.del');

    this.search_input.innerHTML = this.search_input.innerHTML.substring(0, this.search_input.innerHTML.length - 1);
};

search_box.prototype.change_lang = function () {
    _debug('search_box.change_lang');

    this.layers[this.cur_layer_idx].dom_obj.hide();

    if (this.cur_layer_idx < this.layers.length - 1) {
        this.cur_layer_idx++;
    } else {
        this.cur_layer_idx = 0;
    }

    /*if (!this.layers[this.cur_layer_idx]){
        this.cur_layer_idx = 0;
    }*/

    this.layers[this.cur_layer_idx].dom_obj.show();

    this.set_active_cell();
};

search_box.prototype.hshift = function (dir) {
    _debug('search_box.hshift', dir);

    this.set_passive_cell();

    if (dir > 0) {
        if (this.cur_cell < this.layers[this.cur_layer_idx].items[this.cur_row].length - 1) {
            this.cur_cell++;
        } else {
            this.cur_cell = 0;
        }
    } else {
        if (this.cur_cell > 0) {
            this.cur_cell--;
        } else {
            this.cur_cell = this.layers[this.cur_layer_idx].items[this.cur_row].length - 1;
        }
    }

    this.set_active_cell();
};

search_box.prototype.vshift = function (dir) {
    _debug('search_box.vshift', dir);

    this.set_passive_cell();

    if (dir > 0) {
        if (this.cur_row < this.layers[this.cur_layer_idx].items.length - 1) {

            if (!this.layers[this.cur_layer_idx].items[this.cur_row + 1][this.cur_cell]) {
                this.cur_cell = this.layers[this.cur_layer_idx].items[this.cur_row + 1].length - 1;
            }

            this.cur_row++;
        } else {
            this.cur_row = 0;
        }
    } else {
        if (this.cur_row > 0) {

            if (!this.layers[this.cur_layer_idx].items[this.cur_row - 1][this.cur_cell]) {
                this.cur_cell = this.layers[this.cur_layer_idx].items[this.cur_row - 1].length - 1;
            }

            this.cur_row--;
        } else {
            this.cur_row = this.layers[this.cur_layer_idx].items.length - 1;
        }
    }

    this.set_active_cell();
};

search_box.prototype.action = function (type) {
    _debug('search_box.action', type);

    if (type == 'del') {
        this.del();
    } else if (!this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].cmd) {
        return;
    } else {
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].cmd && this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].cmd.call(this);
    }

    this.parent.load_params.search = this.search_input.innerHTML;

    _debug('this.search_input.innerHTML.length', this.search_input.innerHTML.length);

    try {
        if (this.parent.on && (this.search_input.innerHTML.length >= 3 || this.search_input.innerHTML.length == 0)) {

            this.parent.update_header_path([{ "alias": "search", "item": this.search_input.innerHTML }]);

            this.parent.reset();
            this.parent.load_data();
        }
    } catch (e) {
        _debug(e);
    }
};

search_box.prototype.set_active_cell = function () {
    _debug('search_box.set_active_cell');

    this.layers[this.cur_layer_idx] &&
        this.layers[this.cur_layer_idx].items &&
        this.layers[this.cur_layer_idx].items[this.cur_row] &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell] &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].dom_obj &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].dom_obj.setClass('search_active_cell');
};

search_box.prototype.set_passive_cell = function () {
    _debug('search_box.set_passive_cell');

    this.layers[this.cur_layer_idx] &&
        this.layers[this.cur_layer_idx].items &&
        this.layers[this.cur_layer_idx].items[this.cur_row] &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell] &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].dom_obj &&
        this.layers[this.cur_layer_idx].items[this.cur_row][this.cur_cell].dom_obj.delClass();
};

search_box.prototype.bind = function () {

    this.vshift.bind(key.UP, this, -1);
    this.vshift.bind(key.DOWN, this, 1);

    this.hshift.bind(key.LEFT, this, -1);
    this.hshift.bind(key.RIGHT, this, 1);

    this.action.bind(key.BACK, this, 'del').bind(key.NUM0, this, 'del');

    this.action.bind(key.OK, this);

    this.hide.bind(key.EXIT, this);
};

loader.next();
/**
 * Anecdote module.
 */

(function () {

    function anecdote_constructor() {

        this.layer_name = 'anecdote';

        this.dom_obj = this.create_block();
        document.body.appendChild(this.dom_obj);

        this.superclass = BaseLayer.prototype;

        this.page = 0;
        this.total_pages = 0;

        this.resp = [];

        this.bookmark_page = -1;

        this.quick_page_switch = { "on": false, "hide_to": 3000 };

        this.init = function () {

            this.superclass.init.call(this);

            var container = create_block_element('', this.dom_obj);

            var anecdote_body_container = create_block_element('anecdote_body_container', container);

            var info_bar = create_block_element('', anecdote_body_container);

            this.anecdote_date = create_inline_element('date', info_bar);
            this.anecdote_rate = create_inline_element('rate', info_bar);

            this.anecdote_body = create_block_element('anecdote_body', anecdote_body_container);

            this.anecdote_page_bar = create_block_element('anecdote_page_bar', container);

            this.anecdote_left_arrow = create_block_element('anecdote_left_arrow', container);

            this.anecdote_right_arrow = create_block_element('anecdote_right_arrow', container);

            this.scrollbar = new scrollbar(anecdote_body_container, this.anecdote_body, { "height": 330 });
        };

        this.show = function () {
            _debug('anecdote.show');

            this.superclass.show.apply(this);

            this.load();
            this.get_bookmark();
        };

        this.hide = function () {
            _debug('anecdote.hide');

            this.superclass.hide.apply(this);

            if (this.quick_page_switch.on) {
                this.cancel_quick_page_switch();
            }

            this.scrollbar && this.scrollbar.reset && this.scrollbar.reset();

            this.anecdote_body.scrollTop = 0;
        };

        this.load = function () {
            _debug('anecdote.load');

            this.set_loading();

            this.scrollbar && this.scrollbar.reset && this.scrollbar.reset();

            stb.load(
                {
                    "type": "anecdote",
                    "action": "get_by_page",
                    "p": this.page
                },

                function (result) {
                    this.fill(result);
                },

                this
            )
        };

        this.fill = function (resp) {
            _debug('anecdote.fill', resp);

            this.resp = resp;

            this.total_pages = this.resp.total_items;

            this.fill_page_bar();

            if (parseInt(this.resp.data.voted) > 0) {
                //this.color_buttons[this.color_buttons.getIdxByVal('color', 'green')].text_obj.setClass('disable_color_btn_text');
                this.color_buttons.get('green').disable();
            } else {
                //this.color_buttons[this.color_buttons.getIdxByVal('color', 'green')].text_obj.delClass();
                this.color_buttons.get('green').enable();
            }

            this.anecdote_date.innerHTML = this.resp.data['added'];

            /*var rating = parseInt(this.resp.data['rating']);
            
            if (rating > 0){
                rating = '+' + rating;
            }
            
            if (rating == 0){
                rating = '';
            }
            
            this.anecdote_rate.innerHTML = ' ' + rating;*/

            this.set_rating(this.resp.data['rating']);

            this.anecdote_body.innerHTML = this.resp.data['anec_body'];
        };

        this.set_rating = function (rating) {
            _debug('anecdote.set_rating', rating);

            var rating = parseInt(rating);

            if (rating > 0) {
                rating = '+' + rating;
            }

            if (rating == 0) {
                rating = '';
            }

            this.anecdote_rate.innerHTML = ' ' + rating;
        }

        this.fill_page_bar = function () {
            _debug('anecdote.fill_page_bar');

            this.anecdote_page_bar.innerHTML = word['anecdote_pagebar_title'] + ' <span class="text20_white bold">' + (this.page + 1) + '</span> ' + word['layer_from'] + ' <span class="text20_white bold">' + this.total_pages + '</span>';
        };

        this.set_loading = function () {
            _debug('anecdote.set_loading');

            this.anecdote_page_bar.innerHTML = word['layer_loading'];
        }

        this.shift_page = function (dir) {
            _debug('anecdote.shift_page', dir);

            if (dir > 0) {

                if (this.page < this.total_pages - 1) {
                    this.page++;
                } else {
                    this.page = 0;
                }

            } else {
                if (this.page > 0) {
                    this.page--;
                } else {
                    this.page = this.total_pages - 1;
                }
            }

            this.load();
        };

        this.bind = function () {

            this.shift_page.bind(key.LEFT, this, -1);
            this.shift_page.bind(key.RIGHT, this, 1);

            (function () {

                if (this.quick_page_switch.on) {
                    this.hide_quick_page_switch();
                }

            }).bind(key.OK, this);

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.MENU, this);

            (function () {
                if (this.quick_page_switch.on) {
                    this.cancel_quick_page_switch();
                } else {
                    this.hide();
                    main_menu.show();
                }
            }).bind(key.EXIT, this);

            this.show_quick_page_switch.bind(key.NUM1, this, 1);
            this.show_quick_page_switch.bind(key.NUM2, this, 2);
            this.show_quick_page_switch.bind(key.NUM3, this, 3);
            this.show_quick_page_switch.bind(key.NUM4, this, 4);
            this.show_quick_page_switch.bind(key.NUM5, this, 5);
            this.show_quick_page_switch.bind(key.NUM6, this, 6);
            this.show_quick_page_switch.bind(key.NUM7, this, 7);
            this.show_quick_page_switch.bind(key.NUM8, this, 8);
            this.show_quick_page_switch.bind(key.NUM9, this, 9);
            this.show_quick_page_switch.bind(key.NUM0, this, 0);

            (function () {
                if (this.quick_page_switch.on) {
                    this.del_quick_go_page();
                }
            }).bind(key.BACK, this);

            this.vshift.bind(key.UP, this, -1);
            this.vshift.bind(key.DOWN, this, 1);

            this.vshift_page.bind(key.PAGE_PREV, this, -1);
            this.vshift_page.bind(key.PAGE_NEXT, this, 1);
        };

        this.like = function () {
            _debug('anecdote.like');

            if (parseInt(this.resp.data['voted']) > 0) {
                return;
            }

            //this.color_buttons[this.color_buttons.getIdxByVal('color', 'green')].text_obj.setClass('disable_color_btn_text');
            this.color_buttons.get('green').disable();

            stb.load(
                {
                    "type": "anecdote",
                    "action": "set_vote",
                    "anec_id": this.resp.data.id
                },

                function (result) {
                    _debug('anecdote.like result', result);

                    this.resp.data['rating'] = parseInt(this.resp.data['rating']) + 1;

                    this.set_rating(this.resp.data['rating']);

                },

                this
            );
        };

        this.get_bookmark = function () {
            _debug('anecdote.get_bookmark');

            stb.load(
                {
                    "type": "anecdote",
                    "action": "get_bookmark"
                },

                function (result) {
                    _debug('anecdote.get_bookmark result', result);

                    this.bookmark_page = parseInt(result) - 1;

                    if (this.bookmark_page >= 0) {
                        //this.color_buttons[this.color_buttons.getIdxByVal('color', 'blue')].text_obj.delClass();
                        //this.color_buttons[this.color_buttons.getIdxByVal('color', 'blue')].text_obj.innerHTML = word['anecdote_to_bookmark'] + ' <sup>' + (this.bookmark_page + 1) + '</sup>';
                        this.color_buttons.get('blue').enable();
                        this.color_buttons.get('blue').setText(word['anecdote_to_bookmark'] + ' <sup>' + (this.bookmark_page + 1) + '</sup>');
                    } else {
                        //this.color_buttons[this.color_buttons.getIdxByVal('color', 'blue')].text_obj.setClass('disable_color_btn_text');
                        this.color_buttons.get('blue').disable();
                    }
                },

                this
            )
        };

        this.set_bookmark = function () {
            _debug('anecdote.set_bookmark');

            this.bookmark_page = this.page;

            stb.load(
                {
                    "type": "anecdote",
                    "action": "set_bookmark",
                    "anec_id": this.resp.data.id
                },

                function (result) {
                    _debug('anecdote.set_bookmark result', result);

                    //this.color_buttons[this.color_buttons.getIdxByVal('color', 'blue')].text_obj.delClass();
                    //this.color_buttons[this.color_buttons.getIdxByVal('color', 'blue')].text_obj.innerHTML = word['anecdote_to_bookmark'] + ' <sup>' + (this.bookmark_page + 1) + '</sup>';
                    this.color_buttons.get('blue').enable();
                    this.color_buttons.get('blue').setText(word['anecdote_to_bookmark'] + ' <sup>' + (this.bookmark_page + 1) + '</sup>');
                },

                this
            )
        };

        this.to_bookmark = function () {
            _debug('anecdote.to_bookmark');

            if (this.bookmark_page < 0) {
                return;
            }

            this.page = this.bookmark_page;

            this.load();
        };

        this.init_quick_page_switch = function () {
            _debug('anecdote.init_quick_page_switch');

            this.quick_page_switch.dom_obj = create_block_element('quick_page_switch');

            //this.quick_page_switch.dom_obj.moveY(300);

            this.quick_page_switch.input = create_block_element('quick_page_input', this.quick_page_switch.dom_obj);

            this.quick_page_switch.dom_obj.hide();
        };

        this.show_quick_page_switch = function (num) {
            _debug('anecdote.show_quick_page_switch', num);

            num = num || 0;

            if (!this.quick_page_switch.on) {
                this.quick_page_switch.dom_obj.show();
                this.quick_page_switch.on = true;
            }

            if (this.quick_page_switch.input.innerHTML.length <= 4) {
                if (this.quick_page_switch.input.innerHTML.length == 0 && num == 0) {

                } else {
                    this.quick_page_switch.input.innerHTML = this.quick_page_switch.input.innerHTML + '' + num;
                }
            }

            this.t_hide_quick_page_switch();
        };

        this.quick_go_to_page = function () {
            _debug('anecdote.quick_go_to_page');

            var page_num = parseInt(this.quick_page_switch.input.innerHTML);

            if (page_num < this.total_pages) {
                this.page = page_num - 1;
                this.load();
            }
        };

        this.del_quick_go_page = function () {
            _debug('anecdote.del_quick_go_page');

            if (!this.quick_page_switch.on) {
                return;
            }

            this.t_hide_quick_page_switch();

            this.quick_page_switch.input.innerHTML = this.quick_page_switch.input.innerHTML.substr(0, this.quick_page_switch.input.innerHTML.length - 1);
        };

        this.t_hide_quick_page_switch = function () {
            _debug('anecdote.t_hide_quick_page_switch');

            window.clearTimeout(this.quick_page_switch.hide_timer);

            var self = this;

            this.quick_page_switch.hide_timer = window.setTimeout(function () {

                self.hide_quick_page_switch();

            }, this.quick_page_switch.hide_to);
        };

        this.hide_quick_page_switch = function () {
            _debug('anecdote.hide_quick_page_switch');

            if (!this.quick_page_switch.on) {
                return;
            }

            this.quick_go_to_page();

            this.cancel_quick_page_switch();
        };

        this.cancel_quick_page_switch = function () {
            _debug('anecdote.cancel_quick_page_switch');

            window.clearTimeout(this.quick_page_switch.hide_timer);

            this.quick_page_switch.dom_obj.hide();
            this.quick_page_switch.on = false;

            this.quick_page_switch.input.innerHTML = '';
        };

        this.vshift = function (dir) {
            _debug('anecdote.shift', dir);

            var top = this.anecdote_body.scrollTop;

            _debug('top before: ', top);

            if (dir > 0) {
                top = top + 40;
            } else {
                top = top - 40;
            }

            this.anecdote_body.scrollTop = top;

            _debug('top after: ', top);
            _debug('this.anecdote_body.scrollTop: ', this.anecdote_body.scrollTop);

            this.scrollbar.refresh();
        };

        this.vshift_page = function (dir) {
            _debug('anecdote.vshift_page', dir);

            if (dir > 0) {
                this.anecdote_body.scrollTop = this.anecdote_body.scrollTop + 200;
            } else {
                this.anecdote_body.scrollTop = this.anecdote_body.scrollTop - 200;
            }

            this.scrollbar.refresh();
        };

    }

    anecdote_constructor.prototype = new BaseLayer();

    var anecdote = new anecdote_constructor();

    anecdote.init();
    anecdote.init_quick_page_switch();

    anecdote.init_color_buttons([
        { "label": word['anecdote_goto'], "cmd": anecdote.show_quick_page_switch },
        { "label": word['anecdote_like'], "cmd": anecdote.like },
        { "label": word['anecdote_bookmark'], "cmd": anecdote.set_bookmark },
        { "label": word['anecdote_to_bookmark'], "cmd": anecdote.to_bookmark }
    ]);

    anecdote.bind();

    anecdote.init_header_path(word['anecdote_title']);

    anecdote.hide();

    module.anecdote = anecdote;

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": word['anecdote_title'],
        "cmd": function () {
            main_menu.hide();
            module.anecdote.show();
        }
    })

})();

loader.next();
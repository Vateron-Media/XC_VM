/**
 * Mastermind game.
 *
 */

(function () {

    function mastermind_constructor() {

        this.layer_name = 'mastermind';

        this.row_blocks = ['number', 'guess', 'score'];

        this.superclass = ListLayer.prototype;

        this.fields_map = [];

        this.current_cell = 0;
        this.secret_number;

        this.step = 0;

        this.log = [];

        this.guess = [];

        this.finish = false;

        this.show = function (do_not_load) {
            _debug('mastermind.show');

            this.superclass.show.call(this, do_not_load);

            if (!do_not_load) {
                this.start_game();
            }
        };

        this.hide = function (do_not_reset, initial) {
            _debug('mastermind.hide');

            this.superclass.hide.call(this, do_not_reset);

            if (!initial && stb.msg.on) {
                stb.msg.hide();
            }
        };

        this.reset = function () {
            _debug('mastermind.reset');

            this.step = 0;

            this.reset_field();

            this.log = [];

            this.load_data();
        };

        this.reset_field = function () {
            _debug('mastermind.reset_field');

            this.set_passive_arrow();
            this.current_cell = 0;
            this.set_active_arrow();

            for (var i = 0; i < this.fields_map.length; i++) {
                this.fields_map[i].innerHTML = '';
            }

            this.guess = [];
        };

        this.init_game_field = function () {
            _debug('mastermind.init_game_field');

            this.game_field = create_block_element('mastermind_field', this.main_container);

            for (var i = 0; i < 4; i++) {
                this.fields_map.push(create_block_element('', this.game_field));
            }
        };

        this.shift_arrow = function (dir) {
            _debug('mastermind.shift_arrow');

            this.set_passive_arrow();

            if (dir > 0) {
                if (this.current_cell < 3) {
                    this.current_cell++;
                } else {
                    this.current_cell = 0;
                }
            } else {
                if (this.current_cell > 0) {
                    this.current_cell--;
                } else {
                    this.current_cell = 3;
                }
            }

            this.set_active_arrow();
        };

        this.set_passive_arrow = function () {
            this.fields_map[this.current_cell].delClass();
        };

        this.set_active_arrow = function () {
            this.fields_map[this.current_cell].setClass('active');
        };

        this.bind = function () {

            this.superclass.bind.call(this);

            this.shift_arrow.bind(key.LEFT, this, -1);
            this.shift_arrow.bind(key.RIGHT, this, 1);

            this.check_guess.bind(key.OK, this);

            this.fill_cell.bind(key.NUM0, this, 0);
            this.fill_cell.bind(key.NUM1, this, 1);
            this.fill_cell.bind(key.NUM2, this, 2);
            this.fill_cell.bind(key.NUM3, this, 3);
            this.fill_cell.bind(key.NUM4, this, 4);
            this.fill_cell.bind(key.NUM5, this, 5);
            this.fill_cell.bind(key.NUM6, this, 6);
            this.fill_cell.bind(key.NUM7, this, 7);
            this.fill_cell.bind(key.NUM8, this, 8);
            this.fill_cell.bind(key.NUM9, this, 9);

            this.clear_cell.bind(key.BACK, this);

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.EXIT, this);
        };

        this.get_secret_number = function () {
            _debug('mastermind.get_secret_number');

            var secret_number = [];

            while (secret_number.length < 4) {

                var idx = secret_number.length;
                var num = Math.floor(Math.random() * 10);

                if (idx == 0 && num == 0) {
                    continue
                }
                if (secret_number.indexOf(num) >= 0) {
                    continue
                }

                secret_number[idx] = num
            }

            _debug('secret_number', secret_number);

            this.secret_number = secret_number;
        };

        this.start_game = function () {
            _debug('mastermind.start_game');

            this.reset();

            this.get_secret_number();

            this.start_time = new Date().getTime() / 1000;
        };

        this.check_guess = function () {
            _debug('mastermind.check_guess');

            var cows = 0;
            var bulls = 0;

            if (this.fields_map[0].innerHTML == '' || this.fields_map[1].innerHTML == '' || this.fields_map[2].innerHTML == '' || this.fields_map[3].innerHTML == '') {
                return;
            }

            var guess = [this.fields_map[0].innerHTML, this.fields_map[1].innerHTML, this.fields_map[2].innerHTML, this.fields_map[3].innerHTML];

            this.step++;

            for (var i = 0; i < 4; i++) {
                if (guess[i] == this.secret_number[i]) {
                    bulls++;
                } else {
                    if (this.secret_number.indexOf(parseInt(guess[i])) >= 0) {
                        cows++;
                    }
                }
            }

            this.add_log({
                "bulls": bulls,
                "cows": cows,
                "guess": this.fields_map[0].innerHTML + '' + this.fields_map[1].innerHTML + '' + this.fields_map[2].innerHTML + '' + this.fields_map[3].innerHTML
            });

            _debug('cows', cows);

            if (bulls == 4) {
                this.show_win_alert();
            } else {
                this.reset_field();
            }
        };

        this.add_log = function (step) {
            _debug('mastermind.add_log');

            this.log.push({
                "number": this.step + '',
                "guess": step.guess,
                "score": step.bulls + word['mastermind_bull'] + ' ' + step.cows + word['mastermind_cow']
            });

            this.cur_page = Math.ceil(this.log.length / 14);

            this.load_data();
        };

        this.load_data = function () {
            _debug('mastermind.load_data');

            this.total_pages = Math.ceil(this.log.length / 14);
            this.set_total_items(this.log.length);

            var begin = (this.cur_page - 1) * 14;
            var end = this.cur_page * 14;

            this.data_items = this.log.slice(begin, end);

            this.cur_row = this.log.length - (this.total_pages - 1) * 14 - 1;

            this.fill_list(this.data_items);
        };

        this.fill_cell = function (num) {
            _debug('mastermind.fill_cell', num);

            if (this.guess.indexOf(num) < 0) {
                if (this.current_cell == 0 && num == 0) {
                } else {
                    this.fields_map[this.current_cell].innerHTML = num;
                    this.guess[this.current_cell] = num;
                    this.shift_arrow(1);
                }
            }
        };

        this.clear_cell = function () {
            _debug('mastermind.clear_cell');

            delete this.guess[this.current_cell];
            this.fields_map[this.current_cell].innerHTML = '';
            this.shift_arrow(-1);
        };

        this.show_win_alert = function () {
            _debug('mastermind.show_win_alert');

            var time = Math.ceil(new Date().getTime() / 1000 - this.start_time);

            var points = 1;

            if (this.step <= 7 && time < 600) {
                points = 3;
            } else if (this.step <= 10 && time < 600) {
                points = 2;
            }

            var self = this;

            stb.msg.set_callback(
                function () {
                    self.start_game();
                });

            stb.msg.push('Победа!!! Время: ' + time + 'c. Очки: ' + points + '. Чтобы начать заново нажмите ОК');

            this.save_win(this.step, time);
        };

        this.save_win = function (tries, total_time) {
            _debug('mastermind.save_win');

            stb.load(
                {
                    "type": "mastermind",
                    "action": "add_log",
                    "tries": tries,
                    "total_time": total_time
                },

                function (result) {
                    _debug('save_win callback', result);
                }
            )
        };
    }

    mastermind_constructor.prototype = new ListLayer();

    var mastermind = new mastermind_constructor();

    mastermind.bind();
    mastermind.init();

    mastermind.init_game_field();

    mastermind.set_short_container();

    mastermind.init_header_path(word['mastermind_title']);


    /* RATING */
    function rating_constructor() {

        this.layer_name = 'mastermind_rating';

        this.row_blocks = ['place', 'name', 'sum_points'];

        this.load_params = {
            "type": "mastermind",
            "action": "get_rating"
        };

        this.parent = {};

        this.superclass = ListLayer.prototype;

        this.show = function () {
            _debug('rating.show');

            this.cur_page = 0;

            this.parent.hide.call(this.parent, true);

            this.superclass.show.apply(this);
        };

        this.hide = function () {
            _debug('rating.hide');

            this.parent.show.call(this.parent, true);

            this.superclass.hide.apply(this);

            this.cur_page = 0;
        };

        this.bind = function () {

            this.superclass.bind.apply(this);

            (function () {
                this.hide();
            }).bind(key.EXIT, this).bind(key.LEFT, this).bind(key.GREEN, this);

            (function () {
                this.hide();
                this.parent.hide.apply(this.parent);
                main_menu.show();
            }).bind(key.MENU, this);
        };
    }

    rating_constructor.prototype = new ListLayer();
    var rating = new rating_constructor();

    rating.bind();
    rating.init_left_ear(word['ears_back']);

    rating.init();

    rating.parent = mastermind;

    rating.init_header_path(word['mastermind_rating']);

    rating.hide();

    mastermind.rating = rating;
    /* END RATING */


    /* RULES */
    function rules_constructor() {

        this.layer_name = 'mastermind_rules';

        this.parent = {};

        this.superclass = BaseLayer.prototype;

        this.dom_obj = this.create_block();
        document.body.appendChild(this.dom_obj);

        this.init = function () {

            this.superclass.init.call(this);

            this.container = create_block_element('mastermind_rules_txt', this.dom_obj);
            var text = word['mastermind_rules_text'];
            text += '<br><br><table width="100%" cellpadding="3" cellspacing="3"><tr><td width="100" style="color:#ffffff;">&larr; &rarr;</td><td style="color:#3f81cc;">' + word['mastermind_move_cursor'] + '</td></tr>';
            text += '<tr><td style="color:#ffffff;">0-9</td><td style="color:#3f81cc;">' + word['mastermind_cell_numbers'] + '</td></tr>';
            text += '<tr><td style="color:#ffffff;">OK</td><td style="color:#3f81cc;">' + word['mastermind_step_confirmation'] + '</td></tr>';
            text += '<tr><td style="color:#ffffff;">' + word['mastermind_page'] + ' +/-</td><td style="color:#3f81cc;">' + word['mastermind_history_moves'] + '</td></tr></table>';

            this.container.innerHTML = text;
        };

        this.show = function () {
            _debug('rating.show');

            this.parent.hide.call(this.parent, true);

            this.superclass.show.apply(this);
        };

        this.hide = function () {
            _debug('rating.hide');

            this.parent.show.call(this.parent, true);

            this.superclass.hide.apply(this);
        };

        this.bind = function () {

            (function () {
                this.hide();
            }).bind(key.EXIT, this).bind(key.LEFT, this).bind(key.RED, this);

            (function () {
                this.hide();
                this.parent.hide.apply(this.parent);
                main_menu.show();
            }).bind(key.MENU, this);
        };
    }

    rules_constructor.prototype = new BaseLayer();

    var rules = new rules_constructor();

    rules.bind();
    rules.init_left_ear(word['ears_back']);
    rules.init();
    rules.parent = mastermind;
    rules.init_header_path(word['mastermind_rules']);

    rules.hide();
    mastermind.hide(false, true);
    mastermind.rules = rules;
    /* END RULES */


    mastermind.init_color_buttons([
        {
            "label": word['mastermind_rules'], "cmd": function () {
                mastermind.rules.show.apply(mastermind.rules)
            }
        },
        {
            "label": word['mastermind_rating'], "cmd": function () {
                mastermind.rating.show.apply(mastermind.rating)
            }
        },
        { "label": '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', "cmd": '' },
        { "label": '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', "cmd": '' }
    ]);

    module.mastermind = mastermind;

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": word['mastermind_title'],
        "cmd": function () {
            main_menu.hide();
            module.mastermind.show();
        }
    })

})();

loader.next();
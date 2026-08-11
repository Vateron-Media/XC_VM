/**
 * Horoscope module.
 */

(function () {

    function horoscope_constructor() {

        this.layer_name = 'horoscope';

        this.dom_obj = this.create_block();
        document.body.appendChild(this.dom_obj);

        this.superclass = BaseLayer.prototype;

        this.menu_map = [];

        this.items_map = [];

        this.data = {};

        this.start_idx = 0;
        this.total_items = 0;

        this.info_on = false;

        this.init = function () {

            this.superclass.init.call(this);

            var container = create_block_element('', this.dom_obj);

            this.common_info = create_block_element('horoscope_common_info', container);

            this.info = create_block_element('horoscope_info', container);
            this.info.hide();

            var menu = document.createElement('ul');
            menu.setClass('horoscope_menu');

            for (var i = 0; i <= 2; i++) {
                var li = document.createElement('li');
                li.style.background = 'url(template/' + loader.template + '/i' + resolution_prefix + '/horoscope_menu_button_1_' + (i + 1) + '_a.png)';
                menu.appendChild(li);
                this.menu_map.push(li);
            }

            container.appendChild(menu);

            this.left_arrow = create_block_element('horoscope_left_arrow', container);
            this.right_arrow = create_block_element('horoscope_right_arrow', container);
        };

        this.show = function () {
            _debug('horoscope.show');

            this.superclass.show.apply(this);

            this.load();
        };

        this.hide = function () {
            _debug('horoscope.hide');

            this.hide_info();

            this.superclass.hide.apply(this);
        };

        this.load = function () {
            _debug('horoscope.load');

            stb.load(
                {
                    "type": "horoscope",
                    "action": "get_data"
                },

                function (result) {
                    this.fill(result);
                },

                this
            );
        };

        this.fill = function (data) {
            _debug('horoscope.fill', data);

            this.data = data;

            this.common_info.innerHTML = data[0].description;

            data.splice(0, 1);

            this.data = data;
            this.total_items = this.data.length;

            for (var i = 1; i <= this.total_items; i++) {
                this.items_map.push(i);
            }

            this.fill_menu();
        };

        this.shift_menu = function (dir) {
            _debug('horoscope.shift_menu');

            if (this.info_on) {
                this.hide_info();
            }

            if (dir > 0) {
                this.items_map.push(this.items_map.shift());
            } else {
                var items_map_length = this.items_map.length;
                this.items_map.unshift(this.items_map[items_map_length - 1]);
                this.items_map.splice(items_map_length, 1);
            }

            this.fill_menu();
        };

        this.fill_menu = function () {
            _debug('horoscope.fill_menu');

            for (var i = 0; i < 3; i++) {

                var letter = 'a';

                if (i == 1) {
                    letter = 'b';
                }

                _debug('url(template/' + loader.template + '/i' + resolution_prefix + '/horoscope_menu_button_1_' + this.items_map[i] + '_' + letter + '.png)');

                this.menu_map[i].style.background = 'url(template/' + loader.template + '/i' + resolution_prefix + '/horoscope_menu_button_1_' + this.items_map[i] + '_' + letter + '.png)';
            }
        };

        this.show_info = function () {
            _debug('horoscope.show_info');

            if (this.info_on) {
                return;
            }

            this.info_on = true;

            this.menu_map[0].style.visibility = 'hidden';
            this.menu_map[2].style.visibility = 'hidden';

            this.left_arrow.hide();
            this.right_arrow.hide();

            this.common_info.hide();

            this.info.innerHTML = '<span>' + this.data[this.items_map[1] - 1].title + ':</span> ' + this.data[this.items_map[1] - 1].description;
            this.info.show();
        };

        this.hide_info = function () {
            _debug('horoscope.hide_info');

            this.info.innerHTML = '';
            this.info.hide();

            this.menu_map[0].style.visibility = 'visible';
            this.menu_map[2].style.visibility = 'visible';

            this.common_info.show();

            this.left_arrow.show();
            this.right_arrow.show();

            this.info_on = false;
        };

        this.bind = function () {

            this.shift_menu.bind(key.LEFT, this, -1);
            this.shift_menu.bind(key.RIGHT, this, 1);

            this.show_info.bind(key.OK, this);

            (function () {
                if (this.info_on) {
                    this.hide_info();
                } else {
                    this.hide();
                    main_menu.show();
                }
            }).bind(key.EXIT, this);

            (function () {
                this.hide();
                main_menu.show();
            }).bind(key.MENU, this);
        }
    }

    horoscope_constructor.prototype = new BaseLayer();

    var horoscope = new horoscope_constructor();

    horoscope.init();

    horoscope.bind();

    horoscope.init_header_path(word['horoscope_title']);

    horoscope.hide();

    module.horoscope = horoscope;

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": word['horoscope_title'],
        "cmd": function () {
            main_menu.hide();
            module.horoscope.show();
        }
    })

})();

loader.next();
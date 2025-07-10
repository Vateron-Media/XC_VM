/**
 * City info modile.
 */

(function () {

    function cityinfo_constructor() {

        this.layer_name = 'cityinfo';

        this.row_blocks = ['title'];

        this.load_params = {
            "type": "cityinfo",
            "action": "get_ordered_list"
        };

        this.superclass = ListLayer.prototype;

        this.row_callback_timer;
        this.row_callback_timeout = 300;

        this.shift_row_callback = function (item) {

            window.clearTimeout(this.row_callback_timer);

            var self = this;

            this.row_callback_timer = window.setTimeout(function () {

                self.fill_short_info(item);

            },
                this.row_callback_timeout);
        };

        this.fill_short_info = function (item) {
            _debug('cityinfo.fill_short_info');

            this.short_info_box.innerHTML = '<span>' + item.title + '</span><br><br>' + item.number;
        };

        this.init_short_info = function () {
            this.info_box = create_block_element('', this.main_container);

            this.short_info_box = create_block_element('cityinfo_info_box', this.info_box);
        };

        this.sort_menu_switcher = function () {
            if (this.sort_menu && this.sort_menu.on) {
                this.sort_menu.hide();
            } else {
                this.sort_menu.show();
            }
        };

        this.init_sort_menu = function (map, options) {
            this.sort_menu = new bottom_menu(this, options);
            this.sort_menu.init(map);
            this.sort_menu.bind();
        };
    }

    cityinfo_constructor.prototype = new ListLayer();

    var cityinfo = new cityinfo_constructor();

    cityinfo.bind();
    cityinfo.init();

    cityinfo.init_short_info();

    cityinfo.set_middle_container();

    cityinfo.init_left_ear(word['ears_back']);

    cityinfo.init_color_buttons([
        { "label": word['cityinfo_sort'], "cmd": cityinfo.sort_menu_switcher },
        { "label": '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', "cmd": '' },
        { "label": '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', "cmd": '' },
        { "label": '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;', "cmd": '' }
    ]);

    cityinfo.init_sort_menu(
        [
            {
                "label": word['cityinfo_main'], "cmd": function () {
                    this.parent.load_params.part = 'main'
                }
            },
            {
                "label": word['cityinfo_help'], "cmd": function () {
                    this.parent.load_params.part = 'help'
                }
            },
            {
                "label": word['cityinfo_other'], "cmd": function () {
                    this.parent.load_params.part = 'other'
                }
            }
        ],
        {
            "offset_x": 27,
            "color": "red"
        }
    );

    cityinfo.init_header_path(word['cityinfo_title']);

    cityinfo.hide();

    module.cityinfo = cityinfo;

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": word['cityinfo_title'],
        "cmd": function () {
            main_menu.hide();
            module.cityinfo.show();
        }
    })

    loader.next();

})();

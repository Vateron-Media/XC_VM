(function () {

    if (!stb.profile['enable_tariff_plans']) {
        return;
    }

    function ServiceManagement() {

        this.layer_name = 'service_management';
        this.row_blocks = ['name', 'description', 'subscribed_str', 'not_subscribed_str'];

        this.load_params = {
            'type': 'account_info',
            'action': 'get_user_packages'
        };

        this.superclass = ListLayer.prototype;

        this.hide = function (do_not_reset) {
            _debug('service_management.hide', do_not_reset);

            this.password_input.on && this.password_input.hide && this.password_input.hide();

            this.superclass.hide.call(this, do_not_reset);
        };

        this.init = function () {
            this.superclass.init.apply(this);

            PackageInfoConstructor.prototype = new SimpleLayer();
            this.package_info = new PackageInfoConstructor(this);
            this.package_info.init();
            this.package_info.bind();
            this.package_info.init_left_ear(get_word('ears_back'));

            this.package_info.init_header_path(get_word('package_info_title'));

            this.confirm_dialog = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('confirm_service_subscribe_text')
            });
            this.confirm_dialog.getTextDomObj().style.textAlign = "center";
            this.confirm_dialog.enableOnExitClose();
            var scope = this;

            this.confirm_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.confirm_dialog.hide();
                    }
                }
            ));

            this.confirm_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("yes_btn"),
                    "onclick": function () {
                        scope.confirm_dialog.hide();
                        //scope.do_subscribe(scope.confirm_dialog.tariff_package);
                        scope.check_price(scope.confirm_dialog.tariff_package)
                    }
                }
            ));


            this.price_confirm = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('confirm_service_price_text')
            });
            this.price_confirm.getTextDomObj().style.textAlign = "center";
            this.price_confirm.enableOnExitClose();

            this.price_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.price_confirm.hide();
                    }
                }
            ));

            this.price_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("pay_btn"),
                    "onclick": function () {

                        scope.do_subscribe(scope.price_confirm.tariff_package);

                        scope.price_confirm.hide();
                    }
                }
            ));

            this.subscription_message = new ModalForm({ "title": get_word('notice_form_title'), "text": " " });
            this.subscription_message.getTextDomObj().style.textAlign = "center";
            this.subscription_message.enableOnExitClose();
            this.subscription_message.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.subscription_message.hide();
                    }
                }
            ));


            this.confirm_unsubscribe_dialog = new ModalForm({
                "title": get_word('confirm_form_title'),
                "text": get_word('confirm_service_unsubscribe_text')
            });
            this.confirm_unsubscribe_dialog.getTextDomObj().style.textAlign = "center";
            this.confirm_unsubscribe_dialog.enableOnExitClose();

            this.confirm_unsubscribe_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.confirm_unsubscribe_dialog.hide();
                    }
                }
            ));

            this.confirm_unsubscribe_dialog.addItem(new ModalFormButton(
                {
                    "value": get_word("yes_btn"),
                    "onclick": function () {
                        scope.confirm_unsubscribe_dialog.hide();
                        scope.do_unsubscribe(scope.confirm_unsubscribe_dialog.tariff_package);
                    }
                }
            ));


            this.complete_confirm = new ModalForm({
                "title": get_word('notice_form_title'),
                "text": get_word('service_subscribe_success_reboot')
            });
            this.complete_confirm.enableOnExitClose();
            this.complete_confirm.getTextDomObj().style.textAlign = "center";
            this.complete_confirm.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {
                        scope.complete_confirm.hide();

                        _debug('scope.complete_confirm.need_reboot', scope.complete_confirm.need_reboot);

                        if (scope.complete_confirm.need_reboot) {
                            scope.hide();
                            stb.ExecAction('reboot');
                        }
                    }
                }
            ));

            this.parent_password_promt = new ModalForm({
                "title": get_word('parent_password_title'),
                "parent": main_menu
            });
            this.parent_password_promt.enableOnExitClose();

            this.parent_password_promt.addItem(new ModalFormInput({
                "label": get_word('password_label'),
                "name": "parent_password",
                "type": "password",
                "onchange": function () {
                    _debug('change');
                    scope.parent_password_promt.resetStatus()
                }
            }));

            this.parent_password_promt.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        var parent_password = scope.parent_password_promt.getItemByName('parent_password').getValue();

                        _debug('parent_password', parent_password);
                        _debug('stb.user.parent_password', stb.user.parent_password);

                        if (parent_password == stb.user.parent_password) {
                            scope.parent_password_promt.hide();
                            scope.parent_password_promt.callback && scope.parent_password_promt.callback();
                        } else {
                            scope.parent_password_promt.setStatus(get_word('parent_password_error'));
                        }
                    }
                }
            ));

            this.parent_password_promt.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.parent_password_promt.hide();
                    }
                }
            ));

            this.password_input = new ModalForm({ "title": get_word('parent_password_title'), "parent": main_menu });
            this.password_input.enableOnExitClose();

            this.password_input.addItem(new ModalFormInput({
                "label": get_word('password_label'),
                "name": "password_input",
                "type": "password",
                "onchange": function () {
                    _debug('change');
                    scope.password_input.resetStatus()
                }
            }));

            this.password_input.addItem(new ModalFormButton(
                {
                    "value": get_word("ok_btn"),
                    "onclick": function () {

                        var password_input = scope.password_input.getItemByName('password_input').getValue();

                        _debug('password_input', password_input);
                        _debug('stb.user.parent_password', stb.user.parent_password);

                        if (password_input == stb.user.parent_password) {
                            scope.password_input.hide();
                            scope.password_input.callback && scope.password_input.callback();
                        } else {
                            scope.password_input.setStatus(get_word('parent_password_error'));
                        }
                    }
                }
            ));

            this.password_input.addItem(new ModalFormButton(
                {
                    "value": get_word("cancel_btn"),
                    "onclick": function () {
                        scope.password_input.hide();
                    }
                }
            ));
        };

        this.bind = function () {
            this.superclass.bind.apply(this);

            this.action.bind(key.OK, this).bind(key.INFO, this).bind(key.RIGHT, this);
        };

        this.set_active_row = function (num) {
            this.superclass.set_active_row.call(this, num);

            if (!stb.profile['allow_subscription_from_stb']) {
                this.color_buttons.get('green').disable();
                this.color_buttons.get('red').disable();
            } else if (this.data_items[this.cur_row].optional && !this.data_items[this.cur_row].subscribed) {
                this.color_buttons.get('green').enable();
                this.color_buttons.get('red').disable();
            } else if (this.data_items[this.cur_row].optional && this.data_items[this.cur_row].subscribed) {
                this.color_buttons.get('green').disable();
                this.color_buttons.get('red').enable();
            } else {
                this.color_buttons.get('green').disable();
                this.color_buttons.get('red').disable();
            }
        };

        this.action = function () {
            _debug('service_management.action');

            this.on = false;
            this.package_info.show(this.data_items[this.cur_row]);

            /*if (!stb.profile['allow_subscription_from_stb']){
                return;
            }

            if (this.data_items[this.cur_row].optional && !this.data_items[this.cur_row].subscribed){
                this.subscribe();
            }else if (this.data_items[this.cur_row].optional && this.data_items[this.cur_row].subscribed){
                this.unsubscribe();
            }*/
        };

        this.subscribe = function () {
            _debug('service_management.subscribe');

            var self = this;

            this.password_input.callback = function () {
                self.confirm_dialog.tariff_package = self.data_items[self.cur_row];
                self.confirm_dialog.show();
            };

            this.password_input.show();
        };

        this.unsubscribe = function () {
            _debug('service_management.unsubscribe');

            var self = this;

            this.password_input.callback = function () {
                self.confirm_unsubscribe_dialog.tariff_package = self.data_items[self.cur_row];
                self.confirm_unsubscribe_dialog.show();
            };

            this.password_input.show();
        };

        this.check_price = function (tariff_package) {
            _debug('service_management.check_price', tariff_package);


            stb.load({
                "type": "account",
                "action": "check_price",
                "package_id": tariff_package.package_id
            },

                function (result) {
                    _debug('on check_price', result);

                    if (!result) {
                        this.subscription_message.show(get_word('service_subscribe_server_error'));
                    } else if (result.hasOwnProperty('message')) {
                        this.subscription_message.show(result['message']);
                    } else if (result.result === '0' || result.result === 0) {
                        this.do_subscribe(tariff_package);
                    } else if (result.result !== false) {
                        this.price_confirm.price = result.result;

                        if (!/[^0-9\.,]/.test(this.price_confirm.price)) {
                            this.price_confirm.price = this.price_confirm.price + get_word('package_price_measurement');
                        }

                        this.price_confirm.tariff_package = tariff_package;
                        _debug('this.price_confirm.tariff_package', this.price_confirm.tariff_package);
                        this.price_confirm.show(get_word('confirm_service_price_text').format(this.price_confirm.price));
                    }
                },

                this
            );
        };

        this.do_subscribe = function (tariff_package) {
            _debug('service_management.do_subscribe', tariff_package);

            stb.load({
                "type": "account",
                "action": "subscribe_to_package",
                "package_id": tariff_package.package_id
            },

                function (result) {
                    _debug('on do_subscribe', result);

                    this.load_data();

                    this.complete_confirm.need_reboot = false;

                    if (!result || result.hasOwnProperty('result') && result.result === 0) {
                        this.subscription_message.show(get_word('service_subscribe_server_error'));
                    } else if (result.hasOwnProperty('message')) {
                        this.subscription_message.show(result['message']);
                    } else if (result.result > 0) {
                        if (tariff_package.type == 'module') {
                            this.complete_confirm.need_reboot = true;
                            this.complete_confirm.show(get_word('service_subscribe_success_reboot'));
                        } else if (tariff_package.type == 'tv') {
                            this.complete_confirm.need_reboot = false;
                            stb.load_channels();
                            stb.load_fav_channels();
                            stb.load_fav_itv();
                        } else {
                            this.complete_confirm.need_reboot = false;
                            this.complete_confirm.show(get_word('service_subscribe_success'));
                        }
                    } else {
                        this.complete_confirm.show(get_word('service_subscribe_fail'));
                    }
                },

                this
            );
        };

        this.do_unsubscribe = function (tariff_package) {
            _debug('service_management.do_unsubscribe', tariff_package);

            stb.load({
                "type": "account",
                "action": "unsubscribe_from_package",
                "package_id": tariff_package.package_id
            },

                function (result) {
                    _debug('on do_unsubscribe', result);

                    this.load_data();

                    this.complete_confirm.need_reboot = false;

                    if (!result || result.hasOwnProperty('result') && result.result === 0) {
                        this.subscription_message.show(get_word('service_subscribe_server_error'));
                    } else if (result.hasOwnProperty('message')) {
                        this.subscription_message.show(result['message']);
                    } else if (result.result === true) {
                        if (tariff_package.type == 'module') {
                            this.complete_confirm.need_reboot = true;
                            this.complete_confirm.show(get_word('service_unsubscribe_success_reboot'));
                        } else if (tariff_package.type == 'tv') {
                            this.complete_confirm.need_reboot = false;
                            stb.load_channels();
                            stb.load_fav_channels();
                            stb.load_fav_itv();
                        } else {
                            this.complete_confirm.need_reboot = false;
                            this.complete_confirm.show(get_word('service_unsubscribe_success'));
                        }
                    } else {
                        this.complete_confirm.show(get_word('service_subscribe_fail'));
                    }
                },

                this
            );
        };
    }

    function PackageInfoConstructor(parent) {
        this.layer_name = 'package_info';

        this.parent = parent;

        this.header_path_map = [];

        this.superclass = SimpleLayer.prototype;

        this.init = function () {
            _debug('account.init');

            this.superclass.init.apply(this);

            this.main_container = create_block_element("main_container", this.container);

            this.main_container.content = new Scrollable(create_block_element("main_content", this.main_container), this.main_container);

            this.hide();
        };

        this.show = function (tariff_package) {
            _debug('package_info.show', tariff_package);

            this.superclass.show.apply(this);

            this.tariff_package = tariff_package;

            this.update_header_path([{ "alias": "package", "item": tariff_package.name }]);

            this.main_container.content.dom_obj.innerHTML = get_word('Loading...');

            stb.load(
                {
                    "type": "user",
                    "action": "get_package_description",
                    "package_id": tariff_package.package_id
                },
                function (result) {
                    this.fill_info(result);
                },
                this
            );
        };

        this.hide = function () {
            _debug('tariff_package.hide');

            this.parent.on = true;

            this.dom_obj.hide();
            this.on = false;

            this.main_container.content.dom_obj.innerHTML = '';

            this.main_container.content.scrollbar && this.main_container.content.scrollbar.reset && this.main_container.content.scrollbar.reset();
        };

        this.fill_info = function (info) {
            _debug('package_info.fill_info', info);

            var info_str = '';

            if (info['type']) {
                info_str += '<div class="info-block"><span class="label">' + get_word('package_type') + ':</span> ' + info['type'] + '</div>';
            }

            if (info['description']) {
                info_str += '<div class="info-block"><span class="label">' + get_word('package_description') + ':</span> ' + info['description'] + '</div>';
            }

            if (info['content']) {
                info_str += '<div class="info-block"><span class="label">' + get_word('package_content') + ':<br></span> ' + info['content'] + '</div>';
            }

            this.main_container.content.dom_obj.innerHTML = info_str;
        };

        this.shift = function (dir) {
            _debug('package_info.shift', dir);

            this.main_container.content.scroll && this.main_container.content.scroll(dir);
        };

        this.shift_page = function (dir) {
            _debug('account.shift_page', dir);

            this.main_container.content.scrollPage && this.main_container.content.scrollPage(dir);
        };

        this.bind = function () {
            this.shift.bind(key.UP, this, -1);
            this.shift.bind(key.DOWN, this, 1);

            this.shift_page.bind(key.PAGE_PREV, this, -1);
            this.shift_page.bind(key.PAGE_NEXT, this, 1);

            (function () {
                this.hide();
            }).bind(key.EXIT, this).bind(key.LEFT, this);

            (function () {
                this.hide();
                this.parent.hide();
                main_menu.show();
            }).bind(key.MENU, this);
        };
    }

    ServiceManagement.prototype = new ListLayer();

    var service_management = new ServiceManagement();

    service_management.bind();
    service_management.init();

    service_management.init_color_buttons([
        {
            "label": get_word('UNSUBSCRIBE'), "cmd": function () {
                service_management.unsubscribe();
            }
        },
        {
            "label": get_word('SUBSCRIBE'), "cmd": function () {
                service_management.subscribe();
            }
        },
        { "label": '', "cmd": '' },
        { "label": '', "cmd": '' }
    ]);

    service_management.init_left_ear(get_word('ears_back'));
    service_management.init_right_ear(get_word('ears_about_package'));
    service_management.init_header_path(get_word('account_info_title'));
    service_management.update_header_path([{ "alias": "tab", "item": get_word('SERVICES MANAGEMENT') }]);
    service_management.hide();

    module.service_management = service_management;

    if (!module.account_menu) {
        module.account_menu = [];
    }

    module.account_menu.push({
        "title": get_word('SERVICES MANAGEMENT'),
        "cmd": function () {

            _debug('stb.profile[account_page_by_password]', stb.profile['account_page_by_password']);

            if (stb.profile['account_page_by_password']) {
                module.account.parent_password_promt.callback = function () {
                    main_menu.hide();
                    module.service_management.show();
                };
                module.account.parent_password_promt.show();
            } else {
                main_menu.hide();
                module.service_management.show();
            }
        }
    })

})();

loader.next();
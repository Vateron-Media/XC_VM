(function () {

    _debug('stb.auth_access', stb.auth_access);

    if (!stb.auth_access) {
        return;
    }

    if (!module.account_menu) {
        module.account_menu = [];
    }

    var logout_confirm = new ModalForm({ "title": get_word('confirm_logout_title'), "text": get_word('confirm_logout') });
    logout_confirm.getTextDomObj().style.textAlign = "center";
    logout_confirm.enableOnExitClose();

    logout_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("cancel_btn"),
            "onclick": function () {
                logout_confirm.hide();
            }
        }
    ));

    logout_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("yes_btn"),
            "onclick": function () {

                logout_confirm.hide();

                main_menu.hide();
                stb.loader.show();
                stb.key_lock = false;
                if (!stb.auth_dialog) {
                    stb.init_auth_dialog();
                }
                stb.auth_dialog.show();

                // logout on server
                stb.load(
                    {
                        "type": "stb",
                        "action": "logout"
                    },
                    function (result) {
                        _debug('on logout', result);

                        stb.access_token = 'invalid';
                        stb.save_access_token();
                    }
                )
            }
        }
    ));

    module.account_menu.push({
        "title": get_word('LOGOUT'),
        "cmd": function () {
            logout_confirm.show();
        }
    })

})();

loader.next();
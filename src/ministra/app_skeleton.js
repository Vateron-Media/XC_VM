(function () {

    if (!stb.startApplication) {
        loader.next();
        return;
    }

    var app_name = "App Name";
    var app_id = "com.app";
    var app_install_url = "";

    var install_confirm = new ModalForm({ "title": get_word('app_install_confirm'), "parent": main_menu });
    install_confirm.enableOnExitClose();

    install_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("ok_btn"),
            "onclick": function () {
                install_confirm.hide();
                if (app_install_url) {
                    stb.installApplicationByURL(app_install_url);
                } else {
                    stb.installApplication(app_id);
                }
            }
        }
    ));

    install_confirm.addItem(new ModalFormButton(
        {
            "value": get_word("cancel_btn"),
            "onclick": function () {
                install_confirm.hide();
            }
        }
    ));

    module[app_name.toLowerCase()] = {
        open: function () {
            stb.startApplication(app_id);
        }
    };

    main_menu.add(app_name.toUpperCase(), [], 'mm_ico_' + app_name.toLocaleLowerCase() + '.png', function () {

        if (!stb.isInstalled(app_id)) {
            install_confirm.show();
        } else {
            module[app_name.toLowerCase()].open();
        }


    }, { layer_name: app_name.toLowerCase() });

    loader.next();
})();
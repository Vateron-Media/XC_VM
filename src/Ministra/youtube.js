/**
 * Redirection to internal youtube module.
 */
(function () {

    if (stb.type == 'MAG200' && stb.user['disable_youtube_for_mag200']) {
        loader.next();
        return;
    }

    var app_name = "Youtube";
    var app_id = "com.google.android.youtube";
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
            stb.startAplication(app_id);
        }
    };

    main_menu.add('YouTube', [], 'mm_ico_youtube.png', function () {

        if (stb.startAplication) {

            if (!stb.isInstalled(app_id)) {
                install_confirm.show();
            } else {
                module[app_name.toLowerCase()].open();
            }

        } else {
            var params = '';

            if (stb.user['web_proxy_host']) {
                params += '?proxy=http://';
                if (stb.user['web_proxy_user']) {
                    params += stb.user['web_proxy_user'] + ':' + stb.user['web_proxy_pass'] + '@';
                }
                params += stb.user['web_proxy_host'] + ':' + stb.user['web_proxy_port'];
            }

            stb.setFrontPanel('.');
            params = stb.add_referrer(params, this.module.layer_name);

            var url = '/' + stb.portal_path + '/external/youtube/index.html' + params;
            _debug('url - ', url);
            window.location = url;
        }

    }, { layer_name: "youtube" });

    loader.next();
})();
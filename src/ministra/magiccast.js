/**
 * Redirection to ex.ua module.
 */
(function () {

    main_menu.add('MAGic Cast', [], 'mm_ico_magiccast.png', function () {

        var params = '';

        if (stb.user['web_proxy_host']) {
            params += '?proxy=http://';
            if (stb.user['web_proxy_user']) {
                params += stb.user['web_proxy_user'] + ':' + stb.user['web_proxy_pass'] + '@';
            }
            params += stb.user['web_proxy_host'] + ':' + stb.user['web_proxy_port'];
        }

        stb.setFrontPanel('.');

        if (!params) {
            params += '?';
        } else {
            params += '&';
        }

        params = stb.add_referrer(params, this.module.layer_name);

        var url = 'http://magiccast.magapps.net/index.html';

        _debug('url', url + params);

        window.location = url + params;
    }, { layer_name: "magiccast" });

    loader.next();
})();
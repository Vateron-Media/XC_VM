/**
 * Web browser.
 */
(function () {

    if (typeof (stbWindowMgr) == "undefined") {
        return;
    }

    module.internet = {
        win_inited: false
    };

    if (stbWindowMgr.InitWebWindow) {
        stbWindowMgr.InitWebWindow(
            '/home/web/public/app/bookmarks/header.html',
            '/home/web/public/app/bookmarks/footer.html');
    }

    main_menu.add(get_word('internet'), [], 'mm_ico_internet.png', function () {

        stb.EnableVKButton(true);

        _debug('module.internet.win_inited', module.internet.win_inited);

        _debug('stbWindowMgr.IsWebWindowExist', !!stbWindowMgr.IsWebWindowExist);

        _debug('stbWindowMgr.openWebFace', !!stbWindowMgr.openWebFace);

        if (stbWindowMgr.openWebFace) {

            if (gSTB.GetSystemPaths) {
                var system_paths = gSTB.GetSystemPaths();
                _debug('system_paths', system_paths);

                try {
                    system_paths = JSON.parse(system_paths);
                } catch (e) {
                    _debug(e);
                }

                if (system_paths && system_paths.result && system_paths.result.root) {
                    var path = system_paths.result.root;
                }
            } else {
                path = '/home/web/';
            }

            path = path[path.length - 1] != '/' ? path + '/' : path;
            _debug('path', path);
            stbWindowMgr.openWebFace(path + 'public/app/ibman/index.html?mode=2&url=' + encodeURIComponent('http://google.com') + '&view=1');
            module.internet.win_inited = true;
        } else if (module.internet.win_inited && stbWindowMgr.IsWebWindowExist && stbWindowMgr.IsWebWindowExist()) {
            stbWindowMgr.raiseWebWindow();
        } else {
            if (stbWindowMgr.InitWebWindow) {
                stbWindowMgr.LoadUrl('http://google.com');
                stbWindowMgr.raiseWebWindow();
            } else {
                stbWindowMgr.openWebWindow('http://google.com');
            }

            module.internet.win_inited = true;
        }
    }, { layer_name: "internet" });
})();

loader.next();
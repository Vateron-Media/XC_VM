/**
 * Demo video module.
 */

(function () {

    stb.load(
        {
            "type": "account_info",
            "action": "get_demo_video_parts"
        },
        function (result) {
            _debug('result', result);

            this.video_parts = result || [];
        },
        this
    );

    var self = this;

    (function () {
        var cur_part = main_menu.map[1].module.layer_name;
        _debug('cur_part', cur_part);

        if (self.video_parts.hasOwnProperty(cur_part) && self.video_parts[cur_part]) {

            var item = {
                "name": get_word("demo_video"),
                "cmd": "ffmpeg " + self.video_parts[cur_part]
            };

            stb.set_cur_place('demo');

            main_menu.hide();
            stb.player.prev_layer = main_menu;
            stb.player.play(item);
        }

    }).bind(key.INFO, main_menu);

    if (!module.infoportal_sub) {
        module.infoportal_sub = [];
    }

    module.infoportal_sub.push({
        "title": get_word('demo_video_title'),
        "cmd": function () {

            if (!stb.profile["demo_video_url"]) {
                stb.notice.show(get_word('coming_soon'));
                return;
            }

            var item = {
                "name": get_word("demo_video"),
                "cmd": "ffmpeg " + stb.profile["demo_video_url"]
            };

            stb.set_cur_place('demo');

            main_menu.hide();
            stb.player.prev_layer = main_menu;
            stb.player.play(item);
        }
    })

})();

loader.next();
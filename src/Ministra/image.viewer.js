(function () {

    function ImageViewerConstructor() {
        this.layer_name = 'image_viewer';

        this.cur_idx = 0;
        this.image_list = [];
        this.superclass = BaseLayer.prototype;

        this.init = function () {

            this.dom_obj = create_block_element("image_viewer");
            this.dom_obj.id = "image_viewer";

            this.label_obj = create_block_element("label", this.dom_obj);
            this.label_obj.hide();
        };

        this.show = function (img_list, cur_image, path, parent) {
            _debug('image_viewer.show', img_list, cur_image, path);

            this.superclass.show.call(this);

            this.image_list = img_list;
            this.cur_idx = this.image_list.getIdxByVal("name", cur_image) || 0;
            this.path = path;
            this.parent = parent;

            this.parent.dom_obj.hide();
            this.parent.on = false;

            this.update_image();
        };

        this.hide = function () {
            this.superclass.hide.call(this);

            if (this.parent) {
                this.parent.dom_obj.show();
                this.parent.on = true;
                stb.Stop();
            }
        };

        this.shift = function (dir) {

            if (dir > 0) {
                if (this.cur_idx < this.image_list.length - 1) {
                    this.cur_idx++;
                }
            } else {
                if (this.cur_idx > 0) {
                    this.cur_idx--;
                }
            }

            this.update_image();
        };

        this.bind = function () {
            this.hide.bind(key.EXIT, this);
            this.shift.bind(key.RIGHT, this, 1);
            this.shift.bind(key.LEFT, this, -1);
            this.update_label.bind(key.INFO, this);

            (function () {
                stb.player.change_aspect();
            }).bind(key.FRAME, this);

            (function () {
                this.hide();
                this.parent.hide();
                main_menu.show();
            }).bind(key.MENU, this);
        };

        this.update_image = function () {
            _debug('image_viewer.update_image');
            //var img = this.path + decodeURIComponent(this.image_list[this.cur_idx].name);
            var img = decodeURIComponent(this.image_list[this.cur_idx].cmd);
            _debug('img', img);
            this.update_label();

            //stb.Play("auto " + img);
            stb.Play(img);
        };

        this.update_label = function () {

            window.clearTimeout(this.hide_label_timeout);

            this.label_obj.innerHTML = this.image_list[this.cur_idx].name + " (" + this.convert_bytes(this.image_list[this.cur_idx].size) + ")" + " [" + (this.cur_idx + 1) + "/" + this.image_list.length + "] ";
            this.label_obj.show();

            this.t_hide_label();
        };

        this.t_hide_label = function () {

            var self = this;

            this.hide_label_timeout = window.setTimeout(function () {
                self.label_obj.hide()
            }, 5000);
        };

        this.convert_bytes = function (bytes) {
            bytes = parseInt(bytes, 10);

            if (bytes >= 1048576) {
                var postfix = get_word('MB');
                var divider = 1048576;
            } else if (bytes >= 1024) {
                postfix = get_word('KB');
                divider = 1024;
            } else {
                postfix = get_word('B');
                divider = 1;
            }

            return (bytes / divider).toFixed(1) + ' ' + postfix;
        }
    }

    ImageViewerConstructor.prototype = new BaseLayer();

    var image_viewer = new ImageViewerConstructor();

    image_viewer.bind();
    image_viewer.init();
    image_viewer.hide();

    module.image_viewer = image_viewer;

})();

loader.next();
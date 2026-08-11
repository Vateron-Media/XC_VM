/**
 * Personal video recorder constructor.
 * @constructor
 */
function Pvr() {

    this.has_active_rec = false;
    this.current_ch_id = 0;
    this.start_time = 0;
    this.current_time = 0;
    this.record_id = 0;
    this.id_list = [];
    this.data_list = [];

    this.init_rec_list();

    var self = this;

    stb.usbdisk.add_onmount_callback(function () {
        self.init_rec_list();
    });
}

Pvr.prototype.start_rec = function (ch_item) {
    _debug('pvr.start_rec', ch_item);

    stb.load(
        {
            "type": "pvr",
            "action": "get_new_id",
            "ch_id": ch_item.id
        },

        function (result) {
            _debug('new_id', result);

            this.record_id = result;

            //stb.RecordStart(url, '/media/usbdisk/'+this.record_id+'.ts');
            this.has_active_rec = true;
            this.start_time = new Date().getTime() / 1000;
            this.init_rec_list();
        },

        this
    );
};

Pvr.prototype.stop_rec = function () {
    _debug('pvr.stop_rec');

    //stb.RecordStop();

    this.init_rec_list();

    this.reset();
};

Pvr.prototype.reset = function () {
    _debug('pvr.reset');

    this.has_active_rec = false;
    this.record_id = 0;
};

Pvr.prototype.get_current_time = function () {
    _debug('pvr.get_current_time');

    if (!this.has_active_rec) {
        return 0;
    }

    var current_time = new Date().getTime() / 1000;

    return current_time - this.start_time;
};

Pvr.prototype.init_rec_list = function () {
    _debug('pvr.init_rec_list');

    var id_list = [];

    stb.usbdisk.read_dir("/media/usbdisk/");

    var pattern = /(\d+)\.ts/i;

    for (var i = 0; i < stb.usbdisk.files.length; i++) {

        if (pattern.test(stb.usbdisk.files[i].name)) {
            id_list.push(stb.usbdisk.files[i].name.replace(pattern, "$1"));
        }
    }

    this.id_list = id_list;

    stb.load(
        {
            "type": "pvr",
            "action": "get_ordered_list"
        },

        function (result) {

            _debug('rec_list', result);

            var data_list = [];

            for (var i = 0; i < result.length; i++) {

                if (this.id_list.indexOf(result[i].id) >= 0) {
                    data_list.push(result[i]);
                }
            }

            this.data_list = data_list;
        },

        this
    );
};

Pvr.prototype.get_rec_list = function () {
    _debug('pvr.get_rec_list');

    return this.data_list;
};


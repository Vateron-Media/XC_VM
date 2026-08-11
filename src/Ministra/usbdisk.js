/**
 * USB Disk manager.
 * @constructor
 */
function usbdisk() {

    this.mounted = false;
    this.onmount_callbacks = [];
    this.onumount_callbacks = [];
    this.storage_info = [];

    this.dirs = [];
    this.files = [];

    this.audio_ext = '.mp3 .ac3 .wav .flac .ogg';
    this.video_ext = '.mpg .mkv .avi .m2ts .mts .ts .mp4 .m4v .wmv .mov .vob .iso .srt .sub .ass .wtv';
    this.image_ext = '.jpg .jpeg';

    var files_ext = this.audio_ext + ' ' + this.video_ext;

    this.image_ext += ' ' + '.bmp .tiff .tif .png';

    if (stb.type != 'MAG200') {
        files_ext += ' ' + this.image_ext;
    }

    try {
        stb.SetListFilesExt(files_ext);
    } catch (e) {
        _debug(e);
    }
}

usbdisk.prototype.init = function () {
    _debug('usbdisk.init');

    var self = this;

    (function () {
        try {
            self.drive_mounted();
        } catch (e) {
            _debug(e);
        }
    }).bind(key.USB_MOUNTED);

    (function () {
        self.drive_umounted();
    }).bind(key.USB_UNMOUNTED);

    this.check_mounted();
};

usbdisk.prototype.drive_mounted = function () {
    _debug('usbdisk.drive_mounted');

    this.mounted = true;

    var storage_info = JSON.parse(stb.RDir('get_storage_info')) || [];

    this.restore_jobs(storage_info);

    this.storage_info = storage_info;

    if (!stb.player.on) {
        stb.notice && stb.notice.show && stb.notice.show(get_word('usb_drive') + ' ' + get_word('mbrowser_connected'));
    }

    var self = this;

    window.setTimeout(function () {
        self.fire_onmount_callbacks()
    }, 100);
};

usbdisk.prototype.drive_umounted = function () {
    _debug('usbdisk.drive_umounted');

    this.mounted = false;

    var storage_info = JSON.parse(stb.RDir('get_storage_info')) || [];

    //this.invalidate_catalogs(storage_info);

    this.storage_info = storage_info;

    if (!stb.player.on) {
        stb.notice && stb.notice.show && stb.notice.show(get_word('usb_drive') + ' ' + get_word('mbrowser_disconnected'));
    }

    this.fire_onumount_callbacks();
};

usbdisk.prototype.is_drive_mounted = function () {
    _debug('usbdisk.is_drive_mounted');

    return this.mounted;
};

usbdisk.prototype.restore_jobs = function (new_storage_info) {
    _debug('usbdisk.restore_jobs');

    if (!stbDownloadManager || !stbDownloadManager.RestoreJobs) {
        return;
    }

    new_storage_info.every(function (storage) {
        _debug('restore', storage['mountPath']);
        stbDownloadManager.RestoreJobs(storage['mountPath']);
    })
};

usbdisk.prototype.invalidate_catalogs = function (new_storage_info) {
    _debug('usbdisk.invalidate_catalogs');

    if (!stbDownloadManager || !stbDownloadManager.RestoreJobs) {
        return;
    }

    _debug('this.storage_info', this.storage_info);
    _debug('new_storage_info', new_storage_info);

    var diff = this.storage_info.filter(function (exist_storage) {
        return !new_storage_info.some(function (new_storage) {
            return JSON.stringify(exist_storage) == JSON.stringify(new_storage);
        });
    });

    _debug('diff', diff);

    diff.every(function (storage) {
        _debug('invalidate', storage['mountPath']);
        stbDownloadManager.InvalidateCatalog(storage['mountPath']);
    });
};

usbdisk.prototype.check_mounted = function () {
    _debug('usbdisk.check_mounted');

    try {

        //var list = this.read_dir("/media/usbdisk/");

        var storage_info = JSON.parse(stb.RDir('get_storage_info')) || [];

        this.restore_jobs(storage_info);

        this.storage_info = storage_info;

        if (this.storage_info.length > 0) {
            this.drive_mounted();
        }

        /*for (var i=0; i < list.length; i++){
            if (!empty(list[i])){
                this.drive_mounted();
                return;
            }
        }*/
    } catch (e) {
        _debug(e);
    }
};

usbdisk.prototype.read_dir = function (path) {
    _debug('usbdisk.read_dir');

    try {

        var txt = stb.ListDir(path, true);

        _debug(txt);

        eval(txt);

        this.dirs = dirs;
        this.files = files;

        return this.dirs.concat(this.files);
    } catch (e) {
        _debug(e);
    }
};

usbdisk.prototype.is_valid_path_for_write = function (path) {
    _debug('usbdisk.is_valid_path_for_write', path);

    return this.storage_info.some(function (storage) {
        return storage.mountPath === path && storage.isReadOnly === 0;
    })
};

usbdisk.prototype.add_onmount_callback = function (func) {
    _debug('usbdisk.add_onmount_callback');

    this.onmount_callbacks.push(func);
};

usbdisk.prototype.add_onumount_callback = function (func) {
    _debug('usbdisk.add_onumount_callback');

    this.onumount_callbacks.push(func);
};

usbdisk.prototype.fire_onmount_callbacks = function () {
    _debug('usbdisk.fire_onmount_callbacks');

    for (var i = 0; i < this.onmount_callbacks.length; i++) {
        this.onmount_callbacks[i]();
    }
};

usbdisk.prototype.fire_onumount_callbacks = function () {
    _debug('usbdisk.fire_onumount_callbacks');

    for (var i = 0; i < this.onumount_callbacks.length; i++) {
        this.onumount_callbacks[i]();
    }
};
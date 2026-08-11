/*
    Document   :  Downloads_dialog module-layout.
    Created on : 12.07.2011, 11:26:16
    Author     : Affect
*/
function downloads_dialog_constructor() {
    this.init();

    this.secure_url = false;
}

function setEnvironmentValue(name, value) {
    gSTB.RDir('setenv ' + name + ' ' + value)
}

function getEnvironmentValue(name) {
    return gSTB.RDir('getenv ' + name)
}

downloads_dialog_constructor.prototype.cur_device = '';
downloads_dialog_constructor.prototype.count_visible_items = 3;
downloads_dialog_constructor.prototype.count_devices = '';
downloads_dialog_constructor.prototype.visible_devices = [];
downloads_dialog_constructor.prototype.hide = function () {
    if (this.parent) {
        this.parent.on = true;
    }
    this.layout = 0;
    this.dom_obj.hide();
    $('select_device').hide();
    $('select_device_shadow').hide();

    this.on = false;
};
downloads_dialog_constructor.prototype.show = function (options) {
    options = options || {};
    for (var key in options) {
        if (options.hasOwnProperty(key)) {
            this[key] = options[key];
        }
    }

    this.url = options.url || "";
    this.secure_url = options.secure_url || false;
    this.name = options.name || "";

    this.parent.on = false;
    this.dom_obj.show();
    this.on = true;

    var tmp2 = 'var tmp = ' + stb.RDir('get_storage_info').replace(/\//mg, '').replace(/\n/mg, ''),
        dirs = [];
    eval(tmp2);
    for (var i = 0; i < tmp.length; i++) {
        dirs.push('USB-' + tmp[i].sn + '-' + tmp[i].partitionNum);
    }
    var usbDisks = [],
        device,
        devices = new Object(),
        i;
    for (i = 0; i < dirs.length; i++) {
        device = dirs[i].replace(/-\d{1,}/mg, '').replace(/USB-/mg, '');
        var n = new Number(devices[device]);
        if (isNaN(n) != false) {
            devices[device] = 0;
        } else {
            devices[device]++;
        }
    }
    for (i = 0; i < dirs.length; i++) {
        if (/USB-/.test(dirs[i])) {
            var tmp2 = 'var optobj = ' + gSTB.RDir('get_storage_info ' + dirs[i]);
            eval(tmp2);
            usbDisks.push(
                {
                    'dir': '/media/' + dirs[i],
                    'num': devices[dirs[i].replace(/\//mg, '').replace(/\n/mg, '').replace(/-\d{1,}/mg, '').replace(/USB-/mg, '')],
                    'info': optobj
                }
            );
        }
    }

    _debug('usbDisks.length', usbDisks.length);

    if (usbDisks.length == 0) {
        $('d_d_fileName').disabled = true;
        $('d_d_fileName').value = '';
        $('d_d_button_ok').disabled = true;
        $('d_d_fileName').value = '';
        $('d_d_fn').disabled = true;
        $('d_d_fn').value = '';
        $('d_d_button_cancel').focus();
        $('d_d_header').innerHTML = get_word('not_found_mounted_devices');
    } else {
        $('d_d_fileName').disabled = false;
        $('d_d_button_ok').disabled = false;
        $('d_d_fn').disabled = false;
        $('d_d_header').innerHTML = get_word('downloads_add_download');

        this.fillUSBDevices(usbDisks);

        _debug('this.url', this.url);

        if (this.url == "") {
            $('d_d_url').focus();
        } else {
            $('d_d_button_ok').focus();
        }
    }

    _debug('typeof(this.url)', typeof (this.url));
    _debug('this.secure_url', this.secure_url);

    /*if((typeof(this.url) != 'object' && this.url && this.url.length>0) || this.url || !this.secure_url){
        $('d_d_url').value=this.url;
        $('download_url').show();
    }else{
        $('d_d_url').value='[secure url]';
        $('download_url').hide();
    }*/
    if (typeof (this.url) == 'object' || this.secure_url) {
        $('d_d_url').value = '[secure url]';
        $('download_url').hide();
    } else {
        $('d_d_url').value = this.url || "";
        $('download_url').show();
    }
    if (this.name && this.name.length > 0) {
        $('d_d_fn').value = this.name;
    } else {
        if (this.url && this.url.length > 0) {
            var splited = this.url.split('/');
            $('d_d_fn').value = decodeURIComponent(splited[splited.length - 1]);
        } else {
            $('d_d_fn').value = '';
        }
    }
}
downloads_dialog_constructor.prototype.reset = function () {

};
downloads_dialog_constructor.prototype.bind = function () {
    (function () {
        if (this.layout == 0) {
            if ($("d_d_button_ok").hasFocus() == true ||
                $("d_d_button_cancel").hasFocus() == true) {
                $("d_d_fn").focus();
                return;
            }
            if ($("d_d_url").hasFocus() == true) {
                $("d_d_button_cancel").focus();
                return;
            }
            if ($("d_d_fileName").hasFocus() == true) {
                $("d_d_url").focus();
                return;
            }
            if ($("d_d_fn").hasFocus() == true) {
                $("d_d_fileName").focus();
                return;
            }
        }
        if (this.layout == 1) {
            this.moveCursor(-1);
        }
    }).bind(key.UP, this);
    (function () {
        if (this.layout == 0) {
            if ($("d_d_button_ok").hasFocus() == true ||
                $("d_d_button_cancel").focused == true) {
                $("d_d_url").focus();
                return;
            }
            if ($("d_d_url").hasFocus() == true) {
                if ($("d_d_fileName").disabled == true) {
                    $("d_d_button_cancel").focus();
                    return;
                }
                $("d_d_fileName").focus();
                return;
            }
            if ($("d_d_fileName").hasFocus() == true) {
                $("d_d_fn").focus();
                return;

            }
            if ($("d_d_fn").hasFocus() == true) {
                $("d_d_button_ok").focus();
                return;
            }
        }
        if (this.layout == 1) {
            this.moveCursor(1);
        }
    }).bind(key.DOWN, this);
    (function () {
        if (this.layout == 0) {
            if ($("d_d_button_cancel").hasFocus() == true) {
                $("d_d_button_ok").focus();
                return;
            }
        }
    }).bind(key.LEFT, this);
    (function () {
        if (this.layout == 0) {
            if ($("d_d_button_ok").hasFocus() == true) {
                $("d_d_button_cancel").focus();
                return;
            }
        }
    }).bind(key.RIGHT, this);
    (function () {
        //stb.Debug('this.layout: '+this.layout);
        if (this.layout == 0) {
            //stb.Debug('pressed: OK, la: 0');
            if ($("d_d_button_cancel").hasFocus() == true) {
                this.hide();
            }
            if ($("d_d_button_ok").hasFocus() == true) {

                var splited = $("d_d_url").value.split('/'),
                    fn = '';
                //stb.Debug('\nurl:'+$("d_d_url").value+'\ndir: '+$('d_d_fileNameSerial').value+splited[splited.length-1]);
                fn = $('d_d_fn').value != '' ? $('d_d_fn').value : splited[splited.length - 1];
                fn = fn.replace(/\n|\\|\/|\"|\'|\?|\*|\:/mg, '');

                //fn = $('d_d_fileNameSerial').value+'/' + fn;

                var download_task = { "url": this.url };

                if (stbDownloadManager.RestoreJobs) {
                    download_task.mountPoint = $('d_d_fileNameSerial').value;
                    download_task.filePath = fn;
                } else {
                    download_task.filePath = $('d_d_fileNameSerial').value + '/' + fn;
                }

                download_task.url = download_task.url || $('d_d_url').value;

                //if (typeof(this.url) == 'object'){
                module.downloads.add(download_task);
                /*}else{
                    stbDownloadManager.AddJob($("d_d_url").value, fn);
                }*/

                //stbDownloadManager.AddJob($("d_d_url").value, $('d_d_fileNameSerial').value+'/' + fn);

                if (this.parent == module.downloads) {
                    this.parent.every_interval();
                }

                this.hide();
            }
            if ($("d_d_url").hasFocus() == true || $("d_d_fn").hasFocus() == true) {
                stb.ShowVirtualKeyboard();
                return;
            }
            if ($("d_d_fileName").hasFocus() == true) {
                $('select_device_shadow').show();
                $('select_device').show();
                this.layout = 1;
            }
        } else {
            if (this.layout == 1) {
                $('d_d_fileNameSerial').value = $('dev_' + this.cur_device).getElementsByTagName('input')[0].value;
                $('d_d_fileName').value = $('dev_' + this.cur_device).innerHTML.substr(0, $('dev_' + this.cur_device).innerHTML.indexOf('|'));
                setEnvironmentValue('download_path', $('dev_' + this.cur_device).getElementsByTagName('input')[0].value);
                $('select_device_shadow').hide();
                $('select_device').hide();
                this.layout = 0;
            }
        }
    }).bind(key.OK, this);
    (function () {
        if (this.layout == 0) {
            this.hide();
        }
        if (this.layout == 1) {
            this.layout = 0;
            $('select_device').hide();
            $('select_device_shadow').hide();
        }
    }).bind(key.EXIT, this);
    (function () {
        this.hide();
        this.parent.hide();
        main_menu.show();
    }).bind(key.MENU, this);
    var self = this;
    stb.usbdisk.add_onmount_callback(function () {
        if ($('downloadsDialogCreate').style.display != 'none') {
            this.layout = 0;
            $('select_device').hide();
            $('select_device_shadow').hide();
            self.parent.drawCreateDialog();
        }
    });
    stb.usbdisk.add_onumount_callback(function () {
        if ($('downloadsDialogCreate').style.display != 'none') {
            this.layout = 0;
            $('select_device_shadow').hide();
            $('select_device').hide();
            self.parent.drawCreateDialog();
        }
    });
};
downloads_dialog_constructor.prototype.fillUSBDevices = function (arr, set_first) {
    var download_path = getEnvironmentValue('download_path');

    var innerDivs = $('device_list').getElementsByTagName('div');
    for (var i = innerDivs.length - 1; i >= 0; i--) {
        $('device_list').removeChild(innerDivs[i]);
    }
    for (var i = 0; i < arr.length; i++) {
        /*{
         *sn:"001CC0EC33EEBA8177D30190",
         *vendor:"Kingston",
         *model:"DT 101 G2",
         *size:"1998710784",
         *freeSize:"1466531840",
         *label:"AAAAAAAAAAA",
         *partitionNum:"1"}
        */
        var div_item = document.createElement('div');
        if (download_path == arr[i].dir) {
            div_item.setAttribute('class', 'combo_btn_act');
        } else {
            div_item.setAttribute('class', 'combo_btn');
        }
        div_item.setAttribute('dir', arr[i].dir);
        div_item.setAttribute('id', 'dev_' + i);
        //gSTB.Debug('dev_'+i);
        var hdd_space = {
            'full_mb': arr[i].info.size / 1024 / 1024,
            'free_mb': arr[i].info.freeSize / 1024 / 1024,
            'full_gb': arr[i].info.size / 1024 / 1024 / 1024,
            'free_gb': arr[i].info.freeSize / 1024 / 1024 / 1024
        };
        /* если 1 раздел - не писать партишщшон */
        div_item.innerHTML =
            arr[i].info.vendor + ' ' +
            arr[i].info.model + '' +
            (arr[i].info.label != '' ? '(' + arr[i].info.label + ')' : (arr[i].num > 0 ? '(#' + arr[i].info.partitionNum + ')' : '')) + ' | ' +
            (Math.floor(hdd_space.free_mb > 1000) ? Math.floor(hdd_space.free_gb * 100) / 100 + 'Gb' : Math.floor(hdd_space.free_mb) + 'Mb') + ' / ' + (Math.floor(hdd_space.free_mb > 1000) ? Math.floor(hdd_space.full_gb * 100) / 100 + 'Gb' : Math.floor(hdd_space.full_mb) + 'Mb') +
            '<input type="hidden" value="' + arr[i].dir + '" />';
        if (i > this.count_visible_items - 1) {
            div_item.hide();
        }
        this.count_devices = arr.length;
        this.layout = 0;
        $('device_list').appendChild(div_item);
    }
    //stb.Debug('\ndownload_path'+download_path+'\nset_first: '+set_first);
    if (set_first && set_first == true || download_path == '') {
        //stb.Debug('set_first==true');
        download_path = arr[0].dir;
        this.cur_device = 0;
        this.visible_devices = [0, 1, 2];
        $('select_device').getElementsByClassName('combo_arrow_top')[0].hide();
        $('d_d_fileNameSerial').value = download_path;
        $('d_d_fileName').value = arr[0].info.vendor + ' ' + arr[0].info.model +
            (arr[0].info.label != '' ? '(' + arr[0].info.label + ')' : (arr[0].num > 0 ? '(#' + arr[0].info.partitionNum + ')' : ''));
    } else {

        var exist_dev = false;
        for (var i = 0; i < arr.length; i++) {
            if (download_path == arr[i].dir) {
                this.cur_device = i;
                exist_dev = true;
                break;
            }
        }
        if (exist_dev == false) {
            //stb.Debug("251 this.fillUSBDevices(arr,'true');");
            this.fillUSBDevices(arr, true);
            return;
        } else {
            if (this.cur_device == 0) {
                //stb.Debug("256 this.fillUSBDevices(arr,'true');");
                this.fillUSBDevices(arr, true);
                return;
            } else {
                this.visible_devices = [0, 1, 2];
                var tmp_cur_dev = this.cur_device;
                this.cur_device = 0;
                for (var i = 0; i <= this.cur_device; i++) {
                    this.moveCursor(1);
                }
                $('d_d_fileNameSerial').value = download_path;
                $('d_d_fileName').value = arr[tmp_cur_dev].info.vendor + ' ' + arr[tmp_cur_dev].info.model +
                    (arr[tmp_cur_dev].info.label != '' ? '(' + arr[tmp_cur_dev].info.label + ')' : (arr[tmp_cur_dev].num > 0 ? '(#' + arr[tmp_cur_dev].info.partitionNum + ')' : ''));
            }
        }
    }
    $('dev_' + this.cur_device).className = 'combo_btn_act';
};
downloads_dialog_constructor.prototype.moveCursor = function (num) {
    if (num == 1 && this.cur_device >= 0 &&
        this.cur_device != this.count_devices - 1) {
        if (this.cur_device != this.visible_devices[2]) {
            //gSTB.Debug('dev_'+this.cur_device);
            $('dev_' + this.cur_device).className = 'combo_btn';
            this.cur_device++;
            $('dev_' + this.cur_device).className = 'combo_btn_act';
        } else {
            //gSTB.Debug('dev_'+this.cur_device);
            $('dev_' + this.visible_devices[0]).hide();
            for (var i = 0; i < this.visible_devices.length; i++) {
                this.visible_devices[i]++;
            }
            $('dev_' + this.visible_devices[2]).show();
            $('dev_' + this.cur_device).className = 'combo_btn';
            this.cur_device++;
            $('dev_' + this.cur_device).className = 'combo_btn_act';
        }
    }
    if (num == -1 && this.cur_device < this.count_devices &&
        this.cur_device != 0) {

        if (this.cur_device != this.visible_devices[0]) {
            //gSTB.Debug('dev_'+this.cur_device);
            $('dev_' + this.cur_device).className = 'combo_btn';
            this.cur_device--;
            $('dev_' + this.cur_device).className = 'combo_btn_act';
        } else {
            //gSTB.Debug('dev_'+this.cur_device);
            $('dev_' + this.visible_devices[2]).hide();
            for (var i = 0; i < this.visible_devices.length; i++) {
                this.visible_devices[i]--;
            }
            $('dev_' + this.visible_devices[0]).show();
            $('dev_' + this.cur_device).className = 'combo_btn';
            this.cur_device--;
            $('dev_' + this.cur_device).className = 'combo_btn_act';
        }
    }
    if (($('dev_' + (this.visible_devices[0] - 1).toString()) instanceof HTMLDivElement) == true &&
        $('dev_' + (this.visible_devices[0] - 1).toString()).style.display == 'none') {
        $('select_device').getElementsByClassName('combo_arrow_top')[0].show();
    } else {
        $('select_device').getElementsByClassName('combo_arrow_top')[0].hide();
    }
    if (($('dev_' + (this.visible_devices[2] + 1).toString()) instanceof HTMLDivElement) == true &&
        $('dev_' + (this.visible_devices[2] + 1).toString()).style.display == 'none') {
        $('select_device').getElementsByClassName('combo_arrow_bottom')[0].show();
    } else {
        $('select_device').getElementsByClassName('combo_arrow_bottom')[0].hide();
    }
};
downloads_dialog_constructor.prototype.init = function () {
    if (!$('downloadsDialogCreate')) {
        var dialog = {
            'div_outer': {
                'self': document.createElement('div'),
                'divs_inner': {
                    'header': document.createElement('div'),
                    'main': {
                        'self': document.createElement('div'),
                        'item1': {
                            'self': document.createElement('div'),
                            'sub_items': {
                                'label': document.createElement('div'),
                                'div_input': {
                                    'self': document.createElement('div'),
                                    'input': document.createElement('input')
                                }
                            }
                        },
                        'item2': {
                            'self': document.createElement('div'),
                            'sub_items': {
                                'label': document.createElement('div'),
                                'div_input': {
                                    'self': document.createElement('div'),
                                    'input': document.createElement('input'),
                                    'input_hidden': document.createElement('input')
                                }
                            }
                        },
                        'item3': {
                            'self': document.createElement('div'),
                            'sub_items': {
                                'label': document.createElement('div'),
                                'div_input': {
                                    'self': document.createElement('div'),
                                    'input': document.createElement('input')
                                }
                            }
                        },
                        'buttons_lay': {
                            'self': document.createElement('div'),
                            'sub_items': {
                                'button_ok': document.createElement('input'),
                                'button_cancel': document.createElement('input')
                            }
                        }
                    }
                }
            }
        };
        dialog.div_outer.self.setAttribute('id', 'downloadsDialogCreate');
        dialog.div_outer.self.appendChild(dialog.div_outer.divs_inner.header);
        dialog.div_outer.divs_inner.header.setAttribute('id', 'd_d_header');
        dialog.div_outer.divs_inner.header.setAttribute('class', 'header');
        dialog.div_outer.self.appendChild(dialog.div_outer.divs_inner.main.self);
        dialog.div_outer.divs_inner.main.self.setAttribute('class', 'main');
        dialog.div_outer.divs_inner.main.self.appendChild(dialog.div_outer.divs_inner.main.item1.self);
        dialog.div_outer.divs_inner.main.item1.self.setAttribute('class', 'item');
        dialog.div_outer.divs_inner.main.item1.self.setAttribute('id', 'download_url');
        dialog.div_outer.divs_inner.main.item1.self.appendChild(dialog.div_outer.divs_inner.main.item1.sub_items.label);
        dialog.div_outer.divs_inner.main.item1.sub_items.label.setAttribute('class', 'label');
        dialog.div_outer.divs_inner.main.item1.self.appendChild(dialog.div_outer.divs_inner.main.item1.sub_items.div_input.self);
        dialog.div_outer.divs_inner.main.item1.sub_items.div_input.self.setAttribute('class', 'input_cover');
        dialog.div_outer.divs_inner.main.item1.sub_items.div_input.input.setAttribute('id', 'd_d_url');
        dialog.div_outer.divs_inner.main.item1.sub_items.div_input.self.appendChild(dialog.div_outer.divs_inner.main.item1.sub_items.div_input.input);
        dialog.div_outer.divs_inner.main.self.appendChild(dialog.div_outer.divs_inner.main.item2.self);
        dialog.div_outer.divs_inner.main.item2.self.setAttribute('class', 'item');
        dialog.div_outer.divs_inner.main.item2.self.setAttribute('id', 'download_device');
        dialog.div_outer.divs_inner.main.item2.self.appendChild(dialog.div_outer.divs_inner.main.item2.sub_items.label);
        dialog.div_outer.divs_inner.main.item2.sub_items.label.setAttribute('class', 'label');
        dialog.div_outer.divs_inner.main.item2.self.appendChild(dialog.div_outer.divs_inner.main.item2.sub_items.div_input.self);
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.self.setAttribute('class', 'selectbox_cover');
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.self.setAttribute('id', 'd_d_directory');
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.self.appendChild(dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input);
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input.setAttribute('id', 'd_d_fileName');
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input.setAttribute('type', 'button');
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.self.appendChild(dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input_hidden);
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input_hidden.setAttribute('id', 'd_d_fileNameSerial');
        dialog.div_outer.divs_inner.main.item2.sub_items.div_input.input_hidden.setAttribute('type', 'hidden');
        dialog.div_outer.divs_inner.main.self.appendChild(dialog.div_outer.divs_inner.main.item3.self);
        dialog.div_outer.divs_inner.main.item3.self.setAttribute('class', 'item');
        dialog.div_outer.divs_inner.main.item3.self.setAttribute('id', 'download_filename');
        dialog.div_outer.divs_inner.main.item3.self.appendChild(dialog.div_outer.divs_inner.main.item3.sub_items.label);
        dialog.div_outer.divs_inner.main.item3.sub_items.label.setAttribute('class', 'label');
        dialog.div_outer.divs_inner.main.item3.self.appendChild(dialog.div_outer.divs_inner.main.item3.sub_items.div_input.self);
        dialog.div_outer.divs_inner.main.item3.sub_items.div_input.self.setAttribute('class', 'input_cover');
        dialog.div_outer.divs_inner.main.item3.sub_items.div_input.input.setAttribute('id', 'd_d_fn');
        dialog.div_outer.divs_inner.main.item3.sub_items.div_input.self.appendChild(dialog.div_outer.divs_inner.main.item3.sub_items.div_input.input);
        dialog.div_outer.divs_inner.main.self.appendChild(dialog.div_outer.divs_inner.main.buttons_lay.self);
        dialog.div_outer.divs_inner.main.buttons_lay.self.setAttribute('class', 'buttons_lay');
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_ok.setAttribute('id', 'd_d_button_ok');
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_ok.setAttribute('class', 'button_ok');
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_ok.setAttribute('type', 'button');
        dialog.div_outer.divs_inner.main.buttons_lay.self.appendChild(dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_ok);
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_cancel.setAttribute('id', 'd_d_button_cancel');
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_cancel.setAttribute('class', 'button_cancel');
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_cancel.setAttribute('type', 'button');
        dialog.div_outer.divs_inner.main.buttons_lay.self.appendChild(dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_cancel);

        dialog.div_outer.divs_inner.header.innerHTML = get_word('downloads_add_download');
        dialog.div_outer.divs_inner.main.item1.sub_items.label.innerHTML = 'URL:';
        dialog.div_outer.divs_inner.main.item2.sub_items.label.innerHTML = get_word('downloads_device') + ':';
        dialog.div_outer.divs_inner.main.item3.sub_items.label.innerHTML = get_word('downloads_file_name') + ':';
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_ok.setAttribute('value', get_word('downloads_ok'));
        dialog.div_outer.divs_inner.main.buttons_lay.sub_items.button_cancel.setAttribute('value', get_word('downloads_cancel'));

        var _select_device = document.createElement('div');
        _select_device.setAttribute('id', 'select_device');
        _select_device.setAttribute('class', 'combo_bg');

        var _select_device_c_a_t = document.createElement('div');
        _select_device_c_a_t.setAttribute('class', 'combo_arrow_top');
        _select_device.appendChild(_select_device_c_a_t);

        var tupo_div = document.createElement('div');
        tupo_div.setAttribute('id', 'device_list');
        _select_device.appendChild(tupo_div);

        var _select_device_c_a_b = document.createElement('div');
        _select_device_c_a_b.setAttribute('class', 'combo_arrow_bottom');
        _select_device.appendChild(_select_device_c_a_b);

        var shadow_div = document.createElement('div');
        shadow_div.setAttribute('id', 'select_device_shadow');
        document.body.appendChild(shadow_div);
        document.body.appendChild(dialog.div_outer.self);
        document.body.appendChild(_select_device);
        var emements = ["d_d_url", "d_d_fileName", "d_d_button_ok", "d_d_button_cancel", "d_d_fn"];
        for (var i = 0; i < emements.length; i++) {
            $(emements[i]).focused = false;
            $(emements[i]).onfocus = function () {
                this.focused = true;
                switch (this.id) {
                    case "d_d_url":
                        this.parentNode.className = 'input_cover_act';
                        break;
                    case "d_d_fileName":
                        this.parentNode.className = 'selectbox_cover_act';
                        break;
                }
            };

            $(emements[i]).onblur = function () {
                this.focused = false;
                switch (this.id) {
                    case "d_d_url":
                        this.parentNode.className = 'input_cover';
                        break;
                    case "d_d_fileName":
                        this.parentNode.className = 'selectbox_cover';
                        break;
                }
            };
            $(emements[i]).hasFocus = function () {
                if (this.disabled == true) return false;
                else return this.focused;
            };
        }
    }
    this.dom_obj = $('downloadsDialogCreate');//dialog.div_outer.self;
    this.bind.call(this);
};
loader.next();
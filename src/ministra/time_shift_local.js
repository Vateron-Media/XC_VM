/**
 * TimeShift on local storage.
 */

(function () {

    _debug('typeof(timeShift)', typeof (timeShift));

    if (typeof (timeShift) === "undefined") {
        _debug('timeShift not found');
        return;
    }

    var allow_local_recording = stb.user['allowed_stb_types_for_local_recording'].some(function (element) {
        return stb.type.toLowerCase().indexOf(element) != -1;
    });

    _debug('allow_local_recording', allow_local_recording);

    if (!allow_local_recording) {
        _debug('local time-shift not allowed for ' + stb.type);
        return;
    }

    module.time_shift_local = {

        enabled: false,
        channel: {},

        init: function () {
            _debug('time_shift_local.init');

            this.enabled = false;

            _debug('stb.profile.ts_enabled', stb.profile.ts_enabled);
            _debug('stb.profile.ts_enable_icon', stb.profile.ts_enable_icon);
            _debug('stb.profile.ts_path', stb.profile.ts_path);
            _debug('stb.profile.ts_max_length', stb.profile.ts_max_length);
            _debug('stb.profile.ts_buffer_use', stb.profile.ts_buffer_use);
            _debug('stb.profile.ts_action_on_exit', stb.profile.ts_action_on_exit);
            _debug('stb.profile.ts_delay', stb.profile.ts_delay);

            if (parseInt(stb.profile.ts_enabled, 10) != 1) {
                return;
            }

            if (!stb.profile.ts_path) {
                return;
            }

            if (!stb.usbdisk.is_valid_path_for_write(stb.profile.ts_path)) {
                _debug('path not valid');
                return;
            }

            this.enabled = true;

            _debug('time_shift_local.enabled', this.enabled);

            var save_path = stb.profile.ts_path + '/records';

            stb.ExecAction(Utf8.encode('make_dir ' + save_path));

            timeShift.SetTimeShiftFolder(save_path);
            timeShift.SetMaxDuration(stb.profile.ts_max_length);
            timeShift.SetSlidingMode(stb.profile.ts_buffer_use === 'cyclic');
        },

        enable_mode: function () {
            _debug('time_shift_local.enable_mode');

            this.channel = stb.player.cur_media_item;

            var result = timeShift.EnterTimeShift();

            _debug('result', result);

            return !result;
        },

        disable_mode: function () {
            _debug('time_shift_local.disable_mode');

            if (stb.profile.ts_action_on_exit === "no_save") {
                var result = timeShift.ExitTimeShift();
            } else if (stb.profile.ts_action_on_exit === "save") {

                var now = new Date();

                var file_path = stb.profile.ts_path
                    + '/' + this.channel.name.toTranslit() + '/'
                    + now.getFullYear()
                    + '' + this.format_date(now.getMonth() + 1)
                    + '' + this.format_date(now.getDate());

                var file_name = this.format_date(now.getHours())
                    + '' + this.format_date(now.getMinutes())
                    + '' + this.format_date(now.getSeconds());

                result = timeShift.ExitTimeShiftAndSave(file_path, file_name);
            }

            _debug('result', result);

            return !result;
        },

        format_date: function (param) {
            if (param < 10) {
                return '0' + param
            }
            return param
        }
    };

    module.time_shift_local.init();

    stb.usbdisk.add_onmount_callback(function () {
        module.time_shift_local.init();
    });

    stb.usbdisk.add_onumount_callback(function () {
        module.time_shift_local.init();
    });

})();

loader.next();
function SpeedtestStatic() {
}

function Speedtest(url) {

    if (!url) {
        console.log("Empty test download URL!");
    }

    this._url = url;
    this._interval_time = 1;
    this._static = SpeedtestStatic;
}

Speedtest.prototype.start = function () {
    _debug('Speedtest.start');

    var result = this._result = this._getResult();

    if (result && result.hasOwnProperty('id')) {
        this.stop(result.id);
    }

    parent.stbDownloadManager.AddMeasureJob(this._url);

    this.startChecking();
};

Speedtest.prototype.stop = function (id) {
    _debug('Speedtest.stop', id);

    id = id || this._result.id;

    _debug('id', id);

    window.clearInterval(this._static._interval);
    parent.stbDownloadManager.DeleteJob(id, false);
};

Speedtest.prototype.startChecking = function () {
    _debug('Speedtest.t_check');

    window.clearInterval(this._static._interval);

    var self = this;
    self.check();
    this._static._interval = window.setInterval(function () {
        self.check();
    }, this._interval_time * 1000);
};

Speedtest.prototype.setIntervalTime = function (value) {
    _debug('Speedtest.setIntervalTime', value);

    this._interval_time = value;
};

Speedtest.prototype.onSuccess = function (callback) {
    _debug('Speedtest.onSuccess');

    this._callback = callback;
};

Speedtest.prototype.onCheck = function (callback) {
    _debug('Speedtest.onCheck');

    this._check_callback = callback;
};

Speedtest.prototype._getResult = function () {

    var result = parent.stbDownloadManager.GetMeasureInfo();

    _debug('GetMeasureInfo', result);

    result = JSON.parse(result);
    result = result[0];

    return result;
};

Speedtest.prototype.check = function () {
    _debug('Speedtest.check');

    var result = this._result = this._getResult();

    _debug('this._static._interval', this._static._interval);

    if (result.progressPct == 100) {
        this._callback && this._callback(this.getHumanReadableSpeed(result));
        this.stop(result.id);
    } else {
        this._check_callback && this._check_callback(result);
    }
};

Speedtest.prototype.getSpeed = function (result) {
    _debug('Speedtest.getSpeed');

    return (result.sizeDone * 1000) / result.timeWasted;
};

Speedtest.prototype.getHumanReadableSpeed = function (result) {
    _debug('Speedtest.getHumanReadableSpeed');

    var speed = this.getSpeed(result) * 8;

    if (speed >= 1048576) {
        postfix = get_word('Mbps');
        divider = 1048576;
    } else if (speed >= 1024) {
        var postfix = get_word('Kbps');
        var divider = 1024;
    } else {
        postfix = get_word('bps');
        divider = 1;
    }

    return (speed / divider).toFixed(2) + ' ' + postfix;
};

loader.next();
/**
 * Traceroute to hard coded domains (domain1, domain2).
 * Send result to statistic server.
 */

function TracerouteStatic() {
}

function Traceroute(option) {

    this._domains = option && option.domains ? option.domains : [];
    this._time = option && option.time ? parseInt(option.time, 10) : 210;
    this._loadingQueue = [];
    this._start_time = null;
    this._end_time = null;
    this._is_run = false;
    this.result = [];
    this.resultCallback = option && typeof (option.callback) == 'function' ? option.callback : function () {
    };
    this.timer = null;
    this._static = TracerouteStatic;

    if (option.send_statistic) {
        if (option.sendStatisticsCallback && typeof (option.sendStatisticsCallback) == 'function') {
            this.sendStatistics = option.sendStatisticsCallback;
        }
    } else {
        this.sendStatistics = function () {
        };
    }
}

Traceroute.prototype.start = function () {
    console.log('Traceroute.start');
    if (this._loadingQueue.length == 0 && this.checkLoading()) {
        this.stopLoading();
    }
    this._start_time = new Date();
    this._end_time = new Date();
    if (this._time) {
        this._end_time.setSeconds(this._start_time.getSeconds() + (this._time * this._domains.length) + 60);
        var self = this;
        this._domains.forEach(function (item) {
            console.log('domain: ' + item);
            setTimeout(function () { // run mtr
                self._is_run = true;
                console.log('run mtr for domain: ' + item);
                result = parent.gSTB.RDir('mtr --report --no-dns --report-cycles ' + self._time + ' ' + item);
                result = result.split('\n');
                result.shift();
                result.shift();
                result.pop();
                result = result.map(function (item) {
                    tmp = item.trim().replace(/\s{2,}/g, ' ').split(' ');
                    return [tmp[1], tmp[2], Math.round(tmp[5])];
                });
                rec_result = {};
                rec_result[item] = result;
                self.result.push(rec_result);
                self.resultCallback(result);
                self._is_run = self._domains.length != self.result.length;
            }, 100);
        });
    }
    this.checkProgress();
};

Traceroute.prototype.checkLoading = function () {
    console.log('Traceroute.checkLoading');
    return parent.stbDownloadManager.GetQueueInfo() ? true : false;
};

Traceroute.prototype.stopLoading = function () {
    console.log('Traceroute.stopLoading');
    var downloads = JSON.parse(parent.stbDownloadManager.GetQueueInfo());
    for (i = 0, len = downloads.length; i < len; ++i) {
        if (downloads[i].state === 1 || downloads[i].state === 2) {
            this._loadingQueue.push(downloads[i]);
            parent.stbDownloadManager.StopJob(downloads[i].id);
        }
    }
};

Traceroute.prototype.restoreLoading = function () {
    console.log('Traceroute.restoreLoading');
    while (this._loadingQueue.length) {
        parent.stbDownloadManager.StartJob(this._loadingQueue.pop().id);
    }
};

Traceroute.prototype.checkProgress = function () {
    var self = this;
    this.timer = setInterval(function () {
        console.log('Traceroute.checkProgress');
        console.log('mtr is ' + (self._is_run ? 'running' : 'stopped'));
        if (!self._is_run) {
            clearInterval(self.timer);
            self.restoreLoading();
            self.sendStatistics();
        }
    }, 110);
};

Traceroute.prototype.getResult = function () {
    return this.result;
};

Traceroute.prototype.getStartTime = function () {
    return arguments[0] ? this._getNormTime('start') : (this._start_time instanceof Date ? this._start_time.getTime() : 0);
};

Traceroute.prototype.getEndTime = function () {
    return arguments[0] ? this._getNormTime('end') : (this._end_time instanceof Date ? this._end_time.getTime() : 0);
};

Traceroute.prototype._getNormTime = function () {
    gTime = arguments[0] && arguments[0] == 'end' ? '_end_time' : '_start_time';
    if (this[gTime] instanceof Date) {
        h = this[gTime].getHours();
        m = this[gTime].getMinutes();
    } else {
        h = m = 0;
    }
    return (h < 10 ? '0' + h : h) + ':' + (m < 10 ? '0' + m : m);
};

Traceroute.prototype.sendStatistics = function () {
    if (this.result.length) {
        parent.load({
            "type": "stb",
            "action": "save_diagnostic_info",
            "data": JSON.stringify(this.result)
        }, function () {
        }, 'POST', { "Content-type": "application/x-www-form-urlencoded" });
    }
};
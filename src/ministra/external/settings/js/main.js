var spos = 0;
var _GET = {};
var close = false;
var load_fl = false;

(function () {
    var get = window.location.href;
    var x = get.indexOf('?');
    var bad = get.lastIndexOf('?');

    if (x != bad) {
        get = get.split('');
        get[bad] = '&';
        get = get.join('');
    }

    if (x != -1) {
        var l = get.length;
        get = get.substr(x + 1, l - x);
        l = get.split('&');
        x = 0;
        for (var i in l) {
            if (l.hasOwnProperty(i)) {
                get = l[i].split('=');
                _GET[get[0]] = get[1];
                x++;
            }
        }
    }
})();

var stbEvent = {
    event: 0,
    onBroadcastMessage: function (e) {
        console.log(e);
    },
    onMessage: function (e) {
        console.log(e);
    }
};

function init_m() {
    if (load_fl) {
        var punktiT = {
            "lock": [t('Parental control'), "ico_lock", "ico_lock_act", "ico_l_lock", "g_pass.html"],
            "settings_lock": [t('Settings access'), "ico_lock", "ico_lock_act", "ico_l_lock", "g_settings_access.html"],
            "lang": [t('Localization'), "ico_lang", "ico_lang_act", "ico_l_lang", "g_local.html"],
            "update": [t('Software update'), "ico_reload", "ico_reload_act", "ico_l_reload", "g_update.html"],
            "net_info": [t('Network info'), "ico_netinfo", "ico_netinfo_act", "ico_l_netinfo", "g_netw.html"],
            "video": [t('Video'), "ico_video", "ico_video_act", "ico_l_video", "g_video.html"],
            "audio": [t('Audio'), "ico_audio", "ico_audio_act", "ico_l_audio", "g_audio.html"],
            "playback": [t('Playback'), "ico_video", "ico_video_act", "ico_l_video", "g_play.html"],
            "portal": [t('Portal'), "ico_lang", "ico_lang_act", "ico_l_lang", "g_portal.html"],
            "net": [t('Network'), "ico_net", "ico_net_act", "ico_l_net", "g_nets.html"],
            "remote_control": [t('Remote control'), "ico_remote", "ico_remote_act", "ico_l_remote", "g_remote_control.html"],
            "advanced": [t('Advanced settings'), "ico_advset", "ico_advset_act", "ico_l_advset", "g_adv.html"],
            "time_shift": [t('Local TimeShift'), "ico_reload", "ico_reload_act", "ico_l_reload", "g_ts.html"],
            "dvb": [t('DVB'), "ico_reboot", "ico_reboot_act", "ico_l_reboot", "g_dvb.html"],
            "servers": [t('Servers'), "ico_server", "ico_server_act", "ico_l_server", "g_serv.html"],
            "dev_info": [t('Device info'), "ico_sysinfo", "ico_sysinfo_act", "ico_l_sysinfo", "g_dev.html"],
            "reload": [t('Reload portal'), "ico_exit", "ico_exit_act", "ico_l_exit", 2],
            "internal_portal": [t('Go to the inner portal'), "ico_switch", "ico_switch_act", "ico_l_switch", 1],
            "reboot": [t('Reboot device'), "ico_reboot", "ico_reboot_act", "ico_l_reboot", 3]
        };

        try {
            _GET['dvb_supported_scan_types'] = JSON.parse(decodeURIComponent(_GET['dvb_supported_scan_types'])) || [];
        } catch (e) {
            console.log(e.message);
            _GET['dvb_supported_scan_types'] = [];
        }

        punkti = [];
        var cache = [];
        for (var i = 0; i < prof.modules.length; i++) {

            if (prof.modules[i].name == 'dvb' && (!_GET['dvb_supported_scan_types'] || Array.isArray(_GET['dvb_supported_scan_types']) && _GET['dvb_supported_scan_types'].length == 0)) {
                continue
            }

            if (!_GET['enable_setting_access_by_pass'] && prof.modules[i].name == 'settings_lock') {
                continue;
            }

            if (gSTB.IsEmulator && ['lock', 'lang', 'playback', 'portal', 'dev_info', 'reload', 'reboot'].indexOf(prof.modules[i].name) == -1) {
                continue;
            }

            if ((!gSTB.ConfigNetRc || !gSTB.SetNetRcStatus) && prof.modules[i].name == 'remote_control') {
                continue;
            }

            var idx = punkti.length;
            punkti[idx] = punktiT[prof.modules[i].name];
            cache[idx] = new Image();
            cache[idx].src = "style/" + parent.put + "/" + punkti[idx][1] + '.png';
            cache[idx].src = "style/" + parent.put + "/" + punkti[idx][2] + '.png';
        }
        //punkti[-1]=[t('default'),"ico_empty","ico_empty_act",""];
        kol = punkti.length;
        nextMenu('glavnaya.html');
        document.body.style.display = "block";
    }
    else setTimeout(function () { init_m() }, 100);
}

function up_fl() {
    load_fl = true;
}

function nextMenu(urlP) {
    switch (urlP) {
        case 0:
            switch (punkti[vid - 1][4]) {
                case 1: conf(t("Do you want to go to the inner portal?"), '', 'stb.Stop();stb.LoadURL("file:///home/web/services.html");stbWebWindow.close();'); break;
                case 2: conf(t("Do you want to restart portal?"), '', 'stb.Stop();stb.LoadURL("file:///home/web/index.html");stbWebWindow.close();'); break;
                case 3: conf(t("Device is going to reboot. Are you sure?"), '', 'stb.ExecAction("reboot")'); break;
                default: cont.style.visibility = 'hidden'; cont.src = punkti[vid - 1][4]; document.getElementById("zagolovok").innerHTML = punkti[vid - 1][0]; document.getElementById("ico").innerHTML = "<img src='style/" + put + "/" + punkti[vid - 1][3] + ".png' />"; break;
            }break;
        case "glavnaya.html": cont.style.visibility = 'hidden'; cont.src = urlP; document.getElementById("zagolovok").innerHTML = t("Settings"); document.getElementById("ico").innerHTML = "<img src='style/" + put + "/ico_l_set.png' />"; break;
        default: cont.style.visibility = 'hidden'; cont.src = urlP; document.getElementById("zagolovok").innerHTML = punkti[vid - 1][0]; document.getElementById("ico").innerHTML = "<img src='style/" + put + "/" + punkti[vid - 1][3] + ".png' />"; break;
    }
}

function onLoad() {
    stb.EnableVKButton(true);
    document.cookie = "mac=" + escape(parent.stb.GetDeviceMacAddress()) + '; path=/;';
    load({ "type": "stb", "action": "get_settings_profile" }, function (profile) { prof = profile; init_m(); });
    switch (screen.height) {
        case 480: w = 623; h = 430; a = 45; b = 5; put = 576; break;
        case 576: w = 623; h = 430; a = 45; b = 40; put = 576; break;
        case 720: w = 1142; h = 584; a = 60; b = 50; put = 720; break;
        case 1080: w = 1142; h = 584; a = 380; b = 230; put = 720; break;
    }
    var fileref = document.createElement("link");
    fileref.setAttribute("rel", "stylesheet");
    fileref.setAttribute("type", "text/css");
    fileref.setAttribute("href", 'style/glav_' + put + '.css');
    document.getElementsByTagName("head")[0].appendChild(fileref);
    window.resizeTo(w, h);
    window.moveTo(a, b);
    cont = document.getElementById("cont");
    cont.width = w;
    cont.height = h;
    cont.focus();
    langv();
}

function erMes(erm, el) {
    close = false;
    elem = el; ind = 1; maxin = 2;
    var d = document.getElementById('confirm'); d.style.display = "block";
    document.getElementById('b1').focus();
    d.innerHTML = '<div id="modal_ico" class="ico_alert"></div>' + erm + '<div id="modal_btn"><input id="b1" type="button" value="' + t('Ok') + '" onClick="erMbeck()" /></div>';
    document.getElementById('b1').focus();
}

function erMbeck() {
    if (close) {
        stbWebWindow.close();
    }
    else {
        if (spos != 0) {
            document.getElementById('confirmb').style.display = "none";
        } else {
            document.getElementById('confirm').style.display = "none";
        }
        if (elem != '') {
            cont.contentWindow.document.getElementById(elem).focus();
        } else {
            cont.focus();
        }
    }
}

function conf(erm, el, ef, back) {
    if (back) close = true; else close = false;
    elem = el; ind = 1; maxin = 2;
    func = ef; spos = 0;
    var d = document.getElementById('confirm'); d.style.display = "block";
    document.getElementById('b1').focus();
    d.innerHTML = '<div id="modal_ico" class="ico_issue"></div>' + erm + '<div id="modal_btn"><input id="b2" type="button" value="' + t('Cancel') + '" onClick="erMbeck()" /><input id="b1" type="button" value="' + t('Ok') + '" onClick="confOk()" /></div>';
    document.getElementById('b2').focus();
}

function confb(erm, el, ef, back) {
    if (back) close = true; else close = false;
    elem = el; ind = 1; maxin = 2;
    func = ef; spos = 1;
    var d = document.getElementById('confirmb'); d.style.display = "block";
    document.getElementById('b1b').focus();
    d.innerHTML = '<div id="modal_ico" class="ico_issue"></div>' + erm + '<div id="modal_btn"><input id="b2b" type="button" value="' + t('Cancel') + '" onClick="erMbeck()" /><input id="b1b" type="button" value="' + t('Ok') + '" onClick="confOk()" /></div>';
    document.getElementById('b2b').focus();
}

function confOk() {
    eval(func);
    if (document.getElementById('confirmb').style.display == "block" || document.getElementById('confirmb').style.display == "") document.getElementById('confirmb').style.display = "none";
    if (document.getElementById('confirm').style.display == "block" || document.getElementById('confirm').style.display == "") document.getElementById('confirm').style.display = "none";
}

function pressKey(e) {
    ec = e.keyCode;
    switch (ec) {
        case 9: e.preventDefault(); break;
        case 40: perehod(1); break;
        case 38: perehod(-1); break;
        case 37: perehod(-1); break;
        case 39: perehod(1); break;
        case 85: // power button
            var power_off = parent.stb.GetStandByStatus();
            if (power_off) {
                power_off = false;
                parent.stb.StandBy(0);
            } else {
                power_off = true;
                parent.stb.StandBy(1);
            }
            break;
    }
}

function perehod(a) {
    ind += a;
    if (ind < 1) ind = 1; else if (ind > maxin) ind = maxin;
    if (spos != 0) document.getElementById('b' + ind + 'b').focus();
    else document.getElementById('b' + ind).focus();
}

function load(params, callback, method, headers) {

    method = method || 'GET';
    headers = headers || {};

    var sendHeader = { "Authorization": "Bearer " + _GET.token };

    for (var i in headers) {
        sendHeader[i] = headers[i];
    }

    JsHttpRequest.query(
        method + ' ' + _GET.ajax_loader,

        params,

        function (result, errors) {
            // errors - содержит ошибки сервера и debug сообщения
            gSTB.Debug(errors);

            callback(result);
        },

        true,
        sendHeader
    );
}

function auto_fr_save(t) {
    var to_af = 0;
    switch (t) {
        case "50_60":
            to_af = 6;
            break;
        case "24_50_60":
            to_af = 7;
            break;
        case "24_50":
            to_af = 3;
            break;
        case "24_60":
            to_af = 5;
            break;
    }
    stb.SetAutoFrameRate(to_af);
    var wra = '{"auto_framerate":"' + t + '"}';
    stb.SetEnv(wra);
}
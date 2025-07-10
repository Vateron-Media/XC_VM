fc = true;
fln = false;
fusb = false;
block = false;

function pressKey(e) {
    ec = e.keyCode;
    if (!block) switch (ec) {
        case 9: e.preventDefault(); break;
        case 27: parent.wifi_ssid.length = 0; back(); break;
        case 13: fc = true; break;
        case 40: if (iid <= mid) perehod_set(+1); fc = true; e.preventDefault(); if (fln) ni = 1; break;
        case 38: if (iid <= mid) perehod_set(-1); fc = true; e.preventDefault(); if (fln) ni = 1; break;
        case 37: change(-1); if (fln && iid <= mid - 2) if (menuT[iid - 1][0] == 'dn') e.preventDefault(); break;
        case 39: change(1); if (fln && iid <= mid - 2) if (menuT[iid - 1][0] == 'dn') e.preventDefault(); break;
        case 80: if (fusb) { flesh.length = 0; load(); } break;
        case 81: if (fusb) { flesh.length = 0; load(); } break;
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
    else e.preventDefault();
}

function back() {
    parent.nextMenu("glavnaya.html");
}


function perehod_set(set) {
    iid += set;
    if (iid > mid) {
        iid = mid;
    } else if (iid < 1) {
        iid = 1;
    }

    if ((!document.getElementById("i" + iid) || document.getElementById("i" + iid).style.display == 'none') && iid > 1 && iid < mid) {
        perehod_set(set);
    }

    document.getElementById("i" + iid).focus();
}

function init() {
    for (i = 0; i < mid - 2; i++) {
        if (!document.getElementById("t" + (i + 1))) {
            continue;
        }
        document.getElementById("t" + (i + 1)).innerHTML = menuT[i][1];
        switch (menuT[i][0]) {
            case "select": var dd = document.getElementById("i" + (i + 1));
                if (fusb) dd.options.length = 0;
                for (j = 3; j < menuT[i].length; j += 2) {
                    s = document.createElement("option");
                    s.appendChild(document.createTextNode(menuT[i][j]));
                    s.setAttribute("value", menuT[i][j - 1]);
                    if (menuT[i][j - 1] == read.result[pread[i]]) s.selected = true;
                    dd.appendChild(s);
                } break;
            case "input": document.getElementById("i" + (i + 1)).value = read.result[pread[i]]; break;
            case "ic": document.getElementById("i" + (i + 1)).value = read.result[pread[i]]; break;
            case "check": document.getElementById("i" + (i + 1)).checked = read.result[pread[i]] == "true" ? true : false; break;
            case "text": document.getElementById("t" + (i + 1) + "1").innerHTML = menuT[i][2]; break;
            case "dn": if (read.result[pread[i]] != '') { var t = read.result[pread[i]].split('.'); document.getElementById("i" + (i + 1)).value = t[0]; document.getElementById("i" + (i + 1) + "2").value = t[1]; document.getElementById("i" + (i + 1) + "3").value = t[2]; document.getElementById("i" + (i + 1) + "4").value = t[3]; }
            else { document.getElementById("i" + (i + 1)).value = 0; document.getElementById("i" + (i + 1) + "2").value = 0; document.getElementById("i" + (i + 1) + "3").value = 0; document.getElementById("i" + (i + 1) + "4").value = 0; } break;
        }
    }
}

function sload() {
    var fileref = document.createElement("link");
    fileref.setAttribute("rel", "stylesheet");
    fileref.setAttribute("type", "text/css");
    fileref.setAttribute("href", 'style/okno_' + parent.put + '.css');
    document.getElementsByTagName("head")[0].appendChild(fileref);
    var html = document.getElementsByTagName('body')[0];
    var img = document.createElement('img');
    img.src = 'http://127.0.0.1/12313432.jpg';
    img.onerror = function () { document.body.removeChild(img); parent.cont.style.visibility = 'visible'; }
    html.appendChild(img);
}

function URLC(url) {
    var tt = new Array();
    var a = getIP(url);
    if (a[0] != 0) {
        return cIP(a[0]) && a[1] <= 65535;
    }
    else return /^http:\/\/[a-zA-Z0-9]{1,}[a-zA-Z0-9._\/-]+$/.test(url);
}

function getIP(url) {
    if (/^igmp:\/\/[0-9.]+:[0-9]+$/.test(url)) {
        tt = url.split(':');
        var tip = tt[1].split('//');
        var a = [tip[1], tt[2], 0];
        return a;
    }
    else if (/^(igmp|tftp):\/\/[0-9.]+$/.test(url)) {
        tt = url.split('//');
        return [tt[1], 0, 0];
    }
    else if (/^igmp:\/\/[0-9.]+:[0-9]+\//.test(url)) {
        tt = url.split(':');
        var tip = tt[1].split('//');
        var tput = tt[2].split('/');
        var zput = '';
        for (var i = 1; i < tput.length; i++)zput += '/' + tput[i];
        var a = [tip[1], tput[0], zput];
        return a;
    }
    else if (/^(igmp|tftp):\/\/[0-9.]+\//.test(url)) {
        tt = url.split('/');
        var zput = '';
        for (var i = 3; i < tt.length; i++)zput += '/' + tt[i];
        return [tt[2], 0, zput];
    }
    else return [0, 0, 0];
}

function cIP(ip) {
    if (ip == '' || ip == '0.0.0.0') return false;
    var test = ip.split('.');
    var pip = /^([0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3})$/;
    if (test.length != 4) return false;
    if (pip.test(ip))
        for (var i = 0; i < 4; i++) { if (parseInt(test[i]) < 0 || parseInt(test[i]) > 255) return false; }
    else return false;
    return true;
}

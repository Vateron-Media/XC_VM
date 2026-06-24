var formCache = (function () {
    var _form = null, _formData = [], _strFormElements = "input[type='text']," + "input[type='checkbox']," + "input[type='radio']," + "input[type='password']," + "input[type='hidden']," + "input[type='file']," + "select," + "textarea";
    function _warn() {
        console.log('formCache is not initialized.');
    }
    return {
        init: function (formId) {
            var f = (typeof (formId) === 'undefined' || formId === null || $.trim(formId) === '') ?
                $('form').first() : $('#' + formId);
            _form = f.length > 0 ? f : null;
            console.log(_form);
        },
        save: function () {
            if (_form === null) { _warn(); return; }
            _form
                .find(_strFormElements)
                .each(function () {
                    _formData.push($(this).attr('id') + ':' + formCache.getFieldValue($(this)));
                });
            docCookies.setItem('formData', _formData.join(), 31536e3);
            console.log('Cached form data:', _formData);
        },
        fetch: function () {
            if (_form === null) { _warn(); return; }
            if (!docCookies.hasItem('formData')) return;
            var fd = _formData.length < 1 ? docCookies.getItem('formData').split(',') : _formData;
            $.each(fd, function (i, item) {
                var s = item.split(':');
                var elem = $('#' + s[0]);
                formCache.setFieldValue(elem, s[1]);
            });
        },
        setFieldValue: function (elem, value) {
            if (_form === null) { _warn(); return; }

            if (elem.is('input:text') || elem.is('input:hidden') || elem.is('input:image') ||
                elem.is('input:file') || elem.is('textarea')) {
                elem.val(value);
            } else if (elem.is('input:checkbox') || elem.is('input:radio')) {
                elem.prop('checked', value);
            } else if (elem.is('select')) {
                elem.prop('selectedIndex', value);
            }
        },
        getFieldValue: function (elem) {
            if (_form === null) { _warn(); return; }
            if (elem.is('input:text') || elem.is('input:hidden') || elem.is('input:image') ||
                elem.is('input:file') || elem.is('textarea')) {
                return elem.val();
            } else if (elem.is('input:checkbox') || elem.is('input:radio')) {
                return elem.prop('checked');
            } else if (elem.is('select')) {
                return elem.prop('selectedIndex');
            }
            else return null;
        },
        clear: function () {
            _formData = [];
            docCookies.removeItem('formData');
        },
        clearForm: function () {
            _form
                .find(_strFormElements)
                .each(function () {
                    var elem = $(this);
                    if (elem.is('input:text') || elem.is('input:password') || elem.is('input:hidden') ||
                        elem.is('input:image') || elem.is('input:file') || elem.is('textarea')) {
                        elem.val('');
                    } else if (elem.is('input:checkbox') || elem.is('input:radio')) {
                        elem.prop('checked', false);
                    } else if (elem.is('select')) {
                        elem.prop('selectedIndex', -1);
                    }
                });
        }
    };
})();

var docCookies = {
    getItem: function (sKey) {
        if (!sKey || !this.hasItem(sKey)) { return null; }
        return unescape(document.cookie.replace(new RegExp("(?:^|.*;\\s*)" + escape(sKey).replace(/[\-\.\+\*]/g, "\\$&") + "\\s*\\=\\s*((?:[^;](?!;))*[^;]?).*"), "$1"));
    },
    setItem: function (sKey, sValue, vEnd, sPath, sDomain, bSecure) {
        if (!sKey || /^(?:expires|max\-age|path|domain|secure)$/i.test(sKey)) { return; }
        var sExpires = "";
        if (vEnd) {
            switch (vEnd.constructor) {
                case Number:
                    sExpires = vEnd === Infinity ? "; expires=Tue, 19 Jan 2038 03:14:07 GMT" : "; max-age=" + vEnd;
                    break;
                case String:
                    sExpires = "; expires=" + vEnd;
                    break;
                case Date:
                    sExpires = "; expires=" + vEnd.toGMTString();
                    break;
            }
        }
        document.cookie = escape(sKey) + "=" + escape(sValue) + sExpires + (sDomain ? "; domain=" + sDomain : "") + (sPath ? "; path=" + sPath : "") + (bSecure ? "; secure" : "");
    },
    removeItem: function (sKey, sPath) {
        if (!sKey || !this.hasItem(sKey)) { return; }
        document.cookie = escape(sKey) + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT" + (sPath ? "; path=" + sPath : "");
    },
    hasItem: function (sKey) {
        return (new RegExp("(?:^|;\\s*)" + escape(sKey).replace(/[\-\.\+\*]/g, "\\$&") + "\\s*\\=")).test(document.cookie);
    },
    keys: function () {
        var aKeys = document.cookie.replace(/((?:^|\s*;)[^\=]+)(?=;|$)|^\s*|\s*(?:\=[^;]*)?(?:\1|$)/g, "").split(/\s*(?:\=[^;]*)?;\s*/);
        for (var nIdx = 0; nIdx < aKeys.length; nIdx++) { aKeys[nIdx] = unescape(aKeys[nIdx]); }
        return aKeys;
    }
};
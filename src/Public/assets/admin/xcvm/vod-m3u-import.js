/* Shared by the movie and series import forms. */
(function () {
    'use strict';
    var preview = document.getElementById('vod_m3u_preview');
    if (!preview) { return; }
    var form = preview.closest('form');
    var file = document.getElementById('m3u_file');
    var url = document.getElementById('m3u_url');
    var download = document.getElementById('m3u_download');
    var path = document.getElementById('vod_download_path');
    var selection = document.getElementById('m3u_selection');
    var entries = document.getElementById('vod_m3u_entries');
    var list = document.getElementById('vod_m3u_list');
    var all = document.getElementById('vod_m3u_all');
    var generation = 0;

    function reset() {
        generation++;
        selection.value = '';
        document.getElementById('m3u_fingerprint').value = '';
        entries.hidden = true;
        list.replaceChildren();
    }
    function syncSelection() {
        var boxes = Array.from(list.querySelectorAll('input'));
        selection.value = JSON.stringify(boxes.filter(function (box) { return box.checked; }).map(function (box) { return box.value; }));
        all.checked = boxes.length > 0 && boxes.every(function (box) { return box.checked; });
        all.indeterminate = !all.checked && boxes.some(function (box) { return box.checked; });
    }
    function syncDownload() {
        var enabled = download.checked && document.getElementById('import_type_1').checked;
        document.getElementById('vod_download_controls').hidden = !enabled;
        path.required = enabled;
        ['vod_download_file_gib', 'vod_download_import_gib'].forEach(function (id) {
            var limit = document.getElementById(id);
            limit.disabled = !enabled;
            limit.required = enabled;
        });
        ['direct_source', 'direct_proxy'].forEach(function (id) {
            var control = document.getElementById(id);
            if (control && enabled) {
                control.checked = false;
                control.dispatchEvent(new Event('change'));
            }
            if (control) { control.disabled = enabled; }
        });
    }
    file.addEventListener('change', function () {
        if (file.value) { url.value = ''; }
        reset();
    });
    url.addEventListener('input', function () {
        if (url.value) { file.value = ''; }
        reset();
    });
    download.addEventListener('change', syncDownload);
    document.querySelectorAll('[name="import_kind"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var isM3u = document.getElementById('import_type_1').checked;
            file.disabled = url.disabled = download.disabled = !isM3u;
            path.disabled = !isM3u;
            document.getElementById('import_folder').disabled = isM3u;
            syncDownload();
        });
    });
    list.addEventListener('change', syncSelection);
    all.addEventListener('change', function () {
        list.querySelectorAll('input').forEach(function (box) { box.checked = all.checked; });
        syncSelection();
    });
    preview.addEventListener('click', function () {
        var current = ++generation;
        preview.disabled = true;
        fetch('post.php?action=vod_playlist_preview', {
            method: 'POST', body: new FormData(form), headers: {'X-Requested-With': 'XMLHttpRequest'}
        }).then(function (response) { return response.json(); }).then(function (data) {
            if (current !== generation) { return; }
            if (!data.result) { throw new Error(data.message || form.dataset.m3uError); }
            document.getElementById('m3u_fingerprint').value = data.fingerprint;
            list.replaceChildren();
            data.entries.forEach(function (entry) {
                var row = document.createElement('label');
                row.className = 'list-group-item d-flex gap-2 align-items-center';
                var box = document.createElement('input');
                box.type = 'checkbox'; box.className = 'form-check-input'; box.value = entry.id; box.checked = true;
                row.append(box, document.createTextNode(entry.title));
                list.append(row);
            });
            entries.hidden = false;
            syncSelection();
        }).catch(function (error) { xcToast(error.message || form.dataset.m3uError, 'error'); })
            .finally(function () { preview.disabled = false; });
    });
    form.addEventListener('submit', function () {
        if (document.getElementById('import_type_2').checked) {
            file.value = ''; url.value = ''; download.checked = false;
        } else {
            document.getElementById('import_folder').value = '';
        }
    }, true);
    form.dataset.m3uError = document.getElementById('vod_m3u_preview').dataset.error;
    document.getElementById('import_folder').disabled = true;
    syncDownload();
}());

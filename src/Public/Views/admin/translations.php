<?php

/**
 * Translations (Bootstrap 5): the panel's languages with how much of each is
 * translated, each as an .ini file to download, an upload, and an editor
 * (key / English / translation, with a search and a "missing only" filter).
 * Works through the admin Ajax actions translation_keys / _save / _upload
 * (Core\Localization\TranslationStore).
 *
 * In scope: $rLanguages (TranslationStore::languages()), $rTotal (the number
 * of keys in en.ini).
 */

use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\LayoutRenderer;

?>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('translations'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('translations_intro'); ?></p>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle">
            <thead>
                <tr>
                    <th><?= $language::get('language'); ?></th>
                    <th><?= $language::get('translation_translated'); ?></th>
                    <th><?= $language::get('source'); ?></th>
                    <th class="text-end"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rLanguages as $rCode => $rLanguage): ?>
                    <?php $rCode = htmlspecialchars((string) $rCode, ENT_QUOTES); ?>
                    <tr>
                        <td><strong><?= htmlspecialchars(Translator::displayName($rCode), ENT_QUOTES); ?></strong></td>
                        <td style="min-width: 14rem;">
                            <div class="d-flex align-items-center gap-3">
                                <div class="progress flex-grow-1" style="height: 6px;">
                                    <div class="progress-bar" data-translation-bar="<?= $rCode; ?>" style="width: <?= $rTotal > 0 ? round($rLanguage['translated'] * 100 / $rTotal, 1) : 0; ?>%;"></div>
                                </div>
                                <span class="text-nowrap"><span data-translation-count="<?= $rCode; ?>"><?= (int) $rLanguage['translated']; ?></span> / <?= (int) $rTotal; ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($rLanguage['bundled']): ?><span class="badge bg-label-secondary"><?= $language::get('translation_bundled'); ?></span><?php endif; ?>
                            <?php if ($rLanguage['custom'] > 0 || !$rLanguage['bundled']): ?><span class="badge bg-label-primary"><?= $language::get('translation_custom'); ?>: <?= (int) $rLanguage['custom']; ?></span><?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-sm btn-label-secondary" href="./translations?download=<?= $rCode; ?>"><?= $language::get('download'); ?></a>
                            <button type="button" class="btn btn-sm btn-label-primary" data-translation-edit="<?= $rCode; ?>"><?= $language::get('edit'); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('translation_add'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('translation_add_intro'); ?></p>
    </div>
    <div class="card-body">
        <form id="translation-add-form" class="row g-3" autocomplete="off">
            <div class="col-md-4">
                <label class="form-label" for="translation-add-code"><?= $language::get('translation_code'); ?></label>
                <input type="text" class="form-control" id="translation-add-code" maxlength="8" pattern="exyu|[a-z]{2,3}(-[A-Za-z]{2,4})?" placeholder="tr" required>
                <div class="form-text"><?= $language::get('translation_code_hint'); ?></div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><?= $language::get('translation_add_button'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('translation_upload'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('translation_upload_intro'); ?></p>
    </div>
    <div class="card-body">
        <form id="translation-upload-form" class="row g-3" autocomplete="off">
            <div class="col-md-4">
                <label class="form-label" for="translation-code"><?= $language::get('translation_code'); ?></label>
                <input type="text" class="form-control" id="translation-code" name="lang" maxlength="8" pattern="exyu|[a-z]{2,3}(-[A-Za-z]{2,4})?" placeholder="tr" required>
                <div class="form-text"><?= $language::get('translation_code_hint'); ?></div>
            </div>
            <div class="col-md-8">
                <label class="form-label" for="translation-file"><?= $language::get('translation_file'); ?></label>
                <input type="file" class="form-control" id="translation-file" name="file" accept=".ini,text/plain" required>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><?= $language::get('translation_upload_button'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card d-none" id="translation-editor">
    <div class="card-header d-flex flex-wrap align-items-center gap-3">
        <h5 class="card-title mb-0 me-auto"><?= $language::get('edit'); ?>: <span id="translation-editor-lang"></span></h5>
        <input type="search" class="form-control form-control-sm w-auto" id="translation-search" placeholder="<?= htmlspecialchars($language::get('search'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($language::get('search'), ENT_QUOTES); ?>">
        <div class="form-check form-switch mb-0">
            <input class="form-check-input" type="checkbox" id="translation-missing">
            <label class="form-check-label" for="translation-missing"><?= $language::get('translation_missing_only'); ?></label>
        </div>
        <button type="button" class="btn btn-sm btn-label-secondary" id="translation-copy-english"><?= $language::get('translation_copy_english'); ?></button>
        <button type="button" class="btn btn-sm btn-primary" id="translation-save" disabled><?= $language::get('save_changes'); ?></button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle" style="table-layout: fixed; min-width: 48rem;">
            <thead>
                <tr>
                    <th style="width: 22%;"><?= $language::get('key'); ?></th>
                    <th style="width: 39%;"><?= $language::get('translation_english'); ?></th>
                    <th><?= $language::get('translation_text'); ?></th>
                </tr>
            </thead>
            <tbody id="translation-rows"></tbody>
        </table>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var toast = window.xcToast || function(text) {
            window.alert(text);
        };
        var text = <?= json_encode([
            'save' => $language::get('save_changes'),
            'saved' => $language::get('translation_saved'),
            'discard' => $language::get('translation_discard_confirm'),
            'error' => $language::get('error_occured'),
            'error_code' => $language::get('translation_error_code'),
            'error_size' => $language::get('translation_error_size'),
            'error_parse' => $language::get('translation_error_parse'),
            'error_empty' => $language::get('translation_error_empty'),
            'error_file' => $language::get('translation_error_file'),
            'error_write' => $language::get('translation_error_write'),
        ]); ?>;
        var total = <?= (int) $rTotal; ?>;
        var editor = document.getElementById('translation-editor');
        var tbody = document.getElementById('translation-rows');
        var search = document.getElementById('translation-search');
        var missing = document.getElementById('translation-missing');
        var save = document.getElementById('translation-save');
        var lang = null; // the language in the editor
        var rows = {}; // key => {tr, input, value (as stored), text (what the search looks in)}
        var dirty = {}; // key => the value typed, for the rows that differ from what is stored
        var english = {}; // key => the English text, for "Copy English"

        function request(action, options) {
            options.headers = Object.assign({
                'X-Requested-With': 'XMLHttpRequest'
            }, options.headers || {});
            return fetch('./api?action=' + action, options).then(function(r) {
                return r.json();
            });
        }

        function failed(d) {
            toast((text['error_' + (d && d.error)] || text.error).replace('{line}', (d && d.line) || '?'), 'error');
        }

        function unsaved() {
            return Object.keys(dirty).length;
        }

        function showUnsaved() {
            save.disabled = unsaved() === 0;
            save.textContent = text.save + (unsaved() ? ' (' + unsaved() + ')' : '');
        }

        // The search looks in the key, the English text and the stored translation; "missing only" keeps the keys with none.
        function filter() {
            var needle = search.value.trim().toLowerCase();
            Object.keys(rows).forEach(function(key) {
                var row = rows[key];
                row.tr.classList.toggle('d-none', (missing.checked && row.value !== '') || (needle !== '' && row.text.indexOf(needle) === -1));
            });
        }

        // A translation is HTML to the panel and text here: every cell is filled as text, never as markup.
        function load(code) {
            return request('translation_keys&lang=' + encodeURIComponent(code), {}).then(function(d) {
                if (!d || d.result !== true) {
                    failed(d);
                    return;
                }
                lang = code;
                english = d.english;
                rows = {};
                dirty = {};
                var fragment = document.createDocumentFragment();
                Object.keys(d.english).forEach(function(key) {
                    var value = typeof d.values[key] === 'string' ? d.values[key] : '';
                    var tr = document.createElement('tr');
                    var name = document.createElement('code');
                    name.textContent = key;
                    var input = document.createElement('input');
                    input.type = 'text';
                    input.className = 'form-control form-control-sm';
                    input.dir = 'auto';
                    input.value = value;
                    input.setAttribute('data-key', key);
                    [name, document.createTextNode(d.english[key]), input].forEach(function(node) {
                        var td = document.createElement('td');
                        td.className = 'text-break';
                        td.appendChild(node);
                        tr.appendChild(td);
                    });
                    fragment.appendChild(tr);
                    rows[key] = {
                        tr: tr,
                        input: input,
                        value: value,
                        text: (key + '\n' + d.english[key] + '\n' + value).toLowerCase()
                    };
                });
                tbody.textContent = '';
                tbody.appendChild(fragment);
                document.getElementById('translation-editor-lang').textContent = code === 'exyu' ? 'BA_SRB_CRO' : code;
                editor.classList.remove('d-none');
                filter();
                showUnsaved();
            }).catch(function() {
                failed(null);
            });
        }

        document.querySelectorAll('[data-translation-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (unsaved() && !window.confirm(text.discard)) {
                    return;
                }
                load(btn.getAttribute('data-translation-edit')).then(function() {
                    editor.scrollIntoView({
                        behavior: 'smooth'
                    });
                });
            });
        });

        tbody.addEventListener('input', function(e) {
            var key = e.target.getAttribute('data-key');
            if (key === null || !rows[key]) {
                return;
            }
            if (e.target.value === rows[key].value) {
                delete dirty[key];
            } else {
                dirty[key] = e.target.value;
            }
            e.target.classList.toggle('border-warning', key in dirty);
            showUnsaved();
        });
        search.addEventListener('input', filter);
        missing.addEventListener('change', filter);

        // The changed rows in one request; the rows come back as they were stored (sanitised).
        save.addEventListener('click', function() {
            save.disabled = true;
            request('translation_save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    lang: lang,
                    values: dirty
                })
            }).then(function(d) {
                if (!d || d.result !== true) {
                    failed(d);
                    showUnsaved();
                    return;
                }
                toast(text.saved.replace('{count}', d.saved), 'success');
                var count = document.querySelector('[data-translation-count="' + lang + '"]');
                var bar = document.querySelector('[data-translation-bar="' + lang + '"]');
                if (count && bar && total > 0) {
                    count.textContent = d.translated;
                    bar.style.width = (d.translated * 100 / total) + '%';
                }
                return load(lang);
            }).catch(function() {
                failed(null);
                showUnsaved();
            });
        });

        document.getElementById('translation-upload-form').addEventListener('submit', function(e) {
            e.preventDefault();
            request('translation_upload', {
                method: 'POST',
                body: new FormData(this)
            }).then(function(d) {
                if (!d || d.result !== true) {
                    failed(d);
                    return;
                }
                // The list is the server's: a new language is a new row of it.
                toast(text.saved.replace('{count}', d.saved), 'success');
                window.setTimeout(function() {
                    window.location.reload();
                }, 1200);
            }).catch(function() {
                failed(null);
            });
        });

        // Add a language: open the editor on a new (or existing) code, starting
        // from English. The server fills English for every key and whatever the
        // code already translates (nothing, for a new one); Save writes
        // config/lang/<code>.ini. No file is created until the first Save.
        document.getElementById('translation-add-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var input = document.getElementById('translation-add-code');
            var code = input.value.trim();
            if (!input.checkValidity() || code === '') {
                input.reportValidity();
                return;
            }
            if (unsaved() && !window.confirm(text.discard)) {
                return;
            }
            load(code).then(function() {
                editor.scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });

        // Copy English into the cells with no translation yet, as a starting
        // point to edit from; existing translations are left untouched. A value
        // left as the English text is dropped on save (it is no translation),
        // so this only helps the translator see each string in place.
        document.getElementById('translation-copy-english').addEventListener('click', function() {
            Object.keys(rows).forEach(function(key) {
                var row = rows[key];
                if (row.input.value === '' && typeof english[key] === 'string') {
                    row.input.value = english[key];
                    dirty[key] = english[key];
                    row.input.classList.add('border-warning');
                }
            });
            showUnsaved();
        });

        window.addEventListener('beforeunload', function(e) {
            if (unsaved()) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    })();
</script>
</body>

</html>

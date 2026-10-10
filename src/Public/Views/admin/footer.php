<?php

/**
 * Bootstrap 5 admin footer — closes the Vertical Menu shell opened in
 * header.php and loads the core Bootstrap 5 script set.
 *
 * Every admin page's footer (LayoutRenderer::renderFooter('admin')); modal
 * and setup pages get a bare shell ($xmBare). Views call
 * LayoutRenderer::renderFooter('admin') at their end, then append their own page
 * <script> and close </body></html> themselves.
 *
 * Only the layout-critical vendors load here (jQuery, Popper, Bootstrap, Waves,
 * PerfectScrollbar, Hammer, menu.js, main.js) plus the per-page vendor bundles
 * from vendors.php. Pages initialise their own plugins.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\AdminHelpers;

if (count(get_included_files()) == 1) {
    exit();
}

// Setup wizard renders under a branded bare shell (see header.php): it has
// no sidebar / navbar / topbar, so menu.js/main.js and the header-stats poller
// are skipped, but the core scripts + dynamic vendor loader still load.
$xmSetup = !empty($GLOBALS['_SETUP']);
$xmBare  = $xmSetup || isset($_GET['modal']);
?>
<?php if ($xmSetup): /* setup wizard — close the bare centered container */ ?>
    </div><!-- / container-xxl (setup) -->
<?php elseif (isset($_GET['modal'])): /* modal shell — close only the content container */ ?>
    </div><!-- / container-fluid (modal) -->
<?php else: ?>
    </div><!-- / container-xxl (content) -->

    <!-- Footer -->
    <footer class="content-footer footer bg-footer-theme">
        <div class="container-xxl">
            <div class="footer-container d-flex align-items-center justify-content-center py-4 flex-column text-center">
                <div class="text-body">
                    <?= AdminHelpers::getFooter(); ?>
                </div>
            </div>
        </div>
    </footer>
    <!-- / Footer -->

    <div class="content-backdrop fade"></div>
    </div>
    <!-- / Content wrapper -->

    </div>
    <!-- / Layout page -->
    </div>
    <!-- / Layout container -->

    <div class="layout-overlay layout-menu-toggle"></div>
    <div class="drag-target"></div>
    </div>
    <!-- / Layout wrapper -->
<?php endif; /* modal vs full-layout close */ ?>

<!-- Core scripts -->
<script src="assets/vendor/libs/jquery/jquery.js"></script>
<script src="assets/vendor/libs/popper/popper.js"></script>
<script src="assets/vendor/js/bootstrap.js"></script>
<script src="assets/vendor/libs/node-waves/node-waves.js"></script>
<script src="assets/vendor/libs/pickr/pickr.js"></script>
<script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="assets/vendor/libs/hammer/hammer.js"></script>
<?php if (!$xmBare): ?>
    <script src="assets/vendor/js/menu.js"></script>
<?php endif; ?>

<!-- Page vendor scripts — after jQuery, before page init -->
<?php require_once __DIR__ . '/vendors.php'; ?>
<?php xc_newui_vendor_js(xc_newui_vendors_wanted()); ?>

<?php if (!$xmBare): ?>
    <!-- Main (menu init, theme switcher wiring, waves, scrollbars) -->
    <script src="assets/js/main.js"></script>
<?php endif; ?>

<script>
    window.XC_VM = window.XC_VM || {};
    window.XC_VM.Config = {
        jsNavigate: <?= !empty($rSettings['js_navigate']) ? 'true' : 'false'; ?>,
        disableTableResponsive: <?= !empty($rSettings['disable_table_responsive']) ? 'true' : 'false'; ?>,
        i18n: {
            error_occured: <?= json_encode($language::get('error_occured')); ?>
        }
    };

    // DataTable row-action dropdowns live inside a `.table-responsive`
    // (overflow:auto) container that clips them and stacks below the sidebar.
    // Pre-create their Bootstrap Dropdown with Popper strategy:'fixed' (on
    // pointerdown, before Bootstrap's own click handler) so the menu escapes
    // the overflow clip and is positioned against the viewport — Popper then
    // flips/shifts it to stay on screen instead of hiding under the menu.
    document.addEventListener('pointerdown', function(e) {
        var toggle = e.target.closest && e.target.closest('.card-datatable [data-bs-toggle="dropdown"]');
        if (!toggle || !window.bootstrap || bootstrap.Dropdown.getInstance(toggle)) {
            return;
        }
        bootstrap.Dropdown.getOrCreateInstance(toggle, {
            popperConfig: function(cfg) {
                cfg.strategy = 'fixed';
                return cfg;
            }
        });
    }, true);
</script>

<!-- Shared clear-logs modal (used by the topbar #btn-clear-logs on log pages) -->
<div class="modal fade" id="xcClearLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= htmlspecialchars($language::get('clear_logs') ?: 'Clear Logs'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="text-body-secondary small mb-3"><?= htmlspecialchars($language::get('clear_logs_range_hint') ?: 'Leave both dates empty to clear all logs, or set a range to clear only that period.'); ?></p>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" for="xc-clear-from"><?= htmlspecialchars($language::get('from') ?: 'From'); ?></label>
                        <input type="date" class="form-control" id="xc-clear-from">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="xc-clear-to"><?= htmlspecialchars($language::get('to') ?: 'To'); ?></label>
                        <input type="date" class="form-control" id="xc-clear-to">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= htmlspecialchars($language::get('cancel') ?: 'Cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="xc-clear-confirm"><?= htmlspecialchars($language::get('clear_logs') ?: 'Clear Logs'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
    // Wire the per-page topbar action buttons to the page's main DataTable
    // (the shell can't know the page's table id). All handlers are delegated
    // off document so they resolve the table lazily at click time.
    (function() {
        if (!window.jQuery || !jQuery.fn || !jQuery.fn.dataTable) {
            return;
        }
        var $ = jQuery;
        var errText = (window.XC_VM && XC_VM.Config && XC_VM.Config.i18n && XC_VM.Config.i18n.error_occured) || 'An error occurred.';

        // Panel-wide "disable responsive tables" (settings -> disable_table_responsive).
        // Wrap the DataTables constructor so every table inits with responsive:false:
        // columns no longer collapse into an expandable child row on narrow screens; the
        // surrounding .table-responsive wrapper gives a horizontal scrollbar instead. Runs
        // here (footer, after the vendor bundle) before pages initialise their own tables.
        var xcNoResponsive = !!(window.XC_VM && XC_VM.Config && XC_VM.Config.disableTableResponsive);
        if (xcNoResponsive) {
            ['DataTable', 'dataTable'].forEach(function(name) {
                var orig = $.fn[name];
                if (typeof orig !== 'function' || orig.xcNoResponsive) {
                    return;
                }
                var wrapped = function(options) {
                    if (options && typeof options === 'object') {
                        options.responsive = false;
                    }
                    return orig.apply(this, arguments);
                };
                $.extend(wrapped, orig);
                wrapped.xcNoResponsive = true;
                $.fn[name] = wrapped;
            });
        }

        // Header alignment should follow the column's data (DataTables does not
        // propagate a body-cell class like text-center to the <th>). On init,
        // copy each column's body text-align onto its header so centered/right
        // columns don't show a left-aligned header.
        $(document).on('init.dt', function(e, settings) {
            var api = new $.fn.dataTable.Api(settings);
            if (xcNoResponsive) {
                // With responsive off the control (+/-) column is dead weight — hide it.
                api.columns('.control').visible(false);
            }
            api.columns().every(function() {
                var cells = this.nodes(),
                    header = this.header();
                if (!cells.length || !header) {
                    return;
                }
                var align = window.getComputedStyle(cells[0]).textAlign;
                if (align && align !== 'start' && align !== 'left') {
                    header.style.textAlign = align;
                }
            });
        });

        // The page's serverSide table is the visible one whose ajax url is ./table.
        function pickTable() {
            var nodes = $.fn.dataTable.tables({
                visible: true
            });
            var picked = null;
            $(nodes).each(function() {
                var api = new $.fn.dataTable.Api(this),
                    url;
                try {
                    url = api.ajax.url();
                } catch (e) {
                    url = null;
                }
                if (url && /(^|\/)table(\?|$)/.test(url)) {
                    picked = api;
                    return false;
                }
            });
            if (!picked && nodes.length) {
                picked = new $.fn.dataTable.Api(nodes[0]);
            }
            return picked;
        }

        $(document).on('click', '#refreshTable', function() {
            var t = pickTable();
            if (t) {
                t.ajax.reload(null, false);
            }
        });

        $(document).on('click', '#clearFilters', function() {
            document.querySelectorAll('.card-body [id^="filter-"]').forEach(function(el) {
                if (el.tagName === 'SELECT') {
                    el.value = '';
                    el.dispatchEvent(new Event('change', {
                        bubbles: true
                    }));
                } else {
                    el.value = '';
                    el.dispatchEvent(new Event('input', {
                        bubbles: true
                    }));
                    el.dispatchEvent(new Event('keyup', {
                        bubbles: true
                    }));
                }
            });
            var all = document.getElementById('check-all');
            if (all) {
                all.checked = false;
            }
            var t = pickTable();
            if (t) {
                try {
                    t.search('');
                } catch (e) {}
                t.ajax.reload();
            }
        });

        function exportReport(json) {
            var t = pickTable();
            if (!t) {
                return;
            }
            window.location.href = 'api?action=report' + (json ? '&format=json' : '') + '&params=' + encodeURIComponent(JSON.stringify(t.ajax.params()));
        }
        $(document).on('click', '#btn-export-csv', function() {
            exportReport(false);
        });
        $(document).on('click', '#btn-export-json', function() {
            exportReport(true);
        });

        // Clear logs — open the shared modal, remember the log type, confirm.
        var clearType = null;
        $(document).on('click', '#btn-clear-logs', function() {
            clearType = this.getAttribute('data-log-type');
            if (!clearType) {
                return;
            }
            document.getElementById('xc-clear-from').value = '';
            document.getElementById('xc-clear-to').value = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('xcClearLogsModal')).show();
        });
        $(document).on('click', '#xc-clear-confirm', function() {
            if (!clearType) {
                return;
            }
            var btn = this;
            btn.disabled = true;
            var from = document.getElementById('xc-clear-from').value,
                to = document.getElementById('xc-clear-to').value;
            fetch('./api?action=clear_logs&type=' + encodeURIComponent(clearType) + '&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    btn.disabled = false;
                    bootstrap.Modal.getInstance(document.getElementById('xcClearLogsModal')).hide();
                    if (!d || d.result === false) {
                        xcToast(errText, 'error');
                        return;
                    }
                    var t = pickTable();
                    if (t) {
                        t.ajax.reload(null, false);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    xcToast(errText, 'error');
                });
        });

        // Soft live-update: refetch the current page and update each existing row
        // in place (by its id) instead of DataTables' ajax.reload, which clears the
        // tbody and shows the processing overlay. Rows keep their position (no jump)
        // and only changed cells re-render; if the row SET changed (added/removed),
        // fall back to a full reload so paging/counts stay correct.
        function softLive(dt) {
            var url, params;
            try {
                url = dt.ajax.url();
                params = dt.ajax.params();
            } catch (e) {
                return;
            }
            $.getJSON(url, params).done(function(res) {
                if (!res || !Array.isArray(res.data)) {
                    return;
                }
                var idxById = {},
                    count = 0;
                dt.rows().every(function() {
                    var d = this.data();
                    if (d && d.id != null) {
                        idxById[d.id] = this.index();
                        count++;
                    }
                });
                var allMatch = res.data.length === count && res.data.every(function(r) {
                    return idxById[r.id] !== undefined;
                });
                if (!allMatch) {
                    dt.ajax.reload(null, false);
                    return;
                }
                // Only re-render rows whose data actually changed — re-rendering an
                // unchanged row needlessly flickers the table and closes any open
                // row-action dropdown inside it.
                res.data.forEach(function(row) {
                    var idx = idxById[row.id];
                    if (JSON.stringify(dt.row(idx).data()) !== JSON.stringify(row)) {
                        dt.row(idx).data(row);
                    }
                });
            });
        }

        // Live tables auto-refresh every 5s (paused while a modal is open), so
        // status / connections / uptime stay current like the legacy panel.
        var LIVE_PAGES = ['streams', 'lines', 'radios', 'movies', 'ondemand'];
        var xcPage = <?= json_encode(AdminHelpers::getPageName()); ?>;
        if (LIVE_PAGES.indexOf(xcPage) !== -1) {
            setInterval(function() {
                // Pause while a modal is open, or while a row-action dropdown is open
                // (a refresh would re-render its row and close the menu mid-click).
                if (document.querySelector('.modal.show') || document.querySelector('.dropdown-menu.show')) {
                    return;
                }
                var t = pickTable();
                if (t) {
                    softLive(t);
                }
            }, 5000);
        }

        // Shared confirmation modal (SweetAlert2) — views use it for destructive
        // row/bulk actions instead of the native window.confirm. Returns a
        // Promise<boolean>; falls back to window.confirm if Swal is unavailable.
        window.xcConfirm = function(text) {
            if (window.Swal) {
                return Swal.fire({
                    text: text,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'OK',
                    customClass: {
                        confirmButton: 'btn btn-primary',
                        cancelButton: 'btn btn-label-secondary ms-2'
                    },
                    buttonsStyling: false
                }).then(function(r) {
                    return r.isConfirmed;
                });
            }
            return Promise.resolve(window.confirm(text));
        };

        // Shared non-blocking notification — a Bootstrap 5 toast (top-right,
        // auto-dismiss). Views use it for success/error feedback instead of the
        // native window.alert. type: 'success' (default) | 'error' | 'info' | 'warning'.
        window.xcToast = function(msg, type) {
            var cont = document.getElementById('xc-toast-container');
            if (!cont) {
                cont = document.createElement('div');
                cont.id = 'xc-toast-container';
                cont.className = 'toast-container position-fixed top-0 end-0 p-3';
                cont.style.zIndex = '1090';
                document.body.appendChild(cont);
            }
            var bg = type === 'error' ? 'bg-danger' : (type === 'info' ? 'bg-info' : (type === 'warning' ? 'bg-warning' : 'bg-success'));
            var el = document.createElement('div');
            el.className = 'toast align-items-center text-white border-0 ' + bg;
            el.setAttribute('role', 'alert');
            var flex = document.createElement('div');
            flex.className = 'd-flex';
            var body = document.createElement('div');
            body.className = 'toast-body';
            body.textContent = msg;
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'btn-close btn-close-white me-2 m-auto';
            close.setAttribute('data-bs-dismiss', 'toast');
            flex.appendChild(body);
            flex.appendChild(close);
            el.appendChild(flex);
            cont.appendChild(el);
            if (window.bootstrap) {
                var t = new bootstrap.Toast(el, {
                    delay: 3500
                });
                el.addEventListener('hidden.bs.toast', function() {
                    el.remove();
                });
                t.show();
            } else {
                setTimeout(function() {
                    el.remove();
                }, 3500);
            }
        };

        // Shared native HTML5 drag-and-drop reorder for a flat <li> list (replaces the
        // legacy jQuery-Nestable / dual-listbox widgets). Views read the resulting order
        // from the list's <li data-id> attributes on submit.
        window.xcSortable = function(list) {
            if (!list) {
                return;
            }
            var dragEl = null;
            list.addEventListener('dragstart', function(e) {
                if (e.target.closest('button, a, input, select')) {
                    e.preventDefault();
                    return;
                }
                var li = e.target.closest('li');
                if (!li || li.parentNode !== list) {
                    return;
                }
                dragEl = li;
                li.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });
            list.addEventListener('dragend', function() {
                if (dragEl) {
                    dragEl.classList.remove('opacity-50');
                }
                dragEl = null;
            });
            list.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragEl) {
                    return;
                }
                var after = null,
                    closest = -Infinity,
                    items = list.querySelectorAll('li:not(.opacity-50)');
                for (var i = 0; i < items.length; i++) {
                    var box = items[i].getBoundingClientRect();
                    var offset = e.clientY - box.top - box.height / 2;
                    if (offset < 0 && offset > closest) {
                        closest = offset;
                        after = items[i];
                    }
                }
                if (after == null) {
                    list.appendChild(dragEl);
                } else {
                    list.insertBefore(dragEl, after);
                }
            });
        };
    })();

    // An edit form inside an iframe modal posts this after a successful save;
    // close the open modal (its hidden.bs.modal handler reloads the table).
    window.addEventListener('message', function(e) {
        if (e.data !== 'xcModalSaved') {
            return;
        }
        var m = document.querySelector('.modal.show');
        if (m && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(m).hide();
        }
    });
</script>

<?php if (!empty($rSettings['header_stats']) && !$xmSetup): ?>
    <!-- Self-contained header-stats poller -->
    <script>
        (function() {
            var box = document.getElementById('header_stats');
            if (!box) return;
            var nf = new Intl.NumberFormat('en-US');
            var set = function(id, v) {
                var el = document.getElementById(id);
                if (el) el.textContent = nf.format(v);
            };

            function poll() {
                fetch('./api?action=header_stats', {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        set('header_connections', d.total_connections || 0);
                        set('header_users', d.total_users || 0);
                        set('header_streams_up', d.total_running_streams || 0);
                        set('header_streams_down', d.offline_streams || 0);
                        set('header_network_up', Math.floor((d.bytes_sent || 0) / 125000));
                        set('header_network_down', Math.floor((d.bytes_received || 0) / 125000));
                    })
                    .catch(function() {
                        /* keep last values */
                    })
                    .finally(function() {
                        setTimeout(poll, 5000);
                    });
            }
            poll();
        })();
    </script>
<?php endif; ?>

<?php if (!$xmBare && !empty($rSettings['save_restart_logs']) && Authorization::check('adv', 'streams')): ?>
    <!-- Stream event toasts: the stream log's starts, stops and failures, from every server, as they come in
         (the stream log is what Settings → Logs → Stream Restart Logs keeps: off, there is nothing to show) -->
    <script>
        (function() {
            if (!window.xcToast) return;
            var BURST = 5; // more at once (a mass restart): one toast that counts them
            var HOLD = 60000; // how long a missing id is waited for (a row committed after a higher one)
            var TYPES = {
                STREAM_START: 'success',
                STREAM_STOP: 'info',
                STREAM_START_FAIL: 'error',
                STREAM_FAILED: 'error',
                FFMPEG_ERROR: 'error',
                AUDIO_LOSS: 'warning'
            };
            var COUNT = <?= json_encode($language::get('stream_events_count')); ?>;
            // The cursor is this tab's, kept across its page changes. A tab opened from this one
            // copies its sessionStorage, so the key carries an id held in window.name, which a new
            // tab does not inherit: it starts from where the log stands, not from this tab's cursor.
            var tab = /^xcse-[a-z0-9]+$/.test(window.name) ? window.name : null;
            if (!tab && !window.name) {
                tab = 'xcse-' + Math.random().toString(36).slice(2, 10);
                window.name = tab;
            }
            var KEY = tab ? 'xc_stream_events:' + tab : null;
            var state = null; // {after: last id read, holes: {id: first missed (ms)}}
            try {
                state = KEY ? JSON.parse(sessionStorage.getItem(KEY) || 'null') : null;
            } catch (e) {}

            function show(ev) {
                if (ev.length > BURST) {
                    var counts = {},
                        order = [],
                        bad = false;
                    ev.forEach(function(e) {
                        if (!counts[e.label]) {
                            counts[e.label] = 0;
                            order.push(e.label);
                        }
                        counts[e.label]++;
                        bad = bad || TYPES[e.action] === 'error';
                    });
                    xcToast(COUNT.replace('{COUNT}', ev.length) + ': ' + order.map(function(l) {
                        return counts[l] + ' ' + l;
                    }).join(', '), bad ? 'error' : 'info');
                    return;
                }
                ev.forEach(function(e) {
                    xcToast(e.stream + (e.server ? ' (' + e.server + ')' : '') + ': ' + e.label, TYPES[e.action] || 'warning');
                });
            }

            function poll() {
                if (document.hidden) {
                    setTimeout(poll, 5000);
                    return;
                }
                var stop = false;
                var url = './api?action=stream_events';
                if (state) {
                    url += '&after=' + encodeURIComponent(state.after);
                    var ids = Object.keys(state.holes || {});
                    if (ids.length) {
                        url += '&holes=' + ids.join(',');
                    }
                }
                fetch(url, {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        if (typeof d.last !== 'number') {
                            // No permission: nothing to show here. The database not answering: keep
                            // the cursor and ask again.
                            stop = d.result === false;
                            return;
                        }
                        if (state) {
                            show(d.events || []);
                        }
                        var now = Date.now(),
                            holes = {};
                        (d.holes || []).forEach(function(id) {
                            var since = state && state.holes && state.holes[id] ? state.holes[id] : now;
                            if (now - since < HOLD) {
                                holes[id] = since;
                            }
                        });
                        state = {
                            after: d.last,
                            holes: holes
                        };
                        try {
                            if (KEY) {
                                sessionStorage.setItem(KEY, JSON.stringify(state));
                            }
                        } catch (e) {}
                    })
                    .catch(function() {
                        /* try again next time */
                    })
                    .finally(function() {
                        if (!stop) {
                            setTimeout(poll, 5000);
                        }
                    });
            }
            poll();
        })();
    </script>
<?php endif; ?>

<?php if (!empty($rSettings['enable_search']) && !$xmSetup): ?>
    <?php
    $xmSearchI18n = [];
    foreach (['no_results', 'search', 'search_searching', 'search_input_too_short', 'search_error_loading', 'search_action_done', 'error', 'cancel', 'category', 'server', 'connections', 'seasons', 'episodes', 'member_group', 'owner', 'credits', 'users', 'lines', 'expires', 'never', 'last_active', 'restreamer', 'trial', 'fingerprint', 'fingerprint_success', 'fingerprint_fail', 'type', 'activity_id', 'username', 'message', 'size', 'colour', 'position', 'custom_message', 'add_credits', 'amount', 'reason_for_adjustment'] as $xmKey) {
        $xmSearchI18n[$xmKey] = (string) $language::get($xmKey);
    }
    ?>
    <!-- Global quick search: Select2 box rendered client-side from the
         ?action=search JSON (docs/adr/search-json-contract.md). -->
    <style>
        .xc-qs-wrap > .select2-container:not(.select2) { left: auto !important; right: 0; }
        .xc-qs-wrap .select2-dropdown { width: 480px !important; max-width: 92vw; }
        .xc-qs-wrap .select2-results > .select2-results__options { max-height: 70vh; }
        .xc-qs-wrap .select2-selection__rendered { padding-left: 2.4rem !important; }
        .xc-qs-thumb { width: 48px; height: 48px; }
        .xc-qs-thumb-tall { height: 72px; }
        .xc-qs-thumb img { width: 100%; height: 100%; object-fit: cover; }
        .xc-qs-progress { width: 120px; height: 1rem; }
    </style>
    <script>
        window.XC_SEARCH_I18N = <?= json_encode($xmSearchI18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    </script>
    <script src="assets/js/search.js"></script>
<?php endif; ?>

<?php // NOTE: </body></html> are intentionally NOT emitted here. Views call
// LayoutRenderer::renderFooter() and then append their own page scripts
// before closing </body></html> themselves (the legacy convention). 
?>
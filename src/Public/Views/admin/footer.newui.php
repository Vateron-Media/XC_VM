<?php

/**
 * Bootstrap 5 admin footer — closes the Vertical Menu shell opened in
 * header.newui.php and loads the core Bootstrap 5 script set.
 *
 * Reached only for pages opted in via xc_admin_use_newui() (modal/setup pages
 * are routed to the legacy footer.php upstream). Views call
 * renderUnifiedLayoutFooter('admin') at their end, then append their own page
 * <script> and close </body></html> themselves.
 *
 * Only the layout-critical vendors load here (jQuery, Popper, Bootstrap, Waves,
 * PerfectScrollbar, Hammer, menu.js, main.js) plus the per-page vendor bundles
 * from vendors.newui.php. Pages initialise their own plugins.
 */

use XcVm\Core\Util\AdminHelpers;

if (count(get_included_files()) == 1) {
    exit();
}
?>
<?php if (isset($_GET['modal'])): /* modal shell — close only the content container */ ?>
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
<script src="assets/new/vendor/libs/jquery/jquery.js"></script>
<script src="assets/new/vendor/libs/popper/popper.js"></script>
<script src="assets/new/vendor/js/bootstrap.js"></script>
<script src="assets/new/vendor/libs/node-waves/node-waves.js"></script>
<script src="assets/new/vendor/libs/pickr/pickr.js"></script>
<script src="assets/new/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="assets/new/vendor/libs/hammer/hammer.js"></script>
<?php if (!isset($_GET['modal'])): ?>
    <script src="assets/new/vendor/js/menu.js"></script>
<?php endif; ?>

<!-- Page vendor scripts — after jQuery, before page init -->
<?php require_once __DIR__ . '/vendors.newui.php'; ?>
<?php xc_newui_vendor_js(xc_newui_vendors_wanted()); ?>

<?php if (!isset($_GET['modal'])): ?>
    <!-- Main (menu init, theme switcher wiring, waves, scrollbars) -->
    <script src="assets/new/js/main.js"></script>
<?php endif; ?>

<script>
    window.XC_VM = window.XC_VM || {};
    window.XC_VM.Config = {
        jsNavigate: <?= !empty($rSettings['js_navigate']) ? 'true' : 'false'; ?>,
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

        // Header alignment should follow the column's data (DataTables does not
        // propagate a body-cell class like text-center to the <th>). On init,
        // copy each column's body text-align onto its header so centered/right
        // columns don't show a left-aligned header.
        $(document).on('init.dt', function(e, settings) {
            var api = new $.fn.dataTable.Api(settings);
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
                        alert(errText);
                        return;
                    }
                    var t = pickTable();
                    if (t) {
                        t.ajax.reload(null, false);
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    alert(errText);
                });
        });

        // Soft live-update: refetch the current page and update each existing row
        // in place (by its id) instead of DataTables' ajax.reload, which clears the
        // tbody and shows the processing overlay. Rows keep their position (no jump)
        // and only changed cells re-render; if the row SET changed (added/removed),
        // fall back to a full reload so paging/counts stay correct.
        function softLive(dt) {
            var url, params;
            try { url = dt.ajax.url(); params = dt.ajax.params(); } catch (e) { return; }
            $.getJSON(url, params).done(function(res) {
                if (!res || !Array.isArray(res.data)) { return; }
                var idxById = {}, count = 0;
                dt.rows().every(function() {
                    var d = this.data();
                    if (d && d.id != null) { idxById[d.id] = this.index(); count++; }
                });
                var allMatch = res.data.length === count && res.data.every(function(r) { return idxById[r.id] !== undefined; });
                if (!allMatch) { dt.ajax.reload(null, false); return; }
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
        var xcPage = <?= json_encode(\XcVm\Core\Util\AdminHelpers::getPageName()); ?>;
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
                var t = new bootstrap.Toast(el, { delay: 3500 });
                el.addEventListener('hidden.bs.toast', function() { el.remove(); });
                t.show();
            } else {
                setTimeout(function() { el.remove(); }, 3500);
            }
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

<?php if (!empty($rSettings['header_stats'])): ?>
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
                        setTimeout(poll, 1000);
                    });
            }
            poll();
        })();
    </script>
<?php endif; ?>

<?php // NOTE: </body></html> are intentionally NOT emitted here. Views call
// renderUnifiedLayoutFooter() and then append their own page scripts
// before closing </body></html> themselves (the legacy convention). 
?>
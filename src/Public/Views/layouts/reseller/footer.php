<?php

/**
 * Bootstrap 5 reseller footer — closes the Vertical Menu shell opened in
 * reseller/header.php and loads the core Bootstrap 5 script set.
 *
 * Mirrors admin/footer.php: only the layout-critical vendors load here
 * (jQuery, Popper, Bootstrap, Waves, PerfectScrollbar, Hammer, menu.js, main.js)
 * plus the per-page vendor bundles resolved from the shared admin/vendors.php
 * registry. Pages initialise their own plugins.
 *
 * Reached for every reseller page (all migrated to the Bootstrap 5 shell).
 * Views call renderUnifiedLayoutFooter('reseller') at their end, then append their
 * own page <script> and close </body></html> themselves (same convention as admin).
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
<script src="assets/vendor/libs/jquery/jquery.js"></script>
<script src="assets/vendor/libs/popper/popper.js"></script>
<script src="assets/vendor/js/bootstrap.js"></script>
<script src="assets/vendor/libs/node-waves/node-waves.js"></script>
<script src="assets/vendor/libs/pickr/pickr.js"></script>
<script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="assets/vendor/libs/hammer/hammer.js"></script>
<?php if (!isset($_GET['modal'])): ?>
    <script src="assets/vendor/js/menu.js"></script>
<?php endif; ?>

<!-- Page vendor scripts — after jQuery, before page init. The vendor registry is
     shared with the admin scope so both surfaces resolve one vendor map. -->
<?php require_once dirname(__DIR__, 2) . '/admin/vendors.php'; ?>
<?php xc_newui_vendor_js(xc_newui_vendors_wanted()); ?>

<?php if (!isset($_GET['modal'])): ?>
    <!-- Main (menu init, theme switcher wiring, waves, scrollbars) -->
    <script src="assets/js/main.js"></script>
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
    // (overflow:auto) container that clips them. Pre-create their Bootstrap
    // Dropdown with Popper strategy:'fixed' (on pointerdown, before Bootstrap's
    // own click handler) so the menu escapes the overflow clip and is positioned
    // against the viewport.
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

<script>
    // Shared front-end helpers for reseller pages — identical contract to the
    // admin footer so migrated reseller views can rely on the same globals.
    (function() {
        // Header cell alignment should follow the column's data (DataTables does
        // not propagate a body-cell class like text-center to the <th>). Copy each
        // column's body text-align onto its header on init.
        if (window.jQuery && jQuery.fn && jQuery.fn.dataTable) {
            jQuery(document).on('init.dt', function(e, settings) {
                var api = new jQuery.fn.dataTable.Api(settings);
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
        }

        // Shared confirmation modal (SweetAlert2). Returns a Promise<boolean>;
        // falls back to window.confirm if Swal is unavailable.
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
        // auto-dismiss). type: 'success' (default) | 'error' | 'info' | 'warning'.
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

        // Shared native HTML5 drag-and-drop reorder for a flat <li> list. Views
        // read the resulting order from the list's <li data-id> attributes.
        window.xcSortable = function(list) {
            if (!list) { return; }
            var dragEl = null;
            list.addEventListener('dragstart', function(e) {
                if (e.target.closest('button, a, input, select')) { e.preventDefault(); return; }
                var li = e.target.closest('li');
                if (!li || li.parentNode !== list) { return; }
                dragEl = li;
                li.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });
            list.addEventListener('dragend', function() {
                if (dragEl) { dragEl.classList.remove('opacity-50'); }
                dragEl = null;
            });
            list.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragEl) { return; }
                var after = null, closest = -Infinity, items = list.querySelectorAll('li:not(.opacity-50)');
                for (var i = 0; i < items.length; i++) {
                    var box = items[i].getBoundingClientRect();
                    var offset = e.clientY - box.top - box.height / 2;
                    if (offset < 0 && offset > closest) { closest = offset; after = items[i]; }
                }
                if (after == null) { list.appendChild(dragEl); }
                else { list.insertBefore(dragEl, after); }
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

<?php if (!isset($_GET['modal']) && !empty($rSettings['header_stats'])): ?>
    <!-- Self-contained reseller header-stats + credits poller.
         header_stats → #header_connections / #header_users (1s cadence);
         stats        → #owner_credits (reseller owner balance). -->
    <script>
        (function() {
            var box = document.getElementById('header_stats');
            var credits = document.getElementById('owner_credits');
            if (!box && !credits) { return; }
            var nf = new Intl.NumberFormat('en-US');
            var set = function(id, v) {
                var el = document.getElementById(id);
                if (el) el.textContent = nf.format(v);
            };

            function pollStats() {
                fetch('./api?action=header_stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        set('header_connections', d.total_connections || 0);
                        set('header_users', d.total_users || 0);
                    })
                    .catch(function() { /* keep last values */ })
                    .finally(function() { setTimeout(pollStats, 1000); });
            }

            function pollCredits() {
                if (!credits) { return; }
                fetch('./api?action=stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.owner_credits != null) { credits.textContent = nf.format(d.owner_credits); }
                    })
                    .catch(function() { /* keep last value */ })
                    .finally(function() { setTimeout(pollCredits, 10000); });
            }

            if (box) { pollStats(); }
            pollCredits();
        })();
    </script>
<?php endif; ?>

<?php // NOTE: </body></html> are intentionally NOT emitted here. Views call
// renderUnifiedLayoutFooter() and then append their own page scripts
// before closing </body></html> themselves (the legacy convention).
?>

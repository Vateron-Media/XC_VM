<?php

/**
 * Vuexy (Bootstrap 5) admin footer — closes the Vertical Menu shell opened in
 * header.vuexy.php and loads the core Vuexy script set.
 *
 * Reached only for pages opted in via xc_admin_use_vuexy() (modal/setup pages
 * are routed to the legacy footer.php upstream). Views call
 * renderUnifiedLayoutFooter('admin') at their end, then append their own page
 * <script> and close </body></html> themselves.
 *
 * Only the layout-critical vendors load here (jQuery, Popper, Bootstrap, Waves,
 * PerfectScrollbar, Hammer, menu.js, main.js) plus the per-page vendor bundles
 * from vendors.vuexy.php. Pages initialise their own plugins.
 */

use XcVm\Core\Util\AdminHelpers;

if (count(get_included_files()) == 1) {
    exit();
}
?>
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

    <!-- Core scripts -->
    <script src="assets/new/vendor/libs/jquery/jquery.js"></script>
    <script src="assets/new/vendor/libs/popper/popper.js"></script>
    <script src="assets/new/vendor/js/bootstrap.js"></script>
    <script src="assets/new/vendor/libs/node-waves/node-waves.js"></script>
    <script src="assets/new/vendor/libs/pickr/pickr.js"></script>
    <script src="assets/new/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="assets/new/vendor/libs/hammer/hammer.js"></script>
    <script src="assets/new/vendor/js/menu.js"></script>

    <!-- Page vendor scripts — after jQuery, before page init -->
    <?php require_once __DIR__ . '/vendors.vuexy.php'; ?>
    <?php xc_vuexy_vendor_js(xc_vuexy_vendors_wanted()); ?>

    <!-- Main (menu init, theme switcher wiring, waves, scrollbars) -->
    <script src="assets/new/js/main.js"></script>

    <script>
        window.XC_VM = window.XC_VM || {};
        window.XC_VM.Config = {
            jsNavigate: <?= !empty($rSettings['js_navigate']) ? 'true' : 'false'; ?>,
            i18n: {
                error_occured: <?= json_encode($language::get('error_occured')); ?>
            }
        };
    </script>

    <?php if (!empty($rSettings['header_stats'])): ?>
        <!-- Self-contained header-stats poller -->
        <script>
            (function () {
                var box = document.getElementById('header_stats');
                if (!box) return;
                var nf = new Intl.NumberFormat('en-US');
                var set = function (id, v) { var el = document.getElementById(id); if (el) el.textContent = nf.format(v); };
                function poll() {
                    fetch('./api?action=header_stats', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function (r) { return r.json(); })
                        .then(function (d) {
                            set('header_connections', d.total_connections || 0);
                            set('header_users', d.total_users || 0);
                            set('header_streams_up', d.total_running_streams || 0);
                            set('header_streams_down', d.offline_streams || 0);
                            set('header_network_up', Math.floor((d.bytes_sent || 0) / 125000));
                            set('header_network_down', Math.floor((d.bytes_received || 0) / 125000));
                        })
                        .catch(function () { /* keep last values */ })
                        .finally(function () { setTimeout(poll, 1000); });
                }
                poll();
            })();
        </script>
    <?php endif; ?>

    <?php // NOTE: </body></html> are intentionally NOT emitted here. Views call
          // renderUnifiedLayoutFooter() and then append their own page scripts
          // before closing </body></html> themselves (the legacy convention). ?>

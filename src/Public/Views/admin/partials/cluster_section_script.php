<?php /* Cluster section scripts for Manage Servers; included after the layout footer so jQuery/DataTables are loaded. Reads $language and $rCutAny from the body include. */ ?>
<script>
    (function() {
        // The confirmations, delegated: the row menus' forms are moved by DataTables' paging.
        var rConfirms = {
            strip: <?= json_encode($language::get('cluster_strip_credentials_confirm')); ?>,
            revoke: <?= json_encode($language::get('cluster_revoke_confirm')); ?>
        };
        document.addEventListener('submit', function(e) {
            var f = e.target;
            var rText = null;
            if (f.classList.contains('js-cluster-confirm')) {
                if (e.submitter && e.submitter.value === 'unfence') {
                    return;
                }
                rText = f.getAttribute('data-confirm');
            } else if (f.classList.contains('js-cluster-strip')) {
                rText = rConfirms.strip;
            } else if (f.classList.contains('js-cluster-revoke')) {
                rText = rConfirms.revoke;
            }
            if (rText !== null && !confirm(rText)) {
                e.preventDefault();
            }
        });

        var $ = window.jQuery;
        if ($ && $.fn.DataTable) {
            var rTooltips = function(rTable) {
                if (window.bootstrap) {
                    document.querySelectorAll(rTable + ' [data-bs-toggle="tooltip"]').forEach(function(el) {
                        if (!el._tt) {
                            el._tt = new bootstrap.Tooltip(el);
                        }
                    });
                }
            };
            $('#cluster-nodes-table').DataTable({
                order: [[1, 'asc']],
                columnDefs: [{ orderable: false, targets: [3, 4, 10, 11, 12, 13, 14] }],
                layout: { topStart: 'pageLength', topEnd: 'search' },
                language: { emptyTable: <?= json_encode($language::get('cluster_no_nodes')); ?> },
                drawCallback: function() {
                    rTooltips('#cluster-nodes-table');
                }
            });
            if ($('#cluster-audit-table').length) {
                $('#cluster-audit-table').DataTable({
                    order: [[0, 'desc']],
                    pageLength: 10,
                    layout: { topStart: 'pageLength', topEnd: 'search' },
                    language: { emptyTable: <?= json_encode($language::get('cluster_audit_none')); ?> }
                });
            }
        }
        <?php if ($rCutAny): ?>
        // A guided cutover is running: show its progress again shortly (a GET, never the last POST again).
        setTimeout(function() {
            window.location.href = window.location.pathname + window.location.search;
        }, 15000);
        <?php endif; ?>
    })();
</script>

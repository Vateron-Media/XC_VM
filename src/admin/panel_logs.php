<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Panel Errors';
include 'header.php';
?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Panel Errors</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['date']; ?></th>
                                    <th class="text-center"><?php echo $_['server']; ?></th>
                                    <th class="text-center"><?php echo $_['type']; ?></th>
                                    <th><?php echo $_['error']; ?></th>
                                    <th>Line</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
    function sendErrors(rConfirm = false) {
        if (!rConfirm) {
            new jBox("Confirm", {
                confirmButton: "Download",
                cancelButton: "Cancel",
                content: "Downloading error logs will remove them from your system. Do you want to download them now?",
                confirm: function() {
                    sendErrors(true);
                }
            }).open();
        } else {
            $.getJSON("./api?action=download_panel_logs", function(data) {
                const dataToSave = data.data;

                // Create a Blob object with data in JSON format
                const blob = new Blob([JSON.stringify(dataToSave, null, 2)], {
                    type: 'application/json'
                });

                // Create URL for Blob
                const url = URL.createObjectURL(blob);

                // Create temporary download link
                const a = document.createElement('a');
                a.href = url;
                a.download = 'panel_logs.json'; // File name
                document.body.appendChild(a);

                // Initiate download
                a.click();

                // Remove link and release URL
                document.body.removeChild(a);
                URL.revokeObjectURL(url);

                if (data.result === true) {
                    $.toast("Error logs loaded.");
                } else {
                    $.toast("<?php echo $_['error_occured']; ?>");
                }
            });
        }
    }

    $(document).ready(function() {
        $("#datatable").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {
                bindHref();
                refreshTooltips();
            },
            responsive: false,
            processing: true,
            serverSide: true,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "panel_logs";
                }
            },
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 1, 2]
            }],
            order: [
                [0, "desc"]
            ]
        });
        $("#datatable").css("width", "100%");
        $("#btn-download-log").click(function() {
            sendErrors();
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
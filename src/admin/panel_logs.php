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
	<?php
		echo '        ' . "\r\n" . '        function sendErrors(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Send",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Sending error logs will clear them from your system. Do you want to send them now?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        sendErrors(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                $.getJSON("./api?action=send_panel_logs", function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        $.toast("Error logs have been sent for review.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "panel_logs";' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,2]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n" . '            $("#btn-send-xc_vm").click(function() {' . "\r\n" . '                sendErrors();' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
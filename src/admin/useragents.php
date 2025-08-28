<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Blocked User-Agents';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Blocked User-Agents</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    ';
	echo $_['useragent_success'];
	echo '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n\t\t\t\t\t\t" . '<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>User-Agent</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Exact Match</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t";

foreach (getUserAgents() as $rUserAgent) {
	echo "\t\t\t\t\t\t\t\t" . '<tr id="ua-';
	echo intval($rUserAgent['id']);
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo intval($rUserAgent['id']);
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rUserAgent['user_agent'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t\t";

	if ($rUserAgent['exact_match']) {
		echo "\t\t\t\t\t\t\t\t\t\t" . '<i class="text-success fas fa-square"></i>' . "\n\t\t\t\t\t\t\t\t\t\t";
	} else {
		echo "\t\t\t\t\t\t\t\t\t\t" . '<i class="text-secondary fas fa-square"></i>' . "\n\t\t\t\t\t\t\t\t\t\t";
	}

	echo "\t\t\t\t\t\t\t\t\t" . '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="btn-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a href="./useragent?id=';
	echo intval($rUserAgent['id']);
	echo '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(';
	echo intval($rUserAgent['id']);
	echo ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</td>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this user-agent?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=useragent&sub=" + rType + "&ua_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("User-agent successfully deleted.");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                order: [[ 1, "asc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>

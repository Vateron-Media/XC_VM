<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Watch Folder';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Watch Folder</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    The folder is now being watched. It will be scanned during the next Watch Folder run.' . "\n" . '                </div>' . "\n\t\t\t\t";
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n\t\t\t\t\t\t" . '<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n" . '                                    <th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Type</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Server Name</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Directory</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Last Run</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t";

foreach (getWatchFolders() as $rFolder) {
	if (0 < $rFolder['last_run']) {
		$rDate = date('Y-m-d H:i:s', $rFolder['last_run']);
	} else {
		$rDate = 'Never';
	}

	echo "\t\t\t\t\t\t\t\t" . '<tr id="folder-';
	echo intval($rFolder['id']);
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo intval($rFolder['id']);
	echo '</td>' . "\n" . '                                    <td class="text-center">' . "\n" . '                                        ';

	if ($rFolder['active']) {
		echo '                                        <i class="text-success fas fa-square"></i>' . "\n" . '                                        ';
	} else {
		echo '                                        <i class="text-secondary fas fa-square"></i>' . "\n" . '                                        ';
	}

	echo '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo array('movie' => 'Movies', 'series' => 'Series')[$rFolder['type']];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rServers[$rFolder['server_id']]['server_name'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rFolder['directory'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rDate;
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="btn-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a href="./watch_add?id=';
	echo intval($rFolder['id']);
	echo '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>' . "\n" . '                                            <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(';
	echo intval($rFolder['id']);
	echo ", 'force');\"><i class=\"mdi mdi-refresh\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(';
	echo intval($rFolder['id']);
	echo ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</td>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';

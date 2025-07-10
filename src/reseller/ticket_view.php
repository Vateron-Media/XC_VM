<?php

include 'session.php';
include 'functions.php';

if (checkResellerPermissions()) {
} else {
	goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && ($rTicketInfo = getTicket(CoreUtilities::$rRequest['id']))) {
} else {
	goHome();
}

if (hasPermissions('user', $rTicketInfo['member_id'])) {
	if ($rUserInfo['id'] != $rTicketInfo['member_id']) {
		$db->query('UPDATE `tickets` SET `admin_read` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['id']);
	} else {
		$db->query('UPDATE `tickets` SET `user_read` = 1 WHERE `id` = ?;', CoreUtilities::$rRequest['id']);
	}

	$_TITLE = 'View Ticket';
	include 'header.php';
	echo '<div class="wrapper boxed-layout-ext">' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
	include 'topbar.php';
	echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">';
	echo $rTicketInfo['title'];
	echo '</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="timeline" dir="ltr">' . "\r\n\t\t\t\t\t";

	foreach ($rTicketInfo['replies'] as $rReply) {
		echo "\t\t\t\t\t" . '<article class="timeline-item';

		if ($rReply['admin_reply']) {
		} else {
			echo ' timeline-item-left';
		}

		echo '">' . "\r\n\t\t\t\t\t\t" . '<div class="timeline-desk">' . "\r\n\t\t\t\t\t\t\t" . '<div class="timeline-box">' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="arrow-alt"></span>' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="timeline-icon"><i class="mdi mdi-adjust"></i></span>' . "\r\n\t\t\t\t\t\t\t\t" . '<h4 class="mt-0 font-16">';

		if (!$rReply['admin_reply']) {
			echo $rTicketInfo['user']['username'];
		} else {
			echo 'Owner';
		}

		echo '</h4>' . "\r\n\t\t\t\t\t\t\t\t" . '<p class="text-muted"><small>';
		echo date('Y-m-d H:i', $rReply['date']);
		echo '</small></p>' . "\r\n\t\t\t\t\t\t\t\t" . '<p class="mb-0">';
		echo $rReply['message'];
		echo '</p>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '</article>' . "\r\n\t\t\t\t\t";
	}
	echo "\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
	include 'footer.php';
} else {
	exit();
}

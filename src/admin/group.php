<?php







include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {


	goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rGroup = getMemberGroup(CoreUtilities::$rRequest['id']))) {
} else {
	goHome();
}

$rGroupIDs = $rPackageIDs = array();

if (!isset($rGroup)) {
} else {
	$db->query("SELECT `id` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rGroup['group_id']);

	foreach ($db->get_rows() as $rRow) {
		$rPackageIDs[] = $rRow['id'];
	}
	$rGroupIDs = json_decode($rGroup['subresellers'], true);
	$rNotice = html_entity_decode($rGroup['notice_html']);
	$rNotice = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $rNotice);
	$rNotice = preg_replace('#</*\\w+:\\w[^>]*+>#i', '', $rNotice);
	$rNotice = str_replace(array('&amp;', '&lt;', '&gt;'), array('&amp;amp;', '&amp;lt;', '&amp;gt;'), $rNotice);
	$rNotice = preg_replace('/(&#*\\w+)[\\x00-\\x20]+;/u', '$1;', $rNotice);
	$rNotice = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $rNotice);
	$rNotice = html_entity_decode($rNotice, ENT_COMPAT, 'UTF-8');
	$rNotice = preg_replace("#(<[^>]+?[\\x00-\\x20\"'])(?:on|xmlns)[^>]*+[>\\b]?#iu", '$1>', $rNotice);
	$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=[\\x00-\\x20]*([`'\"]*)[\\x00-\\x20]*j[\\x00-\\x20]*a[\\x00-\\x20]*v[\\x00-\\x20]*a[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2nojavascript...', $rNotice);
	$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*v[\\x00-\\x20]*b[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2novbscript...', $rNotice);
	$rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*-moz-binding[\\x00-\\x20]*:#u", '$1=$2nomozbinding...', $rNotice);
	$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?expression[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
	$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?behaviour[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
	$rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:*[^>]*+>#iu", '$1>', $rNotice);
}

$_TITLE = 'Group';
include 'header.php';
echo '<div class="wrapper boxed-layout"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rGroup)) {
	echo $_['edit_group'];
} else {
	echo $_['add_group'];
}

echo '</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\n\t\t\t\t\t\t\t";

if (!isset($rGroup)) {
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
	echo $rGroup['group_id'];
	echo '" />' . "\n\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="permissions_selected" id="permissions_selected" value="" />' . "\n" . '                            <input type="hidden" name="packages_selected" id="packages_selected" value="" />' . "\n" . '                            <input type="hidden" name="groups_selected" id="groups_selected" value="" />' . "\n" . '                            <input type="hidden" name="notice_html" id="notice_html" value="" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#group-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $_['details'];
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    <li class="nav-item" id="package_tab">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#packages" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-package mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Packages</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item" id="reseller_tab">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#reseller" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-badge-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Permissions</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    <li class="nav-item" id="subreseller_tab">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#subreseller" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-multiple-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Subresellers</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n" . '                                    <li class="nav-item" id="notice_tab">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#notice" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-note mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Dashboard</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";

if (isset($rGroup) && !$rGroup['can_delete']) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item"  id="admin_tab">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#permissions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-badge-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['admin_permissions'];

	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="group-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="group_name">';
echo $_['group_name'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="group_name" name="group_name" value="';

if (!isset($rGroup)) {
} else {
	echo htmlspecialchars($rGroup['group_name']);
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="is_admin">';
echo $_['is_admin'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="is_admin" id="is_admin" type="checkbox" ';








if (!isset($rGroup)) {
} else {
	if (!$rGroup['is_admin']) {
	} else {
		echo 'checked ';
	}

	if ($rGroup['can_delete']) {
	} else {
		echo 'disabled ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="is_reseller">';
echo $_['is_reseller'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="is_reseller" id="is_reseller" type="checkbox" ';

if (!isset($rGroup)) {
} else {
	if (!$rGroup['is_reseller']) {
	} else {
		echo 'checked ';
	}

	if ($rGroup['can_delete']) {
	} else {
		echo 'disabled ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';



if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    <div class="tab-pane" id="packages">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-packages" class="table table-striped table-borderless mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['id'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['package_name'];
echo '</th>' . "\n" . '                                                                <th class="text-center">';
echo $_['trial'];
echo '</th>' . "\n" . '                                                                <th class="text-center">';
echo $_['official'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (getPackages() as $rPackage) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr';

	if (!in_array($rPackage['id'], $rPackageIDs)) {
	} else {
		echo " class='selected selectedfilter ui-selected'";
	}

	echo '>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rPackage['id'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rPackage['package_name'];
	echo '</td>' . "\n" . '                                                                <td class="text-center">' . "\n" . '                                                                    ';

	if ($rPackage['is_trial']) {
		echo "                                                                    <i class='text-success mdi mdi-circle'></i>" . "\n" . '                                                                    ';
	} else {
		echo "                                                                    <i class='text-secondary mdi mdi-circle'></i>" . "\n" . '                                                                    ';
	}

	echo '                                                                </td>' . "\n" . '                                                                <td class="text-center">' . "\n" . '                                                                    ';

	if ($rPackage['is_official']) {
		echo "                                                                    <i class='text-success mdi mdi-circle'></i>" . "\n" . '                                                                    ';
	} else {
		echo "                                                                    <i class='text-secondary mdi mdi-circle'></i>" . "\n" . '                                                                    ';
	}

	echo '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n" . '                                                <a href="javascript: void(0);" onClick="togglePackages()" class="btn btn-info">Toggle Packages</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';

if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="reseller">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="sub-header">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";
echo $_['permissions_info'];
echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '</p>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="total_allowed_gen_trials">';
echo $_['allowed_trials'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="total_allowed_gen_trials" name="total_allowed_gen_trials" value="';

if (isset($rGroup)) {
	echo intval($rGroup['total_allowed_gen_trials']);
} else {
	echo '0';
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="total_allowed_gen_in">';
echo $_['allowed_trials_in'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="total_allowed_gen_in" id="total_allowed_gen_in" class="form-control select2" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('Day', 'Month') as $rOption) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

	if (!isset($rGroup)) {
	} else {
		if ($rGroup['total_allowed_gen_in'] != strtolower($rOption)) {
		} else {
			echo 'selected ';
		}
	}

	echo 'value="';
	echo strtolower($rOption);
	echo '">';
	echo $rOption;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="minimum_trial_credits">';
echo $_['minimum_credit_for_trials'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="minimum_trial_credits" name="minimum_trial_credits" value="';

if (isset($rGroup)) {
	echo intval($rGroup['minimum_trial_credits']);
} else {
	echo '0';
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="create_sub_resellers_price">';
echo $_['subreseller_price'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="create_sub_resellers_price" name="create_sub_resellers_price" value="';

if (isset($rGroup)) {
	echo htmlspecialchars($rGroup['create_sub_resellers_price']);
} else {
	echo '0';
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="minimum_username_length">Minimum Username Length</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="minimum_username_length" name="minimum_username_length" value="';


if (isset($rGroup)) {
	echo intval($rGroup['minimum_username_length']);
} else {
	echo '8';
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="minimum_password_length">Minimum Password Length</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="minimum_password_length" name="minimum_password_length" value="';

if (isset($rGroup)) {
	echo htmlspecialchars($rGroup['minimum_password_length']);
} else {
	echo '8';
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="allow_restrictions">Allow Line Restrictions</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_restrictions" id="allow_restrictions" type="checkbox" ';


if (isset($rGroup)) {
	if (!$rGroup['allow_restrictions']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#414d5f"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="allow_change_bouquets">Allow Bouquet Editing</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_change_bouquets" id="allow_change_bouquets" type="checkbox" ';


if (!isset($rGroup)) {
} else {
	if (!$rGroup['allow_change_bouquets']) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#414d5f"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="delete_users">';
echo $_['can_delete_users'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="delete_users" id="delete_users" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['delete_users']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="allow_download">Show M3U Download</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_download" id="allow_download" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['allow_download']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="can_view_vod">';
echo $_['can_view_vod_streams'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="can_view_vod" id="can_view_vod" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['can_view_vod']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="reseller_client_connection_logs">';
echo $_['can_view_live_connections'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="reseller_client_connection_logs" id="reseller_client_connection_logs" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['reseller_client_connection_logs']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="allow_change_username">Change Usernames</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_change_username" id="allow_change_username" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['allow_change_username']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="allow_change_password">Change Passwords</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_change_password" id="allow_change_password" type="checkbox" ';

if (isset($rGroup)) {
	if (!$rGroup['allow_change_password']) {
	} else {
		echo 'checked ';
	}
} else {
	echo 'checked ';
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';

if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    <div class="tab-pane" id="subreseller">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-10 col-form-label" for="create_sub_resellers">Allow Subreseller Creation</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2 mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="create_sub_resellers" id="create_sub_resellers" type="checkbox" ';

if (!isset($rGroup)) {
} else {
	if (!$rGroup['create_sub_resellers']) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-groups" class="table table-striped table-borderless mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
echo $_['id'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>Group Name</th>' . "\n" . '                                                                <th class="text-center">Allowed Subresellers</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (getMemberGroups() as $rSubGroup) {
	if ($rSubGroup['is_reseller'] && !(isset($rGroup) && $rGroup['group_id'] == $rSubGroup['group_id'])) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr';

		if (!in_array($rSubGroup['group_id'], $rGroupIDs)) {
		} else {
			echo " class='selected selectedfilter ui-selected'";
		}

		echo '>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
		echo $rSubGroup['group_id'];
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
		echo $rSubGroup['group_name'];
		echo '</td>' . "\n" . '                                                                <td class="text-center">' . "\n" . '                                                                    ';

		if ($rSubGroup['create_sub_resellers']) {
			echo "                                                                    <i class='text-success mdi mdi-circle'></i>" . "\n" . '                                                                    ';
		} else {
			echo "                                                                    <i class='text-secondary mdi mdi-circle'></i>" . "\n" . '                                                                    ';
		}

		echo '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';

if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                    <div class="tab-pane" id="notice">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="sub-header">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "Display a notice for this group when they've logged into the Reseller Dashboard." . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</p>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <div id="notice-editor" style="height: 400px;">';
echo $rNotice;
echo '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';

if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="permissions">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<p class="sub-header">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";
echo $_['advanced_permissions_info'];
echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '</p>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-permissions" class="table table-borderless mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead class="bg-light">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th style="display:none;">';
echo $_['id'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['permission'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['description'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rAdvPermissions as $rPermission) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr';

	if (!(isset($rGroup) && in_array($rPermission[0], json_decode($rGroup['allowed_pages'], true)))) {
	} else {
		echo " class='selected selectedfilter ui-selected'";
	}

	echo '>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td style="display:none;">';
	echo $rPermission[0];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rPermission[1];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rPermission[2];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n" . '                                            <li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="selectAll()" class="btn btn-info">';
echo $_['select_all'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" onClick="selectNone()" class="btn btn-warning">';
echo $_['deselect_all'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_group" type="submit" class="btn btn-primary" value="';

if (isset($rGroup)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}



echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
<script id="scripts">
	var resizeObserver = new ResizeObserver(entries => $(window).scroll());
	$(document).ready(function() {
		resizeObserver.observe(document.body)
		$("form").attr('autocomplete', 'off');
		$(document).keypress(function(event) {
			if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
		});
		$.fn.dataTable.ext.errMode = 'none';
		var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
		elems.forEach(function(html) {
			var switchery = new Switchery(html, {
				'color': '#414d5f'
			});
			window.rSwitches[$(html).attr("id")] = switchery;
		});
		setTimeout(pingSession, 30000);
		<?php if (!$rMobile && $rSettings['header_stats']): ?>
			headerStats();
		<?php endif; ?>
		bindHref();
		refreshTooltips();
		$(window).scroll(function() {
			if ($(this).scrollTop() > 200) {
				if ($(document).height() > $(window).height()) {
					$('#scrollToBottom').fadeOut();
				}
				$('#scrollToTop').fadeIn();
			} else {
				$('#scrollToTop').fadeOut();
				if ($(document).height() > $(window).height()) {
					$('#scrollToBottom').fadeIn();
				} else {
					$('#scrollToBottom').hide();
				}
			}
		});
		$("#scrollToTop").unbind("click");
		$('#scrollToTop').click(function() {
			$('html, body').animate({
				scrollTop: 0
			}, 800);
			return false;
		});
		$("#scrollToBottom").unbind("click");
		$('#scrollToBottom').click(function() {
			$('html, body').animate({
				scrollTop: $(document).height()
			}, 800);
			return false;
		});
		$(window).scroll();
		$(".nextb").unbind("click");
		$(".nextb").click(function() {
			var rPos = 0;
			var rActive = null;
			$(".nav .nav-item").each(function() {
				if ($(this).find(".nav-link").hasClass("active")) {
					rActive = rPos;
				}
				if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
					$(this).find(".nav-link").trigger("click");
					return false;
				}
				rPos += 1;
			});
		});
		$(".prevb").unbind("click");
		$(".prevb").click(function() {
			var rPos = 0;
			var rActive = null;
			$($(".nav .nav-item").get().reverse()).each(function() {
				if ($(this).find(".nav-link").hasClass("active")) {
					rActive = rPos;
				}
				if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
					$(this).find(".nav-link").trigger("click");
					return false;
				}
				rPos += 1;
			});
		});
		(function($) {
			$.fn.inputFilter = function(inputFilter) {
				return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
					if (inputFilter(this.value)) {
						this.oldValue = this.value;
						this.oldSelectionStart = this.selectionStart;
						this.oldSelectionEnd = this.selectionEnd;
					} else if (this.hasOwnProperty("oldValue")) {
						this.value = this.oldValue;
						this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
					}
				});
			};
		}(jQuery));
		<?php if ($rSettings['js_navigate']): ?>
			$(".navigation-menu li").mouseenter(function() {
				$(this).find(".submenu").show();
			});
			delParam("status");
			$(window).on("popstate", function() {
				if (window.rRealURL) {
					if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
						navigate(window.location.href.split("/").reverse()[0]);
					}
				}
			});
		<?php endif; ?>
		$(document).keydown(function(e) {
			if (e.keyCode == 16) {
				window.rShiftHeld = true;
			}
		});
		$(document).keyup(function(e) {
			if (e.keyCode == 16) {
				window.rShiftHeld = false;
			}
		});
		document.onselectstart = function() {
			if (window.rShiftHeld) {
				return false;
			}
		}
	});

	function togglePackages() {
		$("#datatable-packages tr").each(function() {
			if ($(this).hasClass('selected')) {
				$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
			} else {
				$(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
			}
		});
	}

	function selectAll() {
		$("#datatable-permissions tr").each(function() {
			if (!$(this).hasClass('selected')) {
				$(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
			}
		});
	}

	function selectNone() {
		$("#datatable-permissions tr").each(function() {
			if ($(this).hasClass('selected')) {
				$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
			}
		});
	}

	function deselectGroups() {
		$("#datatable-groups tr").each(function() {
			if ($(this).hasClass('selected')) {
				$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
			}
		});
	}

	function validatePermissions() {
		if ($("#is_admin").is(":checked")) {
			$("#admin_tab").show();
		} else {
			$("#admin_tab").hide();
		}
		if ($("#is_reseller").is(":checked")) {
			$("#reseller_tab").show();
			$("#subreseller_tab").show();
			$("#package_tab").show();
			$("#notice_tab").show();
		} else {
			$("#reseller_tab").hide();
			$("#subreseller_tab").show();
			$("#package_tab").hide();
			$("#notice_tab").hide();
			deselectGroups();
		}
	}
	$(document).ready(function() {
		$('select.select2').select2({
			width: '100%'
		})
		$("#datatable-permissions").DataTable({
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			order: [
				[1, "asc"]
			],
			paging: false,
			bInfo: false,
			searching: false
		});
		$("#datatable-permissions").selectable({
			filter: 'tr',
			selected: function(event, ui) {
				if ($(ui.selected).hasClass('selectedfilter')) {
					$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
				} else {
					$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
				}
			}
		});
		$("#datatable-permissions_wrapper").css("width", "100%");
		$("#datatable-permissions").css("width", "100%");
		$("#total_allowed_gen_trials").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#minimum_trial_credits").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#create_sub_resellers_price").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#minimum_username_length").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#minimum_password_length").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#is_admin").on("change", function() {
			validatePermissions();
		});
		$("#is_reseller").on("change", function() {
			validatePermissions();
		});
		$("#datatable-packages").DataTable({
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}],
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			paging: false,
			bInfo: false,
			searching: false
		});
		$("#datatable-packages").selectable({
			filter: 'tr',
			selected: function(event, ui) {
				if ($(ui.selected).hasClass('selectedfilter')) {
					$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
				} else {
					$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
				}
			}
		});
		$("#datatable-groups").DataTable({
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}],
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			paging: false,
			bInfo: false,
			searching: false
		});
		$("#datatable-groups").selectable({
			filter: 'tr',
			selected: function(event, ui) {
				if (!window.rSwitches["create_sub_resellers"].isChecked()) {
					return;
				}
				if ($(ui.selected).hasClass('selectedfilter')) {
					$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
				} else {
					$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
				}
			}
		});
		$("#create_sub_resellers").change(function() {
			if (!window.rSwitches["create_sub_resellers"].isChecked()) {
				deselectGroups();
			}
		});
		validatePermissions();
		var quill = new Quill("#notice-editor", {
			theme: "snow",
			modules: {
				toolbar: [
					[{
						font: []
					}],
					["bold", "italic", "underline", "strike"],
					[{
						color: []
					}],
					[{
						header: [!1, 1, 2, 3, 4, 5, 6]
					}],
					[{
						list: "ordered"
					}, {
						list: "bullet"
					}, {
						indent: "-1"
					}, {
						indent: "+1"
					}],
					["direction", {
						align: []
					}]
				]
			}
		});
		$("form").submit(function(e) {
			e.preventDefault();
			var rPermissions = [];
			$("#datatable-permissions tr.selected").each(function() {
				rPermissions.push($(this).find("td:eq(0)").text());
			});
			$("#permissions_selected").val(JSON.stringify(rPermissions));
			var rPackages = [];
			$("#datatable-packages tr.selected").each(function() {
				rPackages.push($(this).find("td:eq(0)").text());
			});
			$("#packages_selected").val(JSON.stringify(rPackages));
			var rGroups = [];
			$("#datatable-groups tr.selected").each(function() {
				rGroups.push($(this).find("td:eq(0)").text());
			});
			$("#groups_selected").val(JSON.stringify(rGroups));
			$(':input[type="submit"]').prop('disabled', true);
			$("#notice_html").val(quill.root.innerHTML);
			submitForm(window.rCurrentPage, new FormData($("form")[0]));
		});
	});
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
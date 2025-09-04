<?php include 'session.php';
include 'functions.php';
if (checkPermissions()) {
} else {
    goHome();
}
if (!isset(CoreUtilities::$rRequest['id']) || ($rCode = getCode(CoreUtilities::$rRequest['id']))) {
    $_TITLE = 'Access Code';
    include 'header.php'; ?>
    <div class="wrapper boxed-layout"
        <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
        } else { ?>
        style="display: none;" <?php } ?>>
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title"><?php if (isset($rCode)) {
                                                    echo 'Edit Code';
                                                } else {
                                                    echo 'Add Code';
                                                } ?>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (!(isset($rCode) && getCurrentCode() == $rCode['code'])) {
                    } else { ?>
                        <div class="alert alert-warning" role="alert">
                            You are editing the Access Code you're currently using to access the system. Ensure you have set up
                            another access code before disabling or modifying the access rights of this group.
                        </div>
                    <?php } ?>
                    <div class="card">
                        <div class="card-body">
                            <form action="#" method="POST" data-parsley-validate="">
                                <?php if (!isset($rCode)) {
                                } else { ?>
                                    <input type="hidden" name="edit" value="<?php echo $rCode['id']; ?>" />
                                <?php } ?>
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#code-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#groups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-group mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['groups']; ?></span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#restrictions" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-hazard-lights mr-1"></i>
                                                <span class="d-none d-sm-inline">Restrictions</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="code-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="code">Access Code <i
                                                                title="Enter a secure access code that cannot be guessed. It needs to be at least 8 characters and should be random."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-9 input-group">
                                                            <input type="text" maxlength="16" class="form-control" id="code"
                                                                name="code"
                                                                value="<?php if (isset($rCode)) {
                                                                            echo htmlspecialchars($rCode['code']);
                                                                        } ?>"
                                                                required data-parsley-trigger="change">
                                                            <div class="input-group-append">
                                                                <button class="btn btn-info waves-effect waves-light"
                                                                    onClick="generateCode();" type="button"><i
                                                                        class="mdi mdi-refresh"></i></button>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="type">Access Type <i
                                                                title="Select what access to grant."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-4">
                                                            <select id="type" name="type" class="form-control select2">
                                                                <?php foreach (array('Admin', 'Reseller', 'Ministra', 'Admin API', 'Reseller API', 6 => 'Web Player') as $rID => $rValue) { ?>
                                                                    <option
                                                                        <?php if (isset($rCode) && $rCode['type'] == $rID) {
                                                                            echo 'selected';
                                                                        } ?>
                                                                        value="<?php echo $rID; ?>">
                                                                        <?php echo $rValue; ?>
                                                                    </option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                        <label class="col-md- 3col-form-label"
                                                            for="enabled">Enabled</label>
                                                        <div class="col-md-2">
                                                            <input name="enabled" id="enabled" type="checkbox"
                                                                <?php if (isset($rCode) && $rCode['enabled'] == 1) {
                                                                    echo 'checked';
                                                                } else if (!isset($rCode)) {
                                                                    echo 'checked';
                                                                } ?>
                                                                data-plugin="switchery" class="js-switch"
                                                                data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="nextb list-inline-item float-right">
                                                    <a href="javascript: void(0);"
                                                        class="btn btn-secondary"><?php echo $_['next']; ?></a>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="groups">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <?php foreach (getMemberGroups() as $rGroup) { ?>
                                                            <div class="col-md-6">
                                                                <div class="custom-control custom-checkbox mt-1">
                                                                    <input type="checkbox"
                                                                        class="custom-control-input group-checkbox"
                                                                        id="group-<?php echo $rGroup['group_id']; ?>"
                                                                        data-id="<?php echo $rGroup['group_id']; ?>"
                                                                        name="groups[]"
                                                                        value="<?php echo $rGroup['group_id']; ?>"
                                                                        <?php if (isset($rCode) && in_array($rGroup['group_id'], json_decode($rCode['groups'], true))) {
                                                                            echo ' checked';
                                                                        } ?>>
                                                                    <label class="custom-control-label"
                                                                        for="group-<?php echo $rGroup['group_id']; ?>"><?php echo $rGroup['group_name']; ?></label>
                                                                </div>
                                                            </div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="prevb list-inline-item">
                                                    <a href="javascript: void(0);"
                                                        class="btn btn-secondary"><?php echo $_['prev']; ?></a>
                                                </li>
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="selectAll()"
                                                        class="btn btn-secondary"><?php echo $_['select_all']; ?></a>
                                                    <a href="javascript: void(0);" onClick="selectNone()"
                                                        class="btn btn-secondary"><?php echo $_['deselect_all']; ?></a>
                                                </li>
                                                <li class="nextb list-inline-item float-right">
                                                    <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="restrictions">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="ip_field">Allowed IP
                                                            Addresses <i
                                                                title="Restrict access to specific IP addresses."
                                                                class="tooltip text-secondary far fa-circle"></i></label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" id="ip_field" class="form-control"
                                                                value="">
                                                            <div class="input-group-append">
                                                                <a href="javascript:void(0)" id="add_ip"
                                                                    class="btn btn-primary waves-effect waves-light"><i
                                                                        class="mdi mdi-plus"></i></a>
                                                                <a href="javascript:void(0)" id="remove_ip"
                                                                    class="btn btn-danger waves-effect waves-light"><i
                                                                        class="mdi mdi-close"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="whitelist">&nbsp;</label>
                                                        <div class="col-md-8">
                                                            <select id="whitelist" name="whitelist[]" size="6"
                                                                class="form-control" multiple="multiple">
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <?php if (isset($rCode)) {
                                                foreach (json_decode($rCode['whitelist'], true) as $rIP) { ?>
                                                    <option value="<?php echo $rIP; ?>"><?php echo $rIP; ?></option>
                                            <?php }
                                            } ?>
                                            </select>

                                            <ul class="list-inline wizard mb-0">
                                                <li class="prevb list-inline-item"><a href="javascript: void(0);" class="btn btn-secondary">Previous</a></li>
                                                <li class="list-inline-item float-right"><input name="submit" type="submit" class="btn btn-primary" value="Save"></li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php include 'footer.php';
		echo '        ' . "\r\n\t\t" . 'function generateCode() {' . "\r\n\t\t\t" . "var result           = '';" . "\r\n\t\t\t" . "var characters       = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';" . "\r\n\t\t\t" . 'var charactersLength = characters.length;' . "\r\n\t\t\t" . 'for ( var i = 0; i < 8; i++ ) {' . "\r\n\t\t\t\t" . 'result += characters.charAt(Math.floor(Math.random() * charactersLength));' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$("#code").val(result);' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectAll() {' . "\r\n\t\t\t" . '$(".group-checkbox").each(function() {' . "\r\n\t\t\t\t" . "\$(this).prop('checked', true);" . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function selectNone() {' . "\r\n\t\t\t" . '$(".group-checkbox").each(function() {' . "\r\n\t\t\t\t" . "\$(this).prop('checked', false);" . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . "            \$('.select2').select2({width: '100%'});" . "\r\n\t\t\t" . "\$('#code').keydown(function (e) {" . "\r\n\t\t\t\t" . 'var k = e.which;' . "\r\n\t\t\t\t" . 'var ok = k >= 65 && k <= 90 || // A-Z' . "\r\n\t\t\t\t\t" . 'k >= 96 && k <= 105 || // a-z' . "\r\n\t\t\t\t\t" . 'k >= 35 && k <= 40 || // arrows' . "\r\n\t\t\t\t\t" . 'k == 8 || // Backspaces' . "\r\n\t\t\t\t\t" . '(!e.shiftKey && k >= 48 && k <= 57); // 0-9' . "\r\n\r\n\t\t\t\t" . 'if (!ok) {' . "\r\n\t\t\t\t\t" . 'e.preventDefault();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(\"#whitelist option\").prop('selected', true);" . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#add_ip").click(function() {' . "\r\n\t\t\t\t" . 'if (($("#ip_field").val()) && (isValidIP($("#ip_field").val()))) {' . "\r\n\t\t\t\t\t" . 'var o = new Option($("#ip_field").val(), $("#ip_field").val());' . "\r\n\t\t\t\t\t" . '$("#whitelist").append(o);' . "\r\n\t\t\t\t\t" . '$("#ip_field").val("");' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("Please enter a valid IP address.");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#remove_ip").click(function() {' . "\r\n\t\t\t\t" . "\$('#whitelist option:selected').remove();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t";

		if (!isset($rCode)) {
			echo "\t\t\t" . 'generateCode();' . "\r\n\t\t\t";
		}

		echo "\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
<?php
} else {
    exit();
} ?>
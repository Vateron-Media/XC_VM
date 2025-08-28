<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rType = isset(CoreUtilities::$rRequest['proxy']) ? 1 : 2;

if (isset(CoreUtilities::$rRequest['id'])) {
    if ($rType == 1) {
        $rServerArr = $rProxyServers[intval(CoreUtilities::$rRequest['id'])];
    } else {
        $rServerArr = $rServers[intval(CoreUtilities::$rRequest['id'])];
    }
    if (!$rServerArr) {
        goHome();
    }
}

$_TITLE = $rType == 1 ? 'Install Proxy' : 'Install Server';

include 'header.php'; ?>

<div class="wrapper boxed-layout"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>

    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">
                        <?php if (isset($rServerArr)): ?>
                            <?php if (isset(CoreUtilities::$rRequest['update'])): ?>
                                <?= $rType == 1 ? 'Update Proxy' : 'Update Server'; ?>
                            <?php else: ?>
                                <?= $rType == 1 ? 'Reinstall Proxy' : 'Reinstall Server'; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $rType == 1 ? 'Proxy Installation (not worked)' : 'Server Installation'; ?>
                        <?php endif; ?>
                    </h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <?php if (isset($rServerArr) && $rServerArr['is_main'] == 1): ?>
                            <div class="alert alert-danger" role="alert">
                                This is your main server, you cannot reinstall it from the XC_VM panel. To reinstall this
                                server, please use the installation instructions on the billing panel.
                            </div>
                        <?php else: ?>
                            <form action="#" method="POST" data-parsley-validate="">
                                <?php if (isset($rServerArr)): ?>
                                    <input type="hidden" name="edit" value="<?= $rServerArr['id']; ?>" />
                                <?php endif; ?>
                                <input type="hidden" id="parent_id" name="parent_id" value="" />
                                <input type="hidden" name="type" value="<?= $rType; ?>" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#server-details" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-creation mr-1"></i>
                                                <span class="d-none d-sm-inline"><?= $_['details']; ?></span>
                                            </a>
                                        </li>
                                        <?php if ($rType == 1): ?>
                                            <li class="nav-item">
                                                <a href="#server-coverage" data-toggle="tab"
                                                    class="nav-link rounded-0 pt-2 pb-2">
                                                    <i class="mdi mdi-server mr-1"></i>
                                                    <span class="d-none d-sm-inline">Server Coverage</span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="server-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label"
                                                            for="server_name"><?= $_['server_name']; ?></label>
                                                        <div class="col-md-9">
                                                            <input type="text" class="form-control" id="server_name"
                                                                name="server_name"
                                                                value="<?= isset($rServerArr) ? htmlspecialchars($rServerArr['server_name']) : ''; ?>"
                                                                required data-parsley-trigger="change"
                                                                <?= isset($rServerArr) ? 'readonly' : ''; ?>>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="server_ip">Server
                                                            IP</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="server_ip"
                                                                name="server_ip"
                                                                value="<?= isset($rServerArr) ? htmlspecialchars($rServerArr['server_ip']) : ''; ?>"
                                                                required data-parsley-trigger="change"
                                                                <?= isset($rServerArr) ? 'readonly' : ''; ?>>
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="ssh_port">SSH
                                                            Port</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control text-center"
                                                                id="ssh_port" name="ssh_port" value="22" required
                                                                data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="root_username">SSH
                                                            Username</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="root_username"
                                                                name="root_username" value="root" required
                                                                data-parsley-trigger="change">
                                                        </div>
                                                        <label class="col-md-3 col-form-label" for="root_password">SSH
                                                            Password</label>
                                                        <div class="col-md-3">
                                                            <input type="text" class="form-control" id="root_password"
                                                                name="root_password" value="" required
                                                                data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <?php if ($rType == 1): ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label"
                                                                for="http_broadcast_port">HTTP Port</label>
                                                            <div class="col-md-3">
                                                                <input type="text" class="form-control text-center"
                                                                    id="http_broadcast_port" name="http_broadcast_port"
                                                                    value="80" required data-parsley-trigger="change">
                                                            </div>
                                                            <label class="col-md-3 col-form-label"
                                                                for="https_broadcast_port">HTTPS Port</label>
                                                            <div class="col-md-3">
                                                                <input type="text" class="form-control text-center"
                                                                    id="https_broadcast_port" name="https_broadcast_port"
                                                                    value="443" required data-parsley-trigger="change">
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="update_sysctl">Update
                                                            sysctl.conf</label>
                                                        <div class="col-md-3">
                                                            <input name="update_sysctl" id="update_sysctl" type="checkbox"
                                                                data-plugin="switchery" class="js-switch" checked
                                                                data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                    <?php if ($rType == 1): ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-3 col-form-label" for="use_private_ip">Use
                                                                Private IP</label>
                                                            <div class="col-md-3">
                                                                <input name="use_private_ip" id="use_private_ip" type="checkbox"
                                                                    data-plugin="switchery" class="js-switch"
                                                                    data-color="#039cfd" />
                                                            </div>
                                                        </div>
                                                    <?php endif; ?>
                                                    <?php if (isset($rServerArr) && isset(CoreUtilities::$rRequest['update'])): ?>
                                                        <div class="alert alert-info" role="alert">
                                                            To update your XC_VM core, please enter root SSH details. This will
                                                            reinstall your server with the most up-to-date software.
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="alert alert-warning mb-4" role="alert">
                                                            Installation will begin immediately. You will be alerted of progress
                                                            on the Server View page.
                                                            <?php if (isset($rServerArr)): ?>
                                                                As you are reinstalling the server, it will go offline until the
                                                                installation is complete.
                                                            <?php else: ?>
                                                                With new installations, the file limit is set in the system. A
                                                                reboot is required for this, but you can do it at your own pace.
                                                            <?php endif; ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_server" type="submit" class="btn btn-primary"
                                                        value="<?= $_['install_server']; ?>" />
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo "\t\t" . '$(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n\t\t\t" . '$("#ssh_port").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#rtmp_port").inputFilter(function(value) { return /^\\d*$/.test(value) && (value === "" || parseInt(value) <= 65535); });' . "\r\n\t\t\t" . '$("#http_broadcast_port").inputFilter(function(value) { return /^\\d*$/.test(value) && (value === "" || parseInt(value) <= 65535); });' . "\r\n" . '            $("#https_broadcast_port").inputFilter(function(value) { return /^\\d*$/.test(value) && (value === "" || parseInt(value) <= 65535); });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                ';

		if ($rType != 1) {
		} else {
			echo '                var rServers = [];' . "\r\n\t\t\t\t" . '$("#datatable tr.selected").each(function() {' . "\r\n\t\t\t\t\t" . 'rServers.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t" . '});' . "\r\n" . '                if (rServers.length == 0) {' . "\r\n" . '                    $.toast("Please select at least one server to apply the proxy to.");' . "\r\n" . '                    return;' . "\r\n" . '                }' . "\r\n\t\t\t\t" . '$("#parent_id").val("[" + rServers.join(",") + "]");' . "\r\n" . '                ';
		}

		echo "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n" . '            ';

		if ($rType != 1) {
		} else {
			echo '            $("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'searching: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            ';
		}

		echo "\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
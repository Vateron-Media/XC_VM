<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['id']) || ($rHMAC = getHMACToken(CoreUtilities::$rRequest['id']))) {
    $_TITLE = 'HMAC Key';
    include 'header.php';
?>
    <div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">
                            <?php echo isset($rHMAC) ? 'Edit HMAC Key' : 'Add HMAC Key'; ?>
                        </h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <div class="alert alert-info" role="alert">
                        Use this tool to generate a key you can use to generate HMAC tokens that can access a stream or movie. Visit the XC_VM documentation for more information on how to use it.<br /><br /><strong>Write down the HMAC key as you will not see it again, it will also be encrypted in the database so cannot be extracted.</strong>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <form action="#" method="POST" data-parsley-validate="">
                                <?php if (isset($rHMAC)): ?>
                                    <input type="hidden" name="edit" value="<?php echo $rHMAC['id']; ?>" />
                                <?php endif; ?>
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#hmac-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="hmac-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="notes">Description</label>
                                                        <div class="col-md-9">
                                                            <input type="text" class="form-control" id="notes" name="notes" value="<?php echo isset($rHMAC) ? htmlspecialchars($rHMAC['notes']) : ''; ?>">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-3 col-form-label" for="keygen">HMAC Key</label>
                                                        <div class="col-md-5 input-group">
                                                            <input readonly type="text" maxlength="32" class="form-control" id="keygen" name="keygen" value="<?php echo isset($rHMAC) ? 'HMAC KEY HIDDEN' : ''; ?>" required data-parsley-trigger="change">
                                                            <div class="input-group-append">
                                                                <button class="btn btn-info waves-effect waves-light" onClick="generateCode();" type="button"><i class="mdi mdi-refresh"></i></button>
                                                            </div>
                                                        </div>
                                                        <label class="col-md-2 col-form-label" for="enabled">Enabled</label>
                                                        <div class="col-md-2">
                                                            <input name="enabled" id="enabled" type="checkbox" <?php echo isset($rHMAC) && $rHMAC['enabled'] == 1 ? 'checked' : 'checked'; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_key" type="submit" class="btn btn-primary" value="<?php echo isset($rHMAC) ? $_['edit'] : $_['add']; ?>" />
                                                </li>
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
<?php
    include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function generateCode() {' . "\r\n\t\t\t" . "var result           = '';" . "\r\n\t\t\t" . "var characters       = 'ABCDEF0123456789';" . "\r\n\t\t\t" . 'var charactersLength = characters.length;' . "\r\n\t\t\t" . 'for ( var i = 0; i < 32; i++ ) {' . "\r\n\t\t\t\t" . 'result += characters.charAt(Math.floor(Math.random() * charactersLength));' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$("#keygen").val(result);' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t\t";

		if (!isset($rHMAC)) {
			echo "\t\t\t" . 'generateCode();' . "\r\n\t\t\t";
		}

		echo "\t\t" . '}); ' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
<?php
} else {
    exit();
}

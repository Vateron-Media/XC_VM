<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Bouquet Order';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                        echo ' style="display: none;"';
                                    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['bouquet_order']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS_REPLACE) : ?>
                    <div class="alert alert-success show" role="alert">
                        Bouquet order has taken effect and <strong>all</strong> lines and packages have been modified to utilise the new bouquet order.
                    </div>
                <?php elseif (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success show" role="alert">
                        Bouquet order has taken effect, any <strong>new</strong> lines and packages will use this order.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" id="bouquet_order_array" name="bouquet_order_array" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#order-stream" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-flower-tulip-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['bouquet_order']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="order-stream">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header"><?php echo $_['bouquet_sort_text']; ?></p>
                                                <select multiple id="sort_bouquet" class="form-control" style="min-height:400px;">
                                                    <?php foreach (getBouquets() as $rBouquet) : ?>
                                                        <option value="<?php echo $rBouquet['id']; ?>"><?php echo $rBouquet['bouquet_name']; ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <div class="custom-control custom-checkbox add-margin-top-20">
                                                    <input type="checkbox" class="custom-control-input" name="confirmReplace" id="confirmReplace">
                                                    <label class="custom-control-label" for="confirmReplace">Replace bouquet order for all users and packages retrospectively. This can take a while.</label>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0 add-margin-top-20">
                                            <li class="list-inline-item">
                                                <a href="javascript: void(0);" onClick="MoveUp()" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                <a href="javascript: void(0);" onClick="MoveDown()" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                <a href="javascript: void(0);" onClick="MoveTop()" class="btn btn-pink"><i class="mdi mdi-chevron-triple-up"></i></a>
                                                <a href="javascript: void(0);" onClick="MoveBottom()" class="btn btn-pink"><i class="mdi mdi-chevron-triple-down"></i></a>
                                                <a href="javascript: void(0);" onClick="AtoZ()" class="btn btn-info"><?php echo $_['a_to_z']; ?></a>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <button type="submit" class="btn btn-primary waves-effect waves-light"><?php echo $_['save_changes']; ?></button>
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
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function AtoZ() {' . "\r\n\t\t\t" . '$("#sort_bouquet").append($("#sort_bouquet option").remove().sort(function(a, b) {' . "\r\n\t\t\t\t" . 'var at = $(a).text().toUpperCase(), bt = $(b).text().toUpperCase();' . "\r\n\t\t\t\t" . 'return (at > bt) ? 1 : ((at < bt) ? -1 : 0);' . "\r\n\t\t\t" . '}));' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveUp() {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_bouquet option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'var rPrevious = rSelected.first().prev()[0];' . "\r\n\t\t\t\t" . "if (\$(rPrevious).html() != '') {" . "\r\n\t\t\t\t\t" . 'rSelected.first().prev().before(rSelected);' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveDown() {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_bouquet option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . 'rSelected.last().next().after(rSelected);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveTop() {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_bouquet option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . "rSelected.prependTo(\$('#sort_bouquet'));" . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function MoveBottom() {' . "\r\n\t\t\t" . "var rSelected = \$('#sort_bouquet option:selected');" . "\r\n\t\t\t" . 'if (rSelected.length) {' . "\r\n\t\t\t\t" . "rSelected.appendTo(\$('#sort_bouquet'));" . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('.select2').select2({width: '100%'});" . "\r\n\t\t\t" . '$("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . 'rOrder = [];' . "\r\n\t\t\t\t" . "\$('#sort_bouquet option').each(function() {" . "\r\n\t\t\t\t\t" . 'rOrder.push($(this).val());' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#bouquet_order_array").val(JSON.stringify(rOrder));' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
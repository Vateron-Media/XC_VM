<?php

include 'session.php';
include 'functions.php';

if (!isset(CoreUtilities::$rRequest['id']) || ($rEPGArr = getEPG(CoreUtilities::$rRequest['id']))) {
    $_TITLE = 'EPG';
    include 'header.php';
?>
    <div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                            echo ' style="display: block;"';
                                        } ?>>
        <div class="container-fluid">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title"><?php echo isset($rEPGArr) ? $_['edit'] . ' ' : $_['add'] . ' ';
                                                echo $_['epg']; ?></h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <div class="card">
                        <div class="card-body">
                            <form action="#" method="POST" data-parsley-validate="">
                                <?php if (isset($rEPGArr)) { ?>
                                    <input type="hidden" name="edit" value="<?php echo $rEPGArr['id']; ?>" />
                                <?php } ?>
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#category-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                            </a>
                                        </li>
                                        <?php if (isset($rEPGArr)) { ?>
                                            <li class="nav-item">
                                                <a href="#view-channels" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                    <i class="mdi mdi-play mr-1"></i>
                                                    <span class="d-none d-sm-inline"><?php echo $_['view_channels']; ?></span>
                                                </a>
                                            </li>
                                        <?php } ?>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="category-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="epg_name"><?php echo $_['epg_name']; ?></label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="epg_name" name="epg_name" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['epg_name']) : ''; ?>" required data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="epg_file"><?php echo $_['source']; ?></label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="epg_file" name="epg_file" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['epg_file']) : ''; ?>" required data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="days_keep"><?php echo $_['days_to_keep']; ?></label>
                                                        <div class="col-md-2">
                                                            <input type="text" class="form-control text-center" id="days_keep" name="days_keep" value="<?php echo isset($rEPGArr) ? htmlspecialchars($rEPGArr['days_keep']) : '7'; ?>" required data-parsley-trigger="change">
                                                        </div>
                                                        <label class="col-md-4 col-form-label" for="offset">Minute Offset</label>
                                                        <div class="col-md-2">
                                                            <input type="text" class="form-control text-center" id="offset" name="offset" value="<?php echo isset($rEPGArr) ? intval($rEPGArr['offset']) : '0'; ?>" required data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_epg" type="submit" class="btn btn-primary" value="<?php echo isset($rEPGArr) ? $_['edit'] : $_['add']; ?>" />
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="view-channels">
                                            <div class="row">
                                                <div class="col-12" style="overflow-x:auto;">
                                                    <table id="datatable" class="table dt-responsive nowrap">
                                                        <thead>
                                                            <tr>
                                                                <th><?php echo $_['key']; ?></th>
                                                                <th><?php echo $_['channel_name']; ?></th>
                                                                <th><?php echo $_['languages']; ?></th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <?php $rEPGData = isset($rEPGArr['data']) ? json_decode($rEPGArr['data'], true) : array(); ?>
                                                            <?php foreach ($rEPGData as $rEPGKey => $rEPGRow) { ?>
                                                                <tr>
                                                                    <td><?php echo $rEPGKey; ?></td>
                                                                    <td><?php echo $rEPGRow['display_name']; ?></td>
                                                                    <td><?php echo implode(', ', $rEPGRow['langs']); ?></td>
                                                                </tr>
                                                            <?php } ?>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
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
		echo '        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n\t\t\t\t" . 'bInfo: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#days_keep").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#offset").inputFilter(function(value) { return /^-?[0-9]\\d*(\\.\\d+)?$/.test(value); });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
<?php } else {
    exit();
} ?>
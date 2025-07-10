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
<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rOrderedServers = $rServers;
array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);
$_TITLE = 'Server Order';
include 'header.php';
?>
<div class="wrapper boxed-layout"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Server Order</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Servers have been re-ordered.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body">
                        <div id="basicwizard">
                            <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                <li class="nav-item">
                                    <a href="#servers" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-server mr-1"></i>
                                        <span class="d-none d-sm-inline">Servers</span>
                                    </a>
                                </li>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="servers">
                                    <form action="#" method="POST" id="server_form">
                                        <input type="hidden" id="server_order" name="server_order" value="" />
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To re-order a server, drag it up or down the list using the <i
                                                        class="mdi mdi-view-sequential"></i> icon. Click Save Changes at
                                                    the bottom once finished.<br />Servers will be ordered as below on
                                                    the dashboard, with offline servers moved to the end of the list
                                                    automatically.
                                                </p>
                                                <div class="custom-dd dd" id="server_items">
                                                    <ol class="dd-list">
                                                        <?php foreach ($rOrderedServers as $rServer): ?>
                                                            <li class="dd-item dd3-item server-<?= $rServer['id']; ?>"
                                                                data-id="<?= $rServer['id']; ?>">
                                                                <div class="dd-handle dd3-handle"></div>
                                                                <div class="dd3-content">#<?= $rServer['id']; ?> -
                                                                    <?= $rServer['server_name']; ?>
                                                                    <span style="float:right;"></span>
                                                                </div>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ol>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0 add-margin-top-20">
                                            <li class="list-inline-item float-right">
                                                <button type="submit"
                                                    class="btn btn-primary waves-effect waves-light">Save
                                                    Changes</button>
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
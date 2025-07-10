<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Plex Sync';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Plex Sync</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        The server is now being synced. It will be scanned during the next Plex Sync run.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Plex IP</th>
                                    <th>Server Name</th>
                                    <th>Library</th>
                                    <th class="text-center">Last Run</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getPlexServers() as $rServer) :
                                    $rDate = ($rServer['last_run'] > 0) ? date('Y-m-d H:i:s', $rServer['last_run']) : 'Never';
                                    $rLibraryName = 'Unknown';
                                    foreach (json_decode($rServer['plex_libraries'], true) as $rLibrary) {
                                        if (intval($rLibrary['key']) == intval($rServer['directory'])) {
                                            $rLibraryName = $rLibrary['title'];
                                            break;
                                        }
                                    }
                                    $rServerAdd = is_null($rServer['server_add']) ? 0 : count(json_decode($rServer['server_add'], true));
                                ?>
                                    <tr id="folder-<?= intval($rServer['id']); ?>">
                                        <td class="text-center"><?= intval($rServer['id']); ?></td>
                                        <td class="text-center">
                                            <?php if ($rServer['active']) : ?>
                                                <i class="text-success fas fa-square"></i>
                                            <?php else : ?>
                                                <i class="text-secondary fas fa-square"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= $rServer['plex_ip']; ?></td>
                                        <td>
                                            <?= $rServers[$rServer['server_id']]['server_name'] . ($rServerAdd > 0 ? "&nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . $rServerAdd . "</button>" : ""); ?>
                                        </td>
                                        <td><?= $rLibraryName; ?></td>
                                        <td class="text-center"><?= $rDate; ?></td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="./plex_add?id=<?= intval($rServer['id']); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'force');"><i class="mdi mdi-refresh"></i></button>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
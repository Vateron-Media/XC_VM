<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Stream Providers';
include 'header.php';
?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Stream Providers</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Provider has been added to the database and will be periodically scanned.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th class="text-center">Status</th>
                                    <th>Provider</th>
                                    <th class="text-center">Username</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Streams</th>
                                    <th class="text-center">Movies</th>
                                    <th class="text-center">Series</th>
                                    <th class="text-center">Expires</th>
                                    <th class="text-center">Last Changed</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getStreamProviders() as $rProvider):
                                    $rData = json_decode($rProvider['data'], true);
                                    $rStatus = !$rProvider['enabled'] ? '<i class="text-secondary fas fa-square"></i>' : ($rProvider['enabled'] && $rProvider['status'] ? '<i class="text-success fas fa-square"></i>' : '<i class="text-danger fas fa-square"></i>');
                                ?>
                                    <tr id="provider-<?php echo $rProvider['id']; ?>">
                                        <td class="text-center"><?php echo $rProvider['id']; ?></td>
                                        <td class="text-center"><?php echo $rStatus; ?></td>
                                        <td><?php echo $rProvider['name']; ?><br /><small><?php echo $rProvider['ip']; ?>:<?php echo $rProvider['port']; ?></small></td>
                                        <td class="text-center"><?php echo $rProvider['username']; ?></td>
                                        <td class="text-center">
                                            <?php
                                            if ($rData['max_connections'] > 0) {
                                                $rColour = $rData['max_connections'] * 0.75 < $rData['active_connections'] ? 'danger' : ($rData['max_connections'] * 0.5 < $rData['active_connections'] ? 'warning' : 'success');
                                                echo '<a href="streams?search=' . urlencode(strtolower($rProvider['ip'])) . '&filter=1"><button type="button" class="btn btn-' . $rColour . ' btn-xs waves-effect waves-light">' . number_format($rData['active_connections'], 0) . ' / ' . number_format($rData['max_connections'], 0) . '</button></a>';
                                            } else {
                                                echo '<a href="streams?search=' . urlencode(strtolower($rProvider['ip'])) . '&filter=1"><button type="button" class="btn btn-success btn-xs waves-effect waves-light">' . number_format($rData['active_connections'], 0) . ' / &infin;</button></a>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['streams'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['streams'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['movies'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['movies'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['series'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['series'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $rData['exp_date'] == -1 ? 'Unknown' : ($rData['exp_date'] ? date('Y-m-d', $rData['exp_date']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rData['exp_date']) . '</small>' : 'Never'); ?>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $rProvider['last_changed'] ? date('Y-m-d', $rProvider['last_changed']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rProvider['last_changed']) . '</small>' : 'Never'; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="provider?id=<?php echo $rProvider['id']; ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil"></i></button></a>
                                                <button type="button" title="Force Reload" class="tooltip btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProvider['id']; ?>, 'reload');"><i class="mdi mdi-refresh"></i></button>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProvider['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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
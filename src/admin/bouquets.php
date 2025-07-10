<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (checkPermissions()): ?>
<?php else: goHome(); ?>
<?php endif; ?>

<?php $rBouquets = getBouquets(); ?>
<?php $_TITLE = 'Bouquets'; ?>
<?php include 'header.php'; ?>

<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest'): ?><?php else: ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['bouquets']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)): ?>
                <?php else: ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Bouquet has been added / modified.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['bouquet_name']; ?></th>
                                    <th class="text-center"><?php echo $_['streams']; ?></th>
                                    <th class="text-center"><?php echo $_['movies']; ?></th>
                                    <th class="text-center"><?php echo $_['series']; ?></th>
                                    <th class="text-center"><?php echo $_['stations']; ?></th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rBouquets as $rBouquet): ?>
                                    <tr id="bouquet-<?php echo intval($rBouquet['id']); ?>">
                                        <td class="text-center"><?php echo intval($rBouquet['id']); ?></td>
                                        <td><?php echo htmlspecialchars($rBouquet['bouquet_name']); ?></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(count(json_decode($rBouquet['bouquet_channels'], true)), 0); ?></button></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(count(json_decode($rBouquet['bouquet_movies'], true)), 0); ?></button></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(count(json_decode($rBouquet['bouquet_series'], true)), 0); ?></button></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?php echo number_format(count(json_decode($rBouquet['bouquet_radios'], true)), 0); ?></button></td>
                                        <td class="text-center">
                                            <?php if (hasPermissions('adv', 'edit_bouquet')): ?>
                                                <div class="btn-group">
                                                    <a href="./bouquet_sort?id=<?php echo intval($rBouquet['id']); ?>"><button type="button" title="<?php echo $_['reorder_bouquet']; ?>" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-format-line-spacing"></i></button></a>
                                                    <a href="./bouquet?id=<?php echo intval($rBouquet['id']); ?>"><button type="button" title="<?php echo $_['edit_bouquet']; ?>" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                    <a href="./bouquet?duplicate=<?php echo intval($rBouquet['id']); ?>"><button type="button" title="Duuplicate Bouquet" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-content-copy"></i></button></a>
                                                    <button type="button" title="<?php echo $_['delete_bouquet']; ?>" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(<?php echo intval($rBouquet['id']); ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                                </div>
                                                <?php else: ?>--
                                            <?php endif; ?>
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
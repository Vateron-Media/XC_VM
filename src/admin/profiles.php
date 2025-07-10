<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$_TITLE = 'Transcoding Profiles';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['transcode_profiles']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
                } else { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['profile_success']; ?>
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['profile_name']; ?></th>
                                    <th class="text-center">GPU</th>
                                    <th class="text-center">Video</th>
                                    <th class="text-center">Audio</th>
                                    <th class="text-center">Resolution</th>
                                    <th class="text-center">Logo</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getTranscodeProfiles() as $rProfile) {
                                    $rProfileOptions = json_decode($rProfile['profile_options'], true); ?>
                                    <tr id="profile-<?php echo $rProfile['profile_id']; ?>">
                                        <td class="text-center"><?php echo $rProfile['profile_id']; ?></td>
                                        <td><?php echo $rProfile['profile_name']; ?></td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions['gpu'])) {
                                                echo '<i class="text-success fas fa-square"></i>';
                                            } else {
                                                echo '<i class="text-secondary fas fa-square"></i>';
                                            } ?>
                                        </td>
                                        <td class="text-center"><?php echo ($rProfileOptions['-vcodec'] ?: 'None'); ?></td>
                                        <td class="text-center"><?php echo ($rProfileOptions['-acodec'] ?: 'None'); ?></td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions['gpu'])) {
                                                echo ($rProfileOptions['gpu']['resize'] ?: str_replace(':', 'x', $rProfileOptions[9]['val']));
                                            } else {
                                                echo str_replace(':', 'x', $rProfileOptions[9]['val']);
                                            } ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions[16])) {
                                                echo '<i class="text-success fas fa-square"></i>';
                                            } else {
                                                echo '<i class="text-secondary fas fa-square"></i>';
                                            } ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (hasPermissions('adv', 'edit_tprofile')) { ?>
                                                <div class="btn-group">
                                                    <a href="./profile?id=<?php echo $rProfile['profile_id']; ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProfile['profile_id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                                </div>
                                            <?php } else {
                                                echo '--';
                                            } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>
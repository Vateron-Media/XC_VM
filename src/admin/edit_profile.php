<?php include 'session.php';
include 'functions.php';
$_TITLE = 'Edit Profile';
include 'header.php'; ?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                        echo ' style="display: none;"';
                                    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <h4 class="page-title"><?php echo ucfirst($rUserInfo['username']); ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['profile_success']; ?>
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form onSubmit="return false;" action="#" method="POST" data-parsley-validate="">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#user-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="user-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="password"><?php echo $_['change_password']; ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="password" name="password" value="">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="email"><?php echo $_['email_address']; ?></label>
                                                    <div class="col-md-8">
                                                        <input type="email" id="email" class="form-control" name="email" value="<?php echo htmlspecialchars($rUserInfo['email']); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="timezone">Timezone</label>
                                                    <div class="col-md-8">
                                                        <select name="timezone" id="timezone" class="form-control" data-toggle="select2">
                                                            <option <?php if (empty($rUserInfo['timezone'])) {
                                                                        echo 'selected ';
                                                                    } ?>value="">Server Default</option>
                                                            <?php foreach ($rTimeZones as $rValue => $rText) { ?>
                                                                <option <?php if ($rUserInfo['timezone'] == $rValue) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rValue; ?>"><?php echo $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="theme">System Theme</label>
                                                    <div class="col-md-8">
                                                        <select name="theme" id="theme" class="form-control" data-toggle="select2">
                                                            <?php foreach ($rThemes as $rValue => $rArray) { ?>
                                                                <option <?php if ($rUserInfo['theme'] == $rValue) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rValue; ?>"><?php echo $rArray['name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="hue">Topbar Theme</label>
                                                    <div class="col-md-8">
                                                        <select name="hue" id="hue" class="form-control" data-toggle="select2">
                                                            <?php foreach ($rHues as $rValue => $rText) { ?>
                                                                <option <?php if ($rUserInfo['hue'] == $rValue) {
                                                                            echo 'selected ';
                                                                        } ?>value="<?php echo $rValue; ?>"><?php echo $rText; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <?php foreach (getcodes() as $rCode) {
                                                    if ($rCode['type'] == 3 && in_array($rUserInfo['member_group_id'], json_decode($rCode['groups'], true))) { ?>
                                                        <div class="form-group row mb-4">
                                                            <label class="col-md-4 col-form-label" for="api_key">API Key <i title="API URL:<br/><?php echo CoreUtilities::$rServers[SERVER_ID]['site_url'] . $rCode['code']; ?>/" class="tooltip text-secondary far fa-circle"></i></label>
                                                            <div class="col-md-8 input-group">
                                                                <input readonly type="text" maxlength="32" class="form-control" id="api_key" name="api_key" value="<?php echo htmlspecialchars($rUserInfo['api_key']); ?>">
                                                                <div class="input-group-append">
                                                                    <button class="btn btn-danger waves-effect waves-light" onClick="clearCode();" type="button"><i class="mdi mdi-close"></i></button>
                                                                    <button class="btn btn-info waves-effect waves-light" onClick="generateCode();" type="button"><i class="mdi mdi-refresh"></i></button>
                                                                </div>
                                                            </div>
                                                        </div>
                                                <?php break;
                                                    }
                                                } ?>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="submit_profile" type="submit" class="btn btn-primary" value="<?php echo $_['save_profile']; ?>" />
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
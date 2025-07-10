<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Stream Tools';
include 'header.php';
?>
<div class="wrapper boxed-layout"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Stream Tools</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS) && $_STATUS == 1) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Stream DNS replacement was successful.
                    </div>
                <?php } elseif (isset($_STATUS) && $_STATUS == 2) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Streams have been moved from the source server to the replacement server.
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <div id="basicwizard">
                            <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                <li class="nav-item">
                                    <a href="#dns-replacement" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-dns mr-1"></i>
                                        <span class="d-none d-sm-inline">DNS Replacement</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#move-streams" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-folder-move mr-1"></i>
                                        <span class="d-none d-sm-inline">Move Streams</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#url-decrypt" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-lock-open mr-1"></i>
                                        <span class="d-none d-sm-inline">URL Decrypt</span>
                                    </a>
                                </li>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="dns-replacement">
                                    <form action="#" method="POST" id="dns_form" data-parsley-validate="">
                                        <input type="hidden" name="replace_dns" value="true" />
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    The DNS replacement tool can be used to replace the domain name of a
                                                    stream with another. It can replace any text within a stream, such
                                                    as username and password.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="old_dns">Old DNS</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="old_dns"
                                                            name="old_dns" value="" placeholder="http://example.com"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="new_dns">New DNS</label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="new_dns"
                                                            name="new_dns" value="" placeholder="http://newdns.com"
                                                            required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="replace_dns" id="replace_dns" type="submit"
                                                    class="btn btn-primary" value="Replace DNS" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="move-streams">
                                    <form action="#" method="POST" id="move_form" data-parsley-validate="">
                                        <input type="hidden" name="move_streams" value="true" />
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    This tool will allow you to move all streams from one server to
                                                    another.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="content_type">Content</label>
                                                    <div class="col-md-8">
                                                        <select name="content_type" id="content_type"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach (array('Everything', 'Live Streams', 3 => 'Created Channels', 2 => 'Movies', 5 => 'TV Shows', 4 => 'Radio Stations') as $rID => $rType) { ?>
                                                                <option value="<?php echo $rID; ?>"><?php echo $rType; ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="source_server">Source
                                                        Server</label>
                                                    <div class="col-md-8">
                                                        <select name="source_server" id="source_server"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option value="<?php echo intval($rServer['id']); ?>">
                                                                    <?php echo htmlspecialchars($rServer['server_name']); ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="replacement_server">Replacement Server</label>
                                                    <div class="col-md-8">
                                                        <select name="replacement_server" id="replacement_server"
                                                            class="form-control select2" data-toggle="select2">
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option value="<?php echo intval($rServer['id']); ?>">
                                                                    <?php echo htmlspecialchars($rServer['server_name']); ?>
                                                                </option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="move_streams" id="move_streams" type="submit"
                                                    class="btn btn-primary" value="Move Streams" />
                                            </li>
                                        </ul>
                                    </form>
                                </div>
                                <div class="tab-pane" id="url-decrypt">
                                    <form action="#" method="POST" id="decrypt_form" data-parsley-validate="">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    This tool will allow you to decrypt URLs or parts of an URL that
                                                    your service encrypted.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="encrypted_text">Encrypted Text</label>
                                                    <div class="col-md-8">
                                                        <textarea class="form-control" id="encrypted_text"
                                                            name="encrypted_text" rows="8"></textarea>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label"
                                                        for="decrypted_text">Decrypted Text</label>
                                                    <div class="col-md-8">
                                                        <textarea class="form-control" id="decrypted_text"
                                                            name="decrypted_text" rows="8" readonly></textarea>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="list-inline-item float-right">
                                                <input name="url_decrypt" id="url_decrypt" type="button"
                                                    onClick="decryptText();" class="btn btn-primary"
                                                    value="Decrypt Text" />
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
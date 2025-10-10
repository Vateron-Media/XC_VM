<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Backups';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <form action="#" method="POST">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">Backups</h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Backup settings successfully updated!
                        </div>
                    <?php else : ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Backups will not contain any logs, restoring a database will therefore clear all of your logs.<br />If you want to keep your logs you should manually create your own backups.
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#backups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-backup-restore mr-1"></i>
                                            <span class="d-none d-sm-inline">Backups</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="backups">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="automatic_backups">Automatic Backups <i title="Generate full SQL backups periodically." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <select name="automatic_backups" id="automatic_backups" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('off' => 'Off', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly') as $rType => $rText) : ?>
                                                                <option value="<?php echo $rType; ?>" <?php if ($rSettings['automatic_backups'] == $rType) echo ' selected'; ?>><?php echo $rText; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="backups_to_keep">Local Backups to Keep <i title="Enter 0 for unlimited. Oldest will be deleted." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="backups_to_keep" name="backups_to_keep" value="<?php echo htmlspecialchars($rSettings['backups_to_keep'] ?: 0); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="dropbox_remote">Dropbox Backups <i title="Once a local backup is generated, upload it to Dropbox for safe remote storage." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="dropbox_remote" id="dropbox_remote" type="checkbox" <?php if ($rSettings['dropbox_remote']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="dropbox_keep">Dropbox Backups to Keep <i title="Enter 0 for unlimited. Oldest will be deleted." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="dropbox_keep" name="dropbox_keep" value="<?php echo htmlspecialchars($rSettings['dropbox_keep'] ?: 0); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="dropbox_token">Dropbox Token <i title="Create an application in the Dropbox Developer section, set the expiration to never then generate a token." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="dropbox_token" name="dropbox_token" value="<?php echo htmlspecialchars($rSettings['dropbox_token']); ?>">
                                                    </div>
                                                </div>
                                                <?php if (strlen($rSettings['dropbox_token']) > 0 && !checkRemote()) : ?>
                                                    <div class="alert alert-danger text-center" role="alert">
                                                        Could not access your Dropbox through the API key provided above. Please generate a new one or check that your key is correct.
                                                    </div>
                                                <?php endif; ?>
                                                <table class="table table-striped table-borderless mb-0" id="datatable-backups">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center">Date</th>
                                                            <th class="text-center">Filename</th>
                                                            <th class="text-center">Filesize</th>
                                                            <th class="text-center">Local</th>
                                                            <th class="text-center">Dropbox</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:30px;">
                                            <li class="list-inline-item">
                                                <button id="create_backup" onClick="api('', 'backup')" class="btn btn-info">Create Backup Now</button>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <input name="submit_settings" type="submit" class="btn btn-primary" value="Save Changes" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
    var resizeObserver = new ResizeObserver(entries => $(window).scroll());
    $(document).ready(function() {
        resizeObserver.observe(document.body)
        $("form").attr('autocomplete', 'off');
        $(document).keypress(function(event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
        });
        $.fn.dataTable.ext.errMode = 'none';
        var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
        elems.forEach(function(html) {
            var switchery = new Switchery(html, {
                'color': '#414d5f'
            });
            window.rSwitches[$(html).attr("id")] = switchery;
        });
        setTimeout(pingSession, 30000);
        <?php if (!$rMobile && $rSettings['header_stats']): ?>
            headerStats();
        <?php endif; ?>
        bindHref();
        refreshTooltips();
        $(window).scroll(function() {
            if ($(this).scrollTop() > 200) {
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeOut();
                }
                $('#scrollToTop').fadeIn();
            } else {
                $('#scrollToTop').fadeOut();
                if ($(document).height() > $(window).height()) {
                    $('#scrollToBottom').fadeIn();
                } else {
                    $('#scrollToBottom').hide();
                }
            }
        });
        $("#scrollToTop").unbind("click");
        $('#scrollToTop').click(function() {
            $('html, body').animate({
                scrollTop: 0
            }, 800);
            return false;
        });
        $("#scrollToBottom").unbind("click");
        $('#scrollToBottom').click(function() {
            $('html, body').animate({
                scrollTop: $(document).height()
            }, 800);
            return false;
        });
        $(window).scroll();
        $(".nextb").unbind("click");
        $(".nextb").click(function() {
            var rPos = 0;
            var rActive = null;
            $(".nav .nav-item").each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        $(".prevb").unbind("click");
        $(".prevb").click(function() {
            var rPos = 0;
            var rActive = null;
            $($(".nav .nav-item").get().reverse()).each(function() {
                if ($(this).find(".nav-link").hasClass("active")) {
                    rActive = rPos;
                }
                if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
                    $(this).find(".nav-link").trigger("click");
                    return false;
                }
                rPos += 1;
            });
        });
        (function($) {
            $.fn.inputFilter = function(inputFilter) {
                return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
                    if (inputFilter(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty("oldValue")) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
        }(jQuery));
        <?php if ($rSettings['js_navigate']): ?>
            $(".navigation-menu li").mouseenter(function() {
                $(this).find(".submenu").show();
            });
            delParam("status");
            $(window).on("popstate", function() {
                if (window.rRealURL) {
                    if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
                        navigate(window.location.href.split("/").reverse()[0]);
                    }
                }
            });
        <?php endif; ?>
        $(document).keydown(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = true;
            }
        });
        $(document).keyup(function(e) {
            if (e.keyCode == 16) {
                window.rShiftHeld = false;
            }
        });
        document.onselectstart = function() {
            if (window.rShiftHeld) {
                return false;
            }
        }
    });

    function api(rID, rType, rConfirm = false) {
        if ((rType == "delete") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Delete",
                cancelButton: "Cancel",
                content: "Are you sure you want to delete this backup?",
                confirm: function() {
                    api(rID, rType, true);
                }
            }).open();
        } else if ((rType == "restore") && (!rConfirm)) {
            new jBox("Confirm", {
                confirmButton: "Restore",
                cancelButton: "Cancel",
                content: "Are you sure you want to restore from this backup? This will erase your current database.",
                confirm: function() {
                    $.toast("Restoring backup in background, please exit the system until complete.");
                    $(".content-page").fadeOut();
                    api(rID, rType, true);
                }
            }).open();
        } else {
            rConfirm = true;
        }
        if (rType == "backup") {
            $.toast("Creating backup in background, this may take a few minutes.");
            $("#create_backup").attr("disabled", true);
        }
        if (rConfirm) {
            $.getJSON("./api?action=backup&sub=" + rType + "&filename=" + encodeURIComponent(rID), function(data) {
                if (data.result === true) {
                    if (rType == "delete") {
                        $.toast("Backup successfully deleted.");
                    }
                    if (rType != "backup") {
                        $("#datatable-backups").DataTable().ajax.reload(null, false);
                    }
                } else {
                    $.toast("An error occured while processing your request.");
                    if (rType == "backup") {
                        $("#create_backup").attr("disabled", false);
                    }
                    if (!$(".content-page").is(":visible")) {
                        $(".content-page").fadeIn();
                    }
                }
            });
        }
    }
    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $("#datatable-backups").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                }
            },
            drawCallback: function() {

                bindHref();
                refreshTooltips();
            },
            bInfo: false,
            paging: false,
            searching: false,
            bSort: false,
            responsive: false,
            processing: true,
            serverSide: true,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "backups"
                }
            },
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 1, 2, 3, 4, 5]
            }],
        });
        $("#datatable-backups").css("width", "100%");
        $("#backups_to_keep").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("#dropbox_keep").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
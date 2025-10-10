<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && !($rProvider = getStreamProvider(CoreUtilities::$rRequest['id']))) {
    exit();
}

$_TITLE = 'Stream Provider';
include 'header.php'; ?>
<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') : ?><?php else : ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php if (isset($rProvider)) : ?><?php echo $_['edit']; ?><?php else : ?><?php echo $_['add']; ?><?php endif; ?> Provider</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (!isset($rProvider)) : ?><?php else : ?>
                            <input type="hidden" name="edit" value="<?php echo $rProvider['id']; ?>" />
                            <input class='copyfrom' tabindex='-1' aria-hidden='true' id="stream_url" value="" style="position: absolute; left: -9999px;">
                        <?php endif; ?>
                        <div id="basicwizard">
                            <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                <li class="nav-item">
                                    <a href="#category-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                        <span class="d-none d-sm-inline"><?php echo $_['details']; ?></span>
                                    </a>
                                </li>
                                <?php if (!isset($rProvider)) : ?><?php else : ?>
                                <li class="nav-item">
                                    <a href="#view-streams" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-play mr-1"></i>
                                        <span class="d-none d-sm-inline">Available Streams</span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#view-movies" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-movie mr-1"></i>
                                        <span class="d-none d-sm-inline">Available Movies</span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="category-details">
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="name">Provider Name</label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['name']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="ip">Server IP / Domain</label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="ip" name="ip" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['ip']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                                <label class="col-md-3 col-form-label" for="port">Broadcast Port</label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control text-center" id="port" name="port" value="<?php if (isset($rProvider)) : ?><?php echo htmlspecialchars($rProvider['port']); ?><?php else : ?>80<?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="username">Username</label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="username" name="username" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['username']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                                <label class="col-md-3 col-form-label" for="password">Password</label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="password" name="password" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['password']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="enabled">Enabled</label>
                                                <div class="col-md-3">
                                                    <input name="enabled" id="enabled" type="checkbox" <?php if (isset($rProvider)) : ?><?php if ($rProvider['enabled'] == 1) : ?>checked<?php endif; ?><?php else : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-3 col-form-label" for="ssl">SSL</label>
                                                <div class="col-md-3">
                                                    <input name="ssl" id="ssl" type="checkbox" <?php if (isset($rProvider) && $rProvider['ssl'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="legacy">Legacy XC</label>
                                                <div class="col-md-3">
                                                    <input name="legacy" id="legacy" type="checkbox" <?php if (isset($rProvider) && $rProvider['legacy'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-3 col-form-label" for="hls">Use HLS</label>
                                                <div class="col-md-3">
                                                    <input name="hls" id="hls" type="checkbox" <?php if (isset($rProvider) && $rProvider['hls'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_provider" type="submit" class="btn btn-primary" value="<?php if (isset($rProvider)) : ?><?php echo $_['edit']; ?><?php else : ?><?php echo $_['add']; ?><?php endif; ?>" />
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                                <?php if (isset($rProvider)) : ?>
                                    <!-- Available Streams Tab Pane -->
                                    <div class="tab-pane" id="view-streams">
                                        <div class="row">
                                            <div class="col-12" style="overflow-x:auto;">
                                                <table id="datatable-streams" class="table table-striped table-borderless dt-responsive nowrap">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Stream Name</th>
                                                            <th>Categories</th>
                                                            <th class="text-center">Modified</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($db->get_rows() as $rRow) : ?>
                                                            <?php
                                                            $rStreamURL = (($rProvider['ssl'] ? 'https' : 'http')) . '://' . $rProvider['ip'] . ':' . $rProvider['port'] . '/live/' . $rProvider['username'] . '/' . $rProvider['password'] . '/' . $rRow['stream_id'] . (($rProvider['hls'] ? '.m3u8' : ($rProvider['legacy'] ? '.ts' : '')));
                                                            ?>
                                                            <tr>
                                                                <td class="text-center"><?php echo $rRow['stream_id']; ?></td>
                                                                <td><?php echo $rRow['stream_display_name']; ?></td>
                                                                <td><?php echo implode(', ', json_decode($rRow['category_array'], true)); ?></td>
                                                                <td class="text-center"><?php echo date('Y-m-d', $rRow['modified']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['modified']) . '</small>'; ?></td>
                                                                <td class="text-center">
                                                                    <a href="stream?title=<?php echo urlencode($rRow['stream_display_name']); ?>&url=<?php echo urlencode($rStreamURL); ?>&icon=<?php echo urlencode($rRow['stream_icon']); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-plus"></i></button></a>
                                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="Copy URL" onClick="copyURL('<?php echo $rStreamURL; ?>');"><i class="mdi mdi-clipboard"></i></button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Available Movies Tab Pane -->
                                    <div class="tab-pane" id="view-movies">
                                        <div class="row">
                                            <div class="col-12" style="overflow-x:auto;">
                                                <table id="datatable-movies" class="table table-striped table-borderless dt-responsive nowrap">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Movie Name</th>
                                                            <th>Categories</th>
                                                            <th class="text-center">Modified</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($db->get_rows() as $rRow) : ?>
                                                            <?php
                                                            $rStreamURL = (($rRow['ssl'] ? 'https' : 'http')) . '://' . $rProvider['ip'] . ':' . $rProvider['port'] . '/movie/' . $rProvider['username'] . '/' . $rProvider['password'] . '/' . $rRow['stream_id'] . '.' . $rRow['channel_id'];
                                                            ?>
                                                            <tr>
                                                                <td class="text-center"><?php echo $rRow['stream_id']; ?></td>
                                                                <td><?php echo $rRow['stream_display_name']; ?></td>
                                                                <td><?php echo implode(', ', json_decode($rRow['category_array'], true)); ?></td>
                                                                <td class="text-center"><?php echo date('Y-m-d', $rRow['modified']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rRow['modified']) . '</small>'; ?></td>
                                                                <td class="text-center">
                                                                    <a href="movie?title=<?php echo urlencode($rRow['stream_display_name']); ?>&path=<?php echo urlencode($rStreamURL); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-plus"></i></button></a>
                                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="Copy URL" onClick="copyURL('<?php echo $rStreamURL; ?>');"><i class="mdi mdi-clipboard"></i></button>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>
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



    function copyURL(rURL) {
        $("#stream_url").val(rURL);
        $("#stream_url").select();
        document.execCommand("copy");
        $.toast("URL has been copied to clipboard.");
    }

    $(document).ready(function() {
        $("#datatable-streams").DataTable({
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
            order: [
                [3, "desc"]
            ],
            responsive: false,
            bAutoWidth: false,
            bInfo: false
        });
        $("#datatable-movies").DataTable({
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
            order: [
                [3, "desc"]
            ],
            responsive: false,
            bAutoWidth: false,
            bInfo: false
        });
        $("#port").inputFilter(function(value) {
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
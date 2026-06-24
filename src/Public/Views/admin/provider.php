<?php
$xmIsDark = ($rThemes[$rUserInfo['theme']]['dark'] ?? false);
$xmTheme  = $xmIsDark ? 'xm-dark' : 'xm-light';
?>
<div class="wrapper xm-mag <?= $xmTheme ?>" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') : ?><?php else : ?> style="display: none;" <?php endif; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php if (isset($rProvider)) : ?><?php echo $language::get('edit'); ?><?php else : ?><?php echo $language::get('add'); ?><?php endif; ?> Provider</h4>
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
                                        <span class="d-none d-sm-inline"><?php echo $language::get('details'); ?></span>
                                    </a>
                                </li>
                                <?php if (!isset($rProvider)) : ?><?php else : ?>
                                <li class="nav-item">
                                    <a href="#view-streams" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-play mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('available_streams') ?></span>
                                    </a>
                                </li>
                                <li class="nav-item">
                                    <a href="#view-movies" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                        <i class="mdi mdi-movie mr-1"></i>
                                        <span class="d-none d-sm-inline"><?= $language::get('available_movies') ?></span>
                                    </a>
                                </li>
                            <?php endif; ?>
                            </ul>
                            <div class="tab-content b-0 mb-0 pt-0">
                                <div class="tab-pane" id="category-details">
                                    <div class="row">
                                        <div class="col-12">
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="name"><?= $language::get('provider_name') ?></label>
                                                <div class="col-md-9">
                                                    <input type="text" class="form-control" id="name" name="name" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['name']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="ip"><?= $language::get('server_ip_domain') ?></label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="ip" name="ip" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['ip']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                                <label class="col-md-3 col-form-label" for="port"><?= $language::get('broadcast_port') ?></label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control text-center" id="port" name="port" value="<?php if (isset($rProvider)) : ?><?php echo htmlspecialchars($rProvider['port']); ?><?php else : ?>80<?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="username"><?= $language::get('username') ?></label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="username" name="username" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['username']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                                <label class="col-md-3 col-form-label" for="password"><?= $language::get('password') ?></label>
                                                <div class="col-md-3">
                                                    <input type="text" class="form-control" id="password" name="password" value="<?php if (!isset($rProvider)) : ?><?php else : ?><?php echo htmlspecialchars($rProvider['password']); ?><?php endif; ?>" required data-parsley-trigger="change">
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="enabled"><?= $language::get('enabled') ?></label>
                                                <div class="col-md-3">
                                                    <input name="enabled" id="enabled" type="checkbox" <?php if (isset($rProvider)) : ?><?php if ($rProvider['enabled'] == 1) : ?>checked<?php endif; ?><?php else : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-3 col-form-label" for="ssl"><?= $language::get('ssl') ?></label>
                                                <div class="col-md-3">
                                                    <input name="ssl" id="ssl" type="checkbox" <?php if (isset($rProvider) && $rProvider['ssl'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <label class="col-md-3 col-form-label" for="legacy"><?= $language::get('legacy_xc') ?></label>
                                                <div class="col-md-3">
                                                    <input name="legacy" id="legacy" type="checkbox" <?php if (isset($rProvider) && $rProvider['legacy'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-3 col-form-label" for="hls"><?= $language::get('use_hls') ?></label>
                                                <div class="col-md-3">
                                                    <input name="hls" id="hls" type="checkbox" <?php if (isset($rProvider) && $rProvider['hls'] == 1) : ?>checked<?php endif; ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0">
                                                <?php if (isset($rProvider)): ?>
                                                <li class="list-inline-item">
                                                    <button type="button" id="import_epg_btn" class="btn btn-info waves-effect" onclick="importProviderEPG(<?php echo intval($rProvider['id']); ?>);">
                                                        <i class="mdi mdi-calendar-import mr-1"></i> Import EPG Source
                                                    </button>
                                                </li>
                                                <?php endif; ?>
                                                <li class="list-inline-item float-right">
                                                    <input name="submit_provider" type="submit" class="btn btn-primary" value="<?php if (isset($rProvider)) : ?><?php echo $language::get('edit'); ?><?php else : ?><?php echo $language::get('add'); ?><?php endif; ?>" />
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
                                                            <th class="text-center"><?= $language::get('id') ?></th>
                                                            <th><?= $language::get('stream_name') ?></th>
                                                            <th><?= $language::get('categories') ?></th>
                                                            <th class="text-center"><?= $language::get('modified') ?></th>
                                                            <th class="text-center"><?= $language::get('actions') ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
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
                                                            <th class="text-center"><?= $language::get('id') ?></th>
                                                            <th><?= $language::get('movie_name') ?></th>
                                                            <th><?= $language::get('categories') ?></th>
                                                            <th class="text-center"><?= $language::get('modified') ?></th>
                                                            <th class="text-center"><?= $language::get('actions') ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                    <?php
                                    // Pass provider data to JS for AJAX DataTable
                                    $rProvScheme = $rProvider['ssl'] ? 'https' : 'http';
                                    $rProvBase   = $rProvScheme . '://' . $rProvider['ip'] . ':' . $rProvider['port'];
                                    $rProvExt    = $rProvider['hls'] ? '.m3u8' : ($rProvider['legacy'] ? '.ts' : '');
                                    ?>
                                    <script>
                                    window.rProviderData = {
                                        id:       <?php echo intval($rProvider['id']); ?>,
                                        base:     <?php echo json_encode($rProvBase); ?>,
                                        user:     <?php echo json_encode($rProvider['username']); ?>,
                                        pass:     <?php echo json_encode($rProvider['password']); ?>,
                                        ext:      <?php echo json_encode($rProvExt); ?>
                                    };
                                    </script>
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
<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
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



    function importProviderEPG(providerId) {
        var $btn = $("#import_epg_btn");
        $btn.prop("disabled", true).html('<i class="mdi mdi-loading mdi-spin mr-1"></i> Importing...');
        $.getJSON("./api?action=provider_import_epg&provider_id=" + providerId, function(rData) {
            if (rData.status === 1) {
                $.toast("EPG source \"" + rData.data.name + "\" added successfully (ID: " + rData.data.id + ").");
                $btn.html('<i class="mdi mdi-check mr-1"></i> EPG Imported').removeClass("btn-info").addClass("btn-success");
            } else if (rData.status === 2) {
                $.toast("EPG source already exists (ID: " + rData.data.id + ").");
                $btn.prop("disabled", false).html('<i class="mdi mdi-calendar-import mr-1"></i> Import EPG Source');
            } else {
                $.toast("Error: " + (rData.data || "Could not import EPG source."));
                $btn.prop("disabled", false).html('<i class="mdi mdi-calendar-import mr-1"></i> Import EPG Source');
            }
        }).fail(function() {
            $.toast("Request failed. Please try again.");
            $btn.prop("disabled", false).html('<i class="mdi mdi-calendar-import mr-1"></i> Import EPG Source');
        });
    }

    function copyURL(rURL) {
        $("#stream_url").val(rURL);
        $("#stream_url").select();
        document.execCommand("copy");
        $.toast("URL has been copied to clipboard.");
    }

    $(document).ready(function() {
        if (window.rProviderData) {
            var p = window.rProviderData;

            function makeStreamUrl(row) {
                return p.base + '/live/' + p.user + '/' + p.pass + '/' + row.stream_id + p.ext;
            }
            function makeMovieUrl(row) {
                return p.base + '/movie/' + p.user + '/' + p.pass + '/' + row.stream_id + '.' + row.channel_id;
            }
            function fmtDate(ts) {
                var d = new Date(ts * 1000);
                return d.toISOString().slice(0,10) + '<br><small class="text-secondary">' + d.toISOString().slice(11,19) + '</small>';
            }
            function fmtCats(json) {
                try { return JSON.parse(json).join(', '); } catch(e) { return ''; }
            }

            function initProviderTable(tableId, streamType, urlFn, addHref) {
                $("#" + tableId).DataTable({
                    processing: true,
                    serverSide: true,
                    ajax: {
                        url: "./api?action=provider_streams&provider_id=" + p.id + "&stream_type=" + streamType,
                        type: "GET"
                    },
                    columns: [
                        { data: "stream_id", className: "text-center" },
                        { data: "stream_display_name" },
                        { data: "category_array", orderable: false, render: function(d) { return fmtCats(d); } },
                        { data: "modified", render: function(d) { return fmtDate(d); }, className: "text-center" },
                        { data: null, orderable: false, className: "text-center", render: function(d, t, row) {
                            var url = urlFn(row);
                            var addBtn = addHref
                                ? '<a href="' + addHref + '?title=' + encodeURIComponent(row.stream_display_name) + '&url=' + encodeURIComponent(url) + '&icon=' + encodeURIComponent(row.stream_icon || '') + '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-plus"></i></button></a>'
                                : '<a href="movie?title=' + encodeURIComponent(row.stream_display_name) + '&path=' + encodeURIComponent(url) + '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-plus"></i></button></a>';
                            return addBtn + ' <button type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip" title="Copy URL" onclick="copyURL(\'' + url.replace(/'/g, "\\'") + '\');"><i class="mdi mdi-clipboard"></i></button>';
                        }}
                    ],
                    order: [[3, "desc"]],
                    language: { paginate: { previous: "<i class='mdi mdi-chevron-left'>", next: "<i class='mdi mdi-chevron-right'>" } },
                    drawCallback: function() { bindHref(); refreshTooltips(); },
                    responsive: false,
                    bAutoWidth: false,
                    bInfo: true
                });
            }

            initProviderTable("datatable-streams", "live",  makeStreamUrl, "stream");
            initProviderTable("datatable-movies",  "movie", makeMovieUrl,  null);
        }
        $("#port").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
        $("form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
    <?php if (SettingsManager::getAll()['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>

<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;

$rParams     = RequestManager::getAll();
$rAllSettings = SettingsManager::getAll();

$rWrapperStyle = '';

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    $rWrapperStyle = ' style="display: none;"';
}

$rCollapseClass = $rMobile ? ' collapse' : '';
$rDefaultEntries = intval($rSettings['default_entries']) ?: 10;
$rRedisHandler = $rAllSettings['redis_handler'];

$rSearchValue = isset($rParams['search'])
    ? htmlspecialchars($rParams['search'])
    : '';

$rOrderDirection = in_array(strtolower($rParams['dir'] ?? ''), ['asc', 'desc'], true)
    ? strtolower($rParams['dir'])
    : 'desc';

if ($rRedisHandler) {
    $rOrderColumn = 8;
} else {
    $rOrderColumn = isset($rParams['order'])
        ? intval($rParams['order'])
        : 8;
}

$rLiveFilters = [
    1 => 'User Lines',
    2 => 'MAG Devices',
    3 => 'Enigma2 Devices',
    4 => 'Trial Lines',
    5 => 'Restreamers',
    6 => 'Ministra Lines',
];

$rEntriesOptions = [10, 25, 50, 250, 500, 1000];
?>

<div class="wrapper" <?= $rWrapperStyle ?>>
    <div class="container-fluid">

        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>

                    <h4 class="page-title">
                        <?= $language::get('live_connections'); ?>
                    </h4>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12">

                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">

                        <div id="collapse_filters" class="form-group row mb-4<?= $rCollapseClass ?>">

                            <?php if ($rRedisHandler): ?>

                                <div class="col-md-3">
                                    <select id="live_server" class="form-control" data-toggle="select2">
                                        <option value="" <?= !isset($rParams['server']) ? ' selected' : '' ?>>
                                            <?= $language::get('all_servers'); ?>
                                        </option>

                                        <?php foreach (ServerRepository::getAll() as $rServer): ?>
                                            <?php if ($rServer['enabled']): ?>
                                                <option value="<?= $rServer['id']; ?>" <?= (isset($rParams['server']) && $rParams['server'] == $rServer['id']) ? ' selected' : '' ?>>
                                                    <?= htmlspecialchars($rServer['server_name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <select id="live_stream" class="form-control" data-toggle="select2">
                                        <?php if (isset($rSearchStream)): ?>
                                            <option value="<?= intval($rSearchStream['id']); ?>" selected="selected">
                                                <?= htmlspecialchars($rSearchStream['stream_display_name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <select id="live_line" class="form-control" data-toggle="select2">
                                        <?php if (isset($rSearchUser)): ?>
                                            <option value="<?= intval($rSearchUser['id']); ?>" selected="selected">
                                                <?= htmlspecialchars($rSearchUser['username']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <label class="col-md-1 col-form-label text-center" for="live_show_entries">
                                    <?= $language::get('show'); ?>
                                </label>

                                <div class="col-md-1">
                                    <select id="live_show_entries" class="form-control" data-toggle="select2">
                                        <?php foreach ($rEntriesOptions as $rShow): ?>
                                            <option value="<?= $rShow; ?>" <?= ($rSettings['default_entries'] == $rShow) ? ' selected' : '' ?>>
                                                <?= $rShow; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            <?php else: ?>

                                <div class="col-md-2">
                                    <input type="text" class="form-control" id="live_search" value="<?= $rSearchValue ?>" placeholder="<?= $language::get('search_logs'); ?>...">
                                </div>

                                <div class="col-md-2">
                                    <select id="live_server" class="form-control" data-toggle="select2">
                                        <option value="" <?= !isset($rParams['server']) ? ' selected' : '' ?>>
                                            <?= $language::get('all_servers'); ?>
                                        </option>

                                        <?php foreach (ServerRepository::getAll() as $rServer): ?>
                                            <?php if ($rServer['enabled']): ?>
                                                <option value="<?= $rServer['id']; ?>" <?= (isset($rParams['server']) && $rParams['server'] == $rServer['id']) ? ' selected' : '' ?>>
                                                    <?= htmlspecialchars($rServer['server_name']); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <select id="live_stream" class="form-control" data-toggle="select2">
                                        <?php if (isset($rSearchStream)): ?>
                                            <option value="<?= intval($rSearchStream['id']); ?>" selected="selected">
                                                <?= htmlspecialchars($rSearchStream['stream_display_name']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <select id="live_line" class="form-control" data-toggle="select2">
                                        <?php if (isset($rSearchUser)): ?>
                                            <option value="<?= intval($rSearchUser['id']); ?>" selected="selected">
                                                <?= htmlspecialchars($rSearchUser['username']); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <select id="live_filter" class="form-control" data-toggle="select2">
                                        <option value="" <?= !isset($rParams['filter']) ? ' selected' : '' ?>>
                                            No Filter
                                        </option>

                                        <?php foreach ($rLiveFilters as $rFilterKey => $rFilterName): ?>
                                            <option value="<?= $rFilterKey ?>" <?= (isset($rParams['filter']) && $rParams['filter'] == $rFilterKey) ? ' selected' : '' ?>>
                                                <?= $rFilterName ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <label class="col-md-1 col-form-label text-center" for="live_show_entries">
                                    <?= $language::get('show'); ?>
                                </label>

                                <div class="col-md-1">
                                    <select id="live_show_entries" class="form-control" data-toggle="select2">
                                        <?php foreach ($rEntriesOptions as $rShow): ?>
                                            <option value="<?= $rShow; ?>" <?= ($rSettings['default_entries'] == $rShow) ? ' selected' : '' ?>>
                                                <?= $rShow; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                            <?php endif; ?>

                        </div>

                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id') ?></th>
                                    <th class="text-center"><?= $language::get('quality') ?></th>
                                    <th><?= $language::get('line') ?></th>
                                    <th><?= $language::get('stream') ?></th>
                                    <th><?= $language::get('server') ?></th>
                                    <th><?= $language::get('player') ?></th>
                                    <th><?= $language::get('isp') ?></th>
                                    <th class="text-center"><?= $language::get('ip') ?></th>
                                    <th class="text-center"><?= $language::get('duration') ?></th>
                                    <th class="text-center"><?= $language::get('output') ?></th>
                                    <th class="text-center"><?= $language::get('restreamer') ?></th>
                                    <th class="text-center"><?= $language::get('actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>

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
        resizeObserver.observe(document.body);

        $("form").attr('autocomplete', 'off');

        $(document).keypress(function(event) {
            if (event.which == 13 && event.target.nodeName != "TEXTAREA") {
                return false;
            }
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
        };
    });

    var rClearing = false;

    function api(rID, rType) {
        $.getJSON("./api?action=line_activity&sub=" + rType + "&pid=" + rID, function(data) {
            if (data.result === true) {
                if (rType == "kill") {
                    $.toast(<?= json_encode($language::get('connection_has_been_killed')); ?>);
                }

                $("#datatable-activity").DataTable().ajax.reload(null, false);
            } else {
                $.toast(<?= json_encode($language::get('error_occured')); ?>);
            }
        });
    }

    function refreshTable() {
        $("#datatable-activity").DataTable().ajax.reload(null, false);
    }

    function getStream() {
        return $("#live_stream").val();
    }

    function getLine() {
        return $("#live_line").val();
    }

    function getFilter() {
        return $("#live_filter").val();
    }

    function getServer() {
        return $("#live_server").val();
    }

    function clearFilters() {
        window.rClearing = true;

        $("#live_search").val("").trigger('change');
        $("#live_stream").val("").trigger('change');
        $('#live_line').val("").trigger('change');
        $('#live_server').val("").trigger('change');
        $('#live_filter').val("").trigger('change');
        $('#live_show_entries').val("<?= $rDefaultEntries ?>").trigger('change');

        window.rClearing = false;

        $('#datatable-activity').DataTable().search($("#live_search").val());
        $('#datatable-activity').DataTable().page.len($('#live_show_entries').val());
        $("#datatable-activity").DataTable().page(0).draw('page');
        $("#datatable-activity").DataTable().ajax.reload(null, false);

        delParams(["search", "stream_id", "server", "user_id", "page", "entries", "filter"]);

        checkClear();
    }

    function checkClear() {
        if (!hasParams(["search", "stream_id", "server", "user_id", "filter"])) {
            $("#clearFilters").prop("disabled", true);
        } else {
            $("#clearFilters").prop("disabled", false);
        }
    }

    function getRowIDs() {
        var rRowIDs = [];
        var rIndexes = [];

        $("#datatable-activity").DataTable().rows().every(function(rowIdx, tableLoop, rowLoop) {
            if ($($("#datatable-activity").DataTable().row(rowIdx).data()[8]).text() != "CLOSED") {
                rRowIDs.push($("#datatable-activity").DataTable().row(rowIdx).data()[0]);
                rIndexes.push(rowIdx);
            }
        });

        return [rRowIDs, rIndexes];
    }

    function refreshInformation() {
        if (!window.rProcessing) {
            var rRowIDs = getRowIDs();

            if (rRowIDs[0].length > 0) {
                $.getJSON("./table?" + $.param($("#datatable-activity").DataTable().ajax.params()) + "&refresh=" + rRowIDs[0].join(","), function(rTable) {
                    if (!window.rProcessing) {
                        var rActive = [];

                        $(rTable.data).each(function(rIdx, rItem) {
                            var rFoundIndex = rRowIDs[0].indexOf(rItem[0]);

                            if (rFoundIndex >= 0) {
                                if ($('#datatable-activity').DataTable().row(rRowIDs[1][rFoundIndex]).data() != rItem) {
                                    $('#datatable-activity').DataTable().row(rRowIDs[1][rFoundIndex]).data(rItem);
                                }
                            }

                            rActive.push(rFoundIndex);
                        });

                        $(rRowIDs[1]).each(function(rIndex) {
                            if (rActive.indexOf(rIndex) == -1) {
                                $('#datatable-activity').DataTable().cell(rIndex, 8).data("<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light btn-fixed'>" + <?= json_encode($language::get('closed')); ?> + "</button>");
                            }
                        });

                        bindHref();
                        refreshTooltips(false);
                    }
                });
            }
        }

        clearTimeout(window.rRefresh);
        window.rRefresh = setTimeout(refreshInformation, 5000);
    }

    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });

        $('#live_line').select2({
            ajax: {
                url: './api',
                dataType: 'json',
                data: function(params) {
                    return {
                        search: params.term,
                        action: 'userlist',
                        page: params.page
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;

                    return {
                        results: data.items,
                        pagination: {
                            more: (params.page * 100) < data.total_count
                        }
                    };
                },
                cache: true,
                width: "100%"
            },
            placeholder: 'All Lines'
        });

        $('#live_stream').select2({
            ajax: {
                url: './api',
                dataType: 'json',
                data: function(params) {
                    return {
                        search: params.term,
                        action: 'streamlist',
                        page: params.page
                    };
                },
                processResults: function(data, params) {
                    params.page = params.page || 1;

                    return {
                        results: data.items,
                        pagination: {
                            more: (params.page * 100) < data.total_count
                        }
                    };
                },
                cache: true,
                width: "100%"
            },
            placeholder: 'All Streams'
        });

        var rPage = getParam("page");

        if (!rPage) {
            rPage = 1;
        }

        var rEntries = getParam("entries");

        if (!rEntries) {
            rEntries = <?= intval($rSettings['default_entries']); ?>;
        }

        var rTable = $("#datatable-activity").DataTable({
            language: {
                paginate: {
                    previous: "<i class='mdi mdi-chevron-left'>",
                    next: "<i class='mdi mdi-chevron-right'>"
                },
                infoFiltered: ""
            },
            drawCallback: function() {
                window.rProcessing = false;

                bindHref();
                refreshTooltips();

                if ($("#datatable-activity").DataTable().page.info().page > 0) {
                    setParam("page", $("#datatable-activity").DataTable().page.info().page + 1);
                } else {
                    delParam("page");
                }

                var rOrder = $("#datatable-activity").DataTable().order()[0];

                setParam("order", rOrder[0]);
                setParam("dir", rOrder[1]);

                clearTimeout(window.rRefresh);

                if ($("#datatable-activity").DataTable().rows().count() <= 50) {
                    setTimeout(refreshInformation, 5000);
                }
            },
            responsive: false,
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "live_connections";
                    d.user_id = getLine();
                    d.stream_id = getStream();
                    d.server_id = getServer();

                    <?php if (!$rRedisHandler): ?>
                        d.filter = getFilter();
                    <?php endif; ?>
                }
            },
            columnDefs: [{
                    "className": "dt-center",
                    "targets": [1, 7, 8, 9, 10, 11]
                },
                <?php if ($rRedisHandler): ?> {
                        "orderable": false,
                        "targets": [1, 2, 3, 4, 5, 6, 7, 9, 10, 11]
                    },
                <?php endif; ?> {
                    "visible": false,
                    "targets": [0]
                }
            ],
            <?php if ($rMobile): ?>
                scrollX: true,
            <?php endif; ?>
                order: [
                    [<?= $rOrderColumn ?>, "<?= $rOrderDirection ?>"]
                ],
            pageLength: parseInt(rEntries),
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            displayStart: (parseInt(rPage) - 1) * parseInt(rEntries)
        }).on('processing.dt', function(e, settings, processing) {
            window.rProcessing = processing;
        });

        function doSearch(rValue) {
            clearTimeout(window.rSearch);

            window.rSearch = setTimeout(function() {
                rTable.search(rValue).draw();
            }, 500);
        }

        $("#datatable-activity").css("width", "100%");

        $('#live_search').keyup(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_search").val()) {
                    setParam("search", $("#live_search").val());
                } else {
                    delParam("search");
                }

                checkClear();
                doSearch($(this).val());
            }
        });

        $('#live_show_entries').change(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_show_entries").val()) {
                    setParam("entries", $("#live_show_entries").val());
                } else {
                    delParam("entries");
                }

                checkClear();
                rTable.page.len($(this).val()).draw();
            }
        });

        $('#live_line').change(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_line").val()) {
                    setParam("user_id", $("#live_line").val());
                } else {
                    delParam("user_id");
                }

                checkClear();
                rTable.ajax.reload(null, false);
            }
        });

        $('#live_stream').change(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_stream").val()) {
                    setParam("stream_id", $("#live_stream").val());
                } else {
                    delParam("stream_id");
                }

                checkClear();
                rTable.ajax.reload(null, false);
            }
        });

        $('#live_filter').change(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_filter").val()) {
                    setParam("filter", $("#live_filter").val());
                } else {
                    delParam("filter");
                }

                checkClear();
                rTable.ajax.reload(null, false);
            }
        });

        $('#live_server').change(function() {
            if (!window.rClearing) {
                delParam("page");
                rTable.page(0);

                if ($("#live_server").val()) {
                    setParam("server", $("#live_server").val());
                } else {
                    delParam("server");
                }

                checkClear();
                rTable.ajax.reload(null, false);
            }
        });

        if ($('#live_search').val()) {
            rTable.search($('#live_search').val()).draw();
        }

        $("#btn-export-csv").click(function() {
            $.toast("Generating CSV report...");
            window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-activity").DataTable().ajax.params()));
        });

        checkClear();
    });

    <?php if ($rAllSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>

<script src="assets/old/js/listings.js"></script>

</body>

</html>
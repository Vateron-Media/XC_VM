<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = "ASN's";
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
                    <h4 class="page-title">Autonomous System Numbers</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <form id="asn_search">
                            <div class="form-group row mb-4">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" id="asn_search_box" placeholder="Search ASN's...">
                                </div>
                                <label class="col-md-2 col-form-label text-center" for="asn_type">Filter Results</label>
                                <div class="col-md-3">
                                    <select id="asn_type" class="form-control" data-toggle="select2">
                                        <option value="" selected>All Types</option>
                                        <option value="isp">ISP</option>
                                        <option value="hosting">Hosting Server</option>
                                        <option value="education">Education</option>
                                        <option value="business">Business</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <select id="asn_filter" class="form-control" data-toggle="select2">
                                        <option value="" selected>No Filter</option>
                                        <option value="0">Allowed</option>
                                        <option value="1">Blocked</option>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center" for="asn_show_entries">Show</label>
                                <div class="col-md-1">
                                    <select id="asn_show_entries" class="form-control" data-toggle="select2">
                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) : ?>
                                            <option value="<?php echo $rShow; ?>" <?php if ($rSettings['default_entries'] == $rShow) echo ' selected'; ?>><?php echo $rShow; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </form>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ASN</th>
                                    <th>Name</th>
                                    <th>Domain</th>
                                    <th class="text-center">Country</th>
                                    <th class="text-center">IP Count</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Actions</th>
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

    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });

    <?php endif; ?>

    function api(rID, rType) {
        $.getJSON("./api?action=asn&sub=" + rType + "&id=" + rID, function(data) {
            if (data.result === true) {
                if (rType == "block") {
                    $.toast("ASN has been blocked.");
                } else if (rType == "allow") {
                    $.toast("ASN has been allowed.");
                } else if (rType == "block_all") {
                    $.toast("All ASN's have been blocked.");
                } else if (rType == "allow_all") {
                    $.toast("All ASN's have been allowed.");
                }
                $("#datatable-users").DataTable().ajax.reload(null, false);
            } else {
                $.toast("An error occured while processing your request.");
            }
        });
    }

    function getFilter() {
        return $("#asn_filter").val();
    }

    function getType() {
        return $("#asn_type").val();
    }
    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $("#datatable-users").DataTable({
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
            responsive: false,
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: "./table",
                "data": function(d) {
                    d.id = "asns",
                        d.filter = getFilter(),
                        d.type = getType()
                }
            },
            columnDefs: [{
                "className": "dt-center",
                "targets": [0, 3, 4, 5, 6, 7]
            }],
            order: [
                [0, "asc"]
            ]
        });
        $("#datatable-users").css("width", "100%");
        $('#asn_search_box').keyup(function() {
            if (!window.rClearing) {
                $('#datatable-users').DataTable().search($(this).val()).draw();
            }
        });
        $('#asn_show_entries').change(function() {
            if (!window.rClearing) {
                $('#datatable-users').DataTable().page.len($(this).val()).draw();
            }
        });
        $('#asn_filter').change(function() {
            if (!window.rClearing) {
                $("#datatable-users").DataTable().ajax.reload(null, false);
            }
        });
        $('#asn_type').change(function() {
            if (!window.rClearing) {
                $("#datatable-users").DataTable().ajax.reload(null, false);
            }
        });
        if ($('#asn_search_box').val()) {
            $('#datatable-users').DataTable().search($('#asn_search_box').val()).draw();
        }
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
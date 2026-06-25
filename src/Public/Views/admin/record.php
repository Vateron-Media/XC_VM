<div class="wrapper boxed-layout"
    <?php 
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Config\SettingsManager;if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Record an Event</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <?php if ($rStream) {
                        } else { ?>
                            <form action="record" method="POST" data-parsley-validate="">
                            <?php } ?>
                            <table class="table table-borderless mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Channel</th>
                                        <th class="text-center">Start</th>
                                        <th class="text-center"><?php echo ($rStream ? 'Finish' : 'Minutes'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <?php if ($rStream) { ?>
                                            <td><?php echo $rStream['stream_display_name']; ?></td>
                                            <td class="text-center">
                                                <?php echo date(SettingsManager::getAll()['date_format'], $rProgramme['start']); ?><br /><?php echo date('H:i', $rProgramme['start']); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo date(SettingsManager::getAll()['date_format'], $rProgramme['end']); ?><br /><?php echo date('H:i', $rProgramme['end']); ?>
                                            </td>
                                        <?php } else { ?>
                                            <td><select id="stream_id" name="stream_id" class="form-control"
                                                    data-toggle="select2"></select></td>
                                            <td style="max-width:120px;" class="text-center"><input type="text"
                                                    class="form-control text-center date" id="start_date" name="start_date"
                                                    value="" data-toggle="date-picker" data-single-date-picker="true"></td>
                                            <td style="max-width:40px;" class="text-center"><input type="text"
                                                    class="form-control text-center" id="duration" name="duration"
                                                    value="0"></td>
                                        <?php } ?>
                                    </tr>
                                </tbody>
                            </table>
                            <?php if ($rStream) {
                            } else { ?>
                                <ul class="list-inline wizard mb-0">
                                    <li class="list-inline-item float-right">
                                        <input type="submit" class="btn btn-primary" value="Continue" />
                                    </li>
                                </ul>
                            </form>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <?php if (!$rStream) {
            } else { ?>
                <div class="col-xl-12">
                    <?php if (!empty($rProgramme['archive']) || $rProgramme['start'] > time()) {
                    } else { ?>
                        <div class="alert alert-warning text-center" role="alert">
                            The programme you are intending to record has already started!
                        </div>
                    <?php } ?>
                    <div class="card">
                        <div class="card-body">
                            <form
                                <?php if (!isset(RequestManager::getAll()['import'])) {
                                } else {
                                    echo ' enctype="multipart/form-data"';
                                } ?>
                                action="#" method="POST" data-parsley-validate="">
                                <input type="hidden" name="stream_id" value="<?php echo intval($rStream['id']); ?>" />
                                <input type="hidden" name="start" value="<?php echo intval($rProgramme['start']); ?>" />
                                <input type="hidden" name="end" value="<?php echo intval($rProgramme['end']); ?>" />
                                <input type="hidden" name="archive"
                                    value="<?php echo (isset($rProgramme['archive']) ? 1 : 0); ?>" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#stream-details" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">Details</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="stream-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="title">Event
                                                            Title</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="title" name="title"
                                                                value="<?php echo str_replace('"', '&quot;', $rProgramme['title']); ?>"
                                                                required data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="description">Event
                                                            Description</label>
                                                        <div class="col-md-8">
                                                            <textarea rows="6" class="form-control" id="description"
                                                                name="description"><?php echo htmlspecialchars($rProgramme['description']); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="stream_icon">Poster
                                                            URL</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" class="form-control" id="stream_icon"
                                                                name="stream_icon" value="">
                                                            <div class="input-group-append">
                                                                <a href="javascript:void(0)" onClick="openImage(this)"
                                                                    class="btn btn-primary waves-effect waves-light"><i
                                                                        class="mdi mdi-eye"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="category_id">Categories</label>
                                                        <div class="col-md-8">
                                                            <select name="category_id[]" id="category_id"
                                                                class="form-control select2-multiple" data-toggle="select2"
                                                                multiple="multiple" data-placeholder="Choose...">
                                                                <?php foreach (CategoryService::getAllByType('movie') as $rCategory) { ?>
                                                                    <option value="<?php echo $rCategory['id']; ?>">
                                                                        <?php echo $rCategory['category_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="bouquets">Bouquets</label>
                                                        <div class="col-md-8">
                                                            <select name="bouquets[]" id="bouquets"
                                                                class="form-control select2-multiple" data-toggle="select2"
                                                                multiple="multiple" data-placeholder="Choose...">
                                                                <?php foreach (BouquetService::getAllSimple() as $rBouquet) { ?>
                                                                    <option value="<?php echo $rBouquet['id']; ?>">
                                                                        <?php echo $rBouquet['bouquet_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="source_id">Recording
                                                            Server</label>
                                                        <div class="col-md-8">
                                                            <select name="source_id" id="source_id" class="form-control"
                                                                data-toggle="select2">
                                                                <?php foreach ((is_array($rAvailableServers ?? null) ? $rAvailableServers : []) as $rServerID) { ?>
                                                                    <option
                                                                        value="<?php echo ServerRepository::getAll()[$rServerID]['id']; ?>">
                                                                        <?php echo ServerRepository::getAll()[$rServerID]['server_name']; ?>
                                                                        - <?php echo ServerRepository::getAll()[$rServerID]['server_ip']; ?>
                                                                    </option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item float-right">
                                                            <input type="submit" class="btn btn-primary" value="Schedule" />
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php } ?>
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

    function openImage(elem) {
        rPath = $(elem).parent().parent().find("input").val();
        if (rPath) {
            $.magnificPopup.open({
                items: {
                    src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath),
                    type: 'image'
                }
            });
        }
    }

    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $('#stream_id').select2({
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
            placeholder: 'Search for a stream...'
        });
        $('#start_date').daterangepicker({
            singleDatePicker: true,
            showDropdowns: true,
            minDate: new Date(),
            timePicker: true,
            locale: {
                format: 'YYYY-MM-DD HH:mm'
            }
        });
        <?php if ($rStream) { ?>
            $("form").submit(function(e) {
                e.preventDefault();
                $(':input[type="submit"]').prop('disabled', true);
                submitForm(window.rCurrentPage, new FormData($("form")[0]));
            });
        <?php } else { ?>
            $("form").submit(function(e) {
                if (!$("#stream_id").val()) {
                    $.toast("Please select a stream.");
                    e.preventDefault();
                } else if ($("#duration").val() <= 0) {
                    $.toast("Please enter a duration in minutes.");
                    e.preventDefault();
                }
            });
        <?php } ?>
        $("#duration").inputFilter(function(value) {
            return /^\d*$/.test(value);
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
<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rOverride = isset(CoreUtilities::$rRequest['override']);
$rOrdered = array('stream' => array(), 'movie' => array(), 'series' => array(), 'radio' => array());
$db->query('SELECT COUNT(`id`) AS `count` FROM `streams`;');
$rCount = $db->get_row()['count'];

if (!($rCount <= 50000 || $rOverride)) {
} else {
    $db->query('SELECT `id`, `type`, `stream_display_name`, `category_id` FROM `streams` ORDER BY `order` ASC, `stream_display_name` ASC;');

    if (0 >= $db->num_rows()) {
    } else {
        foreach ($db->get_rows() as $rRow) {
            if ($rRow['type'] == 1 || $rRow['type'] == 3) {
                $rOrdered['stream'][] = $rRow;
            } else {
                if ($rRow['type'] == 2) {
                    $rOrdered['movie'][] = $rRow;
                } else {
                    if ($rRow['type'] == 4) {
                        $rOrdered['radio'][] = $rRow;
                    } else {
                        if ($rRow['type'] != 5) {
                        } else {
                            $rOrdered['series'][] = $rRow;
                        }
                    }
                }
            }
        }
    }
}

$_TITLE = 'Channel Order';
include 'header.php'; ?>
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
                    <h4 class="page-title"><?php echo $_['channel_order']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php
                if (50000 < $rCount && !$rOverride) { ?>
                    <div class="alert alert-danger" role="alert">
                        You have <?php echo number_format($rCount, 0); ?> streams in your database! This is far too many to manually order on this page and will crash your browser attempting to display them in a list, therefore manual channel ordering has been disabled.
                        <?php if (!$rOverride) { ?>
                            <br /><br /><a href="channel_order?override=1" class="btn btn-danger">Continue Anyway</a>
                        <?php } ?>
                    </div>
                    <?php } else {
                    if (isset($_STATUS) && $_STATUS == 1) { ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Channel order has been modified.
                        </div>
                    <?php }
                    if ($rSettings['channel_number_type'] == 'manual') {
                    } else { ?>
                        <div class="alert alert-warning alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            <?php echo $_['channel_order_info']; ?>
                        </div>
                    <?php } ?>
                    <div class="card">
                        <div class="card-body">
                            <form action="#" method="POST">
                                <input type="hidden" id="stream_order_array" name="stream_order_array" value="" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#order-stream" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-play mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['streams']; ?></span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#order-movie" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-movie mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['movies']; ?></span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#order-series" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-youtube-tv mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['episodes']; ?></span>
                                            </a>
                                        </li>
                                        <li class="nav-item">
                                            <a href="#order-radio" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-radio mr-1"></i>
                                                <span class="d-none d-sm-inline"><?php echo $_['stations']; ?></span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="order-stream">
                                            <div class="row">
                                                <div class="col-5">
                                                    <select multiple id="sort_stream_l" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['stream'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                                <div class="col-2 text-center" style="display: flex; justify-content: center; align-items: center; margin-top:-18px;">
                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item">
                                                            <a href="javascript: void(0);" onClick="MoveLeft('stream')" class="btn btn-info"><i class="mdi mdi-chevron-left"></i></a>
                                                            <a href="javascript: void(0);" onClick="MoveRight('stream')" class="btn btn-info"><i class="mdi mdi-chevron-right"></i></a>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <div class="col-5">
                                                    <select multiple id="sort_stream_r" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['stream'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0 add-margin-top-20">
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="MoveUp('stream')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveDown('stream')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveTop('stream')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveBottom('stream')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="AtoZ('stream')" class="btn btn-info"><?php echo $_['a_to_z']; ?></a>
                                                </li>
                                                <li class="list-inline-item float-right">
                                                    <button type="submit" class="btn btn-primary waves-effect waves-light"><?php echo $_['save_changes']; ?></button>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="order-movie">
                                            <div class="row">
                                                <div class="col-5">
                                                    <select multiple id="sort_movie_l" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['movie'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                                <div class="col-2 text-center" style="display: flex; justify-content: center; align-items: center; margin-top:-18px;">
                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item">
                                                            <a href="javascript: void(0);" onClick="MoveLeft('movie')" class="btn btn-info"><i class="mdi mdi-chevron-left"></i></a>
                                                            <a href="javascript: void(0);" onClick="MoveRight('movie')" class="btn btn-info"><i class="mdi mdi-chevron-right"></i></a>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <div class="col-5">
                                                    <select multiple id="sort_movie_r" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['movie'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0 add-margin-top-20">
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="MoveUp('movie')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveDown('movie')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveTop('movie')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveBottom('movie')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="AtoZ('movie')" class="btn btn-info"><?php echo $_['a_to_z']; ?></a>
                                                </li>
                                                <li class="list-inline-item float-right">
                                                    <button type="submit" class="btn btn-primary waves-effect waves-light"><?php echo $_['save_changes']; ?></button>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="order-series">
                                            <div class="row">
                                                <div class="col-5">
                                                    <select multiple id="sort_series_l" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['series'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                                <div class="col-2 text-center" style="display: flex; justify-content: center; align-items: center; margin-top:-18px;">
                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item">
                                                            <a href="javascript: void(0);" onClick="MoveLeft('series')" class="btn btn-info"><i class="mdi mdi-chevron-left"></i></a>
                                                            <a href="javascript: void(0);" onClick="MoveRight('series')" class="btn btn-info"><i class="mdi mdi-chevron-right"></i></a>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <div class="col-5">
                                                    <select multiple id="sort_series_r" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['series'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0 add-margin-top-20">
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="MoveUp('series')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveDown('series')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveTop('series')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveBottom('series')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="AtoZ('series')" class="btn btn-info"><?php echo $_['a_to_z']; ?></a>
                                                </li>
                                                <li class="list-inline-item float-right">
                                                    <button type="submit" class="btn btn-primary waves-effect waves-light"><?php echo $_['save_changes']; ?></button>
                                                </li>
                                            </ul>
                                        </div>
                                        <div class="tab-pane" id="order-radio">
                                            <div class="row">
                                                <div class="col-5">
                                                    <select multiple id="sort_radio_l" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['radio'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                                <div class="col-2 text-center" style="display: flex; justify-content: center; align-items: center; margin-top:-18px;">
                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item">
                                                            <a href="javascript: void(0);" onClick="MoveLeft('radio')" class="btn btn-info"><i class="mdi mdi-chevron-left"></i></a>
                                                            <a href="javascript: void(0);" onClick="MoveRight('radio')" class="btn btn-info"><i class="mdi mdi-chevron-right"></i></a>
                                                        </li>
                                                    </ul>
                                                </div>
                                                <div class="col-5">
                                                    <select multiple id="sort_radio_r" class="form-control" style="min-height:400px;">
                                                        <?php foreach ($rOrdered['radio'] as $rStream) { ?>
                                                            <option value="<?php echo intval($rStream['id']); ?>"><?php echo htmlspecialchars($rStream['stream_display_name']); ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                            <ul class="list-inline wizard mb-0 add-margin-top-20">
                                                <li class="list-inline-item">
                                                    <a href="javascript: void(0);" onClick="MoveUp('radio')" class="btn btn-purple"><i class="mdi mdi-chevron-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveDown('radio')" class="btn btn-purple"><i class="mdi mdi-chevron-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveTop('radio')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-up"></i></a>
                                                    <a href="javascript: void(0);" onClick="MoveBottom('radio')" class="btn btn-pink"><i class="mdi mdi-chevron-triple-down"></i></a>
                                                    <a href="javascript: void(0);" onClick="AtoZ('radio')" class="btn btn-info"><?php echo $_['a_to_z']; ?></a>
                                                </li>
                                                <li class="list-inline-item float-right">
                                                    <button type="submit" class="btn btn-primary waves-effect waves-light"><?php echo $_['save_changes']; ?></button>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php } ?>
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
        <?php if (!$rMobile || $rSettings['header_stats']): ?>
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

    function AtoZ(rType) {
        $("#sort_" + rType + "_l").append($("#sort_" + rType + "_l option").remove().sort(function(a, b) {
            var at = $(a).text().toUpperCase(),
                bt = $(b).text().toUpperCase();
            return (at > bt) ? 1 : ((at < bt) ? -1 : 0);
        }));
        $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
        $('#sort_' + rType + '_l option:selected').each(function() {
            $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);
        });
        $('#sort_' + rType + '_r').scrollTop($('#sort_' + rType + '_l').scrollTop());
    }

    function MoveLeft(rType) {
        if (($('#sort_' + rType + '_l option:selected')) && ($('#sort_' + rType + '_r option:selected').length > 0)) {
            var rSelected = $('#sort_' + rType + '_r option:selected');
            var rPosition = $('#sort_' + rType + '_l option:selected')[$('#sort_' + rType + '_l option:selected').length - 1].value;
            var rIncludePos = true;
            var rIDs = [];
            $(rSelected).each(function() {
                rIDs.push($(this).val());
                if ($(this).val() !== rPosition) {
                    $("#sort_" + rType + "_l option[value=" + $(this).val() + "]").remove();
                } else {
                    rIncludePos = false;
                }
            });
            $(rSelected).each(function() {
                if (($(this).val() !== rPosition) || (rIncludePos)) {
                    $("#sort_" + rType + "_l option[value=" + rPosition + "]").before(new Option($(this).text(), $(this).val()));
                }
            });
            $('#sort_' + rType + '_l option:selected').prop("selected", false);
            $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
            for (let i = 0; i < rIDs.length; ++i) {
                $("#sort_" + rType + "_l option[value=" + rIDs[i] + "]").prop("selected", true);
                $("#sort_" + rType + "_r option[value=" + rIDs[i] + "]").prop("selected", true);
            }
            $("#sort_" + rType + "_r option:selected")[0].scrollIntoView();
        } else {
            $.toast("Please select both a source and destination.");
        }
    }

    function MoveRight(rType) {
        if (($('#sort_' + rType + '_l option:selected')) && ($('#sort_' + rType + '_r option:selected').length > 0)) {
            var rSelected = $('#sort_' + rType + '_l option:selected');
            var rPosition = $('#sort_' + rType + '_r option:selected')[$('#sort_' + rType + '_r option:selected').length - 1].value;
            var rIncludePos = true;
            var rIDs = [];
            $(rSelected).each(function() {
                rIDs.push($(this).val());
                if ($(this).val() !== rPosition) {
                    $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").remove();
                } else {
                    rIncludePos = false;
                }
            });
            $(rSelected).each(function() {
                if (($(this).val() !== rPosition) || (rIncludePos)) {
                    $("#sort_" + rType + "_r option[value=" + rPosition + "]").before(new Option($(this).text(), $(this).val()));
                }
            });
            $('#sort_' + rType + '_r option:selected').prop("selected", false);
            $("#sort_" + rType + "_l").html($("#sort_" + rType + "_r").html());
            for (let i = 0; i < rIDs.length; ++i) {
                $("#sort_" + rType + "_l option[value=" + rIDs[i] + "]").prop("selected", true);
                $("#sort_" + rType + "_r option[value=" + rIDs[i] + "]").prop("selected", true);
            }
            $("#sort_" + rType + "_l option:selected")[0].scrollIntoView();
        } else {
            $.toast("Please select both a source and destination.");
        }
    }

    function MoveUp(rType) {
        var rSelected = $('#sort_' + rType + '_l option:selected');
        if (rSelected.length) {
            var rPrevious = rSelected.first().prev()[0];
            if ($(rPrevious).html() != '') {
                rSelected.first().prev().before(rSelected);
            }
        }
        $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
        $('#sort_' + rType + '_l option:selected').each(function() {
            $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);
        });
        $('#sort_' + rType + '_r').scrollTop($('#sort_' + rType + '_l').scrollTop());
    }

    function MoveDown(rType) {
        var rSelected = $('#sort_' + rType + '_l option:selected');
        if (rSelected.length) {
            rSelected.last().next().after(rSelected);
        }
        $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
        $('#sort_' + rType + '_l option:selected').each(function() {
            $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);
        });
        $('#sort_' + rType + '_r').scrollTop($('#sort_' + rType + '_l').scrollTop());
    }

    function MoveTop(rType) {
        var rSelected = $('#sort_' + rType + '_l option:selected');
        if (rSelected.length) {
            rSelected.prependTo($('#sort_' + rType + '_l'));
        }
        $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
        $('#sort_' + rType + '_l option:selected').each(function() {
            $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);
        });
        $('#sort_' + rType + '_r').scrollTop($('#sort_' + rType + '_l').scrollTop());
    }

    function MoveBottom(rType) {
        var rSelected = $('#sort_' + rType + '_l option:selected');
        if (rSelected.length) {
            rSelected.appendTo($('#sort_' + rType + '_l'));
        }
        $("#sort_" + rType + "_r").html($("#sort_" + rType + "_l").html());
        $('#sort_' + rType + '_l option:selected').each(function() {
            $("#sort_" + rType + "_r option[value=" + $(this).val() + "]").prop("selected", true);
        });
        $('#sort_' + rType + '_r').scrollTop($('#sort_' + rType + '_l').scrollTop());
    }
    $(document).ready(function() {
        $("form").submit(function(e) {
            e.preventDefault();
            rOrder = [];
            $('#sort_stream_l option').each(function() {
                rOrder.push($(this).val());
            });
            $('#sort_movie_l option').each(function() {
                rOrder.push($(this).val());
            });
            $('#sort_series_l option').each(function() {
                rOrder.push($(this).val());
            });
            $('#sort_radio_l option').each(function() {
                rOrder.push($(this).val());
            });
            $("#stream_order_array").val(JSON.stringify(rOrder));
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
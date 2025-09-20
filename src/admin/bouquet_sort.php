<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php
if (!isset(CoreUtilities::$rRequest['id']) || !($rBouquet = getBouquet(CoreUtilities::$rRequest['id']))) {
    goHome();
}

$rListings = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];
$rOrdered = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];
$rChannels = array_map('intval', json_decode($rBouquet['bouquet_channels'], true));
$rMovies = array_map('intval', json_decode($rBouquet['bouquet_movies'], true));
$rSeries = array_map('intval', json_decode($rBouquet['bouquet_series'], true));
$rRadios = array_map('intval', json_decode($rBouquet['bouquet_radios'], true));

populateListings($db, $rChannels, $rMovies, $rRadios, $rSeries, $rListings);
orderListings($rChannels, $rMovies, $rRadios, $rSeries, $rListings, $rOrdered);
?>

<?php include 'header.php'; ?>

<div class="wrapper boxed-layout">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= htmlspecialchars($rBouquet['bouquet_name']); ?></h4>
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
                        <?= $_['bouquet_order_success']; ?>
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" id="stream_order_array" name="stream_order_array" value="" />
                            <input type="hidden" name="reorder" value="<?= intval(CoreUtilities::$rRequest['id']); ?>" />
                            <div id="basicwizard">
                                <?php include 'tabs.php'; ?>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <?php foreach (['stream', 'movie', 'series', 'radio'] as $type) : ?>
                                        <?php include 'tab_content.php'; ?>
                                    <?php endforeach; ?>
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
            var rOrder = {
                "stream": [],
                "movie": [],
                "radio": [],
                "series": []
            };
            $('#sort_stream_l option').each(function() {
                rOrder["stream"].push($(this).val());
            });
            $('#sort_movie_l option').each(function() {
                rOrder["movie"].push($(this).val());
            });
            $('#sort_radio_l option').each(function() {
                rOrder["radio"].push($(this).val());
            });
            $('#sort_series_l option').each(function() {
                rOrder["series"].push($(this).val());
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
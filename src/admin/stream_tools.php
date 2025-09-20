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

    function decryptText() {
        var rText = $("#encrypted_text").val();
        $("#decrypted_text").val("");
        if (rText.length > 0) {
            $.getJSON("./api?action=decrypt_text&text=" + encodeURIComponent(rText), function(rData) {
                if ("data" in rData) {
                    $("#decrypted_text").val(rData.data.join("\n\n"));
                } else {
                    $.toast("Text could not be decrypted...");
                }
            });
        } else {
            $.toast("Please enter data in the encrypted text field.");
        }
    }

    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $("#dns_form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("#dns_form")[0]));
        });
        $("#move_form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("#move_form")[0]));
        });
    });
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
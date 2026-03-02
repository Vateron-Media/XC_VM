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



    function evaluateDirectSource() {
        $(["read_native", "movie_symlink", "auto_encode", "auto_upgrade", "remove_subtitles", "target_container", "transcode_profile_id"]).each(function(rID, rElement) {
            if ($(rElement)) {
                if ($("#direct_proxy").is(":checked")) {
                    if (window.rSwitches[rElement]) {
                        setSwitch(window.rSwitches[rElement], false);
                        window.rSwitches[rElement].disable();
                    } else {
                        $("#" + rElement).prop("disabled", true);
                    }
                } else {
                    if (window.rSwitches[rElement]) {
                        window.rSwitches[rElement].enable();
                    } else {
                        $("#" + rElement).prop("disabled", false);
                    }
                }
            }
        });
    }

    $(document).ready(function() {
        $('select').select2({
            width: '100%'
        });
        $("#scanPlex").click(function() {
            if (($("#plex_ip").val().length > 0) && ($("#plex_port").val().length > 0) && ($("#username").val().length > 0) && ($("#password").val().length > 0)) {
                $("#library_id").empty().trigger("change");
                $.getJSON("./api?action=plex_sections&ip=" + encodeURIComponent($("#plex_ip").val()) + "&port=" + encodeURIComponent($("#plex_port").val()) + "&username=" + encodeURIComponent($("#username").val()) + "&password=" + encodeURIComponent($("#password").val()), function(data) {
                    rLibraries = [];
                    if (data.result == true) {
                        for (i in data.data) {
                            rLibraries.push({
                                "key": data.data[i]["@attributes"]["key"],
                                "title": data.data[i]["@attributes"]["title"]
                            });
                            $("#library_id").append(new Option(data.data[i]["@attributes"]["title"], data.data[i]["@attributes"]["key"])).trigger('change');
                        }
                        $.toast("Libraries have been scanned and added to the list.");
                    } else {
                        $.toast("Failed to get libraries! Check your server credentials.");
                    }
                    $("#libraries").val(JSON.stringify(rLibraries));
                });
            } else {
                $.toast("Please fill in all Plex server information and credentials.");
            }
        });
        $("#direct_proxy").change(function() {
            evaluateDirectSource();
        });
        evaluateDirectSource();
        $("form").submit(function(e) {
            e.preventDefault();
            $(':input[type="submit"]').prop('disabled', true);
            submitForm(window.rCurrentPage, new FormData($("form")[0]));
        });
        $("#plex_port").inputFilter(function(value) {
            return /^\d*$/.test(value);
        });
    });
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
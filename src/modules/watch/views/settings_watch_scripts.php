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

        $(document).ready(function() {
            $("#scan_seconds").inputFilter(function(value) {
                return /^\d*$/.test(value);
            });
            $("#percentage_match").inputFilter(function(value) {
                return /^\d*$/.test(value);
            });
            $("#max_items").inputFilter(function(value) {
                return /^\d*$/.test(value);
            });
            $("#thread_count").inputFilter(function(value) {
                return /^\d*$/.test(value);
            });
            $('select').select2({
                width: '100%'
            });
            $("form").submit(function(e) {
                e.preventDefault();
                $(':input[type="submit"]').prop('disabled', true);
                submitForm(window.rCurrentPage, new FormData($("form")[0]));
            });
        });
    </script>
    <script src="assets/js/listings.js"></script>
    </body>

    </html>

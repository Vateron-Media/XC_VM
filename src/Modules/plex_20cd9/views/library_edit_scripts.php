<script>
    (function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }

        // Lightweight input filter (the legacy shell helper is gone in the new UI).
        $.fn.inputFilter = function (inputFilter) {
            return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function () {
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

        function evaluateDirectSource() {
            var disabled = $("#direct_proxy").is(":checked");
            ["read_native", "movie_symlink", "auto_encode", "auto_upgrade", "remove_subtitles", "target_container", "transcode_profile_id"].forEach(function (rElement) {
                var el = $("#" + rElement);
                el.prop("disabled", disabled);
                if (el.hasClass("select2-hidden-accessible")) {
                    el.trigger("change.select2");
                }
            });
        }

        $(function () {
            // Each control lives inside a .tab-pane, so anchor the dropdown there.
            $("select").each(function () {
                var opts = { width: "100%" };
                var pane = $(this).closest(".tab-pane");
                if (pane.length) {
                    opts.dropdownParent = pane;
                }
                $(this).select2(opts);
            });

            $("#scanPlex").click(function () {
                if (($("#plex_ip").val().length > 0) && ($("#plex_port").val().length > 0) && ($("#username").val().length > 0) && ($("#password").val().length > 0)) {
                    $("#library_id").empty().trigger("change");
                    $.getJSON("./api?action=plex_sections&ip=" + encodeURIComponent($("#plex_ip").val()) + "&port=" + encodeURIComponent($("#plex_port").val()) + "&username=" + encodeURIComponent($("#username").val()) + "&password=" + encodeURIComponent($("#password").val()), function (data) {
                        rLibraries = [];
                        if (data.result == true) {
                            for (i in data.data) {
                                rLibraries.push({
                                    "key": data.data[i]["@attributes"]["key"],
                                    "title": data.data[i]["@attributes"]["title"]
                                });
                                $("#library_id").append(new Option(data.data[i]["@attributes"]["title"], data.data[i]["@attributes"]["key"])).trigger('change');
                            }
                            window.xcToast("Libraries have been scanned and added to the list.");
                        } else {
                            window.xcToast("Failed to get libraries! Check your server credentials.", "error");
                        }
                        $("#libraries").val(JSON.stringify(rLibraries));
                    });
                } else {
                    window.xcToast("Please fill in all Plex server information and credentials.", "warning");
                }
            });
            $("#direct_proxy").change(function () {
                evaluateDirectSource();
            });
            evaluateDirectSource();
            $("form").submit(function (e) {
                e.preventDefault();
                var rButtons = $(':input[type="submit"]');
                rButtons.prop('disabled', true);
                // New-UI submit: the legacy submitForm()/rCurrentPage globals are not
                // loaded by the Bootstrap 5 shell — POST straight to post.php (action
                // plex_add → PlexService::processPlexSync) and follow its JSON.
                fetch('post.php?action=plex_add', { method: 'POST', body: new FormData($("form")[0]), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.text(); })
                    .then(function (txt) {
                        var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                        if (d && d.result && d.location) { window.location.href = d.location; return; }
                        rButtons.prop('disabled', false);
                        if (window.xcToast) { xcToast('An error occurred while processing your request.', 'error'); }
                    })
                    .catch(function () {
                        rButtons.prop('disabled', false);
                        if (window.xcToast) { xcToast('An error occurred while processing your request.', 'error'); }
                    });
            });
            $("#plex_port").inputFilter(function (value) {
                return /^\d*$/.test(value);
            });
        });
    })();
</script>
</body>

</html>

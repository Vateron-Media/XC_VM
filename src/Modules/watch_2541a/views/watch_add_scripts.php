<script>
    (function () {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        if ($.fn.dataTable) {
            $.fn.dataTable.ext.errMode = 'none';
        }

        function selectDirectory(elem) {
            window.currentDirectory += elem + "/";
            $("#selected_path").val(window.currentDirectory);
            $("#changeDir").click();
        }

        function selectParent() {
            $("#selected_path").val(window.currentDirectory.split("/").slice(0, -2).join("/") + "/");
            $("#changeDir").click();
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
            $("#datatable").DataTable({
                responsive: false,
                paging: false,
                bInfo: false,
                searching: false,
                scrollY: "250px",
                columnDefs: [{
                    "className": "dt-center",
                    "targets": [0]
                }, ],
                "language": {
                    "emptyTable": ""
                }
            });
            $("#datatable-files").DataTable({
                responsive: false,
                paging: false,
                bInfo: false,
                searching: true,
                scrollY: "250px",
                columnDefs: [{
                    "className": "dt-center",
                    "targets": [0]
                }, ],
                "language": {
                    "emptyTable": "No compatible files found"
                }
            });
            $("#changeDir").click(function () {
                window.currentDirectory = $("#selected_path").val();
                if (window.currentDirectory.substr(-1) != "/") {
                    window.currentDirectory += "/";
                }
                $("#selected_path").val(window.currentDirectory);
                $("#datatable").DataTable().clear();
                $("#datatable").DataTable().row.add(["", "Loading..."]);
                $("#datatable").DataTable().draw(true);
                $("#datatable-files").DataTable().clear();
                $("#datatable-files").DataTable().row.add(["", "Please wait..."]);
                $("#datatable-files").DataTable().draw(true);
                $.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=video", function (data) {
                    $("#datatable").DataTable().clear();
                    $("#datatable-files").DataTable().clear();
                    if (window.currentDirectory != "/") {
                        $("#datatable").DataTable().row.add(["<i class='icon-base ti tabler-arrow-up'></i>", "Parent Directory"]);
                    }
                    if (data.result == true) {
                        $(data.data.dirs).each(function (id, dir) {
                            $("#datatable").DataTable().row.add(["<i class='icon-base ti tabler-folder'></i>", dir]);
                        });
                        $("#datatable").DataTable().draw(true);
                        $(data.data.files).each(function (id, dir) {
                            $("#datatable-files").DataTable().row.add(["<i class='icon-base ti tabler-file'></i>", dir]);
                        });
                        $("#datatable-files").DataTable().draw(true);
                    }
                });
            });
            $('#datatable').on('click', 'tbody > tr', function () {
                if ($(this).find("td").eq(1).html() == "Parent Directory") {
                    selectParent();
                } else {
                    selectDirectory($(this).find("td").eq(1).html());
                }
            });
            $("#server_id").change(function () {
                $("#selected_path").val("/");
                $("#changeDir").click();
            });
            $("#changeDir").click();
            $("#folder_type").change(function () {
                if ($(this).val() == "movie") {
                    $("#category_movie").show();
                    $("#category_series").hide();
                    $("#fb_category_movie").show();
                    $("#fb_category_series").hide();
                } else {
                    $("#category_movie").hide();
                    $("#category_series").show();
                    $("#fb_category_movie").hide();
                    $("#fb_category_series").show();
                }
            });
            $("form").submit(function (e) {
                e.preventDefault();
                var rButtons = $(':input[type="submit"]');
                rButtons.prop('disabled', true);
                // New-UI submit: the legacy submitForm()/rCurrentPage globals are not
                // loaded by the Bootstrap 5 shell — POST straight to post.php (action
                // watch_add → WatchService::processWatchFolder) and follow its JSON.
                fetch('post.php?action=watch_add', { method: 'POST', body: new FormData($("form")[0]), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
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
        });
    })();
</script>
</body>

</html>

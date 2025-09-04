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
<?php include 'footer.php';
		echo '        ' . "\r\n" . '        function api(rID, rType) {' . "\r\n" . '            $.getJSON("./api?action=asn&sub=" + rType + "&id=" + rID, function(data) {' . "\r\n" . '                if (data.result === true) {' . "\r\n" . '                    if (rType == "block") {' . "\r\n" . '                        $.toast("ASN has been blocked.");' . "\r\n" . '                    } else if (rType == "allow") {' . "\r\n" . '                        $.toast("ASN has been allowed.");' . "\r\n" . '                    } else if (rType == "block_all") {' . "\r\n" . "                        \$.toast(\"All ASN's have been blocked.\");" . "\r\n" . '                    } else if (rType == "allow_all") {' . "\r\n" . "                        \$.toast(\"All ASN's have been allowed.\");" . "\r\n" . '                    }' . "\r\n" . '                    $("#datatable-users").DataTable().ajax.reload(null, false);' . "\r\n" . '                } else {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function getFilter() {' . "\r\n" . '            return $("#asn_filter").val();' . "\r\n" . '        }' . "\r\n" . '        function getType() {' . "\r\n" . '            return $("#asn_type").val();' . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n" . '            $("#datatable-users").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                responsive: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "asns",' . "\r\n" . '                        d.filter = getFilter(),' . "\r\n" . '                        d.type = getType()' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,3,4,5,6,7]}' . "\r\n" . '                ],' . "\r\n" . '                order: [[ 0, "asc" ]]' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-users").css("width", "100%");' . "\r\n" . "            \$('#asn_search_box').keyup(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . "                    \$('#datatable-users').DataTable().search(\$(this).val()).draw();" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . "            \$('#asn_show_entries').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . "                    \$('#datatable-users').DataTable().page.len(\$(this).val()).draw();" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . "            \$('#asn_filter').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    $("#datatable-users").DataTable().ajax.reload( null, false );' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . "            \$('#asn_type').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    $("#datatable-users").DataTable().ajax.reload( null, false );' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . "            if (\$('#asn_search_box').val()) {" . "\r\n" . "                \$('#datatable-users').DataTable().search(\$('#asn_search_box').val()).draw();" . "\r\n" . '            }' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
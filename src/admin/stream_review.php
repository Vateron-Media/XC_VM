<?php
include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (isset(CoreUtilities::$rRequest['save_changes'])) {
    $rChanges = array();

    foreach (array_keys(CoreUtilities::$rRequest) as $rKey) {
        $rSplit = explode('_', $rKey);

        if (!($rSplit[0] == 'modified' && CoreUtilities::$rRequest[$rKey] == 1)) {
        } else {
            $rID = intval($rSplit[1]);
            $rChanges[$rID] = array();

            foreach (array('name', 'channel_id', 'epg_id') as $rChangeKey) {
                $rChanges[$rID][$rChangeKey] = CoreUtilities::$rRequest[$rChangeKey . '_' . $rID];
            }

            foreach (array('bouquets', 'categories') as $rChangeKey) {
                $rChanges[$rID][$rChangeKey] = json_decode(CoreUtilities::$rRequest[$rChangeKey . '_' . $rID], true);
            }
        }
    }

    foreach ($rChanges as $rID => $rStream) {
        if (!CoreUtilities::$rRequest['save_bouquets']) {
        } else {
            $rHasBouquets = array();

            foreach (CoreUtilities::$rBouquets as $rBouquetID => $rBouquet) {
                if (!(in_array($rID, $rBouquet['streams']) || in_array($rID, $rBouquet['channels']))) {
                } else {
                    $rHasBouquets[] = $rBouquetID;
                }
            }
            $rDelBouquet = $rAddBouquet = array();

            foreach ($rHasBouquets as $rBouquetID) {
                if (in_array($rBouquetID, $rStream['bouquets'])) {
                } else {
                    removeFromBouquet('stream', $rBouquetID, $rID);
                }
            }

            foreach ($rStream['bouquets'] as $rBouquetID) {
                if (in_array($rBouquetID, $rHasBouquets)) {
                } else {
                    $rAddBouquet[] = $rBouquetID;
                    addToBouquet('stream', $rBouquetID, $rID);
                }
            }
        }

        if (CoreUtilities::$rRequest['save_categories'] && CoreUtilities::$rRequest['save_epg']) {
            $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ?, `channel_id` = ?, `epg_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', ($rStream['channel_id'] ?: null), (is_null($rStream['epg_id']) ? null : $rStream['epg_id']), $rID);
        } else {
            if (CoreUtilities::$rRequest['save_categories']) {
                $db->query('UPDATE `streams` SET `stream_display_name` = ?, `category_id` = ? WHERE `id` = ?;', $rStream['name'], '[' . implode(',', array_map('intval', $rStream['categories'])) . ']', $rID);
            } else {
                if (CoreUtilities::$rRequest['save_epg']) {
                    $db->query('UPDATE `streams` SET `stream_display_name` = ?, `channel_id` = ?, `epg_id` = ?, WHERE `id` = ?;', $rStream['name'], ($rStream['channel_id'] ?: null), (is_null($rStream['epg_id']) ? null : $rStream['epg_id']), $rID);
                } else {
                    $db->query('UPDATE `streams` SET `stream_display_name` = ? WHERE `id` = ?;', $rStream['name'], $rID);
                }
            }
        }
    }
    header('Location: ./streams?status=' . STATUS_SUCCESS);

    exit();
} else {
    if (!isset(CoreUtilities::$rRequest['streams'])) {
    } else {
        $rStreams = json_decode(CoreUtilities::$rRequest['streams'], true);
        $rCategories = getCategories('live');
        $rBouquets = getBouquets();
        $rStreamBouquets = array();
        foreach ($rBouquets as $rBouquet) {
            $rBouquetChannels = json_decode($rBouquet['bouquet_channels'], true);

            foreach ($rBouquetChannels as $rStreamID) {
                if (!in_array($rStreamID, $rStreams)) {
                } else {
                    $rStreamBouquets[$rStreamID][] = $rBouquet['id'];
                }
            }
        }
        $rOptions = array('categories' => isset(CoreUtilities::$rRequest['edit_categories']), 'epg' => isset(CoreUtilities::$rRequest['edit_epg']), 'bouquets' => isset(CoreUtilities::$rRequest['edit_bouquets']));
        $rWidth = array(25, 20, 20);

        if ($rOptions['categories'] || $rOptions['bouquets'] || $rOptions['epg']) {
        } else {
            $rWidth = array(90, 0, 0);
        }

        $rImport = array();

        if (0 >= count($rStreams)) {
        } else {
            $db->query('SELECT * FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreams)) . ');');

            foreach ($db->get_rows() as $rRow) {
                $rImport[] = array('id' => $rRow['id'], 'channel_id' => ($rRow['channel_id'] ?: ''), 'epg_id' => ($rRow['epg_id'] ?: ''), 'title' => ($rRow['stream_display_name'] ?: ''), 'category' => json_decode($rRow['category_id'], true), 'bouquets' => ($rStreamBouquets[$rRow['id']] ?: array()));
            }
        }

        if (count($rImport) != 0) {
        } else {
            $_STATUS = STATUS_NO_SOURCES;
            $rImport = null;
        }
    }
}
$_TITLE = 'Review';
include 'header.php';
?>

<div class="wrapper<?= $rImport ? '' : ' boxed-layout-ext' ?>"
    <?= empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : ' style="display: none;"' ?>>
    <div class="container-fluid">
        <form action="./stream_review" method="POST" id="stream_form" data-parsley-validate="">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">Mass Edit & Review <small id="selected_count"></small></h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_NO_SOURCES): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            No streams were selected for review.
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <?php if (isset($rImport)): ?>
                                <input type="hidden" name="save_changes" value="1" />
                                <input type="hidden" name="save_categories"
                                    value="<?= intval($rOptions['categories']) ?>" />
                                <input type="hidden" name="save_bouquets" value="<?= intval($rOptions['bouquets']) ?>" />
                                <input type="hidden" name="save_epg" value="<?= intval($rOptions['epg']) ?>" />
                                <?php
                                foreach ($rImport as $rStream) {
                                    include 'stream_import_logic.php';
                                }
                                ?>
                            <?php else: ?>
                                <input type="hidden" name="streams" id="streams" value="" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#stream-selection" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-play mr-1"></i>
                                                <span class="d-none d-sm-inline">Stream Selection</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="stream-selection">
                                            <div class="row" style="margin-bottom: 40px;">
                                                <label class="col-md-2 col-form-label" for="edit_categories">Edit
                                                    Categories</label>
                                                <div class="col-md-2">
                                                    <input name="edit_categories" id="edit_categories" type="checkbox"
                                                        checked data-plugin="switchery" class="js-switch"
                                                        data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-2 col-form-label" for="edit_bouquets">Edit
                                                    Bouquets</label>
                                                <div class="col-md-2">
                                                    <input name="edit_bouquets" id="edit_bouquets" type="checkbox" checked
                                                        data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                                <label class="col-md-2 col-form-label" for="edit_epg">Edit EPG</label>
                                                <div class="col-md-2">
                                                    <input name="edit_epg" id="edit_epg" type="checkbox" checked
                                                        data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                </div>
                                            </div>
                                            <div class="row">
                                                <div class="col-md-3 col-6">
                                                    <input type="text" class="form-control" id="stream_search" value=""
                                                        placeholder="Search Streams...">
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="category_search" class="form-control" data-toggle="select2">
                                                        <option value="" selected>All Categories</option>
                                                        <?php foreach (getCategories('live') as $rCategory): ?>
                                                            <option value="<?= intval($rCategory['id']) ?>"
                                                                <?= isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'] ? ' selected' : '' ?>>
                                                                <?= htmlspecialchars($rCategory['category_name']) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-3 col-6">
                                                    <select id="stream_filter" class="form-control" data-toggle="select2">
                                                        <option value="">No Filter</option>
                                                        <option value="1">Online</option>
                                                        <option value="2">Down</option>
                                                        <option value="3">Stopped</option>
                                                        <option value="4">Starting</option>
                                                        <option value="5">On Demand</option>
                                                        <option value="6">Direct</option>
                                                        <option value="7">Timeshift</option>
                                                        <option value="8">Looping</option>
                                                        <option value="9">Has EPG</option>
                                                        <option value="10">No EPG</option>
                                                    </select>
                                                </div>
                                                <div class="col-md-2 col-8">
                                                    <select id="show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                                            <option
                                                                <?= $rSettings['default_entries'] == $rShow ? ' selected' : '' ?>
                                                                value="<?= $rShow ?>">
                                                                <?= $rShow ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                                <div class="col-md-1 col-2">
                                                    <button type="button" class="btn btn-info waves-effect waves-light"
                                                        onClick="toggleStreams()">
                                                        <i class="mdi mdi-selection"></i>
                                                    </button>
                                                </div>
                                                <table id="datatable-mass" class="table table-borderless mb-0">
                                                    <thead class="bg-light">
                                                        <tr>
                                                            <th class="text-center">ID</th>
                                                            <th>Stream Name</th>
                                                            <th>Category</th>
                                                            <th class="text-center">Status</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n" . '        var rPages = [];' . "\r\n" . '        var rData = [];' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getFilter() {' . "\r\n\t\t\t" . 'return $("#stream_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n" . '        ';

		if (isset($rOptions) && $rOptions['epg']) {
			echo '        function clearEPG(elem) {' . "\r\n" . '            var rEPG = $("#epg_api_" + $(elem).data("id")).val();' . "\r\n" . '            if (rEPG) {' . "\r\n" . '                $("#modified_" + $(elem).data("id")).val(1);' . "\r\n" . '                $("#epg_api_" + $(elem).data("id")).val("").trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n";
		}

		echo '        function evaluateChanges() {' . "\r\n" . '            $(".name_input").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                $("#modified_" + rID).val(1);' . "\r\n" . '                $("#name_s_" + rID).val($(this).val());' . "\r\n" . '            });' . "\r\n" . '            ';

		if (!(isset($rOptions) && $rOptions['bouquets'])) {
		} else {
			echo '            $(".bouquet").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                $("#modified_" + rID).val(1);' . "\r\n" . '                $("#bouquets_s_" + rID).val(JSON.stringify($("#bouquets_" + rID).val()));' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		if (!(isset($rOptions) && $rOptions['categories'])) {
		} else {
			echo '            $(".category_id").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                $("#modified_" + rID).val(1);' . "\r\n" . '                $("#categories_s_" + rID).val(JSON.stringify($("#category_id_" + rID).val()));' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		if (isset($rOptions) && $rOptions['epg']) {
			echo '            $(".epg_api").change(function() {' . "\r\n" . '                var rID = $(this).data("id");' . "\r\n" . '                if (window.rData[rID]) {' . "\r\n" . '                    var rData = window.rData[rID];' . "\r\n" . '                    window.rData[rID] = null;' . "\r\n" . '                } else {' . "\r\n" . '                    var rData = $("#epg_api_" + rID).select2("data")[0];' . "\r\n" . '                }' . "\r\n" . '                $("#modified_" + rID).val(1);' . "\r\n" . '                if (rData) {' . "\r\n" . '                    $("#clear_epg_" + rID).removeClass("btn-secondary").addClass("btn-warning");' . "\r\n" . '                    $("#epg_type_s_" + rID).val(rData.type);' . "\r\n" . '                    if (rData.type == 1) {' . "\r\n" . '                        $("#view_epg_" + rID).removeClass("btn-secondary").addClass("btn-success");' . "\r\n" . '                        $("#view_epg_" + rID + " i").removeClass("far").addClass("fas");' . "\r\n" . '                        $("#epg_id_s_" + rID).val(0);' . "\r\n" . '                        $("#channel_id_s_" + rID).val(rData.id);' . "\r\n" . '                    } else {' . "\r\n" . '                        $("#view_epg_" + rID).removeClass("btn-success").addClass("btn-secondary");' . "\r\n" . '                        $("#view_epg_" + rID + " i").removeClass("fas").addClass("far");' . "\r\n" . '                        $("#epg_id_s_" + rID).val(rData.epg_id);' . "\r\n" . '                        $("#channel_id_s_" + rID).val(rData.id);' . "\r\n" . '                    }' . "\r\n" . '                } else {' . "\r\n" . '                    $("#clear_epg_" + rID).removeClass("btn-warning").addClass("btn-secondary");' . "\r\n" . '                    $("#view_epg_" + rID).removeClass("btn-success").addClass("btn-secondary");' . "\r\n" . '                    $("#view_epg_" + rID + " i").removeClass("fas").addClass("far");' . "\r\n" . '                    $("#epg_id_s_" + rID).val(0);' . "\r\n" . '                    $("#epg_type_s_" + rID).val(0);' . "\r\n" . '                    $("#channel_id_s_" + rID).val("");' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		echo '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            lazyload();' . "\r\n" . '            ' . "\r\n" . '            ';

		if (isset($rImport)) {
			if (!(isset($rOptions) && $rOptions['epg'])) {
			} else {
				echo "            \$('.epg_api').select2({" . "\r\n" . '              ajax: {' . "\r\n" . "                url: './api'," . "\r\n" . "                dataType: 'json'," . "\r\n" . '                data: function (params) {' . "\r\n" . '                  return {' . "\r\n" . '                    search: params.term,' . "\r\n" . "                    action: 'epglist'," . "\r\n" . '                    page: params.page' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                processResults: function (data, params) {' . "\r\n" . '                  params.page = params.page || 1;' . "\r\n" . '                  return {' . "\r\n" . '                    results: data.items,' . "\r\n" . '                    pagination: {' . "\r\n" . '                        more: (params.page * 100) < data.total_count' . "\r\n" . '                    }' . "\r\n" . '                  };' . "\r\n" . '                },' . "\r\n" . '                cache: true' . "\r\n" . '              },' . "\r\n" . "              placeholder: 'Search EPG API...'" . "\r\n" . '            });' . "\r\n" . '            ';
			}

			echo "\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    if ($.inArray($("#datatable").DataTable().page.info().page, window.rPages) == -1) {' . "\r\n" . '                        ';
			echo '                        window.rPages.push($("#datatable").DataTable().page.info().page);' . "\r\n" . '                    }' . "\r\n" . '                    evaluateChanges();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'bAutoWidth: false,' . "\r\n" . '                responsive: false,' . "\r\n" . '                searching: false,' . "\r\n" . '                bSort: false,' . "\r\n" . '                paging: true,' . "\r\n" . '                pageLength: 50,' . "\r\n" . '                lengthChange: false' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n" . '            $("#btn-submit").click(function() {' . "\r\n" . '                $("form").trigger("submit");' . "\r\n\t\t\t" . '});' . "\r\n" . '            ';
		} else {
			echo "\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "stream_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory(),' . "\r\n" . '                        d.filter = getFilter()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
			echo (intval($rSettings['default_entries']) ?: 10);
			echo "\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#stream_filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("#btn-submit").click(function() {' . "\r\n" . '                if (window.rSelected.length >= 250) {' . "\r\n" . '                    new jBox("Confirm", {' . "\r\n" . '                        confirmButton: "Review",' . "\r\n" . '                        cancelButton: "Cancel",' . "\r\n" . '                        content: "Are you sure you want to review " + window.rSelected.length + " streams at once?<br/>Editing too many can crash your browser or cause the edit page to load really slowly.",' . "\r\n" . '                        confirm: function () {' . "\r\n" . '                            $("form").trigger("submit");' . "\r\n" . '                        }' . "\r\n" . '                    }).open();' . "\r\n" . '                } else {' . "\r\n" . '                    $("form").trigger("submit");' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e) {' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Select at least one stream to edit.");' . "\r\n" . '                    e.preventDefault();' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . '                    $("#streams").val(JSON.stringify(window.rSelected));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n" . '            ';
		}

		echo "\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
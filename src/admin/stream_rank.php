<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rStreamTypes = array(1 => 'Live Stream', 2 => 'Movie', 3 => 'Created Channel', 4 => 'Radio Station', 5 => 'Episode');
$rPeriod = (CoreUtilities::$rRequest['period'] ?: 'all');
$db->query('SELECT `streams_stats`.*, `streams`.`stream_display_name` FROM `streams_stats` INNER JOIN `streams` ON `streams`.`id` = `streams_stats`.`stream_id` WHERE `streams_stats`.`type` = ? AND `streams`.`type` IN (1,3) GROUP BY `stream_id` ORDER BY `streams_stats`.`rank` ASC LIMIT 500;', $rPeriod);
$rRows = $db->get_rows();
$_TITLE = 'Stream Rank';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
    } else { ?>
    style="display: none;" <?php } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Stream Rank</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-7">
                                <input type="text" class="form-control" id="log_search" value=""
                                    placeholder="Search Logs...">
                            </div>
                            <div class="col-md-3">
                                <select id="period" class="form-control" data-toggle="select2">
                                    <option value="today" <?php if ($rPeriod == 'today') { ?> selected<?php } ?>>Today
                                    </option>
                                    <option value="week" <?php if ($rPeriod == 'week') { ?> selected<?php } ?>>This Week
                                    </option>
                                    <option value="month" <?php if ($rPeriod == 'month') { ?> selected<?php } ?>>This
                                        Month</option>
                                    <option value="all" <?php if ($rPeriod == 'all') { ?> selected<?php } ?>>All Time
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option<?php if ($rSettings['default_entries'] == $rShow) { ?> selected<?php } ?>
                                            value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity"
                            class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">Rank #</th>
                                    <th>Stream Name</th>
                                    <th class="text-center">Time Watched</th>
                                    <th class="text-center">Total Connections</th>
                                    <th class="text-center">Total Users</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $i = 0;
                                foreach ($rRows as $rRow) {
                                    $i++;
                                    $rTime = $rRow['time'];
                                    if (86400 <= $rTime) {
                                        $rTime = sprintf('%02dd %02dh %02dm', $rTime / 86400, ($rTime / 3600) % 24, ($rTime / 60) % 60);
                                    } else {
                                        $rTime = sprintf('%02dh %02dm %02ds', $rTime / 3600, ($rTime / 60) % 60, $rTime % 60);
                                    } ?>
                                    <tr>
                                        <td class="text-center"><?php echo $i; ?></td>
                                        <td><a
                                                href="stream_view?id=<?php echo intval($rRow['id']); ?>"><?php echo $rRow['stream_display_name']; ?></a>
                                        </td>
                                        <td class="text-center"><button type='button'
                                                class='btn btn-light btn-xs waves-effect waves-light btn-fixed'><?php echo $rTime; ?></button>
                                        </td>
                                        <td class="text-center">
                                            <button type='button'
                                                class='btn btn-light btn-xs waves-effect waves-light btn-fixed'><?php echo number_format($rRow['connections'], 0); ?></button>
                                        </td>
                                        <td class="text-center"><button type='button'
                                                class='btn btn-light btn-xs waves-effect waves-light btn-fixed'><?php echo number_format($rRow['users'], 0); ?></button>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php include 'footer.php';
		echo "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . "\$('#period').on('change', function() {" . "\r\n\t\t\t\t" . "navigate(\"stream_rank?period=\" + \$('#period').val());" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'var rTable = $("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3,4]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"order": [[ 0, "asc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n" . "            \$('#log_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
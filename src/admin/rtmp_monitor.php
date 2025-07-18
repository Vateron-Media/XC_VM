<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['server']) || !isset($rServers[CoreUtilities::$rRequest['server']])) {
    CoreUtilities::$rRequest['server'] = SERVER_ID;
}

$rRTMPInfo = getRTMPStats(CoreUtilities::$rRequest['server']);
$_TITLE = 'RTMP Monitor';
include 'header.php';
?>

<div class="wrapper"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li>
                                <a href="javascript:void(0);" onClick="navigate('rtmp_monitor');"
                                    style="margin-right:10px;">
                                    <button type="button" class="btn btn-dark waves-effect waves-light btn-sm">
                                        <i class="mdi mdi-refresh"></i> <?php echo $_['refresh']; ?>
                                    </button>
                                </a>
                            </li>
                        </ol>
                    </div>
                    <h4 class="page-title">RTMP Monitor</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table class="table table-borderless mb-0">
                            <thead class="thead-light">
                                <tr>
                                    <th class="text-center">RTMP PID</th>
                                    <th class="text-center">NGINX Version</th>
                                    <th class="text-center">FLV Version</th>
                                    <th class="text-center">Uptime</th>
                                    <th class="text-center">Input Mbps</th>
                                    <th class="text-center">Output Mbps</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td class="text-center"><?php echo $rRTMPInfo['pid']; ?></td>
                                    <td class="text-center"><?php echo $rRTMPInfo['nginx_version']; ?></td>
                                    <td class="text-center"><?php echo $rRTMPInfo['nginx_http_flv_version']; ?></td>
                                    <td class="text-center">
                                        <button type='button'
                                            class='btn btn-success btn-xs waves-effect waves-light btn-fixed'><?php
                                                                                                                $rUptime = $rRTMPInfo['uptime'];
                                                                                                                if ($rUptime >= 86400) {
                                                                                                                    echo sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
                                                                                                                } else {
                                                                                                                    echo sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
                                                                                                                }
                                                                                                                ?></button>
                                    </td>
                                    <td class="text-center">
                                        <?php echo number_format($rRTMPInfo['bw_in'] / 1000 / 1000, 2); ?> Mbps</td>
                                    <td class="text-center">
                                        <?php echo number_format($rRTMPInfo['bw_out'] / 1000 / 1000, 2); ?> Mbps</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <form id="line_activity_search">
                            <div class="form-group row mb-4">
                                <div class="col-md-4">
                                    <input type="text" class="form-control" id="live_search" value=""
                                        placeholder="Search Streams...">
                                </div>
                                <label class="col-md-1 col-form-label text-center"
                                    for="live_filter"><?php echo $_['server']; ?></label>
                                <div class="col-md-4">
                                    <select id="live_filter" class="form-control" data-toggle="select2">
                                        <?php foreach ($rServers as $rServer) { ?>
                                            <option value="<?php echo $rServer['id']; ?>"
                                                <?php if (CoreUtilities::$rRequest['server'] == $rServer['id']) {
                                                    echo ' selected';
                                                } ?>>
                                                <?php echo $rServer['server_name']; ?>
                                            </option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <label class="col-md-1 col-form-label text-center"
                                    for="live_show_entries"><?php echo $_['show']; ?></label>
                                <div class="col-md-2">
                                    <select id="live_show_entries" class="form-control" data-toggle="select2">
                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                            <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                        echo ' selected';
                                                    } ?>
                                                value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                            <?php } ?>
                                    </select>
                                </div>
                            </div>
                        </form>
                        <table id="datatable-activity"
                            class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>RTMP URL</th>
                                    <th class="text-center">Publisher IP</th>
                                    <th class="text-center">Uptime</th>
                                    <th class="text-center">Clients</th>
                                    <th class="text-center">Stream Info</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $rStreams = $rRTMPInfo['server']['application']['live']['stream'];
                                if (isset($rStreams['name'])) {
                                    $rStreams = array($rStreams);
                                }
                                foreach ($rStreams as $rStream) {
                                    if (isset($rStream['client']['id'])) {
                                        $rStream['client'] = array($rStream['client']);
                                    }
                                    $rClientCount = count($rStream['client']);
                                    $rPublisher = '';
                                    foreach ($rStream['client'] as $rClient) {
                                        if ($rStream['time'] <= $rClient['time']) {
                                            $rPublisher = "<a onClick=\"whois('" . $rClient['address'] . "');\" href='javascript: void(0);'>" . $rClient['address'] . '</a>';
                                            $rClientCount--;
                                            break;
                                        }
                                    }
                                    $rClients = $rClientCount > 0 ? "<button type='button' class='btn btn-info btn-xs waves-effect waves-light'>" . $rClientCount . '</button>' : "<button type='button' class='btn btn-secondary btn-xs waves-effect waves-light'>0</button>";
                                ?>
                                    <tr>
                                        <td class="text-center"><?php echo $rStream['name']; ?></td>
                                        <td><?php echo htmlspecialchars(CoreUtilities::$rServers[intval(CoreUtilities::$rRequest['server'])]['rtmp_server']) . $rStream['name']; ?>
                                        </td>
                                        <td class="text-center"><?php echo $rPublisher; ?></td>
                                        <td class="text-center">
                                            <button type='button'
                                                class='btn btn-success btn-xs waves-effect waves-light btn-fixed'>
                                                <?php
                                                $rUptime = $rStream['time'] / 1000;
                                                if ($rUptime >= 86400) {
                                                    echo sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
                                                } else {
                                                    echo sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
                                                }
                                                ?>
                                            </button>
                                        </td>
                                        <td class="text-center"><?php echo $rClients; ?></td>
                                        <td class="text-center">
                                            <div style="white-space: nowrap; width: 300px;" class="stream-info">
                                                <?php echo number_format($rStream['bw_in'] / 1000, 0); ?> Kbps<br>
                                                <?php echo $rStream['meta']['video']['width'] . ' x ' . $rStream['meta']['video']['height']; ?><br>
                                                <?php echo $rStream['meta']['video']['codec']; ?><br>
                                                <?php echo $rStream['meta']['audio']['codec']; ?><br>
                                                <?php echo round($rStream['meta']['video']['frame_rate'], 0); ?> FPS
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <button data-toggle="tooltip" title="Kill Stream" type="button"
                                                class="btn tooltip btn-light waves-effect waves-light btn-xs"
                                                onClick="kill(<?php echo intval(CoreUtilities::$rRequest['server']); ?>, '<?php echo $rStream['name']; ?>');"><i
                                                    class="mdi mdi-close"></ i></button>
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
<?php include 'footer.php'; ?>
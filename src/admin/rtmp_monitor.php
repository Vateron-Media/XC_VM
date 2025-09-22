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

			<?php endif; 
		echo '        ' . "\r\n\t\t" . 'function kill(rServerID, rID) {' . "\r\n\t\t\t" . '$.getJSON("./api?action=rtmp_kill&name=" + rID + "&server=" + rServerID, function(data) {' . "\r\n\t\t\t\t" . 'if (data.result === true) {' . "\r\n\t\t\t\t\t" . '$.toast("Stream has been killed. Unless you revoke authentication to this user, it will reconnect.");' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'order: [[ 0, "asc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#live_search').keyup(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_show_entries').change(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_filter').change(function(){" . "\r\n\t\t\t\t" . 'navigate("./rtmp_monitor?server=" + $(this).val());' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$('#live_search').val()).draw();" . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
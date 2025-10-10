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

			<?php
		echo "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . "\$('#period').on('change', function() {" . "\r\n\t\t\t\t" . "navigate(\"stream_rank?period=\" + \$('#period').val());" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'var rTable = $("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,2,3,4]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"order": [[ 0, "asc" ]],' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n" . "            \$('#log_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
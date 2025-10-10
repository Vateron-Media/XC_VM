<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rAudioCodecs = $rVideoCodecs = array();
$db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 1 ORDER BY `audio_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rAudioCodecs[] = $rRow['audio_codec'];
}
$db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 1 ORDER BY `video_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rVideoCodecs[] = $rRow['video_codec'];
}
$_TITLE = 'Streams';
include 'header.php';
echo '<div class="wrapper"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Streams</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    ';
	echo $_['stream_success'];
	echo '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <input type="text" class="form-control" id="stream_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="Search Streams...">' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_server_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['server'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['all_servers'];
echo '</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1)) {
} else {
	echo ' selected';
}

echo '>No Servers</option>' . "\n" . '                                    ';

foreach (getStreamingServers() as $rServer) {
	echo '                                    <option value="';
	echo intval($rServer['id']);
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rServer['server_name'];
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_category_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['category'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['all_categories'];
echo '</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1)) {
} else {
	echo ' selected';
}

echo '>No Categories</option>' . "\n" . '                                    ';

foreach (getCategories('live') as $rCategory) {
	echo '                                    <option value="';
	echo intval($rCategory['id']);
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCategory['category_name'];
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_filter" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>No Filter</option>' . "\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>Online</option>' . "\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>Down</option>' . "\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
} else {
	echo ' selected';
}

echo '>Stopped</option>' . "\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>Starting</option>' . "\n" . '                                    <option value="5"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>On Demand</option>' . "\n" . '                                    <option value="6"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6)) {
} else {
	echo ' selected';
}

echo '>Direct</option>' . "\n" . '                                    <option value="7"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 7)) {
} else {
	echo ' selected';
}

echo '>Timeshift</option>' . "\n" . '                                    <option value="8"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 8)) {
} else {
	echo ' selected';
}

echo '>Looping</option>' . "\n" . '                                    <option value="9"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 9)) {
} else {
	echo ' selected';
}

echo '>Has EPG</option>' . "\n" . '                                    <option value="10"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 10)) {
} else {
	echo ' selected';
}

echo '>No EPG</option>' . "\n" . '                                    <option value="11"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 11)) {
} else {
	echo ' selected';
}

echo '>Adaptive Link</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="12"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 12)) {
} else {
	echo ' selected';
}

echo '>Title Sync</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="13"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 13)) {
} else {
	echo ' selected';
}

echo '>Transcoding</option>' . "\n" . '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_audio" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['audio'])) {
} else {
	echo ' selected';
}

echo '>Audio</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == '-1')) {
} else {
	echo ' selected';
}

echo '>None</option>' . "\n" . '                                    ';

foreach ($rAudioCodecs as $rCodec) {
	echo '                                    <option value="';
	echo $rCodec;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == $rCodec)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCodec;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_video" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['video'])) {
} else {
	echo ' selected';
}

echo '>Video</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == '-1')) {
} else {
	echo ' selected';
}

echo '>None</option>' . "\n" . '                                    ';

foreach ($rVideoCodecs as $rCodec) {
	echo '                                    <option value="';
	echo $rCodec;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == $rCodec)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCodec;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_resolution" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['resolution'])) {
} else {
	echo ' selected';
}

echo '>Quality</option>' . "\n" . '                                    ';

foreach (array(240, 360, 480, 576, 720, 1080, 1440, 2160) as $rResolution) {
	echo '                                    <option value="';
	echo $rResolution;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['resolution']) && CoreUtilities::$rRequest['resolution'] == $rResolution)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rResolution;
	echo 'p</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if (isset(CoreUtilities::$rRequest['entries'])) {
		if (CoreUtilities::$rRequest['entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	} else {
		if ($rSettings['default_entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t\t" . '<table id="datatable-streampage" class="table table-borderless table-striped dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Icon</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Name</th>' . "\n" . '                                    ';

if ($rSettings['streams_grouped'] == 1) {
	echo "\t\t\t\t\t\t\t\t\t" . '<th>Servers</th>' . "\n" . '                                    ';
} else {
	echo '                                    <th>Source</th>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Clients</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Uptime</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Player</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">EPG</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Stream Info</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
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
		echo "\t\t" . 'var rClearing = false;' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).data("src");' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rImage,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function clearLogs() {' . "\r\n" . '            $.getJSON("./api?action=clear_failures&id=" + $(".bs-failures-modal-center").data("id"), function(data) {' . "\r\n" . '                if (data.result == true) {' . "\r\n" . '                    $.toast("Stream monitor logs have been cleared.");' . "\r\n" . '                    $(".bs-failures-modal-center").modal("hide");' . "\r\n" . '                } else {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                }' . "\r\n" . '            }).fail(function() {' . "\r\n" . '                $.toast("An error occured while processing your request.");' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function showFailures(rStreamID, rServerID) {' . "\r\n" . '            $("#datatable-stream").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: false,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n" . '                responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "failures_modal";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,2,3]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '                order: [[ 3, "desc" ]],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-failures-modal-center").data("id", rStreamID);' . "\r\n" . '            $(".bs-failures-modal-center").modal("show");' . "\r\n" . '            $("#datatable-stream").css("width", "100%");' . "\r\n" . '        }' . "\r\n" . '        function viewSources(rTitle, rID) {' . "\r\n" . '            $("#datatable-sources").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "streams";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rID;' . "\r\n" . '                        d.single = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [4,5,6,9]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,8]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-streams-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function viewEPG(rStreamID) {' . "\r\n" . '            $("#datatable-epg").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n" . '                responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                bSort: false,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "epg_modal";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-epg-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function viewLiveConnections(rStreamID, rServerID=-1) {' . "\r\n" . '            $("#datatable-live").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n" . '                    {"visible": false, "targets": [0,3,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-live-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function getStreamIDs() {' . "\r\n" . '            var rStreamIDs = [];' . "\r\n" . '            var rIndexes = [];' . "\r\n" . '            $("#datatable-streampage").DataTable().rows().every(function (rowIdx, tableLoop, rowLoop) {' . "\r\n" . '                rStreamIDs.push($($("#datatable-streampage").DataTable().row(rowIdx).data()[0]).text());' . "\r\n" . '                rIndexes.push(rowIdx);' . "\r\n" . '            });' . "\r\n" . '            return [rStreamIDs, rIndexes];' . "\r\n" . '        }' . "\r\n" . '        function refreshInformation() {' . "\r\n" . '            if (!window.rProcessing) {' . "\r\n" . '                var rUpdateColumns = [4,5,6,7,9];' . "\r\n" . '                var rStreamIDs = getStreamIDs();' . "\r\n" . '                if (rStreamIDs[0].length > 0) {' . "\r\n" . '                    $.getJSON("./table?" + $.param($("#datatable-streampage").DataTable().ajax.params()) + "&refresh=" + rStreamIDs[0].join(","), function(rTable) {' . "\r\n" . '                        if (!window.rProcessing) {' . "\r\n" . '                            $(rTable.data).each(function(rIndex, rItem) {' . "\r\n" . '                                for (i in rUpdateColumns) {' . "\r\n" . '                                    var rIndex = rStreamIDs[0].indexOf($(rItem[0]).text());' . "\r\n" . '                                    if (rIndex >= 0) {' . "\r\n" . "                                        if (\$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data() != rItem[rUpdateColumns[i]]) {" . "\r\n" . "                                            \$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data(rItem[rUpdateColumns[i]]);" . "\r\n" . '                                        }' . "\r\n" . '                                    }' . "\r\n" . '                                }' . "\r\n" . '                            });' . "\r\n" . '                            bindHref(); refreshTooltips(false);' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n" . '            clearTimeout(window.rRefresh);' . "\r\n" . '            window.rRefresh = setTimeout(refreshInformation, 5000);' . "\r\n" . '        }' . "\r\n\t\t" . 'function api(rID, rServerID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this stream?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rServerID == "kill") && (!rConfirm)) {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '                rServerID = -1;' . "\r\n" . '                rType = "kill";' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=stream&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Stream successfully started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Stream successfully stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Stream successfully restarted.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Stream successfully deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-streams-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-sources").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable-streampage tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these streams?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=stream&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Streams have been started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Streams have been stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Streams have been restarted.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Streams have been deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function player(rID) {' . "\r\n\t\t\t" . '$.magnificPopup.open({' . "\r\n\t\t\t\t" . 'items: {' . "\r\n\t\t\t\t\t" . 'src: "./player?type=live&id=" + rID,' . "\r\n\t\t\t\t\t" . "type: 'iframe'" . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#stream_category_id").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getFilter() {' . "\r\n\t\t\t" . 'return $("#stream_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function getServer() {' . "\r\n\t\t\t" . 'return $("#stream_server_id").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getVideo() {' . "\r\n\t\t\t" . 'return $("#stream_video").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getAudio() {' . "\r\n\t\t\t" . 'return $("#stream_audio").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getResolution() {' . "\r\n\t\t\t" . 'return $("#stream_resolution").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function clearFilters() {' . "\r\n\t\t\t" . 'window.rClearing = true;' . "\r\n\t\t\t" . "\$(\"#stream_search\").val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#stream_filter').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#stream_server_id').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#stream_category_id').val(\"\").trigger('change');" . "\r\n" . "            \$('#stream_audio').val(\"\").trigger('change');" . "\r\n" . "            \$('#stream_video').val(\"\").trigger('change');" . "\r\n" . "            \$('#stream_resolution').val(\"\").trigger('change');" . "\r\n\t\t\t" . "\$('#stream_show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n\t\t\t" . 'window.rClearing = false;' . "\r\n\t\t\t" . "\$('#datatable-streampage').DataTable().search(\$(\"#stream_search\").val());" . "\r\n\t\t\t" . "\$('#datatable-streampage').DataTable().page.len(\$('#stream_show_entries').val());" . "\r\n\t\t\t" . "\$(\"#datatable-streampage\").DataTable().page(0).draw('page');" . "\r\n\t\t\t" . '$("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n\t\t\t" . 'delParams(["search", "server", "filter", "category", "page", "entries", "video", "audio", "resolution"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "server", "category", "filter", "video", "audio", "resolution"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n" . '        var rSearch;' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-streampage").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    window.rProcessing = false;' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-streampage").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-streampage").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    clearTimeout(window.rRefresh);' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().rows().count() <= 50) {' . "\r\n" . '                        setTimeout(refreshInformation, 5000);' . "\r\n" . '                    }' . "\r\n" . '                    ';

		if (!hasPermissions('adv', 'edit_stream')) {
		} else {
			echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable-streampage tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_streams_selected").html(window.rSelected.length + " streams");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "streams",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory();' . "\r\n\t\t\t\t\t\t" . 'd.filter = getFilter();' . "\r\n\t\t\t\t\t\t" . 'd.server = getServer();' . "\r\n" . '                        d.audio = getAudio();' . "\r\n" . '                        d.video = getVideo();' . "\r\n" . '                        d.resolution = getResolution();' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,4,5,6,7,8,9]},' . "\r\n\t\t\t\t\t";

		if (CoreUtilities::$rSettings['redis_handler']) {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [4,6,7]},' . "\r\n\t\t\t\t\t";
		} else {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [6,7]},' . "\r\n\t\t\t\t\t";
		}

		echo '                    ';

		if ($rSettings['show_images']) {
		} else {
			echo '                    {"visible": false, "targets": [1]}' . "\r\n" . '                    ';
		}

		echo "\t\t\t\t" . '],' . "\r\n" . '                ';

		if (!$rMobile) {
		} else {
			echo 'scrollX: true,';
		}

		echo "\t\t\t\t" . 'order: [[ ';
		echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 0);
		echo ', "';
		echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
		echo '" ]],' . "\r\n\t\t\t\t" . 'pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n\t\t\t" . "}).on('processing.dt', function (e, settings, processing) {" . "\r\n" . '                window.rProcessing = processing;' . "\r\n" . '            });' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n\t\t\t" . '$("#datatable-streampage").css("width", "100%");' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#stream_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#stream_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'doSearch($(this).val());' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_show_entries').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#stream_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#stream_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_category_id').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#stream_category_id").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("category", $("#stream_category_id").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("category");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_server_id').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#stream_server_id").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#stream_server_id").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_filter').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n\t\t\t\t\t" . 'if ($("#stream_filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#stream_filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#stream_audio').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#stream_audio").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("audio", $("#stream_audio").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("audio");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#stream_video').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#stream_video").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("video", $("#stream_video").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("video");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#stream_resolution').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#stream_resolution").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("resolution", $("#stream_resolution").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("resolution");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "if (\$('#stream_search').val()) {" . "\r\n\t\t\t\t" . "rTable.search(\$('#stream_search').val()).draw();" . "\r\n\t\t\t" . '}' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-streampage").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'checkClear();' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
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

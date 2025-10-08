<?php

if (count(get_included_files()) == 1) {
	exit();
}

$_PAGE = getPageName();
include 'modals.php';

if (!isset($rModal) || !$rModal) {
	echo '        <footer class="footer">' . "\r\n" . '            ';

	if (!$rMobile) {
		echo '            <a href="#" class="scrollToTop" id="scrollToBottom"><button type="button" class="btn btn-info waves-effect waves-light"><i class="fas fa-caret-down"></i></button></a>' . "\r\n" . '            <a href="#" class="scrollToTop" id="scrollToTop"><button type="button" class="btn btn-success waves-effect waves-light"><i class="fas fa-caret-up"></i></button></a>' . "\r\n" . '            ';
	}

	echo '            <div class="container-fluid">' . "\r\n" . '                <div class="row">' . "\r\n" . '                    <div class="col-md-12 copyright text-center">';
	echo getFooter();
	echo '</div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </footer>' . "\r\n\t\t";
}

echo '        <script src="assets/js/vendor.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-toast/jquery.toast.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-nice-select/jquery.nice-select.min.js"></script>' . "\r\n" . '        <script src="assets/libs/switchery/switchery.min.js"></script>' . "\r\n" . '        <script src="assets/libs/select2/select2.min.js"></script>' . "\r\n" . '        <script src="assets/libs/nestable2/jquery.nestable.min.js"></script>' . "\r\n" . '        <script src="assets/libs/bootstrap-touchspin/jquery.bootstrap-touchspin.min.js"></script>' . "\r\n" . '        <script src="assets/libs/bootstrap-maxlength/bootstrap-maxlength.min.js"></script>' . "\r\n" . '        <script src="assets/libs/clockpicker/bootstrap-clockpicker.min.js"></script>' . "\r\n" . '        <script src="assets/libs/moment/moment.min.js"></script>' . "\r\n" . '        <script src="assets/libs/daterangepicker/daterangepicker.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/jquery.dataTables.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.bootstrap4.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.responsive.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/responsive.bootstrap4.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.buttons.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/buttons.bootstrap4.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/buttons.html5.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/buttons.flash.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/buttons.print.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.keyTable.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.select.min.js"></script>' . "\r\n" . '        <script src="assets/libs/datatables/dataTables.rowReorder.js"></script>' . "\r\n" . '        <script src="assets/libs/twitter-bootstrap-wizard/jquery.bootstrap.wizard.min.js"></script>' . "\r\n" . '        <script src="assets/libs/treeview/jstree.min.js"></script>' . "\r\n" . '        <script src="assets/libs/quill/quill.min.js"></script>' . "\r\n" . '        <script src="assets/libs/magnific-popup/jquery.magnific-popup.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jbox/jBox.all.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-knob/jquery.knob.min.js"></script>' . "\r\n" . '        <script src="assets/libs/apexcharts/apexcharts.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-number/jquery.number.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-vectormap/jquery-jvectormap-1.2.2.min.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-vectormap/jquery-jvectormap-world-mill-en.js"></script>' . "\r\n" . '        <script src="assets/libs/jquery-ui/jquery-ui.min.js"></script>' . "\r\n" . '        <script src="assets/libs/peity/jquery.peity.min.js"></script>' . "\r\n\t\t" . '<script src="assets/libs/emodal/emodal.js"></script>' . "\r\n" . '        <script src="assets/libs/bootstrap-colorpicker/bootstrap-colorpicker.min.js"></script>' . "\r\n" . '        <script src="assets/libs/lazyload/lazyload.min.js"></script>' . "\r\n" . '        <script src="assets/libs/parsleyjs/parsley.min.js"></script>' . "\r\n" . '        <script src="assets/js/app.min.js"></script>' . "\r\n" . '        ';
if (!isset($_SETUP)):
	include 'post.php';
?>

	<script>
		var rRealURL = undefined;
		var jBoxes = [];
		var rOptions = [];
		var rSwitches = [];
		var rRefresh = null;
		var rProcessing = false;
		var rSearchTop = 0;
		var rShiftHeld = false;

		window.XC_VM = window.XC_VM || {};
		window.XC_VM.Listings = window.XC_VM.Listings || {};

		function hideModal(rName, rDispose = false) {
			$(rName).modal("hide");
			if (rDispose) {
				$(rName).modal("dispose");
			}
			$(rName).css("display", "none");
		}

		function showError(rText) {
			$.toast({
				text: rText,
				icon: 'warning',
				loader: true,
				loaderBg: '#c62828',
				hideAfter: 8000
			})
		}

		function showSuccess(rText) {
			$.toast({
				text: rText,
				icon: 'success',
				loader: true,
				hideAfter: 5000
			})
		}

		function findRowByID(rTable, rColumn, rID) {
			for (rRow in rTable.rows()[0]) {
				if ($(rTable.row(rRow).data()[rColumn]).data("id") == rID) {
					return rRow;
				} else if ((rTable.row(rRow).data()[rColumn] == rID) || ($(rTable.row(rRow).data()[rColumn]).text() == rID)) {
					return rRow;
				}
			}
			return null;
		}

		function isValidDomain(domain) {
			var re = new RegExp(/^((?:(?:(?:\w[\.\-\+]?)*)\w)+)((?:(?:(?:\w[\.\-\+]?){0,62})\w)+)\.(\w{2,16})$/);
			return domain.match(re);
		}

		function isValidIP(rIP) {
			if (/^(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.(25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/.test(rIP)) {
				return true;
			} else {
				return false;
			}
		}

		function isValidDate(dateString) {
			var regEx = /^\d{4}-\d{2}-\d{2}$/;
			if (!dateString.match(regEx)) return false; // Invalid format
			var d = new Date(dateString);
			var dNum = d.getTime();
			if (!dNum && dNum !== 0) return false; // NaN value, Invalid date
			return d.toISOString().slice(0, 10) === dateString;
		}

		function isNumberKey(evt) {
			var charCode = (evt.which) ? evt.which : evt.keyCode;
			if (charCode != 46 && charCode != 45 && charCode > 31 && (charCode < 48 || charCode > 57)) {
				return false;
			} else {
				return true;
			}
		}

		function pingSession() {
			$.getJSON("./session", function(data) {
				if (!data.result) {
					window.location.href = './login?referrer=' + encodeURIComponent(location.href.split("/").slice(-1)[0]);
				}
			});
			setTimeout(pingSession, 30000);
		}

		function modalFingerprint(rID, rType = "stream") {
			$(".quick_search").select2("close");
			$("#topnav-search").removeClass("active");
			refreshTooltips();
			$('#mod_fingerprint_type').select2();
			$("#mod_custom_message_div").hide();
			$("#mod_fingerprint_type").val(1);
			$("#mod_font_size").val(36);
			$("#mod_font_color").val("#ffffff");
			$("#mod_position_x").val(10);
			$("#mod_position_y").val(10);
			$("#mod_custom_message").val("");
			$(".fingerprintModal").data("id", rID);
			$(".fingerprintModal").data("type", rType)
			$('.fingerprintModal').modal('show');
		}

		function setModalFingerprint() {
			rArray = {
				"id": $(".fingerprintModal").data("id"),
				"font_size": $("#mod_font_size").val(),
				"font_color": $("#mod_font_color").val(),
				"message": "",
				"type": $("#mod_fingerprint_type").val(),
				"xy_offset": ""
			};
			if ($(".fingerprintModal").data("type") == "user") {
				rArray["user"] = true;
			}
			if (rArray.type == 3) {
				rArray["message"] = $("#mod_custom_message").val();
			}
			if (($("#mod_position_x").val() >= 0) && ($("#mod_position_y").val() >= 0)) {
				rArray["xy_offset"] = $("#mod_position_x").val() + "x" + $("#mod_position_y").val();
			}
			if ((rArray["font_size"] > 0) && (rArray["font_color"]) && ((rArray["message"]) || (rArray["type"] != 3)) && (rArray["font_size"] > 0) && (rArray["xy_offset"])) {
				$.getJSON("./api?action=fingerprint&data=" + encodeURIComponent(JSON.stringify(rArray)), function(data) {
					if (data.result == true) {
						$.toast("Fingerprint signal has been sent.");
					} else {
						$.toast("<?php echo $_['error_occured']; ?>");
					}
				});
			} else {
				$.toast("<?php echo $_['fingerprint_fail']; ?>");
			}
			$(".fingerprintModal").modal("hide");
		}

		function setSwitch(switchElement, checkedBool) {
			if ((checkedBool && !switchElement.isChecked()) || (!checkedBool && switchElement.isChecked())) {
				switchElement.setPosition(true);
				switchElement.handleOnchange(true);
			}
		}
		<?php if ($rSettings['js_navigate']): ?>

			function reloadMenu() {
				$(".navigation-menu a").each(function() {
					($(this).removeClass("active"), $(this).parent().removeClass("active"), $(this).parent().parent().removeClass("in"), $(this).parent().parent().prev().removeClass("active"), $(this).parent().parent().parent().removeClass("active"), $(this).parent().parent().parent().parent().removeClass("in"), $(this).parent().parent().parent().parent().parent().removeClass("active"));
				});
				$(".navigation-menu a").each(function() {
					var rURL = window.location.href.split(/[?#]/)[0];
					if (this.href == rURL) {
						($(this).addClass("active"), $(this).parent().addClass("active"), $(this).parent().parent().addClass("in"), $(this).parent().parent().prev().addClass("active"), $(this).parent().parent().parent().addClass("active"), $(this).parent().parent().parent().parent().addClass("in"), $(this).parent().parent().parent().parent().parent().addClass("active"));
					}
				});
				setTimeout(fadeRipple, 350);
			}

			function fadeRipple() {
				$(".waves-ripple").attr("style", "");
			}

			function resetKnobs() {
				$('[data-plugin="knob"]').each(function(t, i) {
					$(this).knob({
						'format': function(value) {
							return value + '%';
						}
					})
				});
				$('[data-plugin="peity-line"]').each(function(t, i) {
					$(this).peity("line", $(this).data());
				});
			}

			function killTimeouts() {
				var highestTimeoutId = setTimeout(";");
				for (var i = 0; i < highestTimeoutId; i++) {
					clearTimeout(i);
				}
			}

			function deleteSwitches() {
				$(window.rSwitches).each(function() {
					$(this).destroy();
				});
				$(".switchery").remove();
			}

			function deleteSelect2() {
				$("select").each(function() {
					if ($(this).data('select2')) {
						$(this).select2('destroy');
					}
				});
			}

			function navigate(rURL, rNewTab = false) {
				if ((window.rCurrentPage) && (rURL == window.rCurrentPage + "#")) {
					return false;
				}
				if (rURL.split("#")[0].split("?")[0] == "epg_view") {
					window.location.href = rURL;
					window.location.reload();
					return;
				}
				if (window.rShiftHeld) {
					return;
				}
				if (rNewTab) {
					window.open(rURL, '_blank');
					return;
				}
				$(".wrapper").off().find("*").off();
				$(".modal").off().find("*").off();
				$(".submenu").hide();
				hideModal('.modal', true);
				$(".modal-backdrop").hide();
				$(".wrapper").fadeOut();
				$(".jBox-wrapper").hide();
				$(window.jBoxes).each(function() {
					this.close();
				});
				window.rReferer = window.location.href.split("/").reverse()[0];
				window.rCurrentPage = rURL.split("/").reverse()[0].split("?")[0].split(".")[0];
				setURL(rURL, false);
				killTimeouts();
				clearTimeout(window.rRefresh);
				deleteSwitches();
				deleteSelect2();
				reloadMenu();
				$.magnificPopup.close();
				$("#status").fadeIn();
				$(".jq-toast-single").remove();
				$('html, body').animate({
					scrollTop: 0
				}, 400);
				$("#header_stats").show();
				$(".app-search").width(150);
				$("#topnav-search").removeClass("active");
				$(".multiselect").hide();
				$.get(rURL, function(rData) {
					if ($(rData).filter("title").data("id") == "login") {
						window.location.href = './login?referrer=' + encodeURIComponent(location.href.split("/").slice(-1)[0]);
					}
					var rTables = $.fn.dataTable.fnTables(true);
					$(rTables).each(function() {
						$(this).DataTable().destroy(true);
					});
					document.title = $(rData).filter("title").text();
					$(".wrapper").replaceWith($(rData).filter(".wrapper"));
					$("#scripts").replaceWith($(rData).filter("#scripts"));
					$("#topnav-custom").replaceWith($(rData).filter("#topnav").find("#topnav-custom"));
					jQuery.ready();
					resetKnobs();
					bindHref();
					refreshTooltips();
					if ($(".nav")) {
						$(".nav:first").find(".nav-link:first").trigger("click");
					}
					$(".wrapper").fadeIn();
					$("#status").fadeOut();
					$(':input[type="submit"]').prop('disabled', false);
				}).fail(function() {
					window.location.href = rURL;
				});
			}
		<?php else: ?>

			function navigate(rURL) {
				window.location.href = rURL;
			}
		<?php endif; ?>

		function reloadPage() {
			navigate(window.location.href);
		}

		function refreshTooltips(rModals = true) {
			if (rModals) {
				$(".jBox-wrapper").hide();
			}
			$(window.jBoxes).each(function() {
				this.close();
				this.destroy();
			});
			window.jBoxes = [];
			$('.tooltip-left').each(function() {
				window.jBoxes.push(new $(this).jBox('Tooltip', {
					theme: 'TooltipDark',
					position: {
						x: 'left',
						y: 'center'
					},
					outside: 'x'
				}));
			});
			$('.tooltip-right').each(function() {
				window.jBoxes.push(new $(this).jBox('Tooltip', {
					theme: 'TooltipDark',
					position: {
						x: 'right',
						y: 'center'
					},
					outside: 'x'
				}));
			});
			$('.tooltip').each(function() {
				window.jBoxes.push(new $(this).jBox('Tooltip', {
					theme: 'TooltipDark'
				}));
			});
		}

		function setURL(rURL, rReplace = true) {
			if (rReplace) {
				window.history.replaceState({}, '', rURL);
			} else {
				if (window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0] != rURL.split("/").reverse()[0].split("?")[0].split(".")[0]) {
					window.history.pushState({}, '', rURL);
				}
				window.rRealURL = rURL;
			}
		}

		function delParams(rParams) {
			$.each(rParams, function(rIndex, rParam) {
				delParam(rParam);
			});
		}

		function delParam(rParam) {
			var rURL = new URL(document.location);
			rURL.searchParams.delete(rParam);
			setURL(rURL);
		}

		function setParam(rParam, rValue) {
			var rURL = new URL(document.location);
			rURL.searchParams.set(rParam, rValue);
			setURL(rURL);
		}

		function getParam(rParam) {
			var rURL = new URL(document.location);
			return rURL.searchParams.get(rParam);
		}

		function hasParam(rParam) {
			var rURL = new URL(document.location);
			return rURL.searchParams.has(rParam);
		}

		function hasParams(rParams, rAll = false) {
			var rURL = new URL(document.location);
			var rCount = 0;
			$.each(rParams, function(rIndex, rParam) {
				if (rURL.searchParams.has(rParam)) {
					rCount += 1;
				}
			});
			if (((rCount > 0) && (!rAll)) || (rCount == rParams.length)) {
				return true;
			} else {
				return false;
			}
		}

		function bindHref() {
			<?php if ($rSettings['js_navigate']): ?>
				$("a").each(function() {
					if (($(this).attr("href")) && ($(this).data("modal") === undefined) && ($(this).attr("href").slice(0, 1) != "#") && ($(this).attr("href").slice(0, 11) != "javascript:") && ($(this).attr("href").slice(0, 8) != "epg_view")) {
						$(this).unbind("click");
						$(this).click(function(e) {
							navigate($(this).attr("href"), e.ctrlKey);
							e.preventDefault();
						});
					}
				});
			<?php endif; ?>
		}

		function whois(rIP) {
			$("#whoisLabel").html(rIP);
			var rRows = $("#whois-table tr");
			$(rRows).each(function() {
				$(this).find("td").html("");
			});
			$.getJSON("./api?action=ip_whois&isp=1&ip=" + encodeURIComponent(rIP), function(rData) {
				if ("data" in rData) {
					if (("continent" in rData.data) && ("names" in rData.data.continent) && ("en" in rData.data.continent.names)) {
						$(rRows[1]).find("td").html(rData.data.continent.names.en);
					}
					if (("country" in rData.data) && ("names" in rData.data.country) && ("en" in rData.data.country.names)) {
						$(rRows[2]).find("td").html(rData.data.country.names.en);
					}
					if (("city" in rData.data) && ("names" in rData.data.city) && ("en" in rData.data.city.names)) {
						$(rRows[3]).find("td").html(rData.data.city.names.en);
					}
					if (("postal" in rData.data) && ("code" in rData.data.postal)) {
						$(rRows[4]).find("td").html(rData.data.postal.code);
					}
					if (("location" in rData.data) && ("latitude" in rData.data.location) && ("longitude" in rData.data.location)) {
						$(rRows[5]).find("td").html(rData.data.location.latitude + ", " + rData.data.location.longitude);
					}
					if (("isp" in rData.data) && (rData.data.isp)) {
						if ("isp" in rData.data.isp) {
							$(rRows[7]).find("td").html(rData.data.isp.isp);
						}
						if ("organization" in rData.data.isp) {
							$(rRows[8]).find("td").html(rData.data.isp.organization);
						}
						if (("autonomous_system_number" in rData.data.isp) && ("autonomous_system_organization" in rData.data.isp)) {
							$(rRows[9]).find("td").html("AS" + rData.data.isp.autonomous_system_number.toString() + " - " + rData.data.isp.autonomous_system_organization);
						}
						if (rData.data.type) {
							$(rRows[10]).find("td").html(rData.data.type.toUpperCase());
						}
					}
					if (("location" in rData.data) && ("time_zone" in rData.data.location)) {
						$(rRows[12]).find("td").html(rData.data.location.time_zone);
					}
					if (("location" in rData.data) && ("time" in rData.data.location)) {
						$(rRows[13]).find("td").html(rData.data.location.time);
					}
				}
			});
			$(".bs-whois-modal-center").modal("show");
		}

		function headerStats() {
			rURL = "./api?action=header_stats";
			$.getJSON(rURL, function(data) {
				$("#header_connections").html($.number(data.total_connections, 0));
				$("#header_users").html($.number(data.total_users, 0));
				$("#header_network_up").html($.number(Math.floor(data.bytes_sent / 125000), 0));
				$("#header_network_down").html($.number(Math.floor(data.bytes_received / 125000), 0));
				$("#header_streams_up").html($.number(data.total_running_streams, 0));
				$("#header_streams_down").html($.number(data.offline_streams, 0));
				setTimeout(headerStats, 1000);
			}).fail(function() {
				setTimeout(headerStats, 1000);
			});
		}

		function addCredits(rID) {
			$('.creditsModal').data('id', rID);
			$("#credits_reason").val("");
			$("#credits").val(0);
			$('.creditsModal').modal('show');
			$(".quick_search").select2("close");
			$("#topnav-search").removeClass("active");
			refreshTooltips();
		}

		function submitCredits() {
			var rID = $('.creditsModal').data('id');
			var rReason = $("#credits_reason").val();
			var rCredits = $("#credits").val();
			$.getJSON("./api?action=adjust_credits&id=" + encodeURIComponent(rID) + "&reason=" + encodeURIComponent(rReason) + "&credits=" + encodeURIComponent(rCredits), function(data) {
				if (data.result === true) {
					$.toast("Credits have been adjusted.");
					refreshTable();
				} else {
					$.toast("Credits could not be adjusted.");
				}
			});
			hideModal('.creditsModal');
		}

		function searchAPI(rType, rID, rAction, rConfirm = false) {
			if (rType == "stream") {
				if ((rAction == "purge") && (!rConfirm)) {
					new jBox("Confirm", {
						confirmButton: "Kill",
						cancelButton: "Cancel",
						content: "Are you sure you want to kill all connections?",
						confirm: function() {
							searchAPI(rType, rID, rAction, true);
						}
					}).open();
				} else {
					rConfirm = true;
				}
				if (rConfirm) {
					$.getJSON("./api?action=stream&sub=" + rAction + "&stream_id=" + rID + "&server_id=-1", function(data) {
						if (data.result == true) {
							if (rAction == "start") {
								$.toast("Stream successfully started.");
							} else if (rAction == "stop") {
								$.toast("Stream successfully stopped.");
							} else if (rAction == "restart") {
								$.toast("Stream successfully restarted.");
							} else if (rAction == "purge") {
								$.toast("Connections have been killed.");
							}
							$(".bigdrop .select2-search__field").trigger("input");
							window.rSearchTop = $(".bigdrop .select2-results__options").scrollTop();
						} else {
							$.toast("An error occured while processing your request.");
						}
					}).fail(function() {
						$.toast("An error occured while processing your request.");
					});
				}
			} else if (rType == "movie") {
				if ((rAction == "purge") && (!rConfirm)) {
					new jBox("Confirm", {
						confirmButton: "Kill",
						cancelButton: "Cancel",
						content: "Are you sure you want to kill all connections?",
						confirm: function() {
							searchAPI(rType, rID, rAction, true);
						}
					}).open();
				} else {
					rConfirm = true;
				}
				if (rConfirm) {
					$.getJSON("./api?action=movie&sub=" + rAction + "&stream_id=" + rID + "&server_id=-1", function(data) {
						if (data.result == true) {
							if (rAction == "start") {
								$.toast("<?php echo $_['movie_encode_started']; ?>");
							} else if (rAction == "stop") {
								$.toast("<?php echo $_['movie_encode_stopped']; ?>");
								refreshTable();
							} else if (rAction == "purge") {
								$.toast("Connections have been killed.");
							}
							$(".bigdrop .select2-search__field").trigger("input");
							window.rSearchTop = $(".bigdrop .select2-results__options").scrollTop();
						} else {
							$.toast("An error occured while processing your request.");
						}
					}).fail(function() {
						$.toast("An error occured while processing your request.");
					});
				}
			} else if (rType == "episode") {
				if ((rAction == "purge") && (!rConfirm)) {
					new jBox("Confirm", {
						confirmButton: "Kill",
						cancelButton: "Cancel",
						content: "Are you sure you want to kill all connections?",
						confirm: function() {
							searchAPI(rType, rID, rAction, true);
						}
					}).open();
				} else {
					rConfirm = true;
				}
				if (rConfirm) {
					$.getJSON("./api?action=episode&sub=" + rAction + "&stream_id=" + rID + "&server_id=-1", function(data) {
						if (data.result == true) {
							if (rAction == "start") {
								$.toast("<?php echo $_['episode_encoding_start']; ?>");
							} else if (rAction == "stop") {
								$.toast("<?php echo $_['episode_encoding_stop']; ?>");
							} else if (rAction == "purge") {
								$.toast("Connections have been killed.");
							}
							$(".bigdrop .select2-search__field").trigger("input");
							window.rSearchTop = $(".bigdrop .select2-results__options").scrollTop();
						} else {
							$.toast("An error occured while processing your request.");
						}
					}).fail(function() {
						$.toast("An error occured while processing your request.");
					});
				}
			} else if (rType == "user") {
				$.getJSON("./api?action=reg_user&sub=" + rAction + "&user_id=" + rID, function(data) {
					if (data.result === true) {
						if (rAction == "enable") {
							$.toast("User has been enabled.");
						} else if (rAction == "disable") {
							$.toast("User has been disabled.");
						}
						$(".bigdrop .select2-search__field").trigger("input");
						window.rSearchTop = $(".bigdrop .select2-results__options").scrollTop();
					} else {
						$.toast("An error occured while processing your request.");
					}
				}).fail(function() {
					$.toast("An error occured while processing your request.");
				});
			} else if (rType == "line") {
				if ((rAction == "kill") && (!rConfirm)) {
					new jBox("Confirm", {
						confirmButton: "Kill",
						cancelButton: "Cancel",
						content: "Are you sure you want to kill all connections for this line?",
						confirm: function() {
							searchAPI(rType, rID, rAction, true);
						}
					}).open();
				} else {
					rConfirm = true;
				}
				if (rConfirm) {
					$.getJSON("./api?action=line&sub=" + rAction + "&user_id=" + rID, function(data) {
						if (data.result === true) {
							if (rAction == "enable") {
								$.toast("Line has been enabled.");
							} else if (rAction == "disable") {
								$.toast("Line has been disabled.");
							} else if (rAction == "unban") {
								$.toast("Line has been unbanned.");
							} else if (rAction == "ban") {
								$.toast("Line has been banned.");
							} else if (rAction == "kill") {
								$.toast("All connections for this line have been killed.");
							}
							$(".bigdrop .select2-search__field").trigger("input");
							window.rSearchTop = $(".bigdrop .select2-results__options").scrollTop();
						} else {
							$.toast("An error occured while processing your request.");
						}
					}).fail(function() {
						$.toast("An error occured while processing your request.");
					});
				}
			}
		}

		function editModal(rEvent, rType, rID, rTitle) {
			if (rEvent) {
				rEvent.preventDefault();
			}
			eModal.iframe(rType + '?id=' + rID + '&modal=1', rTitle);
		}

		function closeEditModal() {
			$('.modal').modal('hide');
			if ($("#datatable-users").length) {
				$("#datatable-users").DataTable().ajax.reload(null, false);
			}
			if ($("#datatable-streampage").length) {
				$("#datatable-streampage").DataTable().ajax.reload(null, false);
			}
		}

		function initSearch() {
			$(".dropdown").unbind("click");
			$(".dropdown").click(function() {
				if ($(this).attr("id") == "search-mobile") {
					if ($("#topnav-search").hasClass("active")) {
						$(".quick_search").select2("close");
						$("#topnav-search").removeClass("active");
					} else {
						$("#topnav-search").addClass("active");
						$(".quick_search").select2("open");
					}
				} else {
					if ($("#topnav-search").hasClass("active")) {
						$(".quick_search").select2("close");
						$("#topnav-search").removeClass("active");
					}
				}
			});
			$(".quick_search").unbind("select2:opening");
			$(".quick_search").unbind("select2:closing");
			$(".quick_search").unbind("select2:selecting");
			$(".quick_search").unbind("select2:draw");
			$(".quick_search").select2({
				ajax: {
					url: './api',
					dataType: 'json',
					data: function(params) {
						return {
							search: params.term,
							action: 'search'
						};
					},
					processResults: function(data, params) {
						return {
							results: data.items
						};
					},
					cache: true
				},
				minimumInputLength: 3,
				templateResult: function(data) {
					return data.html
				},
				escapeMarkup: function(m) {
					return m;
				},
				placeholder: 'Search...',
				dropdownCssClass: 'bigdrop'
			}).on("select2:opening", function(e) {
				if ($("#header_stats").is(":visible")) {
					var rMaxWidth = parseInt($(".app-search-box").position()["left"] - ($("#header_stats li:last").position()["left"] + $("#header_stats li:last").width()));
					if (rMaxWidth < 600) {
						$("#header_stats").hide();
					}
					$(".app-search").width(600);
				}
				window.rSearchTop = 0;
			}).on("select2:closing", function(e) {
				$(".bigdrop .select2-results__options li").remove();
				$("#header_stats").show();
				$(".app-search").width(150);
				window.rSearchTop = 0;
			}).on("select2:selecting", function(e) {
				e.preventDefault();
				return false;
			}).on("select2:draw", function(e) {
				refreshTooltips();
				if (window.rSearchTop > 0) {
					$(".bigdrop .select2-results__options").scrollTop(window.rSearchTop);
					window.rSearchTop = 0;
				}
			});
			$(".app-search-box .select2-selection").addClass($(".app-search").data("theme")).addClass("header-search");
			$("#mod_fingerprint_type").unbind("change");
			$("#mod_fingerprint_type").change(function() {
				if ($(this).val() == 3) {
					$("#mod_custom_message_div").show();
				} else {
					$("#mod_custom_message_div").hide();
				}
			});
			$("#mod_font_size").inputFilter(function(value) {
				return /^\d*$/.test(value);
			});
			$("#mod_position_x").inputFilter(function(value) {
				return /^\d*$/.test(value);
			});
			$("#mod_position_y").inputFilter(function(value) {
				return /^\d*$/.test(value);
			});
		}
	</script>

<?php
endif;

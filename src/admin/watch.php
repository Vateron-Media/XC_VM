<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$_TITLE = 'Watch Folder';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Watch Folder</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    The folder is now being watched. It will be scanned during the next Watch Folder run.' . "\n" . '                </div>' . "\n\t\t\t\t";
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n\t\t\t\t\t\t" . '<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n" . '                                    <th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Type</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Server Name</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Directory</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Last Run</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody>' . "\n\t\t\t\t\t\t\t\t";

foreach (getWatchFolders() as $rFolder) {
	if (0 < $rFolder['last_run']) {
		$rDate = date('Y-m-d H:i:s', $rFolder['last_run']);
	} else {
		$rDate = 'Never';
	}

	echo "\t\t\t\t\t\t\t\t" . '<tr id="folder-';
	echo intval($rFolder['id']);
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo intval($rFolder['id']);
	echo '</td>' . "\n" . '                                    <td class="text-center">' . "\n" . '                                        ';

	if ($rFolder['active']) {
		echo '                                        <i class="text-success fas fa-square"></i>' . "\n" . '                                        ';
	} else {
		echo '                                        <i class="text-secondary fas fa-square"></i>' . "\n" . '                                        ';
	}

	echo '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo array('movie' => 'Movies', 'series' => 'Series')[$rFolder['type']];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rServers[$rFolder['server_id']]['server_name'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td>';
	echo $rFolder['directory'];
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rDate;
	echo '</td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="btn-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a href="./watch_add?id=';
	echo intval($rFolder['id']);
	echo '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>' . "\n" . '                                            <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(';
	echo intval($rFolder['id']);
	echo ", 'force');\"><i class=\"mdi mdi-refresh\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(';
	echo intval($rFolder['id']);
	echo ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</td>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
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
		<?php if (!$rMobile || $rSettings['header_stats']): ?>
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

	<?php endif; ?>

	function disableAll(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Disable",
				cancelButton: "Cancel",
				content: "Are you sure you want to disable all folders?",
				confirm: function() {
					disableAll(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=disable_watch", function(data) {
				$.toast("Folders have been disabled.");
			});
		}
	}

	function enableAll(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Enable",
				cancelButton: "Cancel",
				content: "Are you sure you want to enable all folders?",
				confirm: function() {
					enableAll(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=enable_watch", function(data) {
				$.toast("Folders have been enabled.");
			});
		}
	}

	function killWatchFolder(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Kill",
				cancelButton: "Cancel",
				content: "Are you sure you want to kill all processes?",
				confirm: function() {
					killWatchFolder(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=kill_watch", function(data) {
				$.toast("Watch folder processes have been killed.");
			});
		}
	}

	function api(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this folder?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else if ((rType == "force") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Run",
				cancelButton: "Cancel",
				content: "Are you sure you want to force this folder to run now?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=folder&sub=" + rType + "&folder_id=" + rID, function(data) {
				if (data.result === true) {
					if (rType == "delete") {
						if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {
							$("#datatable").DataTable().rows(rRow).remove().draw(false);
						}
						$.toast("Folder successfully deleted.");
					} else if (rType == "force") {
						$.toast("Folder has been forced to run in the background.");
					}
				} else {
					$.toast("An error occured while processing your request.");
				}
			});
		}
	}

	$(document).ready(function() {
		$("#datatable").DataTable({
			language: {
				paginate: {
					previous: "<i class='mdi mdi-chevron-left'>",
					next: "<i class='mdi mdi-chevron-right'>"
				}
			},
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			order: [
				[5, "desc"]
			],
			columnDefs: [{
				"visible": false,
				"targets": [0]
			}],
			responsive: false
		});
		$("#datatable").css("width", "100%");
		$("form").submit(function(e) {
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("form")[0]));
		});
	});
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'Plex Sync';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
											echo ' style="display: none;"';
										} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Plex Sync</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						The server is now being synced. It will be scanned during the next Plex Sync run.
					</div>
				<?php endif; ?>
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th class="text-center">Status</th>
									<th class="text-center">Plex IP</th>
									<th>Server Name</th>
									<th>Library</th>
									<th class="text-center">Last Run</th>
									<th class="text-center">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach (getPlexServers() as $rServer) :
									$rDate = ($rServer['last_run'] > 0) ? date('Y-m-d H:i:s', $rServer['last_run']) : 'Never';
									$rLibraryName = 'Unknown';
									foreach (json_decode($rServer['plex_libraries'], true) as $rLibrary) {
										if (intval($rLibrary['key']) == intval($rServer['directory'])) {
											$rLibraryName = $rLibrary['title'];
											break;
										}
									}
									$rServerAdd = is_null($rServer['server_add']) ? 0 : count(json_decode($rServer['server_add'], true));
								?>
									<tr id="folder-<?= intval($rServer['id']); ?>">
										<td class="text-center"><?= intval($rServer['id']); ?></td>
										<td class="text-center">
											<?php if ($rServer['active']) : ?>
												<i class="text-success fas fa-square"></i>
											<?php else : ?>
												<i class="text-secondary fas fa-square"></i>
											<?php endif; ?>
										</td>
										<td class="text-center"><?= $rServer['plex_ip']; ?></td>
										<td>
											<?= $rServers[$rServer['server_id']]['server_name'] . ($rServerAdd > 0 ? "&nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . $rServerAdd . "</button>" : ""); ?>
										</td>
										<td><?= $rLibraryName; ?></td>
										<td class="text-center"><?= $rDate; ?></td>
										<td class="text-center">
											<div class="btn-group">
												<a href="./plex_add?id=<?= intval($rServer['id']); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
												<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'force');"><i class="mdi mdi-refresh"></i></button>
												<button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'delete');"><i class="mdi mdi-close"></i></button>
											</div>
										</td>
									</tr>
								<?php endforeach; ?>
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



	function disableAll(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Disable",
				cancelButton: "Cancel",
				content: "Are you sure you want to disable all libraries?",
				confirm: function() {
					disableAll(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=disable_plex", function(data) {
				$.toast("Libraries have been disabled.");
			});
		}
	}

	function enableAll(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Enable",
				cancelButton: "Cancel",
				content: "Are you sure you want to enable all libraries?",
				confirm: function() {
					enableAll(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=enable_plex", function(data) {
				$.toast("Libraries have been enabled.");
			});
		}
	}

	function killPlexSync(rConfirm = false) {
		if (!rConfirm) {
			new jBox("Confirm", {
				confirmButton: "Kill",
				cancelButton: "Cancel",
				content: "Are you sure you want to kill all processes?",
				confirm: function() {
					killPlexSync(true);
				}
			}).open();
		} else {
			$.getJSON("./api?action=kill_plex", function(data) {
				$.toast("Plex Sync processes have been killed.");
			});
		}
	}

	function api(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this library?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else if ((rType == "force") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Run",
				cancelButton: "Cancel",
				content: "Are you sure you want to force this library to run now?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=library&sub=" + rType + "&folder_id=" + rID, function(data) {
				if (data.result === true) {
					if (rType == "delete") {
						if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {
							$("#datatable").DataTable().rows(rRow).remove().draw(false);
						}
						$.toast("Library successfully deleted.");
					} else if (rType == "force") {
						$.toast("Library has been forced to sync in the background.");
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
    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
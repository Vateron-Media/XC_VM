<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

CoreUtilities::$rServers = CoreUtilities::getServers(true);
$_TITLE = 'Proxy Servers';
include 'header.php'; ?>


<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo 'style="display: none;"' ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Proxy Servers</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-12">
				<div class="card">
					<div class="card-body" style="overflow-x:auto;">
						<table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
							<thead>
								<tr>
									<th class="text-center">ID</th>
									<th class="text-center">Status</th>
									<th>Proxy Name</th>
									<th>Proxied Server</th>
									<th class="text-center">Proxy IP</th>
									<th class="text-center">Network</th>
									<th class="text-center">Connections</th>
									<th class="text-center">CPU %</th>
									<th class="text-center">MEM %</th>
									<th class="text-center">Ping</th>
									<th class="text-center">Actions</th>
								</tr>
							</thead>
							<tbody>
								<?php
								foreach (CoreUtilities::$rServers as $rServer):
									if ($rServer['server_type'] == 1):
										$rWatchDog = json_decode($rServer['watchdog_data'], true);

										$rWatchDog = is_array($rWatchDog) ? $rWatchDog : array('total_mem_used_percent' => 0, 'cpu' => 0);

										if (!CoreUtilities::$rServers[$rServer['id']]['server_online']) {
											$rWatchDog['cpu'] = 0;
											$rWatchDog['total_mem_used_percent'] = 0;
										}
								?>
										<tr id="server-<?= $rServer['id'] ?>">
											<td class="text-center"><?= $rServer['id']; ?></td>
											<td class="text-center">

										<?




										if (!$rServer['enabled']) {
											echo '<i class="text-secondary fas fa-square tooltip" title="Disabled"></i>';
										} else {
											if ($rServer['server_online']) {
												echo '<i class="text-success fas fa-square tooltip" title="Online"></i>';
											} else {
												$rLastCheck = $rServer['last_check_ago'] > 0 ? date($rSettings['datetime_format'], $rServer['last_check_ago']) : 'Never';

												if ($rServer['status'] == 3) {
													echo '<i class="text-info fas fa-square tooltip" title="Installing..."></i>';
												} elseif ($rServer['status'] == 4) {
													echo '<i class="text-warning fas fa-square tooltip" title="Installation Failed!"></i>';
												} elseif ($rServer['status'] == 5) {
													echo '<i class="text-info fas fa-square tooltip" title="Updating..."></i>';
												} else {
													echo '<i class="text-danger fas fa-square tooltip" title="Last Ping: ' . $rLastCheck . '"></i>';
												}
											}
										}

										echo '                                    </td>' . "\n" . '                                    <td><a href="server_view?id=';
										echo $rServer['id'];
										echo '">';
										echo $rServer['server_name'];
										echo (!empty($rServer['domain_name']) ? '<br/><small>' . explode(',', $rServer['domain_name'])[0] . '</small>' : '');
										echo '</a></td>' . "\n" . '                                    <td><a href="server_view?id=';
										echo $rServer['parent_id'][0];
										echo '">';
										echo $rServers[$rServer['parent_id'][0]]['server_name'];
										echo '</a>';

										if (1 >= count($rServer['parent_id'])) {
										} else {
											echo '&nbsp; <button title="View All Servers" onClick="viewServers(';
											echo intval($rServer['id']);
											echo ");\" type='button' class='tooltip-left btn btn-info btn-xs waves-effect waves-light'>+ ";
											echo count($rServer['parent_id']) - 1;
											echo '</button>';
										}

										echo '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . "<td class=\"text-center\"><a onClick=\"whois('";
										echo $rServer['server_ip'];
										echo "');\" href=\"javascript: void(0);\">";
										echo $rServer['server_ip'];
										echo '</a></td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t";
										$rClients = getLiveConnections($rServer['id'], true);

										if (hasPermissions('adv', 'live_connections')) {
											$rClients = '<a href="./live_connections?server=' . $rServer['id'] . "\"><button type='button' class='btn btn-dark bg-animate btn-xs waves-effect waves-light no-border'>" . number_format($rClients, 0) . '</button></a>';
										} else {
											$rClients = "<button type='button' class='btn btn-dark bg-animate btn-xs waves-effect waves-light no-border'>" . number_format($rClients, 0) . '</button>';
										}

										echo $rClients;
										echo "\t\t\t\t\t\t\t\t\t" . '<br/><small>of ';
										echo number_format($rServer['total_clients'], 0);
										echo '</small>' . "\n" . '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                        <button type="button" class="btn btn-dark bg-animate btn-xs waves-effect waves-light no-border"><span id="header_streams_up">';
										echo number_format($rWatchDog['bytes_sent'] / 125000, 0);
										echo '</span> <i class="mdi mdi-arrow-up-thick"></i> &nbsp; <span id="header_streams_down">';
										echo number_format($rWatchDog['bytes_received'] / 125000, 0);
										echo '</span> <i class="mdi mdi-arrow-down-thick"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<br/><small>';
										echo number_format($rServer['network_guaranteed_speed'], 0);
										echo ' Mbps</small>' . "\n" . '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                        ';

										if (intval($rWatchDog['cpu']) <= 34) {
											$statusColor = '#23b397';
										} else {
											if (intval($rWatchDog['cpu']) <= 67) {
												$statusColor = '#f8cc6b';
											} else {
												$statusColor = '#f0643b';
											}
										}

										echo '                                        <input data-plugin="knob" data-width="48" data-height="48" data-bgColor="';

										if ($rUserInfo['theme'] == 1) {
											echo '#7e8e9d';
										} else {
											echo '#ebeff2';
										}

										echo '" data-fgColor="';
										echo $statusColor;
										echo '" data-readOnly=true value="';
										echo intval($rWatchDog['cpu']);
										echo '"/>' . "\n" . '                                    </td>' . "\n" . '                                    <td class="text-center">' . "\n" . '                                        ';

										if (intval($rWatchDog['total_mem_used_percent']) <= 34) {
											$statusColor = '#23b397';
										} elseif (intval($rWatchDog['total_mem_used_percent']) <= 67) {
											$statusColor = '#f8cc6b';
										} else {
											$statusColor = '#f0643b';
										}

										echo '                                        <input data-plugin="knob" data-width="48" data-height="48" data-bgColor="';

										if ($rUserInfo['theme'] == 1) {
											echo '#7e8e9d';
										} else {
											echo '#ebeff2';
										}

										echo '" data-fgColor="';
										echo $statusColor;
										echo '" data-readOnly=true value="';
										echo intval($rWatchDog['total_mem_used_percent']);
										echo '"/>' . "\n" . '                                    </td>' . "\n\t\t\t\t\t\t\t\t\t" . "<td class=\"text-center\"><button type='button' class='btn btn-light btn-xs waves-effect waves-light'>";
										echo number_format(($rServer['server_online'] ? $rServer['ping'] : 0), 0);
										echo ' ms</button></td>' . "\n\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n\t\t\t\t\t\t\t\t\t\t";

										if (hasPermissions('adv', 'edit_server')) {
											if (CoreUtilities::$rSettings['group_buttons']) {
												echo "\t\t\t\t\t\t\t\t\t\t" . '<div class="btn-group dropdown">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm" data-toggle="dropdown" aria-expanded="false"><i class="mdi mdi-menu"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="dropdown-menu dropdown-menu-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item btn-reboot-server" href="javascript:void(0);" data-id="';
												echo $rServer['id'];
												echo '">Proxy Tools</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
												echo $rServer['id'];
												echo ", 'kill');\">Kill Connections</a>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="./proxy?id=';
												echo $rServer['id'];
												echo '">Edit Proxy</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

												if ($rServer['enabled']) {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
													echo $rServer['id'];
													echo ", 'disable');\">Disable Proxy</a>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
												} else {
													echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
													echo $rServer['id'];
													echo ", 'enable');\">Enable Proxy</a>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
												}

												echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
												echo $rServer['id'];
												echo ", 'delete');\">Delete Proxy</a>" . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t";
											} else {
												echo "\t\t\t\t\t\t\t\t\t\t" . '<div class="btn-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" title="Proxy Tools" class="btn btn-light waves-effect waves-light btn-xs btn-reboot-server tooltip" data-id="';
												echo $rServer['id'];
												echo '"><i class="mdi mdi-creation"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" title="Kill All Connections" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(';
												echo $rServer['id'];
												echo ", 'kill');\"><i class=\"fas fa-hammer\"></i></button>" . "\n" . '                                            <a href="./proxy?id=';
												echo $rServer['id'];
												echo '"><button type="button" title="Edit Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-pencil-outline"></i></button></a>' . "\n" . '                                            ';

												if ($rServer['enabled']) {
													echo '                                            <button type="button" title="Disable Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(';
													echo $rServer['id'];
													echo ", 'disable');\"><i class=\"mdi mdi-close-network-outline\"></i></button>" . "\n" . '                                            ';
												} else {
													echo '                                            <button type="button" title="Enable Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(';
													echo $rServer['id'];
													echo ", 'enable');\"><i class=\"mdi mdi-access-point-network\"></i></button>" . "\n" . '                                            ';
												}

												echo '                                            <button type="button" title="Delete Proxy" class="btn btn-light waves-effect waves-light btn-xs tooltip" onClick="api(';
												echo $rServer['id'];
												echo ", 'delete');\"><i class=\"mdi mdi-close\"></i></button>" . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t";
											}
										} else {
											echo '--';
										}

										echo "\t\t\t\t\t\t\t\t\t" . '</td>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t";
									endif;
								endforeach;

										?>



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
	var rSelected = [];

	function viewServers(rProxyID) {
		$(".bs-proxies-modal-center #datatable-sources").DataTable({
			destroy: true,
			ordering: true,
			paging: true,
			searching: true,
			responsive: false,
			processing: true,
			serverSide: true,
			bInfo: true,
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			ajax: {
				url: "./table",
				"data": function(d) {
					d.id = "parent_servers";
					d.proxy_id = rProxyID;
				}
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0, 2]
			}],
			order: [
				[0, "asc"]
			],
		});
		$(".bs-proxies-modal-center").data("id", rProxyID);
		$(".bs-proxies-modal-center").modal("show");
		$(".bs-proxies-modal-center #datatable-sources").css("width", "100%");
	}

	function api(rID, rType, rConfirm = false) {
		if ((window.rSelected) && (window.rSelected.length > 0)) {
			$.toast("Individual actions disabled in multi-select mode.");
			return;
		}
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this proxy server?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else if ((rType == "kill") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Kill",
				cancelButton: "Cancel",
				content: "Are you sure you want to kill all connections to this proxy?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else if ((rType == "disable") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Disable",
				cancelButton: "Cancel",
				content: "Are you sure you want to disable this proxy?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else if ((rType == "update") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Update",
				cancelButton: "Cancel",
				content: "Are you sure you want to update this proxy? It will go offline until the update is completed.",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=proxy&sub=" + rType + "&server_id=" + rID, function(data) {
				if (data.result === true) {
					if (rType == "delete") {
						if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {
							$("#datatable").DataTable().rows(rRow).remove().draw(false);
						}
						$.toast("Proxy successfully deleted.");
					} else if (rType == "kill") {
						$.toast("All proxy connections have been killed.");
					} else if (rType == "update") {
						$.toast("Updating proxy server...");
					} else if (rType == "disable") {
						reloadPage();
					} else if (rType == "enable") {
						reloadPage();
					}
				} else {
					$.toast("An error occured while processing your request.");
				}
			});
		}
	}

	function multiAPI(rType, rConfirm = false) {
		if (rType == "clear") {
			if ("#header_stats") {
				$("#header_stats").show();
			}
			window.rSelected = [];
			$(".multiselect").hide();
			$("#datatable tr").removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
			return;
		}
		if (rType == "tools") {
			$(".bs-server-modal-center").data("id", "[" + window.rSelected.join(",") + "]");
			$(".bs-server-modal-center").modal("show");
			$("#reinstall_server").prop("disabled", true);
			return;
		}
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete these proxies?",
				confirm: function() {
					multiAPI(rType, true);
				}
			}).open();
		} else if ((rType == "purge") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Kill",
				cancelButton: "Cancel",
				content: "Are you sure you want to kill all connections?",
				confirm: function() {
					multiAPI(rType, true);
				}
			}).open();
		} else if ((rType == "enable") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Enable",
				cancelButton: "Cancel",
				content: "Are you sure you want to enable these proxies?",
				confirm: function() {
					multiAPI(rType, true);
				}
			}).open();
		} else if ((rType == "disable") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Disable",
				cancelButton: "Cancel",
				content: "Are you sure you want to disable these proxies?",
				confirm: function() {
					multiAPI(rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=multi&type=proxy&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {
				if (data.result == true) {
					if (rType == "purge") {
						$.toast("Connections have been killed.");
					} else if (rType == "delete") {
						$.toast("Proxies have been deleted.");
					} else if (rType == "enable") {
						$.toast("Proxies have been enabled.");
					} else if (rType == "disable") {
						$.toast("Proxies have been disabled.");
					}
					reloadPage();
				} else {
					$.toast("An error occured while processing your request.");
				}
			}).fail(function() {
				$.toast("An error occured while processing your request.");
			});
			multiAPI("clear");
		}
	}

	function bindServers() {
		$("#reinstall_server").unbind();
		$("#reinstall_server").click(function() {
			navigate('./server_install?id=' + $(".bs-server-modal-center").data("id") + "&proxy=1");
		});
		$("#restart_services_ssh").unbind();
		$("#restart_services_ssh").click(function() {
			$(".bs-server-modal-center").modal("hide");
			$.getJSON("./api?action=restart_services&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
				if (data.result === true) {
					$.toast("XC_VM will be restarted shortly.");
				} else {
					$.toast("An error occured while processing your request.");
				}
				$(".bs-server-modal-center").data("id", "");
			});
		});
		$("#reboot_server_ssh").unbind();
		$("#reboot_server_ssh").click(function() {
			$(".bs-server-modal-center").modal("hide");
			$.getJSON("./api?action=reboot_server&server_id=" + $(".bs-server-modal-center").data("id"), function(data) {
				if (data.result === true) {
					$.toast("Server will be rebooted shortly.");
				} else {
					$.toast("An error occured while processing your request.");
				}
				$(".bs-server-modal-center").data("id", "");
			});
		});
		$(".btn-reboot-server").click(function() {
			$(".bs-server-modal-center").data("id", $(this).data("id"));
			$(".bs-server-modal-center").modal("show");
		});
		$("#update_server").prop("disabled", true);
		$("#update_binaries").prop("disabled", true);
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
				bindServers();
				bindHref();
				refreshTooltips();
				<?php if (hasPermissions('adv', 'edit_server')): ?>
					// Multi Actions
					multiAPI("clear");
					$("#datatable tr").click(function() {
						if (window.rShiftHeld) {
							if ($(this).hasClass('selectedfilter')) {
								$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass("selected");
								window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);
							} else {
								$(this).addClass('selectedfilter').addClass('ui-selected').addClass("selected");
								window.rSelected.push($(this).find("td:eq(0)").text());
							}
						}
						$("#multi_proxies_selected").html(window.rSelected.length + " proxies");
						if (window.rSelected.length > 0) {
							if ("#header_stats") {
								$("#header_stats").hide();
							}
							$("#multiselect_proxies").show();
						} else {
							if ("#header_stats") {
								$("#header_stats").show();
							}
							$("#multiselect_proxies").hide();
						}
					});
				<?php endif; ?>
			},
			responsive: false
		});
		$("#datatable").css("width", "100%");
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
<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$_TITLE = 'MAGSCAN Settings';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">MAGSCAN Settings</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<div class="card">
					<div class="card-body">
						<form action="#" method="POST" data-parsley-validate="">
							<div id="basicwizard">
								<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
									<li class="nav-item">
										<a href="#scan_nav_2" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-face-profile mr-1"></i>
											<span class="d-none d-sm-inline">WhiteList MAC</span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#scan_nav_3" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-checkbox-marked-circle-outline mr-1"></i>
											<span class="d-none d-sm-inline">BlackList MAC</span>
										</a>
									</li>
									<li class="nav-item">
										<a href="#scan_nav_4" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
											<i class="mdi mdi-checkbox-marked-circle-outline mr-1"></i>
											<span class="d-none d-sm-inline">WhiteList IP</span>
										</a>
									</li>
								</ul>
								<div class="tab-content b-0 mb-0 pt-0">

									<div class="tab-pane mt-2" id="scan_nav_2">
										<h4>White List of MAC Addresses</h4>
										<p>Enter MAC address that you want to bypass the block!</p>
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="whitelist_mac">MAC Address</label>
													<div class="col-8 input-group">
														<input type="text" id="whitelist_mac" class="form-control" value="" maxlength="17">
														<div class="input-group-append">
															<a href="javascript:void(0)" id="add_mac" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
															<a href="javascript:void(0)" id="remove_mac" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="whitelist_macs">&nbsp;</label>
													<div class="col-8">
														<select id="whitelist_macs" name="whitelist_macs[]" size="10" class="form-control" multiple="multiple">
															<?php if (isset($gData) && count($gData['value']['whitelist_macs']) > 0) { ?>
																<?php foreach ($gData['value']['whitelist_macs'] as $gDataWhiteListMac) { ?>
																	<option value="<?php echo $gDataWhiteListMac; ?>"><?php echo $gDataWhiteListMac; ?></option>
																<?php } ?>
															<?php } ?>
														</select>
													</div>
												</div>
											</div>
										</div>
									</div>

									<div class="tab-pane mt-2" id="scan_nav_3">
										<h4>Black List of MAC Addresses</h4>
										<p>Enter MAC address that you want to block immediately on first request!</p>
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="blacklist_mac">MAC Address</label>
													<div class="col-8 input-group">
														<input type="text" id="blacklist_mac" class="form-control" value="" maxlength="17">
														<div class="input-group-append">
															<a href="javascript:void(0)" id="add_black_mac" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
															<a href="javascript:void(0)" id="remove_black_mac" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="blacklist_macs">&nbsp;</label>
													<div class="col-8">
														<select id="blacklist_macs" name="blacklist_macs[]" size="10" class="form-control" multiple="multiple">
															<?php if (isset($gData) && count($gData['value']['blacklist_macs']) > 0) { ?>
																<?php foreach ($gData['value']['blacklist_macs'] as $gDataBlackListMac) { ?>
																	<option value="<?php echo $gDataBlackListMac; ?>"><?php echo $gDataBlackListMac; ?></option>
																<?php } ?>
															<?php } ?>
														</select>
													</div>
												</div>
											</div>
										</div>
									</div>

									<div class="tab-pane mt-2" id="scan_nav_4">
										<h4>White List of IP Addresses</h4>
										<p>Enter IP address that you want to bypass the block!</p>
										<div class="row">
											<div class="col-12">
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="whitelist_ip">IP Address</label>
													<div class="col-8 input-group">
														<input type="text" id="whitelist_ip" class="form-control" value="">
														<div class="input-group-append">
															<a href="javascript:void(0)" id="add_ip" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-plus"></i></a>
															<a href="javascript:void(0)" id="remove_ip" class="btn btn-danger waves-effect waves-light"><i class="mdi mdi-close"></i></a>
														</div>
													</div>
												</div>
												<div class="form-group row mb-4">
													<label class="col-4 col-form-label" for="whitelist_ips">&nbsp;</label>
													<div class="col-8">
														<select id="whitelist_ips" name="whitelist_ips[]" size="10" class="form-control" multiple="multiple">
															<?php if (isset($gData) && count($gData['value']['whitelist_ips']) > 0) { ?>
																<?php foreach ($gData['value']['whitelist_ips'] as $gDataWhiteListIp) { ?>
																	<option value="<?php echo $gDataWhiteListIp; ?>"><?php echo $gDataWhiteListIp; ?></option>
																<?php } ?>
															<?php } ?>
														</select>
													</div>
												</div>
											</div>
										</div>
									</div>

									<ul class="list-inline wizard mt-4 mb-0">
										<li class="list-inline-item float-right">
											<input name="submit_magscan" type="submit" class="btn btn-success" value="Save Settings">
										</li>
									</ul>

								</div> <!-- tab-content -->
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>
<?php include 'footer.php'; ?>
<script>
	$(document).ready(function() {


		var macAddressWhite = document.getElementById("whitelist_mac");
		var macAddressBlack = document.getElementById("blacklist_mac");

		function formatMAC(e) {
			var r = /([a-f0-9]{2})([a-f0-9]{2})/i,
				str = e.target.value.replace(/[^a-f0-9]/ig, "");

			while (r.test(str)) {
				str = str.replace(r, '$1' + ':' + '$2');
			}

			e.target.value = str.slice(0, 17);
		};

		macAddressWhite.addEventListener("keyup", formatMAC, false);
		macAddressBlack.addEventListener("keyup", formatMAC, false);

		//Checkboxes
		var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
		elems.forEach(function(html) {
			var switchery = new Switchery(html);
		});

		$(document).keypress(function(event) {
			if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
		});
		$("form").attr('autocomplete', 'off');

		//Add MAC
		$("#add_mac").click(function() {
			if ($("#whitelist_mac").val().length > 0) {
				var macAddress = $("#whitelist_mac").val();
				var macAddressRegex = /^([0-9A-Fa-f]{2}[:\-]){5}([0-9A-Fa-f]{2})$/;
				if (macAddressRegex.test(macAddress)) {
					// Check if the MAC address already exists
					var options = $("#whitelist_macs option");
					var exists = false;
					for (var i = 0; i < options.length; i++) {
						if (options[i].value == macAddress) {
							exists = true;
							break;
						}
					}
					if (!exists) {
						var o = new Option(macAddress, macAddress);
						$("#whitelist_macs").append(o);
						$("#whitelist_mac").val("");
					} else {
						$.toast("MAC Address is already whitelisted.");
					}
				} else {
					$.toast("Please enter a valid MAC Address.");
				}
			} else {
				$.toast("Please enter MAC Address.");
			}
		});
		$("#remove_mac").click(function() {
			$('#whitelist_macs option:selected').remove();
		});

		//Black
		$("#add_black_mac").click(function() {
			if ($("#blacklist_mac").val().length > 0) {
				var macAddress = $("#blacklist_mac").val();
				var macAddressRegex = /^([0-9A-Fa-f]{2}[:\-]){5}([0-9A-Fa-f]{2})$/;
				if (macAddressRegex.test(macAddress)) {
					// Check if the MAC address already exists
					var options = $("#blacklist_macs option");
					var exists = false;
					for (var i = 0; i < options.length; i++) {
						if (options[i].value == macAddress) {
							exists = true;
							break;
						}
					}
					if (!exists) {
						var o = new Option(macAddress, macAddress);
						$("#blacklist_macs").append(o);
						$("#blacklist_mac").val("");
					} else {
						$.toast("MAC Address is already blacklisted.");
					}
				} else {
					$.toast("Please enter a valid MAC Address.");
				}
			} else {
				$.toast("Please enter MAC Address.");
			}
		});
		$("#remove_black_mac").click(function() {
			$('#blacklist_macs option:selected').remove();
		});

		//Add IP
		$("#add_ip").click(function() {
			if ($("#whitelist_ip").val().length > 0) {
				var ipAddress = $("#whitelist_ip").val();
				var ipAddressRegex = /^([0-9]{1,3}\.){3}[0-9]{1,3}$/;
				if (ipAddressRegex.test(ipAddress)) {
					// Check if the IP address already exists
					var options = $("#whitelist_ips option");
					var exists = false;
					for (var i = 0; i < options.length; i++) {
						if (options[i].value == ipAddress) {
							exists = true;
							break;
						}
					}
					if (!exists) {
						var o = new Option(ipAddress, ipAddress);
						$("#whitelist_ips").append(o);
						$("#whitelist_ip").val("");
					} else {
						$.toast("IP Address is already whitelisted.");
					}
				} else {
					$.toast("Please enter a valid IP Address.");
				}
			} else {
				$.toast("Please enter IP Address.");
			}
		});

		$("#remove_ip").click(function() {
			$('#whitelist_ips option:selected').remove();
		});

		//Form Submit
		// $("#scan_settings").submit(function(e){
		//     $("#whitelist_macs option").prop('selected', true);
		//     $("#blacklist_macs option").prop('selected', true);
		//     $("#whitelist_ips option").prop('selected', true);
		// });
	});
</script>
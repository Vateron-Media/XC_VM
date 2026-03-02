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

	var rClearing = false;

	function api(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this record?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=watch_output&sub=" + rType + "&result_id=" + rID, function(data) {
				if (data.result == true) {
					if (rType == "delete") {
						$.toast("Record successfully deleted.");
					}
					$("#datatable-md1").DataTable().ajax.reload(null, false);
				} else {
					$.toast("An error occured while processing your request.");
				}
			}).fail(function() {
				$.toast("An error occured while processing your request.");
			});
		}
	}

	function getServer() {
		return $("#result_server").val();
	}

	function getType() {
		return $("#result_type").val();
	}

	function getStatus() {
		return $("#result_status").val();
	}

	function clearFilters() {
		window.rClearing = true;
		$("#result_search").val("").trigger('change');
		$('#result_server').val("").trigger('change');
		$('#result_type').val("").trigger('change');
		$('#result_status').val("").trigger('change');
		$('#result_show_entries').val("<?= intval($rSettings['default_entries']) ?: 10; ?>").trigger('change');
		window.rClearing = false;
		$('#datatable-md1').DataTable().search($("#result_search").val());
		$('#datatable-md1').DataTable().page.len($('#result_show_entries').val());
		$("#datatable-md1").DataTable().page(0).draw('page');
		$("#datatable-md1").DataTable().ajax.reload(null, false);
	}
	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$("#datatable-md1").DataTable({
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
			responsive: false,
			processing: true,
			serverSide: true,
			ajax: {
				url: "./table",
				"data": function(d) {
					d.id = "watch_output";
					d.server = getServer();
					d.type = getType();
					d.status = getStatus();
				}
			},
			columnDefs: [{
					"className": "dt-center",
					"targets": [0, 4, 5, 6]
				},
				{
					"orderable": false,
					"targets": [6]
				}
			],
			order: [
				[5, "desc"]
			],
			pageLength: <?= intval($rSettings['default_entries']) ?: 10; ?>
		});
		$("#datatable-md1").css("width", "100%");
		$('#result_search').keyup(function() {
			if (!window.rClearing) {
				$('#datatable-md1').DataTable().search($(this).val()).draw();
			}
		})
		$('#result_show_entries').change(function() {
			if (!window.rClearing) {
				$('#datatable-md1').DataTable().page.len($(this).val()).draw();
			}
		})
		$('#result_server').change(function() {
			if (!window.rClearing) {
				$("#datatable-md1").DataTable().ajax.reload(null, false);
			}
		})
		$('#result_type').change(function() {
			if (!window.rClearing) {
				$("#datatable-md1").DataTable().ajax.reload(null, false);
			}
		})
		$('#result_status').change(function() {
			if (!window.rClearing) {
				$("#datatable-md1").DataTable().ajax.reload(null, false);
			}
		})
		$('#datatable-md1').DataTable().search($(this).val()).draw();
		$('#range_clear_to').daterangepicker({
			singleDatePicker: true,
			showDropdowns: true,
			locale: {
				format: 'YYYY-MM-DD'
			},
			autoUpdateInput: false
		}).val("");
		$('#range_clear_from').daterangepicker({
			singleDatePicker: true,
			showDropdowns: true,
			locale: {
				format: 'YYYY-MM-DD'
			},
			autoUpdateInput: false
		}).val("");
		$('#range_clear_from').on('apply.daterangepicker', function(ev, picker) {
			$(this).val(picker.startDate.format('YYYY-MM-DD'));
		});
		$('#range_clear_from').on('cancel.daterangepicker', function(ev, picker) {
			$(this).val('');
		});
		$('#range_clear_to').on('apply.daterangepicker', function(ev, picker) {
			$(this).val(picker.startDate.format('YYYY-MM-DD'));
		});
		$('#range_clear_to').on('cancel.daterangepicker', function(ev, picker) {
			$(this).val('');
		});
		$("#btn-clear-logs").click(function() {
			$(".bs-logs-modal-center").modal("show");
		});
		$("#clear_logs").click(function() {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "<?= $language::get('clear_confirm'); ?>",
				confirm: function() {
					$(".bs-logs-modal-center").modal("hide");
					$.getJSON("./api?action=clear_logs&type=watch_logs&from=" + encodeURIComponent($("#range_clear_from").val()) + "&to=" + encodeURIComponent($("#range_clear_to").val()), function(data) {
						$.toast("Logs have been cleared.");
						$("#datatable-activity").DataTable().ajax.reload(null, false);
					});
				}
			}).open();
		});
		$("#btn-export-csv").click(function() {
			$.toast("Generating CSV report...");
			window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-md1").DataTable().ajax.params()));
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
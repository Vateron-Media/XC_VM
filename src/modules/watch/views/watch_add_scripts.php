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

	function selectDirectory(elem) {
		window.currentDirectory += elem + "/";
		$("#selected_path").val(window.currentDirectory);
		$("#changeDir").click();
	}

	function selectParent() {
		$("#selected_path").val(window.currentDirectory.split("/").slice(0, -2).join("/") + "/");
		$("#changeDir").click();
	}

	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$("#datatable").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: false,
			scrollY: "250px",
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			"language": {
				"emptyTable": ""
			}
		});
		$("#datatable-files").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: true,
			scrollY: "250px",
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			"language": {
				"emptyTable": "No compatible files found"
			}
		});
		$("#select_folder").click(function() {
			$("#import_folder").val("s:" + $("#server_id").val() + ":" + window.currentDirectory);
			$.magnificPopup.close();
		});
		$("#changeDir").click(function() {
			window.currentDirectory = $("#selected_path").val();
			if (window.currentDirectory.substr(-1) != "/") {
				window.currentDirectory += "/";
			}
			$("#selected_path").val(window.currentDirectory);
			$("#datatable").DataTable().clear();
			$("#datatable").DataTable().row.add(["", "Loading..."]);
			$("#datatable").DataTable().draw(true);
			$("#datatable-files").DataTable().clear();
			$("#datatable-files").DataTable().row.add(["", "Please wait..."]);
			$("#datatable-files").DataTable().draw(true);
			$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=video", function(data) {
				$("#datatable").DataTable().clear();
				$("#datatable-files").DataTable().clear();
				if (window.currentDirectory != "/") {
					$("#datatable").DataTable().row.add(["<i class='mdi mdi-subdirectory-arrow-left'></i>", "Parent Directory"]);
				}
				if (data.result == true) {
					$(data.data.dirs).each(function(id, dir) {
						$("#datatable").DataTable().row.add(["<i class='mdi mdi-folder-open-outline'></i>", dir]);
					});
					$("#datatable").DataTable().draw(true);
					$(data.data.files).each(function(id, dir) {
						$("#datatable-files").DataTable().row.add(["<i class='mdi mdi-file-video'></i>", dir]);
					});
					$("#datatable-files").DataTable().draw(true);
				}
			});
		});
		$('#datatable').on('click', 'tbody > tr', function() {
			if ($(this).find("td").eq(1).html() == "Parent Directory") {
				selectParent();
			} else {
				selectDirectory($(this).find("td").eq(1).html());
			}
		});
		$("#server_id").change(function() {
			$("#selected_path").val("/");
			$("#changeDir").click();
		});
		$("#changeDir").click();
		$("#folder_type").change(function() {
			if ($(this).val() == "movie") {
				$("#category_movie").show();
				$("#category_series").hide();
				$("#fb_category_movie").show();
				$("#fb_category_series").hide();
			} else {
				$("#category_movie").hide();
				$("#category_series").show();
				$("#fb_category_movie").hide();
				$("#fb_category_series").show();
			}
		});
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
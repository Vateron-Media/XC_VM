<?php







include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rStatusArray = array('CLOSED', 'OPEN', 'RESPONDED TO', 'READ BY USER', 'NEW RESPONSE', 'READ BY ME', 'READ BY USER');
$_TITLE = 'Tickets';
include 'header.php';
echo '<div class="wrapper"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">Tickets</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="card-box">' . "\r\n\t\t\t\t\t" . '<table class="table table-striped table-borderless dt-responsive nowrap w-100" id="tickets-table">' . "\r\n\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\r\n\t\t\t\t\t\t\t\t" . '<th>Reseller</th>' . "\r\n" . '                                <th>Title</th>' . "\r\n\t\t\t\t\t\t\t\t" . '<th class="text-center">Status</th>' . "\r\n\t\t\t\t\t\t\t\t" . '<th class="text-center">Created Date</th>' . "\r\n\t\t\t\t\t\t\t\t" . '<th class="text-center">Last Reply</th>' . "\r\n\t\t\t\t\t\t\t\t" . '<th class="text-center">Action</th>' . "\r\n\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t" . '<tbody>' . "\r\n\t\t\t\t\t\t\t";
$rTickets = getTickets($rUserInfo['id'], true);

foreach ($rTickets as $rTicket) {
	echo "\t\t\t\t\t\t\t" . '<tr id="ticket-';
	echo intval($rTicket['id']);
	echo '">' . "\r\n\t\t\t\t\t\t\t\t" . '<td class="text-center"><a href="./ticket_view?id=';
	echo intval($rTicket['id']);
	echo '">';
	echo intval($rTicket['id']);
	echo '</a></td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td>';
	echo $rTicket['username'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td>';
	echo $rTicket['title'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td class="text-center"><span class="badge badge-';
	echo array('secondary', 'warning', 'success', 'warning', 'info', 'purple', 'warning')[$rTicket['status']];
	echo '">';
	echo $rStatusArray[$rTicket['status']];
	echo '</span></td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rTicket['created'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td class="text-center">';
	echo $rTicket['last_reply'];
	echo '</td>' . "\r\n\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="btn-group dropdown">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="table-action-btn dropdown-toggle arrow-none btn btn-light btn-sm" data-toggle="dropdown" aria-expanded="false"><i class="mdi mdi-dots-horizontal"></i></a>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div class="dropdown-menu dropdown-menu-right">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="./ticket_view?id=';
	echo intval($rTicket['id']);
	echo '"><i class="mdi mdi-eye mr-2 text-muted font-18 vertical-middle"></i>View Ticket</a>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t";

	if (!hasPermissions('adv', 'ticket')) {
	} else {
		if (0 < $rTicket['status']) {
			echo "\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
			echo intval($rTicket['id']);
			echo ", 'close');\"><i class=\"mdi mdi-check-all mr-2 text-muted font-18 vertical-middle\"></i>Close</a>" . "\r\n\t\t\t\t\t\t\t\t\t\t\t";
		} else {
			echo "\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
			echo intval($rTicket['id']);
			echo ", 'reopen');\"><i class=\"mdi mdi-check-all mr-2 text-muted font-18 vertical-middle\"></i>Re-Open</a>" . "\r\n\t\t\t\t\t\t\t\t\t\t\t";
		}

		echo "\t\t\t\t\t\t\t\t\t\t\t" . '<a class="dropdown-item" href="javascript:void(0);" onClick="api(';
		echo intval($rTicket['id']);
		echo ", 'delete');\"><i class=\"mdi mdi-delete mr-2 text-muted font-18 vertical-middle\"></i>Delete</a>" . "\r\n\t\t\t\t\t\t\t\t\t\t\t";
	}

	echo "\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '</td>' . "\r\n\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
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

	function api(rID, rType, rConfirm = false) {
		if ((rType == "delete") && (!rConfirm)) {
			new jBox("Confirm", {
				confirmButton: "Delete",
				cancelButton: "Cancel",
				content: "Are you sure you want to delete this ticket?",
				confirm: function() {
					api(rID, rType, true);
				}
			}).open();
		} else {
			rConfirm = true;
		}
		if (rConfirm) {
			$.getJSON("./api?action=ticket&sub=" + rType + "&ticket_id=" + rID, function(data) {
				if (data.result == true) {
					reloadPage();
				} else {
					$.toast("An error occured while processing your request.");
				}
			}).fail(function() {
				$.toast("An error occured while processing your request.");
			});
		}
	}
	$(document).ready(function() {
		$("#tickets-table").DataTable({
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
				[0, "desc"]
			]
		});
		$("#tickets-table").css("width", "100%");
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
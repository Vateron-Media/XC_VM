<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
	goHome();
}

$rCategories = array(1 => getCategories(), 2 => getCategories('movie'), 3 => getCategories('series'), 4 => getCategories('radio'));
$rMainCategories = array(1 => array(), 2 => array(), 3 => array());

foreach (array(1, 2, 3, 4) as $rID) {
	foreach ($rCategories[$rID] as $rCategoryID => $rCategoryData) {
		$rMainCategories[$rID][] = $rCategoryData;
	}
}
$_TITLE = 'Stream Categories';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
									} else {
										echo ' style="display: none;"';
									} ?>>
	<div class="container-fluid">
		<div class="row">
			<div class="col-12">
				<div class="page-title-box">
					<div class="page-title-right">
						<?php include 'topbar.php'; ?>
					</div>
					<h4 class="page-title">Categories</h4>
				</div>
			</div>
		</div>
		<div class="row">
			<div class="col-xl-12">
				<?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Your new Category has been added. You can move it up the list to re-order it.
					</div>
				<?php } elseif (isset($_STATUS) && $_STATUS == STATUS_SUCCESS_MULTI) { ?>
					<div class="alert alert-success alert-dismissible fade show" role="alert">
						<button type="button" class="close" data-dismiss="alert" aria-label="Close">
							<span aria-hidden="true">&times;</span>
						</button>
						Categories have been re-ordered.
					</div>
				<?php } ?>
				<div class="card">
					<div class="card-body">
						<div id="basicwizard">
							<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
								<li class="nav-item">
									<a href="#category-order-1" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
										<i class="mdi mdi-play mr-1"></i>
										<span class="d-none d-sm-inline">Streams</span>
									</a>
								</li>
								<li class="nav-item">
									<a href="#category-order-2" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
										<i class="mdi mdi-movie mr-1"></i>
										<span class="d-none d-sm-inline">Movies</span>
									</a>
								</li>
								<li class="nav-item">
									<a href="#category-order-3" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
										<i class="mdi mdi-youtube-tv mr-1"></i>
										<span class="d-none d-sm-inline">Series</span>
									</a>
								</li>
								<li class="nav-item">
									<a href="#category-order-4" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
										<i class="mdi mdi-radio mr-1"></i>
										<span class="d-none d-sm-inline">Radio</span>
									</a>
								</li>
							</ul>
							<div class="tab-content b-0 mb-0 pt-0">
								<?php foreach (array(1, 2, 3, 4) as $tabID) { ?>
									<div class="tab-pane" id="category-order-<?php echo $tabID; ?>">
										<form action="#" method="POST" id="stream_categories_form-<?php echo $tabID; ?>">
											<input type="hidden" id="categories_input-<?php echo $tabID; ?>"
												name="categories" value="" />
											<div class="row">
												<div class="col-12">
													<p class="sub-header">
														To re-order a category, drag it up or down the list using the <i
															class="mdi mdi-view-sequential"></i> icon. Click Save Changes at
														the bottom once finished.
													</p>
													<div class="custom-dd dd" id="category_order-<?php echo $tabID; ?>">
														<ol class="dd-list">
															<?php foreach ($rMainCategories[$tabID] as $rCategory) { ?>
																<li class="dd-item dd3-item category-<?php echo $rCategory['id']; ?>"
																	data-id="<?php echo $rCategory['id']; ?>">
																	<div class="dd-handle dd3-handle"></div>
																	<div class="dd3-content">
																		<?php echo $rCategory['category_name']; ?>
																		<?php if ($rCategory['is_adult']) { ?>
																			<i class='text-pink mdi mdi-record'></i>
																		<?php } ?>
																		<span style="float:right;">
																			<?php if (hasPermissions('adv', 'edit_cat')) { ?>
																				<div class="btn-group">
																					<button type="button"
																						onClick="navigate('stream_category?id=<?php echo $rCategory['id']; ?>');"
																						class="btn btn-light waves-effect waves-light sml-button"><i
																							class="mdi mdi-pencil-outline"></i></button>
																					<button type="button"
																						class="btn btn-light waves-effect waves-light sml-button"
																						onClick="deleteCategory(<?php echo $rCategory['id']; ?>)"><i
																							class="mdi mdi-close"></i></button>
																				</div>
																			<?php } ?>
																		</span>
																	</div>
																</li>
															<?php } ?>
														</ol>
													</div>
												</div>
											</div>
											<ul class="list-inline wizard mb-0 add-margin-top-20">
												<li class="list-inline-item float-right">
													<button type="submit"
														class="btn btn-primary waves-effect waves-light">Save
														Changes</button>
												</li>
											</ul>
										</form>
									</div>
								<?php } ?>
							</div>
						</div>
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

	function deleteCategory(rID) {
		new jBox("Confirm", {
			confirmButton: "Delete",
			cancelButton: "Cancel",
			content: "Are you sure you want to delete this category? All streams attached will be uncategorised.",
			confirm: function() {
				$.getJSON("./api?action=category&sub=delete&category_id=" + rID, function(data) {
					if (data.result === true) {
						$(".category-" + rID).remove();
						$.toast("Category successfully deleted.");
					} else {
						$.toast("An error occured while processing your request.");
					}
				});
			}
		}).open();
	}
	$(document).ready(function() {
		$("#category_order-1").nestable({
			maxDepth: 1
		});
		$("#category_order-2").nestable({
			maxDepth: 1
		});
		$("#category_order-3").nestable({
			maxDepth: 1
		});
		$("#category_order-4").nestable({
			maxDepth: 1
		});
		$("#stream_categories_form-1").submit(function(e) {
			$("#categories_input-1").val(JSON.stringify($('#category_order-1.dd').nestable('serialize')));
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("#stream_categories_form-1")[0]));
		});
		$("#stream_categories_form-2").submit(function(e) {
			$("#categories_input-2").val(JSON.stringify($('#category_order-2.dd').nestable('serialize')));
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("#stream_categories_form-2")[0]));
		});
		$("#stream_categories_form-3").submit(function(e) {
			$("#categories_input-3").val(JSON.stringify($('#category_order-3.dd').nestable('serialize')));
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("#stream_categories_form-3")[0]));
		});
		$("#stream_categories_form-4").submit(function(e) {
			$("#categories_input-4").val(JSON.stringify($('#category_order-4.dd').nestable('serialize')));
			e.preventDefault();
			$(':input[type="submit"]').prop('disabled', true);
			submitForm(window.rCurrentPage, new FormData($("#stream_categories_form-4")[0]));
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
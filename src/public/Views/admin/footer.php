<?php

if (count(get_included_files()) == 1) {
	exit();
}

$_PAGE = getPageName();
include 'modals.php';

if (!isset($rModal) || !$rModal): ?>
	<footer class="footer">
		<?php if (!$rMobile): ?>
			<a href="#" class="scrollToTop" id="scrollToBottom"><button type="button" class="btn btn-info waves-effect waves-light"><i class="fas fa-caret-down"></i></button></a>
			<a href="#" class="scrollToTop" id="scrollToTop"><button type="button" class="btn btn-success waves-effect waves-light"><i class="fas fa-caret-up"></i></button></a>
		<?php endif; ?>

		<div class="container-fluid">
			<div class="row">
				<div class="col-md-12 copyright text-center">
					<?php echo getFooter(); ?>
				</div>
			</div>
		</div>
	</footer>
<?php endif; ?>

<script src="assets/js/vendor.min.js"></script>
<script src="assets/libs/jquery-toast/jquery.toast.min.js"></script>
<script src="assets/libs/jquery-nice-select/jquery.nice-select.min.js"></script>
<script src="assets/libs/switchery/switchery.min.js"></script>
<script src="assets/libs/select2/select2.min.js"></script>
<script src="assets/libs/nestable2/jquery.nestable.min.js"></script>
<script src="assets/libs/bootstrap-touchspin/jquery.bootstrap-touchspin.min.js"></script>
<script src="assets/libs/bootstrap-maxlength/bootstrap-maxlength.min.js"></script>
<script src="assets/libs/clockpicker/bootstrap-clockpicker.min.js"></script>
<script src="assets/libs/moment/moment.min.js"></script>
<script src="assets/libs/daterangepicker/daterangepicker.js"></script>
<script src="assets/libs/datatables/jquery.dataTables.min.js"></script>
<script src="assets/libs/datatables/dataTables.bootstrap4.js"></script>
<script src="assets/libs/datatables/dataTables.responsive.min.js"></script>
<script src="assets/libs/datatables/responsive.bootstrap4.min.js"></script>
<script src="assets/libs/datatables/dataTables.buttons.min.js"></script>
<script src="assets/libs/datatables/buttons.bootstrap4.min.js"></script>
<script src="assets/libs/datatables/buttons.html5.min.js"></script>
<script src="assets/libs/datatables/buttons.flash.min.js"></script>
<script src="assets/libs/datatables/buttons.print.min.js"></script>
<script src="assets/libs/datatables/dataTables.keyTable.min.js"></script>
<script src="assets/libs/datatables/dataTables.select.min.js"></script>
<script src="assets/libs/datatables/dataTables.rowReorder.js"></script>
<script src="assets/libs/twitter-bootstrap-wizard/jquery.bootstrap.wizard.min.js"></script>
<script src="assets/libs/treeview/jstree.min.js"></script>
<script src="assets/libs/quill/quill.min.js"></script>
<script src="assets/libs/magnific-popup/jquery.magnific-popup.min.js"></script>
<script src="assets/libs/jbox/jBox.all.min.js"></script>
<script src="assets/libs/jquery-knob/jquery.knob.min.js"></script>
<script src="assets/libs/apexcharts/apexcharts.min.js"></script>
<script src="assets/libs/jquery-number/jquery.number.js"></script>
<script src="assets/libs/jquery-vectormap/jquery-jvectormap-1.2.2.min.js"></script>
<script src="assets/libs/jquery-vectormap/jquery-jvectormap-world-mill-en.js"></script>
<script src="assets/libs/jquery-ui/jquery-ui.min.js"></script>
<script src="assets/libs/peity/jquery.peity.min.js"></script>
<script src="assets/libs/emodal/emodal.js"></script>
<script src="assets/libs/bootstrap-colorpicker/bootstrap-colorpicker.min.js"></script>
<script src="assets/libs/lazyload/lazyload.min.js"></script>
<script src="assets/libs/parsleyjs/parsley.min.js"></script>
<script src="assets/js/app.min.js"></script>

<?php if (!isset($_SETUP)): ?>
	<?php include 'post.php'; ?>
	<script>
		window.XC_VM = window.XC_VM || {};
		window.XC_VM.Config = {
			jsNavigate: <?php echo !empty($rSettings['js_navigate']) ? 'true' : 'false'; ?>,
			i18n: {
				error_occured: <?php echo json_encode($language::get('error_occured')); ?>,
				fingerprint_fail: <?php echo json_encode($language::get('fingerprint_fail')); ?>,
				movie_encode_started: <?php echo json_encode($language::get('movie_encode_started')); ?>,
				movie_encode_stopped: <?php echo json_encode($language::get('movie_encode_stopped')); ?>,
				episode_encoding_start: <?php echo json_encode($language::get('episode_encoding_start')); ?>,
				episode_encoding_stop: <?php echo json_encode($language::get('episode_encoding_stop')); ?>
			}
		};
	</script>
	<script src="assets/js/common.js"></script>
<?php endif; ?>
<script id="scripts">
	(function() {
		var toast = window.xcToast || function() {};
		var confirmDialog = window.xcConfirm || function(msg) {
			return Promise.resolve(confirm(msg));
		};

		window.disableAll = function() {
			confirmDialog('Are you sure you want to disable all folders?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=disable_watch', function() {
					toast('Folders have been disabled.');
				});
			});
		};

		window.enableAll = function() {
			confirmDialog('Are you sure you want to enable all folders?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=enable_watch', function() {
					toast('Folders have been enabled.');
				});
			});
		};

		window.killWatchFolder = function() {
			confirmDialog('Are you sure you want to kill all processes?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=kill_watch', function() {
					toast('Watch folder processes have been killed.');
				});
			});
		};

		window.api = function(rID, rType) {
			var doIt = function() {
				$.getJSON('./api?action=folder&sub=' + rType + '&folder_id=' + rID, function(data) {
					if (data.result === true) {
						if (rType === 'delete') {
							$('#datatable').DataTable().row('#folder-' + rID).remove().draw(false);
							toast('Folder successfully deleted.');
						} else if (rType === 'force') {
							toast('Folder has been forced to run in the background.');
						}
					} else {
						toast('An error occured while processing your request.', 'error');
					}
				}).fail(function() {
					toast('An error occured while processing your request.', 'error');
				});
			};
			if (rType === 'delete') {
				confirmDialog('Are you sure you want to delete this folder?').then(function(ok) {
					if (ok) {
						doIt();
					}
				});
			} else if (rType === 'force') {
				confirmDialog('Are you sure you want to force this folder to run now?').then(function(ok) {
					if (ok) {
						doIt();
					}
				});
			} else {
				doIt();
			}
		};

		$(function() {
			$('#datatable').DataTable({
				order: [
					[5, 'desc']
				],
				columnDefs: [{
					visible: false,
					targets: [0]
				}],
				layout: {
					topStart: 'pageLength',
					topEnd: 'search'
				}
			});
		});
	})();
</script>
</body>

</html>

<script id="scripts">
	(function() {
		var toast = window.xcToast || function() {};
		var confirmDialog = window.xcConfirm || function(msg) {
			return Promise.resolve(confirm(msg));
		};

		window.disableAll = function() {
			confirmDialog('Are you sure you want to disable all libraries?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=disable_plex', function() {
					toast('Libraries have been disabled.');
				});
			});
		};

		window.enableAll = function() {
			confirmDialog('Are you sure you want to enable all libraries?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=enable_plex', function() {
					toast('Libraries have been enabled.');
				});
			});
		};

		window.killPlexSync = function() {
			confirmDialog('Are you sure you want to kill all processes?').then(function(ok) {
				if (!ok) {
					return;
				}
				$.getJSON('./api?action=kill_plex', function() {
					toast('Plex Sync processes have been killed.');
				});
			});
		};

		window.api = function(rID, rType) {
			var doIt = function() {
				$.getJSON('./api?action=library&sub=' + rType + '&folder_id=' + rID, function(data) {
					if (data.result === true) {
						if (rType === 'delete') {
							$('#datatable').DataTable().row('#folder-' + rID).remove().draw(false);
							toast('Library successfully deleted.');
						} else if (rType === 'force') {
							toast('Library has been forced to sync in the background.');
						}
					} else {
						toast('An error occured while processing your request.', 'error');
					}
				}).fail(function() {
					toast('An error occured while processing your request.', 'error');
				});
			};
			if (rType === 'delete') {
				confirmDialog('Are you sure you want to delete this library?').then(function(ok) {
					if (ok) {
						doIt();
					}
				});
			} else if (rType === 'force') {
				confirmDialog('Are you sure you want to force this library to run now?').then(function(ok) {
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

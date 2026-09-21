<script id="scripts">
	(function() {
		var toast = window.xcToast || function() {};
		var confirmDialog = window.xcConfirm || function(msg) {
			return Promise.resolve(confirm(msg));
		};

		// Permission flags (emitted server-side; the controller returns clean JSON).
		var canServers = <?= \XcVm\Core\Auth\Authorization::check('adv', 'servers') ? 'true' : 'false'; ?>;
		var canEditMovie = <?= \XcVm\Core\Auth\Authorization::check('adv', 'edit_movie') ? 'true' : 'false'; ?>;
		var canEditEpisode = <?= \XcVm\Core\Auth\Authorization::check('adv', 'edit_episode') ? 'true' : 'false'; ?>;
		var WATCH_STATUS = { 1: ["success", "ADDED"], 2: ["danger", "SQL FAILED"], 3: ["danger", "NO CATEGORY"], 4: ["danger", "NO TMDb MATCH"], 5: ["danger", "INVALID FILE"], 6: ["info", "UPGRADED"] };
		function esc(v) { return $("<div>").text(v == null ? "" : v).html(); }
		function typeCell(d) { return d == 1 ? "Movies" : (d == 2 ? "Series" : ""); }
		function serverCell(d, t, row) { return canServers ? '<a href="server_view?id=' + row.server_id + '">' + esc(row.server_name) + '</a>' : esc(row.server_name); }
		function statusCell(d) { var s = WATCH_STATUS[d]; return s ? '<span class="badge bg-label-' + s[0] + '">' + s[1] + '</span>' : ""; }
		function actionsCell(d, t, row) {
			var h = '<div class="d-inline-flex gap-1">';
			if (row.stream_id > 0) {
				if (row.type == 1 && canEditMovie) { h += '<a href="stream_view?id=' + row.stream_id + '" title="View Movie" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-eye"></i></a>'; }
				else if (row.type == 2 && canEditEpisode) { h += '<a href="stream_view?id=' + row.stream_id + '" title="View Episode" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-eye"></i></a>'; }
			}
			if (row.status > 1 && row.type == 1) { h += '<a href="movie?path=' + encodeURIComponent("s:" + row.server_id + ":" + row.filename) + '" title="Manual Match" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-plus"></i></a>'; }
			h += '<button type="button" title="Delete" class="btn btn-sm btn-icon btn-label-danger" onClick="api(' + row.id + ', \'delete\');"><i class="icon-base ti tabler-x"></i></button>';
			return h + "</div>";
		}

		window.api = function(rID, rType) {
			var doIt = function() {
				$.getJSON('./api?action=watch_output&sub=' + rType + '&result_id=' + rID, function(data) {
					if (data.result == true) {
						if (rType === 'delete') {
							toast('Record successfully deleted.');
						}
						$('#datatable-md1').DataTable().ajax.reload(null, false);
					} else {
						toast('An error occured while processing your request.', 'error');
					}
				}).fail(function() {
					toast('An error occured while processing your request.', 'error');
				});
			};
			if (rType === 'delete') {
				confirmDialog('Are you sure you want to delete this record?').then(function(ok) {
					if (ok) {
						doIt();
					}
				});
			} else {
				doIt();
			}
		};

		function getServer() {
			return $('#result_server').val();
		}

		function getType() {
			return $('#result_type').val();
		}

		function getStatus() {
			return $('#result_status').val();
		}

		$(function() {
			if ($.fn.select2) {
				$('#result_server, #result_type, #result_status').select2({
					width: '100%'
				});
			}

			var table = $('#datatable-md1').DataTable({
				processing: true,
				serverSide: true,
				responsive: false,
				ajax: {
					url: './table',
					data: function(d) {
						d.id = 'watch_output';
						d.server = getServer();
						d.type = getType();
						d.status = getStatus();
					}
				},
				columns: [
					{ data: "id", className: "text-center" },
					{ data: "type", render: typeCell },
					{ data: null, render: serverCell },
					{ data: "filename" },
					{ data: "status", className: "text-center", render: statusCell },
					{ data: "dateadded", className: "text-center" },
					{ data: null, orderable: false, searchable: false, className: "text-center", render: actionsCell },
				],
				order: [
					[5, 'desc']
				],
				pageLength: <?= intval($rSettings['default_entries']) ?: 10; ?>,
				layout: {
					topStart: 'pageLength',
					topEnd: 'search'
				}
			});

			$('#result_search').keyup(function() {
				table.search($(this).val()).draw();
			});
			$('#result_show_entries').change(function() {
				table.page.len($(this).val()).draw();
			});
			$('#result_server').change(function() {
				table.ajax.reload(null, false);
			});
			$('#result_type').change(function() {
				table.ajax.reload(null, false);
			});
			$('#result_status').change(function() {
				table.ajax.reload(null, false);
			});

			// Clear logs by date range.
			if (window.flatpickr) {
				flatpickr('#range_clear_from', {
					dateFormat: 'Y-m-d',
					allowInput: true
				});
				flatpickr('#range_clear_to', {
					dateFormat: 'Y-m-d',
					allowInput: true
				});
			}
			$('#btn-clear-logs').on('click', function() {
				bootstrap.Modal.getOrCreateInstance(document.getElementById('clearLogsModal')).show();
			});
			$('#clear_logs').on('click', function() {
				confirmDialog(<?= json_encode($language::get('clear_confirm')); ?>).then(function(ok) {
					if (!ok) {
						return;
					}
					$.getJSON('./api?action=watch_clear_logs&from=' + encodeURIComponent($('#range_clear_from').val()) + '&to=' + encodeURIComponent($('#range_clear_to').val()), function() {
						toast('Logs have been cleared.');
						bootstrap.Modal.getOrCreateInstance(document.getElementById('clearLogsModal')).hide();
						table.ajax.reload(null, false);
					});
				});
			});

			// Export current results as CSV (server-side report).
			$('#btn-export-csv').on('click', function() {
				toast('Generating CSV report...');
				window.location.href = 'api?action=report&params=' + encodeURIComponent(JSON.stringify(table.ajax.params()));
			});

			// Export the currently loaded rows as JSON.
			$('#btn-export-json').on('click', function() {
				var rows = table.rows().data().toArray();
				var blob = new Blob([JSON.stringify(rows, null, 2)], {
					type: 'application/json'
				});
				var url = URL.createObjectURL(blob);
				var a = document.createElement('a');
				a.href = url;
				a.download = 'watch_output.json';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});
		});
	})();
</script>
</body>

</html>

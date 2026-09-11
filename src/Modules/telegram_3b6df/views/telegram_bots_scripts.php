<script>
(function() {
    function toast(type, msg) {
        if (window.xcToast) {
            window.xcToast(msg, type);
        } else if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: type === 'error' ? 'error' : 'success',
                title: msg,
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        } else {
            alert(msg);
        }
    }

    // Search filter
    var searchInput = document.getElementById('botSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value.trim().toLowerCase();
            document.querySelectorAll('.bot-card-wrapper').forEach(function(card) {
                var name = card.getAttribute('data-name') || '';
                var user = card.getAttribute('data-user') || '';
                var chat = card.getAttribute('data-chat') || '';
                if (name.includes(val) || user.includes(val) || chat.includes(val)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Toggle Active Status
    document.querySelectorAll('.js-toggle-status').forEach(function(toggle) {
        toggle.addEventListener('change', function() {
            var id = this.getAttribute('data-id');
            var isChecked = this.checked ? 1 : 0;
            var el = this;

            fetch('./api?action=telegram_bot_toggle&id=' + encodeURIComponent(id) + '&status=' + isChecked, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.result) {
                    toast('success', data.message || 'Status updated');
                } else {
                    el.checked = !el.checked;
                    toast('error', data.message || 'Failed to update status');
                }
            })
            .catch(function() {
                el.checked = !el.checked;
                toast('error', 'Network error');
            });
        });
    });

    // Test Broadcast Action
    document.querySelectorAll('.js-btn-test').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Sending...';

            fetch('./api?action=telegram_bot_broadcast_test&bot_id=' + encodeURIComponent(id), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                if (data.result) {
                    toast('success', data.message || 'Test broadcast sent successfully!');
                } else {
                    toast('error', data.message || 'Failed to send broadcast');
                }
            })
            .catch(function() {
                btn.disabled = false;
                btn.innerHTML = originalHtml;
                toast('error', 'Network connection error');
            });
        });
    });

    // Delete Bot Action
    document.querySelectorAll('.js-btn-delete').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            var cardWrapper = this.closest('.bot-card-wrapper');

            var confirmPromise = typeof Swal !== 'undefined'
                ? Swal.fire({
                    title: 'Delete Bot?',
                    text: 'Are you sure you want to delete "' + name + '"? All related broadcast history will be removed.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#ea5455',
                    cancelButtonColor: '#82868b',
                    confirmButtonText: 'Yes, delete it!'
                }).then(function(r) { return r.isConfirmed; })
                : Promise.resolve(window.confirm('Delete bot ' + name + '?'));

            confirmPromise.then(function(confirmed) {
                if (!confirmed) return;

                fetch('./api?action=telegram_bot_delete&id=' + encodeURIComponent(id), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.result) {
                        toast('success', data.message || 'Bot deleted');
                        if (cardWrapper) {
                            cardWrapper.remove();
                        }
                    } else {
                        toast('error', data.message || 'Failed to delete bot');
                    }
                })
                .catch(function() {
                    toast('error', 'Network connection error');
                });
            });
        });
    });
})();
</script>
</body>
</html>

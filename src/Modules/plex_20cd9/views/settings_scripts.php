<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};

        $(function() {
            // Numeric-only filter for the tuning inputs.
            $.fn.inputFilter = function(cb) {
                return this.on('input keydown keyup mousedown mouseup select contextmenu drop', function() {
                    if (cb(this.value)) {
                        this.oldValue = this.value;
                        this.oldSelectionStart = this.selectionStart;
                        this.oldSelectionEnd = this.selectionEnd;
                    } else if (this.hasOwnProperty('oldValue')) {
                        this.value = this.oldValue;
                        this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
                    }
                });
            };
            var digits = function(value) {
                return /^\d*$/.test(value);
            };
            $('#scan_seconds').inputFilter(digits);
            $('#max_items').inputFilter(digits);
            $('#thread_count_movie').inputFilter(digits);
            $('#thread_count_show').inputFilter(digits);

            // Category / bouquet pickers (full-page tabs, no modal → no dropdownParent).
            if ($.fn.select2) {
                $('.select2').select2({
                    width: '100%'
                });
            }

            // Save → post.php?action=settings_plex (mirrors legacy submitForm contract).
            $('#plex-settings-form').on('submit', function(e) {
                e.preventDefault();
                var btn = document.getElementById('save-settings');
                if (btn) {
                    btn.disabled = true;
                }
                var fd = new FormData(this);
                fd.append('submit_settings', '1');
                fetch('post.php?action=settings_plex', {
                        method: 'POST',
                        body: fd,
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var dt;
                        try {
                            dt = JSON.parse(txt);
                        } catch (err) {
                            dt = {
                                result: false
                            };
                        }
                        if (dt && dt.result !== false) {
                            window.location.href = dt.location || 'settings_plex';
                            return;
                        }
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast('Failed to save Plex settings.', 'error');
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast('Failed to save Plex settings.', 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>

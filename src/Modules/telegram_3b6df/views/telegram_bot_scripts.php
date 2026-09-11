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

    // Step switching logic
    var currentStep = 1;
    function goToStep(step) {
        currentStep = step;
        document.querySelectorAll('.wizard-step-pane').forEach(function(pane) {
            pane.classList.add('d-none');
        });
        var targetPane = document.getElementById('stepPane' + step);
        if (targetPane) targetPane.classList.remove('d-none');

        // Update stepper indicator
        document.querySelectorAll('.step-indicator').forEach(function(ind) {
            var s = parseInt(ind.getAttribute('data-step'), 10);
            var num = ind.querySelector('.step-number');
            if (s === step) {
                ind.classList.add('active');
                ind.classList.remove('text-muted');
                num.className = 'step-number badge rounded-pill bg-primary fs-7';
            } else if (s < step) {
                ind.classList.remove('active', 'text-muted');
                num.className = 'step-number badge rounded-pill bg-success fs-7';
            } else {
                ind.classList.remove('active');
                ind.classList.add('text-muted');
                num.className = 'step-number badge rounded-pill bg-label-secondary fs-7';
            }
        });

        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    document.querySelectorAll('.js-next-step').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = parseInt(this.getAttribute('data-target'), 10);
            if (target === 2) {
                // Validate Step 1
                var name = document.getElementById('bot_name').value.trim();
                var token = document.getElementById('bot_token').value.trim();
                if (!name) { toast('error', 'Please enter a bot name'); return; }
                if (!token) { toast('error', 'Please enter the Telegram bot token'); return; }
            } else if (target === 3) {
                // Validate Step 2
                var chat = document.getElementById('chat_id').value.trim();
                if (!chat) { toast('error', 'Please enter a target Channel or Chat ID'); return; }
            }
            goToStep(target);
        });
    });

    document.querySelectorAll('.js-prev-step').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var target = parseInt(this.getAttribute('data-target'), 10);
            goToStep(target);
        });
    });

    // Step 1: Verify Token Action
    var btnVerify = document.getElementById('btnVerifyToken');
    if (btnVerify) {
        btnVerify.addEventListener('click', function() {
            var token = document.getElementById('bot_token').value.trim();
            if (!token) {
                toast('error', 'Please enter a bot token first.');
                return;
            }

            var originalHtml = btnVerify.innerHTML;
            btnVerify.disabled = true;
            btnVerify.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Checking...';

            fetch('./api?action=telegram_bot_test_token&bot_token=' + encodeURIComponent(token), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btnVerify.disabled = false;
                btnVerify.innerHTML = originalHtml;
                if (data.result && data.result.username) {
                    var resDiv = document.getElementById('tokenVerifyResult');
                    resDiv.classList.remove('d-none');
                    document.getElementById('verifiedBotName').textContent = data.result.name || 'Telegram Bot';
                    document.getElementById('verifiedBotUsername').textContent = '@' + data.result.username;
                    toast('success', data.message || 'Token verified successfully!');
                } else {
                    toast('error', data.message || 'Failed to verify token');
                }
            })
            .catch(function() {
                btnVerify.disabled = false;
                btnVerify.innerHTML = originalHtml;
                toast('error', 'Connection error to server');
            });
        });
    }

    // Step 2: Test Chat Action
    var btnTestChat = document.getElementById('btnTestChat');
    if (btnTestChat) {
        btnTestChat.addEventListener('click', function() {
            var token = document.getElementById('bot_token').value.trim();
            var chatId = document.getElementById('chat_id').value.trim();
            var botName = document.getElementById('bot_name').value.trim() || 'XC_VM Bot';

            if (!token || !chatId) {
                toast('error', 'Please enter both Bot Token and Channel/Chat ID.');
                return;
            }

            var originalHtml = btnTestChat.innerHTML;
            btnTestChat.disabled = true;
            btnTestChat.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Sending...';

            var alertBox = document.getElementById('chatTestAlert');
            alertBox.className = 'mt-3 d-none';

            fetch('./api?action=telegram_bot_test_chat&bot_token=' + encodeURIComponent(token) + '&chat_id=' + encodeURIComponent(chatId) + '&bot_name=' + encodeURIComponent(botName), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btnTestChat.disabled = false;
                btnTestChat.innerHTML = originalHtml;
                alertBox.classList.remove('d-none');
                if (data.result) {
                    alertBox.className = 'alert alert-success p-2 fs-7';
                    alertBox.textContent = '✅ ' + (data.message || 'Test message sent successfully!');
                    toast('success', data.message || 'Test message sent!');
                } else {
                    alertBox.className = 'alert alert-danger p-2 fs-7';
                    alertBox.textContent = '❌ ' + (data.message || 'Failed to send test message. Check bot permissions.');
                    toast('error', data.message || 'Failed to send test message');
                }
            })
            .catch(function() {
                btnTestChat.disabled = false;
                btnTestChat.innerHTML = originalHtml;
                toast('error', 'Connection error to server');
            });
        });
    }

    // Step 3: Category toggle all/custom
    var catModeAll = document.getElementById('catModeAll');
    var catModeCustom = document.getElementById('catModeCustom');
    var catContainer = document.getElementById('categorySelectionContainer');

    function syncCatMode() {
        if (catModeCustom && catModeCustom.checked) {
            catContainer.classList.remove('d-none');
        } else {
            catContainer.classList.add('d-none');
        }
    }
    if (catModeAll) catModeAll.addEventListener('change', syncCatMode);
    if (catModeCustom) catModeCustom.addEventListener('change', syncCatMode);

    var btnSelectAllCats = document.getElementById('btnSelectAllCats');
    if (btnSelectAllCats) {
        btnSelectAllCats.addEventListener('click', function() {
            document.querySelectorAll('.js-cat-checkbox').forEach(function(cb) { cb.checked = true; });
        });
    }

    var btnDeselectAllCats = document.getElementById('btnDeselectAllCats');
    if (btnDeselectAllCats) {
        btnDeselectAllCats.addEventListener('click', function() {
            document.querySelectorAll('.js-cat-checkbox').forEach(function(cb) { cb.checked = false; });
        });
    }

    // Step 4: Media choice radio styling
    document.querySelectorAll('input[name="image_type"]').forEach(function(r) {
        r.addEventListener('change', function() {
            document.querySelectorAll('.media-choice-card').forEach(function(c) {
                c.classList.remove('border-primary', 'bg-label-primary');
            });
            var label = document.querySelector('label[for="' + this.id + '"]');
            if (label) label.classList.add('border-primary', 'bg-label-primary');

            // Update Mockup
            var holder = document.getElementById('mockupImageHolder');
            var img = document.getElementById('mockupImage');
            if (this.value === 'none') {
                holder.classList.add('d-none');
            } else {
                holder.classList.remove('d-none');
                if (this.value === 'backdrop') {
                    img.src = 'https://image.tmdb.org/t/p/w780/rAiYTsqJiOEZvo79zoPx7UwhLcp.jpg';
                    img.style.maxHeight = '180px';
                } else {
                    img.src = 'https://image.tmdb.org/t/p/w600_and_h900_bestv2/oYuLEt3zVCKq57qu2F8dT7NIa6f.jpg';
                    img.style.maxHeight = '240px';
                }
            }
        });
    });

    // Step 4: Button toggle
    var incBtn = document.getElementById('include_button');
    var btnOpts = document.getElementById('buttonOptionsContainer');
    var mockupBtnHolder = document.getElementById('mockupButtonHolder');
    var mockupBtnText = document.getElementById('mockupButtonText');

    if (incBtn) {
        incBtn.addEventListener('change', function() {
            if (this.checked) {
                btnOpts.classList.remove('d-none');
                mockupBtnHolder.classList.remove('d-none');
            } else {
                btnOpts.classList.add('d-none');
                mockupBtnHolder.classList.add('d-none');
            }
        });
    }

    var btnTextInput = document.getElementById('button_text');
    if (btnTextInput) {
        btnTextInput.addEventListener('input', function() {
            mockupBtnText.textContent = this.value || '🎬 Watch Now';
        });
    }

    // Tag chip clicks
    document.querySelectorAll('.js-tag-chip').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var tag = this.getAttribute('data-tag');
            var textarea = document.getElementById('custom_template');
            var start = textarea.selectionStart;
            var end = textarea.selectionEnd;
            var text = textarea.value;
            textarea.value = text.substring(0, start) + tag + text.substring(end);
            textarea.focus();
            textarea.selectionStart = textarea.selectionEnd = start + tag.length;
        });
    });

    // Form Submission
    var form = document.getElementById('botWizardForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSaveBot');
            var spinner = btn.querySelector('.spinner-border');
            btn.disabled = true;
            spinner.classList.remove('d-none');

            var formData = new FormData(form);

            fetch('./api?action=telegram_bot_save', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                btn.disabled = false;
                spinner.classList.add('d-none');
                if (data.result) {
                    toast('success', data.message || 'Bot saved successfully!');
                    setTimeout(function() {
                        window.location.href = 'telegram_bots';
                    }, 800);
                } else {
                    toast('error', data.message || 'Failed to save bot.');
                }
            })
            .catch(function() {
                btn.disabled = false;
                spinner.classList.add('d-none');
                toast('error', 'Connection error to server.');
            });
        });
    }
})();
</script>
</body>

</html>

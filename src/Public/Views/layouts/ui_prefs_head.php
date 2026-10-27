    <!-- Helpers + template customizer must precede config.js -->
    <script src="assets/vendor/js/helpers.js"></script>
    <script src="assets/vendor/js/template-customizer.js"></script>
    <!-- Per-user customizer state (server-authoritative) consumed by config.js -->
    <script>
        window.XC_VM = window.XC_VM || {};
        window.XC_VM.uiPrefsUrl = './api?action=save_ui_prefs';
        window.XC_VM_UIPrefs = <?= json_encode($xmUiPrefs, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="assets/js/config.js"></script>

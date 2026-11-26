<?php if (!empty($rUserInfo['can_set_forced_country'])):
    $rCurrentCountry = strtoupper(trim((string) ($rForcedCountryLine ?? '')));
    $rPackageCountry = strtoupper(trim((string) ($rForcedCountryPackage ?? '')));
    $rInheritOption = $language::get('forced_country_inherit_option');
    $rOptionalHelp = $language::get('forced_country_optional_help');
    $rPackageHelp = $language::get('forced_country_package_help');
    $rScriptJsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="mb-6">
    <label class="form-label" for="forced_country"><?= htmlspecialchars($language::get('forced_country'), ENT_QUOTES); ?></label>
    <select name="forced_country" id="forced_country" class="form-select" <?= $rPackageCountry !== '' ? 'disabled' : ''; ?>>
        <option value=""><?= htmlspecialchars($rInheritOption, ENT_QUOTES); ?></option>
        <?php if ($rPackageCountry !== '' && !array_key_exists($rPackageCountry, \XcVm\Core\Reference\GeoReference::countryCodes())): ?>
            <option value="<?= htmlspecialchars($rPackageCountry, ENT_QUOTES); ?>" data-package-only selected><?= htmlspecialchars($rPackageCountry, ENT_QUOTES); ?></option>
        <?php endif; ?>
        <?php foreach (\XcVm\Core\Reference\GeoReference::countryCodes() as $rCode => $rName): ?>
            <option value="<?= htmlspecialchars($rCode, ENT_QUOTES); ?>" <?= (($rPackageCountry ?: $rCurrentCountry) === $rCode) ? 'selected' : ''; ?>><?= htmlspecialchars($rName, ENT_QUOTES); ?></option>
        <?php endforeach; ?>
    </select>
    <div class="form-text" id="forced_country_help"><?= htmlspecialchars($rPackageCountry !== '' ? $rPackageHelp : $rOptionalHelp, ENT_QUOTES); ?></div>
</div>
<script>
var resellerForcedCountryPackageHelp = <?= json_encode($rPackageHelp, $rScriptJsonFlags); ?>;
var resellerForcedCountryOptionalHelp = <?= json_encode($rOptionalHelp, $rScriptJsonFlags); ?>;
window.updateResellerForcedCountry = function(packageCountry, currentCountry) {
    var field = document.getElementById('forced_country');
    if (!field) { return; }
    packageCountry = String(packageCountry || '').toUpperCase();
    field.querySelectorAll('[data-package-only]').forEach(function(option) { option.remove(); });
    var hasPackageCountry = Array.prototype.some.call(field.options, function(option) { return option.value === packageCountry; });
    if (packageCountry && !hasPackageCountry) {
        var inherited = new Option(packageCountry, packageCountry);
        inherited.dataset.packageOnly = '1';
        field.add(inherited);
    }
    field.disabled = packageCountry !== '';
    field.value = packageCountry || String(currentCountry || '').toUpperCase();
    document.getElementById('forced_country_help').textContent = packageCountry
        ? resellerForcedCountryPackageHelp
        : resellerForcedCountryOptionalHelp;
};
</script>
<?php endif; ?>

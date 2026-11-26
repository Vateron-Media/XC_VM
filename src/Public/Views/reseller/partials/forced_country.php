<?php if (!empty($rUserInfo['can_set_forced_country'])):
    $rCurrentCountry = strtoupper(trim((string) ($rForcedCountryLine ?? '')));
    $rPackageCountry = strtoupper(trim((string) ($rForcedCountryPackage ?? '')));
?>
<div class="mb-6">
    <label class="form-label" for="forced_country"><?= $language::get('forced_country'); ?></label>
    <select name="forced_country" id="forced_country" class="form-select" <?= $rPackageCountry !== '' ? 'disabled' : ''; ?>>
        <option value="">Inherit package/global policy</option>
        <?php foreach (\XcVm\Core\Reference\GeoReference::countryCodes() as $rCode => $rName): ?>
            <option value="<?= htmlspecialchars($rCode, ENT_QUOTES); ?>" <?= (($rPackageCountry ?: $rCurrentCountry) === $rCode) ? 'selected' : ''; ?>><?= htmlspecialchars($rName, ENT_QUOTES); ?></option>
        <?php endforeach; ?>
    </select>
    <div class="form-text" id="forced_country_help"><?= $rPackageCountry !== '' ? 'Inherited from the package and cannot be changed.' : 'Optional. Blank inherits the package/global policy.'; ?></div>
</div>
<script>
window.updateResellerForcedCountry = function(packageCountry, currentCountry) {
    var field = document.getElementById('forced_country');
    if (!field) { return; }
    packageCountry = String(packageCountry || '').toUpperCase();
    field.disabled = packageCountry !== '';
    field.value = packageCountry || String(currentCountry || '').toUpperCase();
    document.getElementById('forced_country_help').textContent = packageCountry
        ? 'Inherited from the package and cannot be changed.'
        : 'Optional. Blank inherits the package/global policy.';
};
</script>
<?php endif; ?>

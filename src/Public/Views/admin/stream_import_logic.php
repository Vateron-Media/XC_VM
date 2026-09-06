<?php

/**
 * Per-stream review row for the mass edit/review page (Bootstrap 5).
 *
 * Included in a loop from stream_review.php (review mode) once per stream, with:
 *   $rStream    — the stream: id, channel_id, epg_id, title, category[], bouquets[]
 *   $rCategories — live categories, id => row (category_name)
 *   $rBouquets   — bouquets, id => row (bouquet_name)
 *   $rOptions    — ['categories'=>bool, 'epg'=>bool, 'bouquets'=>bool]
 *   $rWidth      — column widths [name, categories, bouquets] (percent)
 *   $language    — Translator class-name for ::get()
 *
 * Emits the hidden SAVE-contract inputs consumed by StreamReviewController:
 *   modified_<id>, name_<id>, channel_id_<id>, epg_id_<id>, bouquets_<id>, categories_<id>
 * (plus epg_type_<id>, which the controller ignores but the ported JS still sets).
 * The element ids/classes match the evaluateChanges/clearEPG/.epg_api handlers in
 * stream_review.php exactly.
 */

$rSid = (int) $rStream['id'];
$rTitle = (string) $rStream['title'];
$rStreamCats = array_values(array_map('intval', (array) ($rStream['category'] ?? array())));
$rStreamBqs = array_values(array_map('intval', (array) ($rStream['bouquets'] ?? array())));

// Derive Bootstrap grid columns (of 12) from the percent widths, EPG gets the remainder.
$rGrid = static function ($rPct) {
    return max(1, min(12, (int) round($rPct / 100 * 12)));
};
$rNameCol = $rGrid($rWidth[0] ?? 25);
$rUsed = $rNameCol;
$rCatCol = 0;
$rBqCol = 0;
if (!empty($rOptions['categories'])) {
    $rCatCol = $rGrid($rWidth[1] ?? 20);
    $rUsed += $rCatCol;
}
if (!empty($rOptions['bouquets'])) {
    $rBqCol = $rGrid($rWidth[2] ?? 20);
    $rUsed += $rBqCol;
}
$rEpgCol = !empty($rOptions['epg']) ? max(2, 12 - $rUsed) : 0;
?>
<div class="card mb-3">
    <div class="card-body">
        <input type="hidden" id="modified_<?= $rSid ?>" name="modified_<?= $rSid ?>" value="0">
        <div class="row g-3 align-items-start">
            <!-- Name -->
            <div class="col-12 col-md-<?= $rNameCol ?>">
                <label class="form-label" for="name_input_<?= $rSid ?>">
                    <?= $language::get('name') ?> <span class="badge bg-label-secondary">#<?= $rSid ?></span>
                </label>
                <input type="text" class="form-control name_input" id="name_input_<?= $rSid ?>" data-id="<?= $rSid ?>" value="<?= htmlspecialchars($rTitle, ENT_QUOTES) ?>">
                <input type="hidden" id="name_s_<?= $rSid ?>" name="name_<?= $rSid ?>" value="<?= htmlspecialchars($rTitle, ENT_QUOTES) ?>">
            </div>

            <?php if (!empty($rOptions['categories'])): ?>
                <!-- Categories -->
                <div class="col-12 col-md-<?= $rCatCol ?>">
                    <label class="form-label" for="category_id_<?= $rSid ?>"><?= $language::get('categories') ?></label>
                    <select id="category_id_<?= $rSid ?>" class="form-select category_id" data-id="<?= $rSid ?>" multiple>
                        <?php foreach ($rCategories as $rCatId => $rCat): ?>
                            <option value="<?= (int) $rCatId ?>" <?= in_array((int) $rCatId, $rStreamCats, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="categories_s_<?= $rSid ?>" name="categories_<?= $rSid ?>" value="<?= htmlspecialchars(json_encode($rStreamCats), ENT_QUOTES) ?>">
                </div>
            <?php endif; ?>

            <?php if (!empty($rOptions['bouquets'])): ?>
                <!-- Bouquets -->
                <div class="col-12 col-md-<?= $rBqCol ?>">
                    <label class="form-label" for="bouquets_<?= $rSid ?>"><?= $language::get('bouquets') ?></label>
                    <select id="bouquets_<?= $rSid ?>" class="form-select bouquet" data-id="<?= $rSid ?>" multiple>
                        <?php foreach ($rBouquets as $rBqId => $rBq): ?>
                            <option value="<?= (int) $rBqId ?>" <?= in_array((int) $rBqId, $rStreamBqs, true) ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) $rBq['bouquet_name'], ENT_QUOTES) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="bouquets_s_<?= $rSid ?>" name="bouquets_<?= $rSid ?>" value="<?= htmlspecialchars(json_encode($rStreamBqs), ENT_QUOTES) ?>">
                </div>
            <?php endif; ?>

            <?php if (!empty($rOptions['epg'])): ?>
                <!-- EPG -->
                <div class="col-12 col-md-<?= $rEpgCol ?>">
                    <label class="form-label" for="epg_api_<?= $rSid ?>"><?= $language::get('epg') ?></label>
                    <div class="d-flex align-items-start gap-2">
                        <div class="flex-grow-1">
                            <select id="epg_api_<?= $rSid ?>" class="form-select epg_api" data-id="<?= $rSid ?>"></select>
                        </div>
                        <button type="button" id="clear_epg_<?= $rSid ?>" data-id="<?= $rSid ?>" onClick="clearEPG(this)" class="btn btn-sm btn-icon flex-shrink-0 <?= $rStream['channel_id'] ? 'btn-warning' : 'btn-secondary' ?>" title="<?= htmlspecialchars((string) $language::get('clear_epg'), ENT_QUOTES) ?>">
                            <i class="icon-base ti tabler-x"></i>
                        </button>
                        <a id="view_epg_<?= $rSid ?>" href="./epg?stream_id=<?= $rSid ?>" target="_blank" class="btn btn-sm btn-icon flex-shrink-0 btn-secondary" title="<?= htmlspecialchars((string) $language::get('view_epg'), ENT_QUOTES) ?>">
                            <i class="icon-base ti tabler-eye"></i>
                        </a>
                    </div>
                    <input type="hidden" id="channel_id_s_<?= $rSid ?>" name="channel_id_<?= $rSid ?>" value="<?= htmlspecialchars((string) ($rStream['channel_id'] ?? ''), ENT_QUOTES) ?>">
                    <input type="hidden" id="epg_id_s_<?= $rSid ?>" name="epg_id_<?= $rSid ?>" value="<?= htmlspecialchars((string) ($rStream['epg_id'] ?? ''), ENT_QUOTES) ?>">
                    <input type="hidden" id="epg_type_s_<?= $rSid ?>" name="epg_type_<?= $rSid ?>" value="0">
                </div>
            <?php else: ?>
                <input type="hidden" id="channel_id_s_<?= $rSid ?>" name="channel_id_<?= $rSid ?>" value="<?= htmlspecialchars((string) ($rStream['channel_id'] ?? ''), ENT_QUOTES) ?>">
                <input type="hidden" id="epg_id_s_<?= $rSid ?>" name="epg_id_<?= $rSid ?>" value="<?= htmlspecialchars((string) ($rStream['epg_id'] ?? ''), ENT_QUOTES) ?>">
            <?php endif; ?>
        </div>
    </div>
</div>

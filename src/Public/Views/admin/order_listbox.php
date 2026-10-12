<?php

/**
 * One tab of the channel / bouquet order editor (included by channel_order.php and
 * bouquet_sort.php inside their tab loop). Expects $rType (stream / movie / series /
 * radio), $rItems (that type's rows in their current order) and $language. Renders the
 * toolbar and a multi-select drag-and-drop list; the behaviour is window.xcOrderListbox
 * (admin footer.php), and the page reads the order back with xcOrderListbox.ids($rType).
 */

$rTitle = static function (string $rKey, string $rFallback) use ($language): string {
    return htmlspecialchars((string) ($language::get($rKey) ?: $rFallback), ENT_QUOTES);
};
?>
<p class="text-body-secondary small mb-2"><i class="icon-base ti tabler-grip-vertical"></i> Drag to re-order. Click to select, Ctrl/Cmd+click or Shift+click to select several, then drag them together or use the buttons; Up/Down move them by the <b>Step</b> number of places. Click <b>Save Changes</b> when done.</p>
<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="input-group" style="width:auto">
        <label class="input-group-text" for="order-step-<?= $rType; ?>">Step</label>
        <input type="number" class="form-control" id="order-step-<?= $rType; ?>" min="1" value="1" style="width:5rem">
        <button type="button" class="btn btn-label-primary btn-icon" data-type="<?= $rType; ?>" data-order-action="up" title="<?= $rTitle('move_up', 'Move up'); ?>"><i class="icon-base ti tabler-chevron-up"></i></button>
        <button type="button" class="btn btn-label-primary btn-icon" data-type="<?= $rType; ?>" data-order-action="down" title="<?= $rTitle('move_down', 'Move down'); ?>"><i class="icon-base ti tabler-chevron-down"></i></button>
    </div>
    <div class="btn-group" role="group">
        <button type="button" class="btn btn-label-primary btn-icon" data-type="<?= $rType; ?>" data-order-action="top" title="<?= $rTitle('move_to_top', 'Move to top'); ?>"><i class="icon-base ti tabler-arrow-bar-to-up"></i></button>
        <button type="button" class="btn btn-label-primary btn-icon" data-type="<?= $rType; ?>" data-order-action="bottom" title="<?= $rTitle('move_to_bottom', 'Move to bottom'); ?>"><i class="icon-base ti tabler-arrow-bar-to-down"></i></button>
    </div>
    <button type="button" class="btn btn-label-info" data-type="<?= $rType; ?>" data-order-action="atoz"><?= $rTitle('a_to_z', 'A to Z'); ?></button>
    <span class="text-body-secondary small ms-auto" id="order-count-<?= $rType; ?>"></span>
</div>
<ol class="list-group mb-3 js-order-list" id="order-list-<?= $rType; ?>" data-type="<?= $rType; ?>" style="list-style:none;padding-left:0;max-height:60vh;overflow-y:auto;user-select:none">
    <?php foreach ($rItems as $rItem): ?>
        <li class="list-group-item d-flex align-items-center" data-id="<?= (int) $rItem['id']; ?>" draggable="true" style="cursor:pointer">
            <i class="icon-base ti tabler-grip-vertical text-body-secondary me-2" style="cursor:grab"></i>
            <span class="js-order-pos text-body-secondary small me-3" style="min-width:2.5rem"></span>
            <span class="flex-grow-1 js-order-name"><?= htmlspecialchars((string) ($rItem['stream_display_name'] ?? $rItem['title'] ?? ''), ENT_QUOTES); ?></span>
        </li>
    <?php endforeach; ?>
</ol>

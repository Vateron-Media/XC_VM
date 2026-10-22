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
<div class="border rounded mb-2">
    <div class="d-flex flex-wrap align-items-center gap-2 p-2 border-bottom">
        <div class="input-group" style="width:auto">
            <label class="input-group-text" for="order-step-<?= $rType; ?>">Step</label>
            <input type="number" class="form-control" id="order-step-<?= $rType; ?>" min="1" value="1" style="width:4.5rem">
            <button type="button" class="btn btn-outline-secondary btn-icon" data-type="<?= $rType; ?>" data-order-action="up" title="<?= $rTitle('move_up', 'Move up'); ?>"><i class="icon-base ti tabler-chevron-up"></i></button>
            <button type="button" class="btn btn-outline-secondary btn-icon" data-type="<?= $rType; ?>" data-order-action="down" title="<?= $rTitle('move_down', 'Move down'); ?>"><i class="icon-base ti tabler-chevron-down"></i></button>
        </div>
        <div class="btn-group" role="group">
            <button type="button" class="btn btn-outline-secondary btn-icon" data-type="<?= $rType; ?>" data-order-action="top" title="<?= $rTitle('move_to_top', 'Move to top'); ?>"><i class="icon-base ti tabler-arrow-bar-to-up"></i></button>
            <button type="button" class="btn btn-outline-secondary btn-icon" data-type="<?= $rType; ?>" data-order-action="bottom" title="<?= $rTitle('move_to_bottom', 'Move to bottom'); ?>"><i class="icon-base ti tabler-arrow-bar-to-down"></i></button>
        </div>
        <button type="button" class="btn btn-outline-secondary" data-type="<?= $rType; ?>" data-order-action="atoz"><i class="icon-base ti tabler-sort-ascending-letters me-1"></i><?= $rTitle('a_to_z', 'A to Z'); ?></button>
        <span class="badge bg-label-primary ms-auto" id="order-count-<?= $rType; ?>"></span>
    </div>
    <ol class="list-group list-group-flush js-order-list" id="order-list-<?= $rType; ?>" data-type="<?= $rType; ?>" style="list-style:none;padding-left:0;max-height:60vh;overflow-y:auto;user-select:none">
        <?php foreach ($rItems as $rItem): ?>
            <li class="list-group-item list-group-item-action d-flex flex-shrink-0 align-items-center gap-2 py-2" data-id="<?= (int) $rItem['id']; ?>" draggable="true" style="cursor:pointer">
                <i class="icon-base ti tabler-grip-vertical text-body-secondary" style="cursor:grab"></i>
                <span class="js-order-pos text-body-secondary small text-end me-2" style="min-width:2.5rem;font-variant-numeric:tabular-nums"></span>
                <span class="flex-grow-1 text-truncate js-order-name"><?= htmlspecialchars((string) ($rItem['stream_display_name'] ?? $rItem['title'] ?? ''), ENT_QUOTES); ?></span>
            </li>
        <?php endforeach; ?>
    </ol>
</div>
<p class="text-body-secondary small mb-3"><i class="icon-base ti tabler-info-circle me-1"></i><?= $rTitle('order_listbox_help', 'Drag rows to re-order them. Ctrl/Cmd+click or Shift+click selects several rows to drag or move together; Up and Down move the selection by Step places. Save Changes stores the order.'); ?></p>

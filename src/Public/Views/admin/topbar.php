<?php

/**
 * Bootstrap 5 per-page action topbar. Server-side render of Topbar::items() for the
 * current page (single source shared with legacy topbar.php). Included once by
 * header.php above the page content, so every migrated page gets its
 * primary action + related-tools dropdown (+ clear-filters / refresh on table
 * pages) without per-view wiring.
 *
 * Standard button ids are preserved (clearFilters, refreshTable, btn-export-csv,
 * btn-export-json, btn-clear-logs) and wired generically in footer.php.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\Topbar;

$xmTopbarPage = AdminHelpers::getPageName();
$xmTopbarItems = Topbar::items($xmTopbarPage, [
    'rMobile' => $rMobile ?? false,
    'rID' => RequestManager::get('id'),
    'rSID' => RequestManager::get('sid'),
    'rMulti' => RequestManager::has('multi'),
]);

if (!empty($xmTopbarItems)):
    // Table/list pages also get clear-filters + refresh (mirrors legacy topbar).
    $xmTopbarTablePages = [
        'streams',
        'created_channels',
        'movies',
        'series',
        'users',
        'mags',
        'lines',
        'radios',
        'enigmas',
        'ondemand',
        'episodes',
        // Log / list tables: give them the same instant refresh + clear-filters.
        'client_logs',
        'credit_logs',
        'user_logs',
        'line_activity',
        'live_connections',
        'stream_errors',
        'login_logs',
        'mysql_syslog',
        'mag_events',
        'panel_logs',
        'asns',
        'backups',
    ];
    $xmTopbarTools = in_array($xmTopbarPage, $xmTopbarTablePages, true);

    // Render one item as a <button>/<a>. $tag = 'primary' | 'drop'.
    $xmTopbarBtn = static function (array $it, string $tag): string {
        $rIsLink = ($it['url'] !== null && $it['id'] === null && $it['attr'] === null);
        $rLabel = htmlspecialchars((string) $it['label'], ENT_QUOTES);
        if ($tag === 'primary') {
            if ($rIsLink) {
                return '<a href="' . htmlspecialchars((string) $it['url'], ENT_QUOTES) . '" class="btn btn-primary">' . $rLabel . '</a>';
            }
            return '<button type="button" class="btn btn-primary" ' . ($it['attr'] ?? '') . '>' . $rLabel . '</button>';
        }
        // Dropdown entry.
        if ($rIsLink) {
            return '<li><a class="dropdown-item" href="' . htmlspecialchars((string) $it['url'], ENT_QUOTES) . '">' . $rLabel . '</a></li>';
        }
        $rExtra = $it['attr'] ?? '';
        $rClass = 'dropdown-item';
        if ($it['id'] === 'btn-clear-logs') {
            $rClass .= ' text-danger';
            if (!empty($it['logType'])) {
                $rExtra .= ' data-log-type="' . htmlspecialchars((string) $it['logType'], ENT_QUOTES) . '"';
            }
        }
        return '<li><a class="' . $rClass . '" href="javascript:void(0);" ' . $rExtra . '>' . $rLabel . '</a></li>';
    };

    $xmPrimary = $xmTopbarItems[0];
    $xmRest = array_slice($xmTopbarItems, 1);
?>
    <div class="d-flex justify-content-end mb-4">
        <div class="btn-group">
            <?= $xmTopbarBtn($xmPrimary, 'primary'); ?>
            <?php if ($xmTopbarTools): ?>
                <button type="button" id="clearFilters" class="btn btn-label-warning" title="Clear Filters"><i class="icon-base ti tabler-filter-off"></i></button>
                <button type="button" id="refreshTable" class="btn btn-label-info" title="Refresh"><i class="icon-base ti tabler-refresh"></i></button>
            <?php endif; ?>
            <?php if (!empty($xmRest)): ?>
                <button type="button" class="btn btn-label-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <?php foreach ($xmRest as $xmItem): ?>
                        <?= $xmTopbarBtn($xmItem, 'drop'); ?>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
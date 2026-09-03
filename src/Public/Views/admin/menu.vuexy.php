<?php

/**
 * Bootstrap 5 vertical sidebar menu, driven by NavbarRegistry.
 *
 * Included by header.vuexy.php (which defines the shared _xc_nav_visible() /
 * _xc_nav_label() helpers). Renders the same registry tree the legacy top
 * navigation uses, so core + module nav entries appear automatically.
 *
 * The tree is produced by XcBootstrap 5MenuBuilder in a single pass: each node
 * renders its own subtree first and reports back whether it (or a descendant)
 * is the current page, so ancestors can mark themselves `open active` without
 * re-walking the tree.
 */

use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Util\AdminHelpers;

if (count(get_included_files()) == 1) {
    exit();
}

/**
 * Map a registry nav icon to a bundled Bootstrap 5 (iconify Tabler) icon so every
 * sidebar glyph renders in one system: `ti tabler-*`. Legacy FontAwesome
 * (`fas fa-*`) / Feather (`fe-*`) classes from CoreNavbarProvider and modules
 * are translated here; the builder wraps the result in `menu-icon icon-base`.
 */
if (!function_exists('_xc_vuexy_icon')) {
    function _xc_vuexy_icon(string $icon): string {
        $icon = trim($icon);
        if ($icon === '') return 'ti tabler-circle';
        if (str_starts_with($icon, 'ti ')) return $icon; // already Tabler

        // Exact matches for the core top-level nav icons.
        static $map = [
            'fe-activity'    => 'ti tabler-smart-home',
            'fas fa-server'  => 'ti tabler-server',
            'fas fa-desktop' => 'ti tabler-device-desktop',
            'fas fa-play'    => 'ti tabler-player-play',
            'fas fa-film'    => 'ti tabler-movie',
            'fas fa-sitemap' => 'ti tabler-sitemap',
            'fas fa-spa'     => 'ti tabler-flower',
            'fas fa-wrench'  => 'ti tabler-tool',
            'fas fa-users'   => 'ti tabler-users',
            'fas fa-clipboard-list' => 'ti tabler-clipboard-list',
        ];
        if (isset($map[$icon])) return $map[$icon];

        // Heuristic fallback for other fa-*/fe-* icons modules may register.
        static $needles = [
            'film'     => 'ti tabler-movie',
            'tv'       => 'ti tabler-device-tv',
            'wrench'   => 'ti tabler-tool',
            'cog'      => 'ti tabler-settings',
            'gear'     => 'ti tabler-settings',
            'list'     => 'ti tabler-list',
            'chart'    => 'ti tabler-chart-bar',
            'folder'   => 'ti tabler-folder',
            'database' => 'ti tabler-database',
            'server'   => 'ti tabler-server',
            'user'     => 'ti tabler-users',
        ];
        foreach ($needles as $needle => $tabler) {
            if (str_contains($icon, $needle)) return $tabler;
        }
        return 'ti tabler-point';
    }
}

/**
 * Builds the Bootstrap 5 vertical menu markup from the NavbarRegistry tree.
 *
 * Every render*() returns [html, isActive] so the active leaf and its ancestor
 * groups are resolved in one traversal. Only depth-0 items carry an icon, and
 * top-level dividers that carry a label become Bootstrap 5 section headers.
 */
if (!class_exists('XcBootstrap 5MenuBuilder')) {
    final class XcBootstrap 5MenuBuilder {
        public function __construct(
            private bool $mobile,
            private array $settings,
            private string $language,
            private string $page,
        ) {
        }

        /**
         * Full contents of <ul class="menu-inner"> for the registry top level.
         *
         * $sections is an ordered list of ['title' => string, 'keys' => string[]]
         * groups; each renders a Bootstrap 5 `menu-header` (when titled) followed by
         * the listed top-level items in the given order. Any visible top-level
         * item not placed in a section — e.g. one a module registered — falls
         * into a trailing catch-all group, so nothing silently disappears.
         */
        public function renderInner(array $sections = []): string {
            $top = [];
            foreach (NavbarRegistry::getTopLevel() as $item) {
                if (_xc_nav_visible($item, $this->mobile, $this->settings)) {
                    $top[$item->key] = $item;
                }
            }

            $html = '';
            $used = [];
            foreach ($sections as $section) {
                $body = '';
                foreach ($section['keys'] ?? [] as $key) {
                    if (!isset($top[$key])) {
                        continue;
                    }
                    [$node] = $this->renderNode($top[$key], 0);
                    $body .= $node;
                    $used[$key] = true;
                }
                if ($body === '') {
                    continue; // no visible items in this section
                }
                $html .= $this->sectionHeader($section['title'] ?? '') . $body;
            }

            // Trailing catch-all for top-level items no section claimed.
            $rest = '';
            foreach ($top as $key => $item) {
                if (isset($used[$key])) {
                    continue;
                }
                [$node] = $this->renderNode($item, 0);
                $rest .= $node;
            }
            if ($rest !== '') {
                $html .= ($sections ? $this->sectionHeader('More') : '') . $rest;
            }

            return $html;
        }

        /** A Bootstrap 5 sidebar section caption (empty title renders nothing). */
        private function sectionHeader(string $title): string {
            if ($title === '') {
                return '';
            }
            return '<li class="menu-header small"><span class="menu-header-text">'
                . htmlspecialchars($title, ENT_QUOTES) . '</span></li>';
        }

        /**
         * Render a single menu node.
         *
         * @return array{0:string,1:bool} [html, isActive]
         */
        private function renderNode(NavbarItem $item, int $depth): array {
            if ($item->divider) {
                $label = _xc_nav_label($item, $this->language);
                if ($depth === 0 && $label !== '') {
                    return ['<li class="menu-header small"><span class="menu-header-text">' . $label . '</span></li>', false];
                }
                return ['', false]; // inline dividers are not a vertical-menu construct
            }

            $hasKids = NavbarRegistry::hasChildren($item->key) && !($item->noMobileSubmenu && $this->mobile);

            $childHtml = '';
            $childActive = false;
            if ($hasKids) {
                [$childHtml, $childActive] = $this->renderList($item, $depth + 1);
                if ($childHtml === '') {
                    $hasKids = false; // every child was filtered out
                }
            }

            // Match the current page by the item's URL basename OR its registry
            // key (the landing item's url is 'index' while its route/key is
            // 'dashboard', so a URL-only check would never light it up).
            $selfActive = ($item->url !== '#' && $item->url !== '' && basename($item->url) === $this->page)
                || $item->key === $this->page;
            $active     = $selfActive || ($hasKids && $childActive);

            $liClass = 'menu-item' . ($active ? ' active' : '') . ($hasKids && $active ? ' open' : '');
            $href    = htmlspecialchars($hasKids ? 'javascript:void(0);' : $item->url, ENT_QUOTES);
            $icon    = $depth === 0
                ? '<i class="menu-icon icon-base ' . _xc_vuexy_icon((string) $item->icon) . '"></i>'
                : '';

            $html  = '<li class="' . $liClass . '">';
            $html .= '<a href="' . $href . '" class="menu-link' . ($hasKids ? ' menu-toggle' : '') . '">';
            $html .= $icon . '<div>' . _xc_nav_label($item, $this->language) . '</div>';
            $html .= '</a>' . $childHtml . '</li>';

            return [$html, $active];
        }

        /**
         * Render the <ul class="menu-sub"> for a parent's visible children.
         *
         * @return array{0:string,1:bool} [html, anyChildActive]
         */
        private function renderList(NavbarItem $parent, int $depth): array {
            $items = '';
            $anyActive = false;
            foreach (NavbarRegistry::getChildren($parent->key) as $child) {
                if (!_xc_nav_visible($child, $this->mobile, $this->settings)) {
                    continue;
                }
                [$node, $active] = $this->renderNode($child, $depth);
                $items .= $node;
                $anyActive = $anyActive || $active;
            }
            return $items === '' ? ['', false] : ['<ul class="menu-sub">' . $items . '</ul>', $anyActive];
        }
    }
}

$_menu = new XcBootstrap 5MenuBuilder($rMobile, $rSettings, (string) $language, AdminHelpers::getPageName());

/**
 * Sidebar sections (view-level presentation, Bootstrap 5 `menu-header` captions).
 * Keys are NavbarRegistry top-level keys — reorder/regroup/rename freely.
 * Any visible top-level item not listed here (e.g. one a module registers)
 * automatically lands in a trailing "More" section, so nothing is lost.
 */
$_menuSections = [
    ['title' => '',               'keys' => ['dashboard']],
    ['title' => 'Catalog',        'keys' => ['users', 'content', 'vod', 'distribution']],
    ['title' => 'Infrastructure', 'keys' => ['servers', 'logs', 'management']],
];
?>
<!-- Vertical menu -->
<aside id="layout-menu" class="layout-menu menu-vertical menu">
    <div class="app-brand demo">
        <a href="dashboard" class="app-brand-link">
            <span class="app-brand-logo demo">
                <img src="assets/old/images/logo-topbar.png" alt="<?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?>" height="24">
            </span>
            <span class="app-brand-text demo menu-text fw-bold ms-3"><?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?></span>
        </a>
        <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
            <i class="icon-base ti menu-toggle-icon d-none d-xl-block"></i>
            <i class="icon-base ti tabler-x d-block d-xl-none"></i>
        </a>
    </div>

    <div class="menu-inner-shadow"></div>

    <ul class="menu-inner py-1">
        <?= $_menu->renderInner($_menuSections); ?>
    </ul>
</aside>
<!-- / Vertical menu -->
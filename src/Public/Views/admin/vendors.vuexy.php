<?php

/**
 * Vuexy vendor bundle manifest + emit helpers.
 *
 * Maps a logical bundle name to the Vuexy (Bootstrap 5) plugin CSS/JS that a
 * page needs, and emits the <link>/<script> tags. Only bundles whose libraries
 * are vendored under assets/new/vendor/libs/ are listed; add more here as the
 * matching lib is copied in.
 *
 * Usage:
 *   - A default common bundle (tables + selects + pickers + alerts) loads on
 *     every Vuexy page. A controller can request extras BEFORE render():
 *         $GLOBALS['xmVuexyVendors'] = ['apexcharts'];
 *   - header.vuexy.php calls xc_vuexy_vendor_css(); footer.vuexy.php calls
 *     xc_vuexy_vendor_js(), both via xc_vuexy_vendors_wanted().
 *
 * Init code is intentionally NOT wired here — pages initialise their own plugins.
 */

if (!function_exists('xc_vuexy_vendor_manifest')) {
    function xc_vuexy_vendor_manifest(): array {
        $b = 'assets/new/vendor/libs/';
        return [
            'datatables' => [
                'css' => [
                    $b . 'datatables-bs5/datatables.bootstrap5.css',
                    $b . 'datatables-responsive-bs5/responsive.bootstrap5.css',
                ],
                'js' => [
                    $b . 'datatables-bs5/datatables-bootstrap5.js',
                ],
            ],
            'select2' => [
                'css' => [$b . 'select2/select2.css'],
                'js'  => [$b . 'select2/select2.js'],
            ],
            'flatpickr' => [
                'css' => [$b . 'flatpickr/flatpickr.css'],
                'js'  => [$b . 'flatpickr/flatpickr.js'],
            ],
            'sweetalert2' => [
                'css' => [$b . 'sweetalert2/sweetalert2.css'],
                'js'  => [$b . 'sweetalert2/sweetalert2.js'],
            ],
            'apexcharts' => [
                'css' => [$b . 'apex-charts/apex-charts.css'],
                'js'  => [$b . 'apex-charts/apexcharts.js'],
            ],
        ];
    }
}

if (!function_exists('xc_vuexy_vendors_wanted')) {
    /**
     * Bundles to load for the current page: the always-on common set plus any
     * per-page extras a controller declared via $GLOBALS['xmVuexyVendors'].
     * Unknown names are dropped.
     */
    function xc_vuexy_vendors_wanted(): array {
        $default = ['datatables', 'select2', 'flatpickr', 'sweetalert2'];
        $extra   = (array) ($GLOBALS['xmVuexyVendors'] ?? []);
        $known   = array_keys(xc_vuexy_vendor_manifest());
        return array_values(array_intersect($known, array_unique(array_merge($default, $extra))));
    }
}

if (!function_exists('xc_vuexy_vendor_css')) {
    function xc_vuexy_vendor_css(array $bundles): void {
        $manifest = xc_vuexy_vendor_manifest();
        $seen = [];
        foreach ($bundles as $name) {
            foreach ($manifest[$name]['css'] ?? [] as $href) {
                if (isset($seen[$href])) continue;
                $seen[$href] = true;
                echo '    <link rel="stylesheet" href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . "\n";
            }
        }
    }
}

if (!function_exists('xc_vuexy_vendor_js')) {
    function xc_vuexy_vendor_js(array $bundles): void {
        $manifest = xc_vuexy_vendor_manifest();
        $seen = [];
        foreach ($bundles as $name) {
            foreach ($manifest[$name]['js'] ?? [] as $src) {
                if (isset($seen[$src])) continue;
                $seen[$src] = true;
                echo '    <script src="' . htmlspecialchars($src, ENT_QUOTES) . '"></script>' . "\n";
            }
        }
    }
}

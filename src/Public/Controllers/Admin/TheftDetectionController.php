<?php

/**
 * TheftDetectionController — theft detection controller
 *
 * @renders Views/admin/theft_detection.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TheftDetectionController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('VOD Theft Detection');

        $rRange = intval($this->input('range')) ?: 0;
        $cacheFile = CACHE_TMP_PATH . 'theft_detection';
        $rTheftDetection = file_exists($cacheFile)
            ? (igbinary_unserialize(file_get_contents($cacheFile)) ?: [])
            : [];

        $this->render('theft_detection', [
            'theftDetection' => $rTheftDetection,
            'range'          => $rRange,
        ]);
    }
}

<?php

class TheftDetectionController extends BaseAdminController
{
    public function index()
    {
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

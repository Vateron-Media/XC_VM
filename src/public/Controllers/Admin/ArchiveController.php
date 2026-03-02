<?php
/**
 * ArchiveController — TV Archive / Recordings (Phase 6.3 — Group N).
 */
class ArchiveController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rRecordings = null;

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rStream = StreamRepository::getById(CoreUtilities::$rRequest['id']);

            if (!$rStream || $rStream['type'] != 1 || $rStream['tv_archive_duration'] == 0 || $rStream['tv_archive_server_id'] == 0) {
                $this->redirect('archive');
                return;
            }

            $rArchive = getArchive($rStream['id']);
        } else {
            $rRecordings = WatchService::getRecordings();
        }

        $rTitle = (!is_null($rRecordings) ? 'Recordings' : 'TV Archive');
        $this->setTitle($rTitle);
        $this->render('archive', compact('rRecordings', 'rStream', 'rArchive'));
    }
}

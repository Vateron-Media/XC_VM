<?php
/**
 * MovieController — редактирование/добавление фильма (Phase 6.3 — Group B).
 */
class MovieController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db, $rServers;

        $rCategories = getCategories('movie');
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rMovie = StreamRepository::getById(CoreUtilities::$rRequest['id']);
            if (!$rMovie || $rMovie['type'] != 2) {
                $this->redirect('movies');
                return;
            }
        }

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];
        $activeStreamingServers = [];

        if (isset($rMovie)) {
            $rMovie['properties'] = json_decode($rMovie['movie_properties'], true);
            $rStreamSys = StreamRepository::getSystemRows(CoreUtilities::$rRequest['id']);

            $streamSourceJson = $rMovie['stream_source'] ?? '';
            $rMovieSource = json_decode($streamSourceJson, true);
            if (!is_array($rMovieSource)) {
                $rMovieSource = [''];
            }
            $rSource = $rMovieSource[0] ?? '';
            if (str_starts_with($rSource, 's:')) {
                $parts = explode(':', $rSource, 3);
                $rPathSources = (count($parts) >= 3) ? urldecode($parts[2]) : '';
            } else {
                $rPathSources = $rSource;
            }

            foreach ($rServers as $rServer) {
                if ($rServer['direct_source'] == 0 && $rServer['stream_status'] == 1) {
                    $activeStreamingServers[] = intval($rServer['id']);
                }
                if (isset($rStreamSys[intval($rServer['id'])])) {
                    $rParent = 'source';
                } else {
                    $rParent = 'offline';
                }
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
            }
        }

        $this->setTitle('Movie');
        $this->render('movie', compact(
            'rCategories', 'rTranscodeProfiles', 'rMovie', 'rServerTree',
            'activeStreamingServers', 'rStreamSys', 'rMovieSource', 'rSource', 'rPathSources'
        ));
    }
}

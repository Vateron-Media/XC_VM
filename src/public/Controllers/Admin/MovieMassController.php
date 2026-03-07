<?php
/**
 * MovieMassController — массовое редактирование фильмов.
 */
class MovieMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('movie');

        if (isset(RequestManager::getAll()['submit_stream'])) {
            $rReturn = MovieService::massEdit(RequestManager::getAll());
            $_STATUS = $rReturn['status'];
            $GLOBALS['_STATUS'] = $_STATUS;

            if ($_STATUS == 0) {
                header('Location: ./movies_mass?status=0');
                exit();
            }
        }

        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('Mass Edit Movies');
        $this->render('movie_mass', compact('rCategories', 'rTranscodeProfiles', 'rServerTree'));
    }
}

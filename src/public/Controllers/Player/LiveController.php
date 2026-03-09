<?php

class LiveController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (!in_array(1, $rUserInfo['allowed_outputs']) || SettingsManager::getAll()['disable_hls']) {
            header('Location: index');
            exit;
        }

        $rCategories = getOrderedCategories($rUserInfo['category_ids'], 'live');
        $rFilterArray = array('all' => 'All Channels', 'timeshift' => 'Timeshift Only', 'epg' => 'Has EPG Only');
        $rFilterBy = (isset($rFilterArray[RequestManager::getAll()['filter'] ?? '']) ? RequestManager::getAll()['filter'] : 'all');
        $rPicking = array('filter' => $rFilterBy);
        $rSortArray = array('number' => 'Default', 'name' => 'Name A-Z', 'added' => 'Date Added');
        $rSortBy = (isset($rSortArray[RequestManager::getAll()['sort'] ?? '']) ? RequestManager::getAll()['sort'] : 'number');
        $rCategoryID = (intval(RequestManager::getAll()['category'] ?? 0) ?: $rCategories[0]['id']);
        $rSearchBy = (RequestManager::getAll()['search'] ?? null);
        $rStreamIDs = array();
        $rStreams = getUserStreams($rUserInfo, array('live', 'created_live'), $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, null, null, true);

        foreach ($rStreams as $rStream) {
            $rStreamIDs[] = $rStream['id'];
        }

        $db->query('SELECT `movie_properties` FROM `streams` WHERE `movie_properties` IS NOT NULL AND `type` = 2 ORDER BY RAND() LIMIT 5;');
        $rCover = '';

        foreach ($db->get_rows() as $rStream) {
            $rProperties = json_decode($rStream['movie_properties'], true);

            if (!empty($rProperties['backdrop_path'][0])) {
                $rCover = ImageUtils::validateURL($rProperties['backdrop_path'][0]);
                break;
            }
        }

        $GLOBALS['_TITLE'] = 'Live TV';
        $GLOBALS['rStreamIDs'] = $rStreamIDs;

        $this->render('live', [
            'rCategories' => $rCategories,
            'rFilterArray' => $rFilterArray,
            'rFilterBy' => $rFilterBy,
            'rSortArray' => $rSortArray,
            'rSortBy' => $rSortBy,
            'rCategoryID' => $rCategoryID,
            'rSearchBy' => $rSearchBy,
            'rStreamIDs' => $rStreamIDs,
            'rCover' => $rCover,
        ]);
    }
}

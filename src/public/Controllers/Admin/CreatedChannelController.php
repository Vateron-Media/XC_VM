<?php
/**
 * CreatedChannelController — редактирование/добавление канала (Phase 6.3 — Group A).
 */
class CreatedChannelController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db, $rServers;

        $rCategories = getCategories('live');
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        if (!isset(CoreUtilities::$rRequest['id'])) {
            $rChannel = null;
        } else {
            $rChannel = StreamRepository::getById(CoreUtilities::$rRequest['id']);

            if (!$rChannel || $rChannel['type'] != 3) {
                goHome();
            }
        }

        $rOnDemand = [];
        $rProperties = null;
        $rChannelSys = null;
        $rServerTree = [
            [
                'id' => 'source',
                'parent' => '#',
                'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
                'icon' => 'mdi mdi-play',
                'state' => ['opened' => true]
            ],
            [
                'id' => 'offline',
                'parent' => '#',
                'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
                'icon' => 'mdi mdi-stop',
                'state' => ['opened' => true]
            ]
        ];

        if (isset($rChannel)) {
            $rProperties = json_decode($rChannel['movie_properties'], true);

            if (!$rProperties) {
                $rProperties = ['type' => $rChannel['series_no'] > 0 ? 0 : 1];
            }

            $rChannelSys = StreamRepository::getSystemRows(CoreUtilities::$rRequest['id']);

            foreach ($rServers as $rServer) {
                if (isset($rChannelSys[intval($rServer['id'])])) {
                    $rParent = $rChannelSys[intval($rServer['id'])]['parent_id'] != 0
                        ? intval($rChannelSys[intval($rServer['id'])]['parent_id'])
                        : (!$rChannelSys[intval($rServer['id'])]['on_demand'] ? 'source' : null);
                } else {
                    $rParent = 'offline';
                }

                if ($rParent !== null) {
                    $rServerTree[] = [
                        'id' => $rServer['id'],
                        'parent' => $rParent,
                        'text' => $rServer['server_name'],
                        'icon' => 'mdi mdi-server-network',
                        'state' => ['opened' => true]
                    ];
                }
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = [
                    'id' => $rServer['id'],
                    'parent' => 'offline',
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => ['opened' => true]
                ];
            }
        }

        $this->setTitle('Created Channel');
        $this->render('created_channel', compact(
            'rCategories', 'rTranscodeProfiles', 'rChannel', 'rOnDemand',
            'rServerTree', 'rProperties', 'rChannelSys'
        ));
    }
}

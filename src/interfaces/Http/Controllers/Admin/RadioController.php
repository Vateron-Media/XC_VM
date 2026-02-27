<?php
/**
 * RadioController — редактирование/добавление радиостанции (Phase 6.3 — Group A).
 */
class RadioController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db, $rServers;

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rStation = StreamRepository::getById(CoreUtilities::$rRequest['id']);
            if (!$rStation || $rStation['type'] != 4) {
                goHome();
            }
        }

        $rStation = null;
        $rStationOptions = null;
        $rStationSys = null;
        $rOnDemand = array();
        $rStationArguments = StreamConfigRepository::getStreamArguments();
        $rServerTree = array(
            array(
                'id' => 'source',
                'parent' => '#',
                'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
                'icon' => 'mdi mdi-play',
                'state' => array('opened' => true)
            ),
            array(
                'id' => 'offline',
                'parent' => '#',
                'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
                'icon' => 'mdi mdi-stop',
                'state' => array('opened' => true)
            )
        );

        if (isset($rStation)) {
            $rStationOptions = StreamRepository::getOptions(CoreUtilities::$rRequest['id']);
            $rStationSys = StreamRepository::getSystemRows(CoreUtilities::$rRequest['id']);

            foreach ($rServers as $rServer) {
                if (isset($rStationSys[intval($rServer['id'])])) {
                    $rParent = ($rStationSys[intval($rServer['id'])]['parent_id'] != 0) ? intval($rStationSys[intval($rServer['id'])]['parent_id']) : 'source';
                    if ($rStationSys[intval($rServer['id'])]['on_demand']) {
                        $rOnDemand[] = intval($rServer['id']);
                    }
                } else {
                    $rParent = 'offline';
                }

                $rServerTree[] = array(
                    'id' => $rServer['id'],
                    'parent' => $rParent,
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => array('opened' => true)
                );
            }
        } else {
            foreach ($rServers as $rServer) {
                $rServerTree[] = array(
                    'id' => $rServer['id'],
                    'parent' => 'offline',
                    'text' => $rServer['server_name'],
                    'icon' => 'mdi mdi-server-network',
                    'state' => array('opened' => true)
                );
            }
        }

        $this->setTitle('Radio Stations');
        $this->render('radio', compact(
            'rStation', 'rOnDemand', 'rStationArguments', 'rServerTree',
            'rStationOptions', 'rStationSys'
        ));
    }
}

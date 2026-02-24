<?php
/**
 * RadioMassController — массовое редактирование радиостанций (Phase 6.3 — Group A).
 */
class RadioMassController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rCategories = getCategories('radio');
        $rServerTree = array(
            array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)),
            array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true))
        );

        foreach ($rServers as $rServer) {
            $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
        }

        $this->setTitle('Mass Edit Stations');
        $this->render('radio_mass', compact('rCategories', 'rServerTree'));
    }
}

<?php

/**
 * StreamMassController — массовое редактирование стримов.
 *
 * @renders Views/admin/stream_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('live');
        $rStreamArguments = StreamConfigRepository::getStreamArguments();
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>", 'icon' => 'mdi mdi-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'mdi mdi-server-network', 'state' => ['opened' => true]];
        }

        $this->setTitle('Mass Edit Streams');
        $this->render('stream_mass', compact('rCategories', 'rStreamArguments', 'rTranscodeProfiles', 'rServerTree'));
    }
}

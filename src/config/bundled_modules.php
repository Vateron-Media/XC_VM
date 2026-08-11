<?php

/**
 * Standard module set — the modules the panel provisions by default.
 *
 * Foundation for moving modules into separate repositories (see the
 * module-update-source roadmap). Each entry is keyed by the module's PERMANENT
 * `hash_id` so identity survives a rename or a move to another repo. Today every
 * module ships `bundled` (its files are in the panel archive); when a module is
 * extracted into its own repo, flip its entry to a fetchable source and the panel
 * pulls it during `syncBundledModules()` / `cron:module_updates`.
 *
 * Entry shape:
 *   [
 *     'hash_id'    => '…',                 // required — the module's stable identity
 *     'name'       => 'watch',             // expected directory/name (best-effort)
 *     'source'     => 'bundled|git|url|platform',
 *     'repository' => 'https://github.com/Vateron-Media/xc_vm-module-watch', // git
 *     'channel'    => 'stable',            // git/url
 *     'slug'       => 'watch',             // platform (defaults to name)
 *     'url'        => 'https://…/version.json', // url
 *   ]
 *
 * `bundled` entries need no source fields — their files come with the panel and
 * are installed on-disk by syncBundledModules().
 *
 * @package XC_VM_Config
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

return [
    // ministra is no longer a module — the Stalker portal moved into core at
    // src/Ministra/ (served via /home/xc_vm/Ministra). No entry needed here.
    [
        'hash_id'    => '2541abe329f151202d7a3c4ecfb17db4',
        'name'       => 'watch',
        'source'     => 'git',
        'repository' => 'https://github.com/Vateron-Media/Module_Watch',
        'channel'    => 'stable',
    ],
    [
        'hash_id'    => '20cd9576a466f5c1d60cc32c971a571b',
        'name'       => 'plex',
        'source'     => 'git',
        'repository' => 'https://github.com/Vateron-Media/Module_Plex',
        'channel'    => 'stable',
    ],
];

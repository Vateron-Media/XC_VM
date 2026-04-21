<?php

// Third-party library bootstrap (Gemorroj/M3uParser) without Composer autoload.

require_once __DIR__ . '/src/Exception.php';
require_once __DIR__ . '/src/TagAttributesTrait.php';
require_once __DIR__ . '/src/Tag/ExtTagInterface.php';
require_once __DIR__ . '/src/Tag/ExtInf.php';
require_once __DIR__ . '/src/Tag/ExtTv.php';
require_once __DIR__ . '/src/Tag/ExtLogo.php';
require_once __DIR__ . '/src/Tag/ExtVlcOpt.php';
require_once __DIR__ . '/src/Tag/ExtGrp.php';
require_once __DIR__ . '/src/Tag/Playlist.php';
require_once __DIR__ . '/src/Tag/ExtTitle.php';
require_once __DIR__ . '/src/Tag/ExtAlbumArtUrl.php';
require_once __DIR__ . '/src/Tag/ExtGenre.php';
require_once __DIR__ . '/src/Tag/ExtArt.php';
require_once __DIR__ . '/src/Tag/ExtAlb.php';
require_once __DIR__ . '/src/Tag/ExtImg.php';
require_once __DIR__ . '/src/TagsManagerTrait.php';
require_once __DIR__ . '/src/M3uData.php';
require_once __DIR__ . '/src/M3uEntry.php';
require_once __DIR__ . '/src/M3uParser.php';

# Parser/Generator m3u playlists

[![License](https://poser.pugx.org/gemorroj/m3u-parser/license)](https://packagist.org/packages/gemorroj/m3u-parser)
[![Latest Stable Version](https://poser.pugx.org/gemorroj/m3u-parser/v/stable)](https://packagist.org/packages/gemorroj/m3u-parser)
[![Continuous Integration](https://github.com/Gemorroj/M3uParser/workflows/Continuous%20Integration/badge.svg)](https://github.com/Gemorroj/M3uParser/actions?query=workflow%3A%22Continuous+Integration%22)


### Requirements:

- PHP >= 8.0.2


### Installation:
```bash
composer require gemorroj/m3u-parser
```


### Example parser:

```php
<?php
declare(strict_types=1);

use M3uParser\M3uParser;

$m3uParser = new M3uParser();
$m3uParser->addDefaultTags();
$data = $m3uParser->parseFile('path_to_file.m3u');
// or $data = $m3uParser->parse('playlist content');

print_r($data->getAttributes());
/*
Array
(
    [url-tvg] => http://www.teleguide.info/download/new3/jtv.zip
    [m3uautoload] => 1
    [deinterlace] => 8
    [cache] => 500
)
*/

/** @var \M3uParser\M3uEntry $entry */
foreach ($data as $entry) {
    print_r($entry);
    /*
        M3uParser\M3uEntry Object
        (
            [lineDelimiter:protected] =>

            [extTags:M3uParser\M3uEntry:private] => Array
                (
                    [0] => M3uParser\Tag\ExtInf Object
                        (
                            [title:M3uParser\Tag\ExtInf:private] => TV SLO 1 HD
                            [duration:M3uParser\Tag\ExtInf:private] => 1
                            [attributes:M3uParser\Tag\ExtInf:private] => Array
                                (
                                )

                        )

                    [1] => M3uParser\Tag\ExtTv Object
                        (
                            [tags:M3uParser\Tag\ExtTv:private] => Array
                                (
                                    [0] => Slovenski
                                    [1] => HD
                                )

                            [language:M3uParser\Tag\ExtTv:private] => slv
                            [xmlTvId:M3uParser\Tag\ExtTv:private] => SLO1HD
                            [iconUrl:M3uParser\Tag\ExtTv:private] =>
                        )

                )

            [path:M3uParser\M3uEntry:private] => rtp://@232.2.201.53:5003
        )
    */

    echo $entry->getPath() . "\n";

    foreach ($entry->getExtTags() as $extTag) {
        switch ($extTag) {
            case $extTag instanceof \M3uParser\Tag\ExtInf: // If EXTINF tag
                echo $extTag->getTitle() . "\n";
                echo $extTag->getDuration() . "\n";

                if ($extTag->hasAttribute('tvg-name')) { // If tvg-name attribute in EXTINF tag
                    echo $extTag->getAttribute('tvg-name') . "\n";
                }
                break;

            case $extTag instanceof \M3uParser\Tag\ExtTv: // If EXTTV tag
                echo $extTag->getXmlTvId() . "\n";
                echo $extTag->getIconUrl() . "\n";
                echo $extTag->getLanguage() . "\n";
                foreach ($extTag->getTags() as $tag) {
                    echo $tag . "\n";
                }
                break;

            case $extTag instanceof \M3uParser\Tag\ExtLogo: // If EXTLOGO tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtVlcOpt: // If EXTVLCOPT tag
                echo $extTag->getKey() . ':' . $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtGrp: // If EXTGRP tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\Playlist: // If PLAYLIST tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtTitle: // If EXTTITLE tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtAlbumArtUrl: // If EXTALBUMARTURL tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtGenre: // If EXTGENRE tag
                echo $extTag->getValue() . "\n";
                break;
                
            case $extTag instanceof \M3uParser\Tag\ExtArt: // If EXTART tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtAlb: // If EXTALB tag
                echo $extTag->getValue() . "\n";
                break;

            case $extTag instanceof \M3uParser\Tag\ExtImg: // If EXTIMG tag
                echo $extTag->getValue() . "\n";
                break;
        }
    }
}
```

### Example generator:

```php
<?php
declare(strict_types=1);

use M3uParser\M3uData;
use M3uParser\M3uEntry;
use M3uParser\Tag\ExtInf;
use M3uParser\Tag\ExtTv;
use M3uParser\Tag\ExtLogo;
use M3uParser\Tag\ExtVlcOpt;
use M3uParser\Tag\ExtGrp;
use M3uParser\Tag\Playlist;
use M3uParser\Tag\ExtTitle;
use M3uParser\Tag\ExtAlbumArtUrl;
use M3uParser\Tag\ExtGenre;
use M3uParser\Tag\ExtArt;
use M3uParser\Tag\ExtAlb;
use M3uParser\Tag\ExtImg;

$entry = new M3uEntry();
$entry->setPath('test-path');
$entry->addExtTag(
    (new ExtInf())
        ->setDuration(123)
        ->setTitle('extinf-title')
        ->setAttribute('test-attr', 'test-attrname')
);
$entry->addExtTag(
    (new ExtTv())
        ->setIconUrl('https://example.org/icon.png')
        ->setLanguage('ru')
        ->setXmlTvId('xml-tv-id')
        ->setTags(['hd', 'sd'])
);
$entry->addExtTag(
    (new ExtLogo())
        ->setValue('https://example.org/logo.png')
);
$entry->addExtTag(
    (new ExtVlcOpt())
        ->setKey('http-user-agent')
        ->setValue('M2uParser')
);
$entry->addExtTag(
    (new ExtGrp())
        ->setValue('Rock')
);
$entry->addExtTag(
    (new Playlist())
        ->setValue('My favorite playlist')
);
$entry->addExtTag(
    (new ExtTitle())
        ->setValue('title')
);
$entry->addExtTag(
    (new ExtAlbumArtUrl())
        ->setValue('https://store.example.com/download/A32X5yz-1.jpg')
);
$entry->addExtTag(
    (new ExtGenre())
        ->setValue('Rock')
);
$entry->addExtTag(
    (new ExtArt())
        ->setValue('some artist')
);
$entry->addExtTag(
    (new ExtAlb())
        ->setValue('some album')
);
$entry->addExtTag(
    (new ExtImg())
        ->setValue('https://example.org/logo.png')
);

$data = new M3uData();
$data->setAttribute('test-name', 'test-value');
$data->append($entry);

echo $data;
/*
#EXTM3U test-name="test-value"
#EXTINF:123 test-attr="test-attrname", extinf-title
#EXTTV:hd,sd;ru;xml-tv-id;https://example.org/icon.png
#EXTLOGO:https://example.org/logo.png
#EXTVLCOPT:http-user-agent=M2uParser
#EXTGRP:Rock
#PLAYLIST:My favorite playlist
#EXTTITLE:title
#EXTALBUMARTURL:https://store.example.com/download/A32X5yz-1.jpg
#EXTGENRE:Rock
#EXTART:some artist
#EXTALB:some album
#EXTIMG:https://example.org/logo.png
test-path
*/
```

### Example custom tag:
```
#EXTM3U
#EXTCUSTOMTAG:123
http://nullwave.barricade.lan:8000/club
```

implement `ExtTagInterface` interface
```php
<?php

use M3uParser\M3uParser;
use M3uParser\Tag\ExtTagInterface;

// create custom tag
class ExtCustomTag implements ExtTagInterface
{
    /**
     * @var string
     */
    private $data;

    /**
     * #EXTCUSTOMTAG:data
     */
    public function __construct(?string $lineStr = null)
    {
        if (null !== $lineStr) {
            $this->makeData($lineStr);
        }
    }

    protected function makeData(string $lineStr): void
    {
        /*
EXTCUSTOMTAG format:
#EXTCUSTOMTAG:data
example:
#EXTCUSTOMTAG:123
         */

        $data = \substr($lineStr, \strlen('#EXTCUSTOMTAG:'));

        $this->setData(\trim($data));
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function setData(string $data): self
    {
        $this->data = $data;
        return $this;
    }

    public function __toString(): string
    {
        return '#EXTCUSTOMTAG:' . $this->getData();
    }

    public static function isMatch(string $lineStr): bool
    {
        return 0 === \stripos($lineStr, '#EXTCUSTOMTAG:');
    }
}

// use custom tag
$m3uParser = new M3uParser();
$m3uParser->addTag(ExtCustomTag::class); // add custom tag
$data = $m3uParser->parseFile('path_to_file.m3u');

print_r($data);
/*
M3uParser\M3uData Object
(
    [attributes:M3uParser\M3uData:private] => Array
        (
        )

    [storage:ArrayIterator:private] => Array
        (
            [0] => M3uParser\M3uEntry Object
                (
                    [lineDelimiter:protected] =>

                    [extTags:M3uParser\M3uEntry:private] => Array
                        (
                            [0] => M3uParser\Tests\ExtCustomTag Object
                                (
                                    [data:M3uParser\Tests\ExtCustomTag:private] => 123
                                )

                        )
 
                    [path:M3uParser\M3uEntry:private] => http://nullwave.barricade.lan:8000/club
                )
 
        )

)
*/
 ```

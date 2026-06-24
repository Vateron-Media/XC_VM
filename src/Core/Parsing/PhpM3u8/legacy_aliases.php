<?php

$aliases = [
    'Config' => 'Chrisyue\\PhpM3u8\\Config',
    'AttributeListParser' => 'Chrisyue\\PhpM3u8\\Parser\\AttributeListParser',
    'DataBuilder' => 'Chrisyue\\PhpM3u8\\Parser\\DataBuilder',
    'DataBuildingException' => 'Chrisyue\\PhpM3u8\\Parser\\DataBuildingException',
    'Parser' => 'Chrisyue\\PhpM3u8\\Parser\\Parser',
    'Line' => 'Chrisyue\\PhpM3u8\\Line\\Line',
    'Lines' => 'Chrisyue\\PhpM3u8\\Line\\Lines',
    'AttributeListDumper' => 'Chrisyue\\PhpM3u8\\Dumper\\AttributeListDumper',
    'Dumper' => 'Chrisyue\\PhpM3u8\\Dumper\\Dumper',
    'DumpingException' => 'Chrisyue\\PhpM3u8\\Dumper\\DumpingException',
    'DefinitionException' => 'Chrisyue\\PhpM3u8\\Definition\\DefinitionException',
    'TagDefinition' => 'Chrisyue\\PhpM3u8\\Definition\\TagDefinition',
    'TagDefinitions' => 'Chrisyue\\PhpM3u8\\Definition\\TagDefinitions',
    'AttributeStringToArray' => 'Chrisyue\\PhpM3u8\\Data\\Transformer\\AttributeStringToArray',
    'Iso8601Transformer' => 'Chrisyue\\PhpM3u8\\Data\\Transformer\\Iso8601Transformer',
    'Resolution' => 'Chrisyue\\PhpM3u8\\Data\\Value\\Attribute\\Resolution',
    'Byterange' => 'Chrisyue\\PhpM3u8\\Data\\Value\\Byterange',
    'Inf' => 'Chrisyue\\PhpM3u8\\Data\\Value\\Tag\\Inf',
    'ParserFacade' => 'Chrisyue\\PhpM3u8\\Facade\\ParserFacade',
    'DumperFacade' => 'Chrisyue\\PhpM3u8\\Facade\\DumperFacade',
    'FileStream' => 'Chrisyue\\PhpM3u8\\Stream\\FileStream',
    'TextStream' => 'Chrisyue\\PhpM3u8\\Stream\\TextStream',
];

foreach ($aliases as $legacy => $modern) {
    if (!class_exists($legacy, false) && (class_exists($modern) || interface_exists($modern))) {
        class_alias($modern, $legacy);
    }
}

if (!interface_exists('M3UStreamInterface', false) && interface_exists('Chrisyue\\PhpM3u8\\Stream\\StreamInterface')) {
    class_alias('Chrisyue\\PhpM3u8\\Stream\\StreamInterface', 'M3UStreamInterface');
}

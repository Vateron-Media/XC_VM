<?php

$attributeListParser = new AttributeListParser(new Config(require __DIR__ . '/attributeValueParsers.php'), new AttributeStringToArray());

return array(
	'int' => 'intval',
	'bool' => null,
	'enum' => null,
	'attribute-list' => array($attributeListParser, 'parse'),
	'inf' => array('Inf', 'fromString'),
	'byterange' => array('Byterange', 'fromString'),
	'datetime' => array('Iso8601Transformer', 'fromString')
);

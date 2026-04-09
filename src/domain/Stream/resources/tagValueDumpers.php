<?php

$attributeListDumper = new AttributeListDumper(new Config(require __DIR__ . '/attributeValueDumpers.php'));

return array(
	'int' => 'strval',
	'bool' => null,
	'enum' => null,
	'attribute-list' => array($attributeListDumper, 'dump'),
	'inf' => 'strval',
	'byterange' => 'strval',
	'datetime' => array('Iso8601Transformer', 'toString')
);

<?php

return array(
	'decimal-integer' => 'intval',
	'hexadecimal-sequence' => 'strval',
	'decimal-floating-point' => 'floatval',
	'signed-decimal-floating-point' => 'floatval',
	'quoted-string' => 'function($value) {' . "\n\t" . 'return trim($value, \'"\');' . "\n" . '}',
	'enumerated-string' => 'strval',
	'decimal-resolution' => array('Resolution', 'fromString'),
	'datetime' => 'function($value) {' . "\n\t" . 'return Iso8601Transformer::fromString(trim($value, \'"\'));' . "\n" . '}',
	'byterange' => 'function($value) {' . "\n\t" . 'return Byterange::fromString(trim($value, \'"\'));' . "\n" . '}'
);

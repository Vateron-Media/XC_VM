<?php

return array('decimal-integer' => 'strval', 'hexadecimal-sequence' => 'strval', 'decimal-floating-point' => 'strval', 'signed-decimal-floating-point' => 'strval', 'quoted-string' => 'function($value) {' . "\n\t" . 'return sprintf(\'"%s"\', $value);' . "\n" . '}', 'enumerated-string' => 'strval', 'decimal-resolution' => 'strval', 'datetime' => 'function($value) {' . "\n\t" . 'return sprintf(\'"%s"\', Iso8601Transformer::toString($value));' . "\n" . '}', 'byterange' => 'function($value) {' . "\n\t" . 'return sprintf(\'"%s"\', $value);' . "\n" . '}');

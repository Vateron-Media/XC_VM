<?php

/**
 * gen-module-hashes.php — ensure every module.json has a stable, immutable hash_id.
 *
 * The hash_id is a module's permanent identity. It is generated ONCE, when the
 * module first lacks one, and NEVER changes afterwards — it must survive version
 * bumps and renames. It is therefore a RANDOM value (not derived from name or
 * version, which would change it). This script is idempotent: a module that
 * already has a non-empty hash_id is left completely untouched.
 *
 * Usage:
 *   php tools/gen-module-hashes.php        (or: make module-hashes)
 *
 * @package XC_VM_Tools
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

$rRoot  = dirname(__DIR__);
$rFiles = glob($rRoot . '/src/Modules/*/module.json') ?: [];

$rChanged = 0;

foreach ($rFiles as $rFile) {
    $rRaw  = (string) file_get_contents($rFile);
    $rData = json_decode($rRaw, true);

    if (!is_array($rData)) {
        fwrite(STDERR, '[SKIP] invalid JSON: ' . $rFile . "\n");
        continue;
    }

    if (isset($rData['hash_id']) && trim((string) $rData['hash_id']) !== '') {
        continue; // already has a permanent id — never regenerate
    }

    $rHash = bin2hex(random_bytes(16));

    // Insert "hash_id" right after the "name" line, preserving the file's existing
    // formatting (a full json_encode would reflow every array).
    $rIndent = '    ';
    if (preg_match('/^([ \t]*)"name"\s*:/m', $rRaw, $rMatch)) {
        $rIndent = $rMatch[1];
    }

    $rCount   = 0;
    $rUpdated = preg_replace(
        '/^([ \t]*"name"\s*:\s*"[^"]*"\s*,?)[ \t]*$/m',
        '$1' . "\n" . $rIndent . '"hash_id": "' . $rHash . '",',
        $rRaw,
        1,
        $rCount
    );

    // Fallback: if the surgical insert did not produce valid JSON, rewrite the
    // whole file via json_encode (reflows formatting but is always correct).
    if ($rUpdated === null || $rCount !== 1 || json_decode($rUpdated, true) === null) {
        $rNew = [];
        foreach ($rData as $rKey => $rVal) {
            $rNew[$rKey] = $rVal;
            if ($rKey === 'name') {
                $rNew['hash_id'] = $rHash;
            }
        }
        if (!isset($rNew['hash_id'])) {
            $rNew = ['hash_id' => $rHash] + $rData;
        }
        $rUpdated = json_encode($rNew, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    file_put_contents($rFile, $rUpdated);
    echo '[OK] ' . basename(dirname($rFile)) . ' -> ' . $rHash . "\n";
    $rChanged++;
}

echo $rChanged === 0
    ? "All modules already have a hash_id.\n"
    : "Generated {$rChanged} hash_id(s).\n";

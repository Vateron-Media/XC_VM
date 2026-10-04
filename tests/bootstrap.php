<?php

$projectRoot = dirname(__DIR__);
$srcRoot = $projectRoot . '/src';
$flatRoot = $projectRoot;

if (file_exists($srcRoot . '/vendor/autoload.php')) {
	$appRoot = $srcRoot;
} elseif (file_exists($flatRoot . '/vendor/autoload.php')) {
	$appRoot = $flatRoot;
} else {
	throw new RuntimeException('Unable to locate vendor/autoload.php in expected project paths.');
}

// Silence error_log() diagnostics emitted by exercised code paths (ModuleLoader
// dependency skips, GitHubReleases version warnings, …) so the test run stays clean.
// No test asserts on this output; production behaviour is unchanged.
ini_set('error_log', '/dev/null');

$tmpRoot = __DIR__ . '/.tmp';
$binRoot = $tmpRoot . '/bin';

if (!is_dir($tmpRoot)) {
	mkdir($tmpRoot, 0775, true);
}
if (!is_dir($binRoot)) {
	mkdir($binRoot, 0775, true);
}

$fakeBinary = $binRoot . '/fake_bin.sh';
if (!file_exists($fakeBinary)) {
	file_put_contents($fakeBinary, "#!/bin/sh\nexit 0\n");
	chmod($fakeBinary, 0755);
}

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', $appRoot . '/');
}

if (!defined('PHP_BIN')) {
	define('PHP_BIN', PHP_BINARY);
}
// Extra OpenSSL entropy/seed used by Encryption (real value in AppConfig.php).
// Any stable value works for tests that round-trip encrypt/decrypt.
if (!defined('OPENSSL_EXTRA')) {
	define('OPENSSL_EXTRA', 'test-openssl-extra');
}
// FfmpegPaths::binary() builds paths under BIN_PATH . 'ffmpeg_bin/<ver>/'. No such
// fixtures exist here, so lookups miss and the code falls back to FFMPEG_BIN_40.
if (!defined('BIN_PATH')) {
	define('BIN_PATH', $binRoot . '/');
}
if (!defined('VOD_PATH')) {
	define('VOD_PATH', $tmpRoot . '/vod/');
}
if (!is_dir(VOD_PATH)) {
	mkdir(VOD_PATH, 0775, true);
}
// VodItemImporter keeps its TMDb caches and per-series locks here.
if (!defined('WATCH_TMP_PATH')) {
	define('WATCH_TMP_PATH', $tmpRoot . '/watch/');
}
if (!is_dir(WATCH_TMP_PATH)) {
	mkdir(WATCH_TMP_PATH, 0775, true);
}

foreach (array('40', '71', '80') as $version) {
	$ffmpegConst = 'FFMPEG_BIN_' . $version;
	$ffprobeConst = 'FFPROBE_BIN_' . $version;
	if (!defined($ffmpegConst)) {
		define($ffmpegConst, $fakeBinary);
	}
	if (!defined($ffprobeConst)) {
		define($ffprobeConst, $fakeBinary);
	}
}

if (!isset($_FILES)) {
	$_FILES = array();
}

if (!function_exists('igbinary_serialize')) {
	function igbinary_serialize($value) {
		return serialize($value);
	}
}
if (!function_exists('igbinary_unserialize')) {
	function igbinary_unserialize($value) {
		return unserialize($value);
	}
}

require_once MAIN_HOME . 'vendor/autoload.php';
require_once __DIR__ . '/Unit/M3uParser/ExtCustomTag.php';

// Test support: in-memory SQLite harness for DB-touching repositories/services.
require_once __DIR__ . '/Support/TestDb.php';
require_once __DIR__ . '/Support/ClusterReference.php';
require_once __DIR__ . '/Support/FakeClusterCrypto.php';
require_once __DIR__ . '/Support/FakeSshFleet.php';
require_once __DIR__ . '/Support/BusServer.php';
require_once __DIR__ . '/Support/QueryLogDb.php';
require_once __DIR__ . '/Support/InstallSchema.php';
require_once __DIR__ . '/Support/ReplicaFixture.php';
require_once __DIR__ . '/Support/TestSourceDriver.php';
require_once __DIR__ . '/Support/AgentUser.php';
require_once __DIR__ . '/Support/NodeLeaseFixture.php';
require_once __DIR__ . '/Support/FakeTmdbClient.php';

// EventIngest's lane locks are real flock files. Without this they would land
// in the shared TMP_PATH or system temp dir, where suite runs from other
// checkouts contend on the same files: give each test process its own
// directory under tests/.tmp, removed when the process ends.
$ingestLockDir = $tmpRoot . '/cluster_ingest/' . getmypid() . '/';
\XcVm\Domain\Cluster\EventIngest::useLockDir($ingestLockDir);
register_shutdown_function(static function () use ($ingestLockDir): void {
	foreach (glob($ingestLockDir . '*') ?: array() as $lockFile) {
		@unlink($lockFile);
	}
	@rmdir($ingestLockDir);
});

// The node replica's ETag cache (ReplicaEtagCache) lives in TMP_PATH, which
// some tests define as a shared path, and many tests fix the cluster clock at
// the same instant, so a cached section would outlive the test that built it:
// off for the suite; a test that checks the caching gives it a directory.
\XcVm\Core\Cluster\ReplicaEtagCache::useDir(false);

// The settings-miss audit (SettingsAudit) counts reads on nodes in mode 1 or
// 2, and many tests point NodeFlows at such a file: off for the suite; a
// test that checks it gives it its own directories.
\XcVm\Core\Cluster\SettingsAudit::useDir(false);

// The connect audit (ConnectAudit) counts connects on nodes in mode 1 or 2,
// in STORAGE_PATH, which some tests define as a shared path: off for the
// suite; a test that checks it gives it its own directory.
\XcVm\Core\Cluster\ConnectAudit::useDir(false);

// The node's own store of its streams' runtime state (StreamRuntime) defaults
// to the deploy's config/cluster/runtime/, and every stream state write with
// STREAMS off lapses it (or with STREAMS on keeps into it): a per-run
// directory under tests/.tmp, removed when the process ends, so no test
// touches an install on this machine. A test that checks the store gives it
// its own directory and restores this one (StreamRuntime::dir()) after.
$runtimeDir = $tmpRoot . '/cluster_runtime/' . getmypid() . '/';
\XcVm\Core\Cluster\StreamRuntime::useDir($runtimeDir);
register_shutdown_function(static function () use ($runtimeDir): void {
	exec('rm -rf ' . escapeshellarg($runtimeDir));
});

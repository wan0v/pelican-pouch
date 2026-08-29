<?php

/**
 * Builds the distributable plugin zip.
 *
 *   php scripts/build-zip.php [version]
 *
 * Without an argument the version already present in plugin.json is used.
 *
 * Publishing a Pelican plugin needs exactly two things: `meta` removed from
 * plugin.json, and the plugin folder zipped. Everything else here is about not
 * shipping development files.
 *
 * Written in PHP rather than shell so it needs nothing beyond the runtime the
 * plugin already requires.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
chdir($root);

/**
 * Runtime only. Deliberately absent:
 *   agent/                 shipped as a container image, documented in the repo
 *   AGENTS.md              contributor notes
 *   plugin-development.md  a copy of the upstream Pelican docs, not ours to redistribute
 *   scripts/, .github/     build tooling
 *   update.json            release manifest, served from the repository
 */
const INCLUDE_PATHS = [
    'src',
    'config',
    'lang',
    'database',
    'routes',
    'resources',
    'plugin.json',
    'README.md',
    'LICENSE',
];

function fail(string $message): never
{
    fwrite(STDERR, "error: $message\n");
    exit(1);
}

function rmrf(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (!is_dir($path) || is_link($path)) {
        unlink($path);

        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            rmrf("$path/$entry");
        }
    }

    rmdir($path);
}

function copyInto(string $source, string $target): void
{
    if (!is_dir($source)) {
        copy($source, $target);

        return;
    }

    if (!is_dir($target) && !mkdir($target, 0o755, true)) {
        fail("could not create $target");
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            copyInto("$source/$entry", "$target/$entry");
        }
    }
}

$manifest = json_decode((string) file_get_contents('plugin.json'), true, 512, JSON_THROW_ON_ERROR);

$id = $manifest['id'] ?? null;
if (!is_string($id) || $id === '') {
    fail('plugin.json has no id');
}

$version = ltrim($argv[1] ?? (string) ($manifest['version'] ?? ''), 'v');
if ($version === '') {
    fail('no version given and none in plugin.json');
}

$stage = "dist/$id";
rmrf('dist');
if (!mkdir($stage, 0o755, true)) {
    fail('could not create dist directory');
}

foreach (INCLUDE_PATHS as $path) {
    if (file_exists($path)) {
        copyInto($path, "$stage/$path");
    }
}

// `meta` is local installation state (status, load order). Publishing it would
// push this machine's state onto whoever installs the zip.
unset($manifest['meta']);
$manifest['version'] = $version;

file_put_contents(
    "$stage/plugin.json",
    json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

// The install directory is taken from plugin.json.id and Plugin::getRows()
// rejects a mismatch between folder name and id, so guard both invariants.
$written = json_decode((string) file_get_contents("$stage/plugin.json"), true, 512, JSON_THROW_ON_ERROR);
if (($written['id'] ?? null) !== $id) {
    fail('id changed while stripping meta');
}
if (array_key_exists('meta', $written)) {
    fail('meta is still present');
}

$zipPath = "dist/$id-$version.zip";
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail("could not create $zipPath");
}

$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST,
);

foreach ($files as $file) {
    /** @var SplFileInfo $file */
    $local = substr($file->getPathname(), strlen('dist/'));

    if ($file->isDir()) {
        $zip->addEmptyDir($local);
    } else {
        $zip->addFile($file->getPathname(), $local);
    }
}

$zip->close();

echo "$zipPath\n";

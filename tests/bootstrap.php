<?php

/*
 * Test bootstrap.
 *
 * PluginService deliberately loads no plugin while tests run, so the pieces the
 * loader would normally set up — the PSR-4 prefix, the config, the translations,
 * the migrations, the service providers — are wired by hand here and in
 * PouchTestCase.
 */

declare(strict_types=1);

use Composer\Autoload\ClassLoader;

/** @var ClassLoader $loader */
$loader = require __DIR__ . '/../../../vendor/autoload.php';

$loader->addPsr4('Wan0v\\Pouch\\', __DIR__ . '/../src/');
$loader->addPsr4('Wan0v\\Pouch\\Tests\\', __DIR__ . '/');

return $loader;

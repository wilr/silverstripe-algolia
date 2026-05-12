<?php

declare(strict_types=1);

/**
 * silverstripe/cms `tests/bootstrap/app.php` only copies Page stubs when `app/` does not yet exist,
 * leaving an empty tracked or partially-created directory without stubs. Align with upstream fixtures on every run,
 * unless Page.php is already provided (e.g. by silverstripe/installer) to avoid duplicate class declarations.
 */

$moduleRoot = dirname(__DIR__);
$fixtureDir = $moduleRoot . '/vendor/silverstripe/cms/tests/bootstrap/fixtures';

if (!is_dir($fixtureDir)) {
    fwrite(STDERR, "Missing vendor/silverstripe/cms test fixtures — run composer install with dev deps.\n");
    exit(1);
}

$projectPath = $moduleRoot . '/app';
$pagePath = $projectPath . '/code/Page.php';

// Skip copying stubs when Page.php already exists (e.g. installed via silverstripe/installer into app/src/Page.php),
// as copying a second definition would cause a fatal "Cannot redeclare class Page" error.
$installerPagePath = $projectPath . '/src/Page.php';
if (!file_exists($pagePath) && !file_exists($installerPagePath)) {
    if (!is_dir($dir = dirname($pagePath))) {
        mkdir($dir, 02775, true);
    }
    if (!is_dir($projectPath . '/_config')) {
        mkdir($projectPath . '/_config', 02775, true);
    }

    $copies = [
        'Page.php.fixture' => $pagePath,
        'PageController.php.fixture' => $projectPath . '/code/PageController.php',
    ];
    foreach ($copies as $from => $to) {
        if (!copy($fixtureDir . '/' . $from, $to)) {
            throw new RuntimeException(sprintf('Unable to copy CMS test fixture %s → %s', $from, $to));
        }
    }
}

require $moduleRoot . '/vendor/silverstripe/cms/tests/bootstrap.php';

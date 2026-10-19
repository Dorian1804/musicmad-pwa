<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/paths.php';
$taskRoot = musicmadQaRoot() . '/relocation-' . bin2hex(random_bytes(8));
mkdir($taskRoot . '/public/app/uploads', 0700, true);
mkdir($taskRoot . '/public/app/config', 0700);
mkdir($taskRoot . '/backups/musicmad-media', 0700, true);
define('APP_ROOT', $taskRoot . '/public/app');
define('MUSICMAD_MEDIA_BACKUP_DIR', $taskRoot . '/backups/musicmad-media');
$_SERVER['DOCUMENT_ROOT'] = $taskRoot . '/public';
require dirname(__DIR__) . '/includes/private_environment.php';
require dirname(__DIR__) . '/includes/media_backup.php';
require dirname(__DIR__) . '/includes/public_artifacts.php';
try {
    file_put_contents(APP_ROOT . '/.env', 'QA_FAKE_CONFIGURATION');
    file_put_contents(APP_ROOT . '/config/local.php', 'QA_FAKE_UNTOUCHED');
    file_put_contents(APP_ROOT . '/uploads/owned.wav', 'QA_FAKE_USER_BYTES');
    $private = musicmadMigratePublicEnvironment(APP_ROOT, $_SERVER['DOCUMENT_ROOT']);
    $results = ['env_moved' => $private !== null && !file_exists(APP_ROOT . '/.env') && file_get_contents($private) === 'QA_FAKE_CONFIGURATION'];
    file_put_contents(APP_ROOT . '/.env', 'QA_FAKE_DUPLICATE');
    $again = musicmadMigratePublicEnvironment(APP_ROOT, $_SERVER['DOCUMENT_ROOT']);
    $results['env_not_replaced'] = $again === $private && file_get_contents($private) === 'QA_FAKE_CONFIGURATION' && !file_exists(APP_ROOT . '/.env');
    foreach (['audit.md','release.zip','release.zip.sha256','MANIFIESTO_ENTREGA.json'] as $name) {
        file_put_contents(APP_ROOT . '/' . $name, 'QA_ARTIFACT');
    }
    foreach (['.tests-tmp','.phpunit.cache'] as $name) {
        mkdir(APP_ROOT . '/' . $name);
        file_put_contents(APP_ROOT . '/' . $name . '/fixture', 'QA');
    }
    mkdir(APP_ROOT . '/docs');
    file_put_contents(APP_ROOT . '/docs/untouched.md', 'QA_DOCUMENT');
    file_put_contents(APP_ROOT . '/manifest.json', '{}');
    $first = musicmadRetirePublicArtifacts();
    $second = musicmadRetirePublicArtifacts();
    $results['bounded_retirement'] = $first['moved'] === 6 && $second['moved'] === 0;
    $results['preserves_runtime'] = is_file(APP_ROOT . '/manifest.json') && file_get_contents(APP_ROOT . '/uploads/owned.wav') === 'QA_FAKE_USER_BYTES'
        && file_get_contents(APP_ROOT . '/config/local.php') === 'QA_FAKE_UNTOUCHED' && is_file(APP_ROOT . '/docs/untouched.md');
    file_put_contents(APP_ROOT . '/audit.md', 'QA_CONFLICT');
    $conflict = musicmadRetirePublicArtifacts();
    $results['no_overwrite'] = $conflict['conflicts'] === 1 && !is_file(APP_ROOT . '/audit.md') && $conflict['moved'] === 1
        && file_get_contents($taskRoot . '/backups/musicmad-media/retired-public/audit.md') === 'QA_ARTIFACT';
    rename(APP_ROOT, $taskRoot . '/public/previous-app');
    mkdir(APP_ROOT);
    $results['survives_deploy'] = musicmadMigratePublicEnvironment(APP_ROOT, $_SERVER['DOCUMENT_ROOT']) === $private;
    echo json_encode($results, JSON_THROW_ON_ERROR);
} finally {
    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($taskRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($taskRoot);
}

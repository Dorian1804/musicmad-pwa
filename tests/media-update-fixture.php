<?php

require_once __DIR__ . '/paths.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}
$qa = realpath(musicmadQaRoot());
$phase = $argv[1] ?? 'coordinate';
$tree = ($argv[2] ?? '') !== '' ? $argv[2] : ($qa . '/media-update-' . bin2hex(random_bytes(12)));
$mode = $argv[3] ?? 'default';
if (!in_array($mode, ['default', 'public-configured'], true)) {
    exit(2);
}
if ($qa === false || !preg_match('/^media-update-[a-f0-9]{24}$/D', basename($tree))
    || realpath(dirname($tree)) !== $qa || is_link($tree)) {
    exit(2);
}
$samples = [
    'audio' => 'qa_update.wav',
    'videos' => 'qa_update.webm',
    'avatars' => '1_' . str_repeat('a', 24) . '.jpg',
    'covers' => '1_' . str_repeat('b', 24) . '.jpg',
    'market' => str_repeat('c', 24) . '.jpg',
    'partituras' => str_repeat('d', 24) . '.pdf',
];
if ($phase !== 'coordinate') {
    if (!in_array($phase, ['write', 'read'], true) || !is_dir($tree)) {
        exit(2);
    }
    define('APP_ROOT', $tree . '/home/htdocs/MusicMad_PWA');
    $_SERVER['DOCUMENT_ROOT'] = $tree . '/home/htdocs';
    if ($mode === 'public-configured') {
        mkdir(APP_ROOT . '/uploads', 0700);
        define('APP_UPLOAD_ROOT', APP_ROOT . '/uploads');
    }
    // No configured backup directory or persistent PHP state: use hosting-style discovery.
    putenv('MM_MEDIA_BACKUP_DIR');
    require dirname(__DIR__) . '/includes/media_storage.php';
    $result = ['outside_app' => !rutaDentroRespaldo(musicmadMediaRoot(), APP_ROOT),
        'expected_root' => realpath(musicmadMediaRoot()) === realpath($tree . '/home/backups/musicmad-media/live')];
    foreach ($samples as $kind => $name) {
        $expected = 'SYNTHETIC_UPDATE_QA_' . $kind;
        $dir = musicmadUploadDirectory($kind);
        $file = $dir . '/' . $name;
        if ($phase === 'write') {
            file_put_contents($file, $expected);
            respaldarMedioGuardado($kind, $name);
            $result[$kind] = hash_file('sha256', $file) === hash('sha256', $expected);
        } else {
            $result[$kind . '_survives'] = is_file($file) && file_get_contents($file) === $expected;
            $copy = carpetaRespaldoMedios($kind) . '/' . $name;
            $hash = hash_file('sha256', $copy);
            // Delete only this child's synthetic primary bytes to exercise actual recovery.
            unlink($file);
            clearstatcache(true, $file);
            $resolved = musicmadMediaDirectoryForFile($kind, $name) . '/' . $name;
            $result[$kind . '_restores'] = is_file($resolved) && hash_file('sha256', $resolved) === hash('sha256', $expected);
            $result[$kind . '_backup_unchanged'] = $hash === hash_file('sha256', $copy);
        }
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

mkdir($tree . '/home/htdocs/MusicMad_PWA', 0700, true);
mkdir($tree . '/home/backups/musicmad-media', 0700, true);
$run = static function (string $stage) use ($tree, $mode): array {
    $process = proc_open([PHP_BINARY, __FILE__, $stage, $tree, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start isolated fixture.');
    }
    $out = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || $errors !== '') {
        throw new RuntimeException('Isolated media update failed: ' . $errors);
    }
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
};
try {
    $write = $run('write');
    $app = $tree . '/home/htdocs/MusicMad_PWA';
    file_put_contents($app . '/index.php', 'OLD_SYNTHETIC_APP');
    rename($app, $tree . '/home/htdocs/previous-app');
    mkdir($app, 0700);
    file_put_contents($app . '/index.php', 'NEW_SYNTHETIC_APP');
    $read = $run('read');
    echo json_encode(['write' => $write, 'fresh_process_after_update' => $read], JSON_THROW_ON_ERROR);
} finally {
    // Exact random fixture root verified above; no application or user paths enter this tree.
    if (realpath(dirname($tree)) !== $qa || !str_starts_with(basename($tree), 'media-update-') || is_link($tree)) {
        exit(3);
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        if ($item->isDir() && !$item->isLink()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($tree);
}

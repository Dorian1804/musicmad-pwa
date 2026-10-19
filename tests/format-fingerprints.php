<?php

if (PHP_SAPI !== 'cli') {
    exit(2);
}
$mode = $argv[1] ?? '';
$destination = $argv[2] ?? '';
if (!in_array($mode, ['record','verify'], true) || !str_ends_with($destination, '.json')) {
    exit(2);
}
$root = dirname(__DIR__);
$process = proc_open(['git','ls-files','-z','--','*.php'], [1 => ['pipe','w'],2 => ['pipe','w']], $pipes, $root, null, ['bypass_shell' => true]);
$list = stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($process) !== 0) {
    exit(2);
}
$fingerprints = [];
foreach (array_filter(explode("\0", $list)) as $name) {
    if ($name === 'config/local.php' || str_starts_with($name, 'vendor/') || str_contains($name, '/.tools/')) {
        continue;
    }
    $syntax = [];
    foreach (token_get_all(file_get_contents($root . '/' . $name)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT], true)) {
                continue;
            }
            $syntax[] = [$token[0], $token[0] === T_OPEN_TAG ? '<?php' : $token[1]];
        } else {
            $syntax[] = $token;
        }
    }
    $fingerprints[$name] = hash('sha256', serialize($syntax));
}
if ($mode === 'record') {
    file_put_contents($destination, json_encode($fingerprints, JSON_THROW_ON_ERROR));
} else {
    $prior = json_decode(file_get_contents($destination), true, 512, JSON_THROW_ON_ERROR);
    foreach ($prior as $name => $hash) {
        if (($fingerprints[$name] ?? null) !== $hash) {
            fwrite(STDERR, 'Cambio de tokens: ' . $name . "\n");
            exit(1);
        }
    }
    echo count($prior) . " archivos conservan sus tokens de código y HTML.\n";
}

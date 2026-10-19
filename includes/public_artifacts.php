<?php

/** Bounded deployment maintenance. Fixed root names only; no user media/configuration. */
function musicmadRetirePublicArtifacts(): array
{
    $result = ['moved' => 0, 'conflicts' => 0];
    $root = realpath(APP_ROOT);
    if ($root === false || is_link(APP_ROOT)) {
        return $result;
    }
    $backup = directorioRespaldoMedios();
    $destination = $backup . '/retired-public';
    if (is_link($destination) || (!is_dir($destination) && !@mkdir($destination, 0700) && !is_dir($destination))) {
        return $result;
    }
    if (realpath(dirname($destination)) !== realpath($backup) || !is_writable($destination)) {
        return $result;
    }
    $lock = @fopen($destination . '/.migration.lock', 'c');
    if (!$lock) {
        return $result;
    }
    try {
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            return $result;
        }
        foreach (new DirectoryIterator($root) as $entry) {
            if ($result['moved'] >= 40) {
                break;
            }
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            $name = $entry->getFilename();
            $directory = in_array($name, ['.tests-tmp', '.phpunit.cache'], true) && $entry->isDir();
            $file = $entry->isFile() && preg_match('/^(?:[A-Za-z0-9_-]+\.md|[A-Za-z0-9_.-]+\.zip(?:\.sha256|\.manifest\.json)?|MANIFIESTO_[A-Za-z0-9_-]+\.json)$/Di', $name);
            if (!$directory && !$file) {
                continue;
            }
            $source = $root . '/' . $name;
            $target = $destination . '/' . $name;
            if (file_exists($target) || is_link($target)) {
                $result['conflicts']++;
                $target .= '.retired-' . bin2hex(random_bytes(12));
                if (file_exists($target) || is_link($target)) {
                    continue;
                }
            }
            if (@rename($source, $target)) {
                $result['moved']++;
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $result;
}

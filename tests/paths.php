<?php

/** Paths for synthetic QA data. Never place credentials or fixtures in the app root. */
function musicmadQaRoot(): string
{
    if (defined('MUSICMAD_QA_ROOT')) {
        return MUSICMAD_QA_ROOT;
    }

    $appRoot = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    $appRoot = rtrim($appRoot, '/');
    $identity = PHP_OS_FAMILY === 'Windows' ? strtolower($appRoot) : $appRoot;
    $configured = getenv('MM_QA_ROOT');
    $locationFile = __DIR__ . '/qa-location.txt';
    if (($configured === false || $configured === '') && is_file($locationFile)) {
        if (is_link($locationFile)) {
            throw new RuntimeException('La ubicación QA local no admite enlaces simbólicos.');
        }
        $location = file_get_contents($locationFile);
        if ($location === false || trim($location) === '') {
            throw new RuntimeException('La ubicación QA local no está disponible.');
        }
        $configured = trim($location);
    }
    $path = $configured !== false && $configured !== ''
        ? $configured
        : rtrim(sys_get_temp_dir(), '/\\') . '/musicmad-qa-' . substr(hash('sha256', $identity), 0, 12);

    if (!preg_match('~^(?:[A-Za-z]:[/\\\\]|[/\\\\])~', $path)) {
        throw new RuntimeException('MM_QA_ROOT debe ser una ruta absoluta fuera de la aplicación.');
    }
    $path = rtrim(str_replace('\\', '/', realpath($path) ?: $path), '/');
    if (preg_match('~(?:^|/)\.\.(?:/|$)~', $path)) {
        throw new RuntimeException('MM_QA_ROOT no admite segmentos de ruta relativos.');
    }
    $comparison = PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    if ($comparison === $identity || str_starts_with($comparison . '/', $identity . '/')) {
        throw new RuntimeException('Los datos QA deben guardarse fuera de la aplicación.');
    }

    return $path;
}

if (!defined('MUSICMAD_QA_ROOT')) {
    define('MUSICMAD_QA_ROOT', musicmadQaRoot());
}

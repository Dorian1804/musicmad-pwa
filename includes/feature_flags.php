<?php

const MUSICMAD_EXPERIMENTAL_FEATURES = ['salas','clips','estudio','playlists_equipo'];
function validarFlagsMusicMad(string $raw): array
{
    if (strlen($raw) > 4096) {
        throw new InvalidArgumentException('Configuración de funciones demasiado grande.');
    }
    $config = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($config)) {
        throw new InvalidArgumentException('Configuración de funciones no válida.');
    }
    foreach ($config as $key => $value) {
        if (!in_array($key, MUSICMAD_EXPERIMENTAL_FEATURES, true)) {
            throw new InvalidArgumentException('Función desconocida.');
        }
        if (is_bool($value)) {
            $config[$key] = ['enabled' => $value,'percent' => 100];
            continue;
        }
        if (!is_array($value) || array_diff(array_keys($value), ['enabled','percent']) || !isset($value['enabled']) || !is_bool($value['enabled']) || !isset($value['percent']) || !is_int($value['percent']) || $value['percent'] < 0 || $value['percent'] > 100) {
            throw new InvalidArgumentException('Despliegue no válido.');
        }
    }
    return $config;
}
function evaluarFlagMusicMad(string $key, int $uid, array $flags): bool
{
    if (!in_array($key, MUSICMAD_EXPERIMENTAL_FEATURES, true)) {
        return false;
    }
    $flag = $flags[$key] ?? ['enabled' => true,'percent' => 100];
    if (!$flag['enabled']) {
        return false;
    }
    if ($flag['percent'] === 100) {
        return true;
    }
    if ($uid < 1 || $flag['percent'] === 0) {
        return false;
    }
    $bucket = hexdec(substr(hash('sha256', 'MusicMad rollout v1:' . $key . ':' . $uid), 0, 8)) % 100;
    return $bucket < $flag['percent'];
}
function funcionalidadMusicMad(string $key, int $uid = 0): bool
{
    // Released sections are available to every account; route authorization still applies.
    if (in_array($key, ['salas','clips','estudio'], true)) {
        return true;
    }
    $environment = getenv('MM_FEATURE_FLAGS');
    $raw = defined('MUSICMAD_FEATURE_FLAGS') ? (string)MUSICMAD_FEATURE_FLAGS : ($environment === false || $environment === '' ? '{}' : $environment);
    try {
        return evaluarFlagMusicMad($key, $uid, validarFlagsMusicMad($raw));
    } catch (JsonException|InvalidArgumentException $e) {
        static $logged = false;
        if (!$logged) {
            error_log('MusicMad feature configuration invalid');
            $logged = true;
        }
        return false;
    }
}
function flagRutaMusicMad(string $route): ?string
{
    return match(basename($route)) {
        'salas.php','sala.php','sala-datos.php','sala-accion.php' => 'salas',
        'clips.php','clip.php','clip-medio.php','clip-accion.php' => 'clips',
        'colabs.php','colab.php','colab-subir.php','colab-accion.php' => 'estudio',
        'playlist-equipo.php','playlist-equipo-accion.php' => 'playlists_equipo',default => null,
    };
}
function exigirFlagRutaMusicMad(?string $route = null): void
{
    $feature = flagRutaMusicMad($route ?? (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($feature !== null && !funcionalidadMusicMad($feature, (int)($_SESSION['usuario_id'] ?? 0))) {
        responderErrorMusicMad(404);
    }
}
function exigirFuncionMusicMad(string $key, int $uid): void
{
    if (!funcionalidadMusicMad($key, $uid)) {
        throw new OutOfBoundsException('Esta función no está disponible para tu cuenta por el momento.');
    }
}

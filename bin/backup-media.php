<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/social.php';
try {
    $copied = 0;
    $missing = 0;
    $deadline = microtime(true) + 25;
    $sources = [
        ['audio', 'SELECT DISTINCT archivo FROM publicacion_audio ORDER BY archivo'],
        ['videos', 'SELECT DISTINCT archivo FROM publicacion_video ORDER BY archivo'],
        ['partituras', 'SELECT DISTINCT archivo FROM publicacion_partitura ORDER BY archivo'],
        ['avatars', "SELECT DISTINCT avatar AS archivo FROM usuarios WHERE activo=1 AND avatar LIKE 'uploads/avatars/%' ORDER BY avatar"],
        ['covers', 'SELECT DISTINCT f.archivo FROM fondos_perfil f JOIN usuarios u ON u.id=f.usuario_id AND u.activo=1 ORDER BY f.archivo'],
        ['market', "SELECT DISTINCT f.archivo FROM instrumentos_fotos f JOIN instrumentos_usados i ON i.id=f.instrumento_id AND i.estado<>'oculto' JOIN usuarios u ON u.id=i.usuario_id AND u.activo=1 ORDER BY f.archivo"],
    ];
    foreach ($sources as [$kind, $query]) {
        $directory = carpetaRespaldoMedios($kind);
        $st = getDB()->query($query);
        foreach ($st as $row) {
            if (microtime(true) > $deadline || $copied >= 20) {
                break 2;
            }
            $name = $row['archivo'];
            if ($kind === 'avatars' && is_string($name)) {
                $name = substr($name, strlen('uploads/avatars/'));
            }
            if (!is_string($name) || !nombreMedioRespaldoValido($kind, $name)) {
                continue;
            }
            $source = musicmadMediaDirectoryForFile($kind, $name) . '/' . $name;
            if (!is_file($source) || is_link($source)) {
                $missing++;
                continue;
            }
            if (is_file($directory . '/' . $name) && !is_link($directory . '/' . $name)) {
                continue;
            }
            respaldarMedioGuardado($kind, $name);
            $copied++;
        }
    }
    echo 'Copias nuevas: ' . $copied . '; archivos ausentes: ' . $missing . "\n";
} catch (Throwable $error) {
    error_log('Media backup: ' . get_class($error));
    fwrite(STDERR, "Respaldo incompleto. Revisa carpeta privada y espacio del hosting.\n");
    exit(1);
}

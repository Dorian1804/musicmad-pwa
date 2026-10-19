<?php

// Upload seguro de fotos de perfil: solo decodifica y vuelve a codificar imágenes raster.
const AVATAR_UPLOAD_MAX_BYTES = 5 * 1024 * 1024;
define('AVATAR_UPLOAD_DIR', musicmadUploadDirectory('avatars'));

function guardarAvatarSubido(int $usuarioId, array $archivo): array
{
    $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'mensaje' => 'La foto supera el límite de este hosting. Prueba con una imagen menor o pide aumentar el límite de subida.'];
    }
    if ($error !== UPLOAD_ERR_OK || !isset($archivo['tmp_name'], $archivo['size'])
        || !is_string($archivo['tmp_name']) || !is_numeric($archivo['size'])
        || !is_uploaded_file($archivo['tmp_name'])) {
        return ['ok' => false, 'mensaje' => 'No se pudo recibir la foto. Inténtalo otra vez.'];
    }
    if ((int) $archivo['size'] < 1 || (int) $archivo['size'] > AVATAR_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'mensaje' => 'La foto debe pesar máximo 5 MB.'];
    }
    if (!class_exists('finfo') || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['ok' => false, 'mensaje' => 'El servidor necesita las extensiones Fileinfo y GD para procesar fotos de perfil.'];
    }

    try {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($archivo['tmp_name']);
        $info = @getimagesize($archivo['tmp_name']);
        $mimes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!is_string($mime) || !in_array($mime, $mimes, true) || !is_array($info)
            || !in_array($info['mime'] ?? '', $mimes, true)) {
            return ['ok' => false, 'mensaje' => 'Formato no válido. Usa una foto JPG, PNG o WebP.'];
        }
        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        if ($width < 64 || $height < 64 || $width > 12000 || $height > 12000 || $width * $height > 16000000) {
            return ['ok' => false, 'mensaje' => 'La imagen conserva dimensiones demasiado grandes. Vuelve a elegirla para que MusicMad la reduzca automáticamente.'];
        }
        if (!presupuestoImagenSeguro($width, $height, (string)ini_get('memory_limit'), memory_get_usage(true))) {
            return ['ok' => false, 'mensaje' => 'La foto necesita más memoria de la disponible. Elige una imagen reducida.'];
        }
        $bytes = file_get_contents($archivo['tmp_name']);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        unset($bytes);
        if ($source === false) {
            return ['ok' => false, 'mensaje' => 'No se pudo procesar esa imagen. Elige otra foto.'];
        }

        if (!is_dir(AVATAR_UPLOAD_DIR) && !mkdir(AVATAR_UPLOAD_DIR, 0755, true) && !is_dir(AVATAR_UPLOAD_DIR)) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'No se pudo preparar el espacio para guardar la foto.'];
        }
        if (is_link(AVATAR_UPLOAD_DIR)) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'El espacio de fotos no está configurado de forma segura.'];
        }

        $canvas = imagecreatetruecolor(512, 512);
        if ($canvas === false) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'No se pudo preparar la foto.'];
        }
        $background = imagecolorallocate($canvas, 255, 255, 255);
        imagefill($canvas, 0, 0, $background);
        $crop = min($width, $height);
        $sourceX = intdiv($width - $crop, 2);
        $sourceY = intdiv($height - $crop, 2);
        imagecopyresampled($canvas, $source, 0, 0, $sourceX, $sourceY, 512, 512, $crop, $crop);
        imagedestroy($source);

        $token = bin2hex(random_bytes(12));
        $base = $usuarioId . '_' . $token;
        $tmpPath = AVATAR_UPLOAD_DIR . '/' . $base . '.tmp';
        $finalPath = AVATAR_UPLOAD_DIR . '/' . $base . '.jpg';
        $ok = imagejpeg($canvas, $tmpPath, 86);
        imagedestroy($canvas);
        if (!$ok || !rename($tmpPath, $finalPath)) {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
            return ['ok' => false, 'mensaje' => 'No se pudo guardar la foto en el servidor.'];
        }
        respaldarMedioGuardado('avatars', $base . '.jpg');
        return ['ok' => true, 'ruta' => 'uploads/avatars/' . $base . '.jpg', 'mensaje' => ''];
    } catch (Throwable $e) {
        error_log('No se pudo procesar la foto de perfil: ' . get_class($e));
        return ['ok' => false, 'mensaje' => 'No se pudo procesar la foto. Inténtalo de nuevo.'];
    }
}

function esAvatarSubidoDelUsuario(int $usuarioId, string $ruta): bool
{
    if (!preg_match('#^uploads/avatars/' . preg_quote((string) $usuarioId, '#') . '_[a-f0-9]{24}\.jpg$#', $ruta)) {
        return false;
    }
    $base = realpath(musicmadMediaDirectoryForFile('avatars', basename($ruta)));
    $archivo = realpath(musicmadMediaDirectoryForFile('avatars', basename($ruta)) . '/' . basename($ruta));
    return $base !== false && $archivo !== false && dirname($archivo) === $base && is_file($archivo) && !is_link(musicmadMediaDirectoryForFile('avatars', basename($ruta)) . '/' . basename($ruta));
}

function eliminarAvatarSubido(int $usuarioId, string $ruta): void
{
    if (esAvatarSubidoDelUsuario($usuarioId, $ruta)) {
        @unlink(musicmadMediaDirectoryForFile('avatars', basename($ruta)) . '/' . basename($ruta));
    }
}

/** Guarda una portada horizontal como JPEG optimizado. */
function guardarPortadaSubida(int $usuarioId, array $archivo): array
{
    $error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
        return ['ok' => false, 'mensaje' => 'La portada supera el límite de subida de este hosting.'];
    }
    if ($error !== UPLOAD_ERR_OK || !isset($archivo['tmp_name'], $archivo['size'])
        || !is_string($archivo['tmp_name']) || !is_numeric($archivo['size']) || !is_uploaded_file($archivo['tmp_name'])) {
        return ['ok' => false, 'mensaje' => 'No se recibió la portada. Inténtalo de nuevo.'];
    }
    if ((int) $archivo['size'] < 1 || (int) $archivo['size'] > 8 * 1024 * 1024) {
        return ['ok' => false, 'mensaje' => 'La portada debe pesar máximo 8 MB.'];
    }
    if (!class_exists('finfo') || !function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return ['ok' => false, 'mensaje' => 'El hosting necesita Fileinfo y GD para procesar portadas.'];
    }

    try {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
        $info = @getimagesize($archivo['tmp_name']);
        $permitidos = ['image/jpeg', 'image/png', 'image/webp'];
        if (!is_string($mime) || !in_array($mime, $permitidos, true) || !is_array($info)
            || !in_array($info['mime'] ?? '', $permitidos, true)) {
            return ['ok' => false, 'mensaje' => 'Usa una portada JPG, PNG o WebP.'];
        }
        $w = (int) ($info[0] ?? 0);
        $h = (int) ($info[1] ?? 0);
        if ($w < 64 || $h < 64 || $w > 12000 || $h > 12000 || $w * $h > 16000000) {
            return ['ok' => false, 'mensaje' => 'La portada conserva dimensiones demasiado grandes. Vuelve a elegirla para que MusicMad la reduzca automáticamente.'];
        }
        if (!presupuestoImagenSeguro($w, $h, (string)ini_get('memory_limit'), memory_get_usage(true))) {
            return ['ok' => false, 'mensaje' => 'La portada necesita más memoria de la disponible. Elige una imagen reducida.'];
        }
        $bytes = file_get_contents($archivo['tmp_name']);
        $source = is_string($bytes) ? @imagecreatefromstring($bytes) : false;
        unset($bytes);
        if ($source === false) {
            return ['ok' => false, 'mensaje' => 'No se pudo procesar esa portada.'];
        }

        $dir = musicmadUploadDirectory('covers');
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'No se pudo preparar el espacio para portadas.'];
        }
        if (is_link($dir)) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'La carpeta de portadas no es segura.'];
        }

        $canvas = imagecreatetruecolor(1500, 600);
        if ($canvas === false) {
            imagedestroy($source);
            return ['ok' => false, 'mensaje' => 'No se pudo preparar la portada.'];
        }
        $bg = imagecolorallocate($canvas, 35, 22, 42);
        imagefill($canvas, 0, 0, $bg);
        $ratio = 1500 / 600;
        if ($w / $h > $ratio) {
            $cropW = (int) floor($h * $ratio);
            $srcX = intdiv($w - $cropW, 2);
            $srcY = 0;
            $cropH = $h;
        } else {
            $cropW = $w;
            $srcX = 0;
            $cropH = (int) floor($w / $ratio);
            $srcY = intdiv($h - $cropH, 2);
        }
        imagecopyresampled($canvas, $source, 0, 0, $srcX, $srcY, 1500, 600, $cropW, $cropH);
        imagedestroy($source);

        $name = $usuarioId . '_' . bin2hex(random_bytes(12)) . '.jpg';
        $tmp = $dir . '/' . $name . '.tmp';
        $dest = $dir . '/' . $name;
        $ok = imagejpeg($canvas, $tmp, 86);
        imagedestroy($canvas);
        if (!$ok || !rename($tmp, $dest)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            return ['ok' => false, 'mensaje' => 'No se pudo guardar la portada en el servidor.'];
        }
        respaldarMedioGuardado('covers', $name);
        return ['ok' => true, 'archivo' => $name, 'ruta' => 'uploads/covers/' . $name, 'mensaje' => ''];
    } catch (Throwable $e) {
        error_log('No se pudo procesar portada de perfil: ' . get_class($e));
        return ['ok' => false, 'mensaje' => 'No se pudo procesar la portada. Inténtalo de nuevo.'];
    }
}

function esPortadaSubidaDelUsuario(int $usuarioId, string $archivo): bool
{
    if (!preg_match('/^' . preg_quote((string) $usuarioId, '/') . '_[a-f0-9]{24}\.jpg$/', $archivo)) {
        return false;
    }
    $base = realpath(musicmadMediaDirectoryForFile('covers', $archivo));
    $path = realpath(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo);
    return $base !== false && $path !== false && dirname($path) === $base && is_file($path);
}

function eliminarPortadaSubida(int $usuarioId, string $archivo): void
{
    if (esPortadaSubidaDelUsuario($usuarioId, $archivo)) {
        @unlink(musicmadMediaDirectoryForFile('covers', $archivo) . '/' . $archivo);
    }
}

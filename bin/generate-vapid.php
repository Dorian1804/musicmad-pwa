<?php

// CLI only; never overwrite a key or create one under the public application.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/vendor/autoload.php';
$destination = $argv[1] ?? '';
$parent = realpath(dirname($destination));
$app = realpath(dirname(__DIR__));
$normalizedParent = $parent ? strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $parent)) : '';
$normalizedApp = strtolower(str_replace(DIRECTORY_SEPARATOR, '/', $app));
if ($destination === '' || !$parent || !is_dir($parent) || is_link($destination) || file_exists($destination) || $normalizedParent === $normalizedApp || str_starts_with($normalizedParent . '/', $normalizedApp . '/') || preg_match('#(?:^|/)(?:public_html|htdocs|wwwroot|www)(?:/|$)#', $normalizedParent)) {
    fwrite(STDERR, "Indica un archivo nuevo fuera de la carpeta pública, por ejemplo /home/tu_usuario/musicmad-vapid.env.\n");
    exit(1);
}
$handle = null;
try {
    $keys = \Minishlink\WebPush\VAPID::createVapidKeys();
    $handle = fopen($destination, 'x');
    if (!$handle) {
        throw new RuntimeException('No se pudo crear el archivo.');
    }
    if (PHP_OS_FAMILY !== 'Windows' && !chmod($destination, 0600)) {
        throw new RuntimeException('No se pudo proteger el archivo.');
    }
    $content = 'MM_VAPID_PUBLIC_KEY="' . $keys['publicKey'] . "\"\n" . 'MM_VAPID_PRIVATE_KEY="' . $keys['privateKey'] . "\"\n";
    if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) {
        throw new RuntimeException('Escritura incompleta.');
    }
    fclose($handle);
    $handle = null;
    echo "Claves guardadas en el archivo privado indicado. Agrégalas a la configuración del hosting; no las compartas ni las incluyas en el ZIP.\n";
} catch (Throwable $error) {
    if (is_resource($handle)) {
        fclose($handle);
        unlink($destination);
    }
    error_log('VAPID generation: ' . get_class($error));
    fwrite(STDERR, "No se pudieron generar las claves. Revisa OpenSSL y el permiso de la carpeta privada.\n");
    exit(1);
}

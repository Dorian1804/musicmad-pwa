<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';

try {
    $checks = [
        'Esquema de correo' => correoCuentaDisponible(),
        'Migracion de verificacion obligatoria' => verificacionObligatoriaDisponible(),
        'Transporte de correo configurado' => correoConfigurado(),
        'Transporte permitido (mail o smtp)' => in_array(parametroCorreoMusicMad('TRANSPORT'), ['mail','smtp'], true),
        'Extension cURL para DNS' => function_exists('curl_init'),
        'Clave de cifrado de cola disponible' => strlen((string) getenv('MM_ENC_KEY')) >= 32,
    ];
    try {
        direccionCanonicaCorreo();
        $checks['URL HTTPS canonica'] = true;
    } catch (Throwable $error) {
        $checks['URL HTTPS canonica'] = false;
    }
    foreach ($checks as $name => $ok) {
        echo ($ok ? '[OK] ' : '[FALTA] ') . $name . "\n";
    }
    echo "Este diagnostico no envia correos ni muestra claves. No confirma entrega real: el modo mail requiere un servicio local del hosting; SMTP requiere autenticacion.\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $error) {
    error_log('Email diagnostic unavailable: ' . get_class($error));
    fwrite(STDERR, "No se pudo completar el diagnostico. Revisa la configuracion privada y las migraciones.\n");
    exit(1);
}

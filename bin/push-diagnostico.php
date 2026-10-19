<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/social.php';
try {
    $checks = [
        'Esquema Web Push' => pushSchemaDisponible(),
        'Migración de horarios y cron' => controlesPushDisponibles(),
        'cURL' => extension_loaded('curl'),
        'OpenSSL' => extension_loaded('openssl'),
        'Biblioteca local Web Push' => class_exists(\Minishlink\WebPush\WebPush::class),
        'Configuración VAPID válida' => pushConfigurado(),
        'Cifrado de cola disponible' => strlen((string)getenv('MM_ENC_KEY')) >= 32,
        'Cron correcto en los últimos cinco minutos' => cronPushReciente(),
    ];
    $checks['URL pública HTTPS'] = basePublicaMusicMad() !== null;
    foreach ($checks as $name => $ok) {
        echo ($ok ? '[OK] ' : '[FALTA] ') . $name . "\n";
    }
    if (colaTrabajosDisponible()) {
        foreach (getDB()->query("SELECT estado,COUNT(*) AS total FROM musicmad_jobs WHERE tipo='push' GROUP BY estado") as $row) {
            if (in_array($row['estado'], ['pendiente','procesando','completo','fallido'], true)) {
                echo 'Cola ' . $row['estado'] . ': ' . (int)$row['total'] . "\n";
            }
        }
    }
    echo "No envía avisos ni muestra claves, suscripciones o datos personales. La entrega real se comprueba desde el dispositivo.\n";
    exit(in_array(false, $checks, true) ? 1 : 0);
} catch (Throwable $error) {
    error_log('Push diagnostic failed: ' . get_class($error));
    fwrite(STDERR, "No se pudo completar el diagnóstico. Revisa configuración privada y migraciones.\n");
    exit(1);
}

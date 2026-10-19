<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/mailer.php';
require_once dirname(__DIR__) . '/includes/social.php';
try {
    $inicioCron = microtime(true);
    limpiarLimitesLecturaPublica();
    limpiarSalasEscucha();
    limpiarClipsMusicales();
    limpiarRecibosEscucha();
    procesarHitosArtistas();
    generarRetoSemanal();
    limpiarRecibosRetos();
    limpiarRecibosRecomendaciones();
    limpiarRecibosOnboarding();
    limpiarRecibosRepost();
    $vencidas = vencerCuentasSinVerificar(2);
    $eliminadas = purgarCuentasSolicitadas(2, 3);
    $medios = recogerMediosEliminados(30, 2);
    $recordatorios = generarRecordatoriosBandas() + generarRecordatoriosEventos();
    $result = ejecutarTrabajosAcotados(['correo' => 'enviarCorreoTrabajo','push' => 'enviarTrabajoPush'], 10, max(1, 20 - (int)(microtime(true) - $inicioCron)));
    registrarSaludTrabajos(true, $result);
    echo 'Cuentas sin verificar vencidas: ' . $vencidas . '; eliminadas: ' . $eliminadas . '; medios recogidos: ' . $medios . '; recordatorios: ' . $recordatorios . '; completos: ' . $result['completos'] . '; reintentos: ' . $result['reintentos'] . "\n";
} catch (Throwable $error) {
    try {
        registrarSaludTrabajos(false);
    } catch (Throwable $ignored) {
    }
    error_log('Jobs runner failed: ' . get_class($error));
    fwrite(STDERR, "No se pudo procesar la cola. Revisa la configuración privada.\n");
    exit(1);
}

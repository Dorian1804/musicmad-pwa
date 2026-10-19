<?php

function limitesLecturaPublicaDisponibles(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    return $ready = (bool)getDB()->query("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='musicmad_public_limits'")->fetchColumn();
}
/** Short-lived HMAC counters, no clear address, visitor cookie or cross-day identifier. */
function registrarLecturaPublica(string $address, int $maximum = 240): bool
{
    if (!limitesLecturaPublicaDisponibles()) {
        throw new RuntimeException('Límites de lectura pendientes de activar.');
    }
    $fingerprint = hash_hmac('sha256', 'public-read:' . gmdate('Y-m-d') . '\0' . $address, prepararClaveLimites());
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $db->prepare("INSERT INTO musicmad_public_limits(huella,bucket,solicitudes) VALUES(?,DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:00'),1) ON DUPLICATE KEY UPDATE solicitudes=LEAST(solicitudes+1,65535)")->execute([$fingerprint]);
        $st = $db->prepare("SELECT solicitudes FROM musicmad_public_limits WHERE huella=? AND bucket=DATE_FORMAT(UTC_TIMESTAMP(),'%Y-%m-%d %H:%i:00') FOR UPDATE");
        $st->execute([$fingerprint]);
        $count = (int)$st->fetchColumn();
        if ($owns) {
            $db->commit();
        }
        return $count <= max(1, min(1000, $maximum));
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}
function exigirLimiteLecturaPortafolio(): void
{
    try {
        if (estaAutenticado()) {
            limiteChat((int)sesion('usuario_id'), 'epk_lectura', 240);
            return;
        }
        if (!registrarLecturaPublica((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
            http_response_code(429);
            header('Retry-After: 60');
            exit('Espera un momento antes de abrir más pistas o portafolios.');
        }
    } catch (OverflowException $error) {
        http_response_code(429);
        header('Retry-After: 60');
        exit('Espera un momento antes de volver a intentar.');
    } catch (Throwable $error) {
        error_log('Public read limit unavailable: ' . get_class($error));
        http_response_code(503);
        exit('La escucha pública no está disponible por el momento.');
    }
}
function limpiarLimitesLecturaPublica(): int
{
    if (!limitesLecturaPublicaDisponibles()) {
        return 0;
    }
    $st = getDB()->prepare('DELETE FROM musicmad_public_limits WHERE bucket<UTC_TIMESTAMP()-INTERVAL 1 HOUR LIMIT 1000');
    $st->execute();
    return $st->rowCount();
}

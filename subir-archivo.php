<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/upload_transport.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
function respuestaCarga(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respuestaCarga(['ok' => false,'mensaje' => 'Método no permitido.'], 405);
}
if (!estaAutenticado()) {
    respuestaCarga(['ok' => false,'mensaje' => 'Inicia sesión para subir archivos.'], 401);
}
$token = $_SERVER['HTTP_X_MUSICMAD_CSRF'] ?? '';
if (!is_string($token) || !hash_equals(csrfToken(), $token)) {
    respuestaCarga(['ok' => false,'mensaje' => 'Tu sesión cambió. Recarga antes de subir.'], 403);
}
$uid = (int)sesion('usuario_id');
session_write_close();
try {
    $db = getDB();
    $operation = textoCampo($_GET, 'op');
    $id = textoCampo($_GET, 'id');
    $row = match($operation) {
        'iniciar' => crearSubidaPendiente($db, $uid, textoCampo($_POST, 'tipo'), textoCampo($_POST, 'nombre'), filter_var($_POST['bytes'] ?? null, FILTER_VALIDATE_INT) ?: 0),
        'estado' => obtenerSubidaPropia($db, $id, $uid),
        'cancelar' => cancelarSubida($db, $uid, $id),
        'publicar' => finalizarSubida($db, $uid, $id, $_POST),
        'bloque' => null,
        default => throw new InvalidArgumentException('Acción de subida inválida.')
    };
    if ($operation === 'bloque') {
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > MUSICMAD_CHUNK_BYTES) {
            respuestaCarga(['ok' => false,'mensaje' => 'Bloque demasiado grande.'], 413);
        }
        $stream = fopen('php://input', 'rb');
        $bytes = $stream ? stream_get_contents($stream, MUSICMAD_CHUNK_BYTES + 1) : false;
        if ($stream) {
            fclose($stream);
        }
        $offset = filter_var($_SERVER['HTTP_X_UPLOAD_OFFSET'] ?? null, FILTER_VALIDATE_INT);
        if ($offset === false || $bytes === false) {
            throw new InvalidArgumentException('Bloque inválido.');
        }
        $row = recibirBloqueSubida($db, $uid, $id, $offset, $bytes, (string)($_SERVER['HTTP_X_UPLOAD_SHA256'] ?? ''));
    }
    $target = 'feed.php';
    if ($row['estado'] === 'completo' && bandasWorkspaceDisponible()) {
        $st = $db->prepare('SELECT banda_id FROM musicmad_band_posts WHERE publicacion_id=?');
        $st->execute([(int)$row['publicacion_id']]);
        $band = (int)$st->fetchColumn();
        if ($band) {
            $target = 'banda.php?id=' . $band;
        }
    }
    $redirect = $row['estado'] === 'completo' ? $target . '#pub-' . (int)$row['publicacion_id'] : null;
    if ($row['estado'] === 'completo' && estudioCreativoDisponible()) {
        $st = $db->prepare('SELECT s.colaboracion_id,s.id FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id WHERE t.publicacion_id=? AND s.usuario_id=? LIMIT 1');
        $st->execute([(int)$row['publicacion_id'],$uid]);
        if ($stem = $st->fetch()) {
            $redirect = 'colab.php?id=' . (int)$stem['colaboracion_id'] . '#stem-' . (int)$stem['id'];
        }
    }
    respuestaCarga(['ok' => true,'upload' => resumenSubida($row),'redirect' => $redirect === null ? null : rutaAplicacion($redirect)]);
} catch (RespaldoMedioException $error) {
    respuestaCarga(['ok' => false,'mensaje' => $error->getMessage()], 503);
} catch (OutOfBoundsException $error) {
    respuestaCarga(['ok' => false,'mensaje' => $error->getMessage()], 404);
} catch (LengthException $error) {
    respuestaCarga(['ok' => false,'mensaje' => $error->getMessage()], 409);
} catch (InvalidArgumentException|DomainException $error) {
    respuestaCarga(['ok' => false,'mensaje' => $error->getMessage()], 422);
} catch (PDOException $error) {
    error_log('Upload DB: ' . $error->getCode());
    respuestaCarga(['ok' => false,'mensaje' => $error->getCode() === '42S02' ? 'Falta terminar una actualización de la instalación. Conserva tu archivo y revisa los pasos de actualización.' : 'No pudimos guardar la subida. Conservamos el archivo para reintentar.'], 503);
} catch (Throwable $error) {
    error_log('Upload: ' . get_class($error));
    respuestaCarga(['ok' => false,'mensaje' => 'No pudimos guardar el archivo. Revisa el espacio y permisos del hosting; puedes reintentar.'], 503);
}

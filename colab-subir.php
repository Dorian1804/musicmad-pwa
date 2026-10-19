<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/social.php';
require_once __DIR__ . '/includes/upload_transport.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit;
}
if (!estaAutenticado()) {
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false,'flash' => ['error','Inicia sesión para subir tu aporte.'],'redirect' => null]);
        exit;
    }
    requerirSesion();
}
exigirCsrf();
$uid = (int)sesion('usuario_id');
$project = idCampo($_POST, 'colaboracion_id');
$target = 'colab.php?id=' . $project;
$flash = null;
try {
    limiteChat($uid, 'colab_subir', 10);
    if (!colabDisponiblePara($uid, $project)) {
        throw new OutOfBoundsException('Colaboración no disponible.');
    }
    $file = $_FILES['audio'] ?? null;
    if (!is_array($file) || !is_string($file['tmp_name'] ?? null) || ($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new InvalidArgumentException('Elige un audio. Si supera el límite del hosting, utiliza la subida reanudable.');
    }
    $request = textoCampo($_POST, 'solicitud');
    $db = getDB();
    $row = crearSubidaPendiente($db, $uid, 'audio', (string)$file['name'], (int)$file['size'], $request);
    $stream = fopen($file['tmp_name'], 'rb');
    if (!$stream) {
        throw new RuntimeException('No se pudo leer tu archivo.');
    }
    try {
        if ($row['estado'] === 'completo') {
            $st = $db->prepare('SELECT m.sha256,s.id,s.colaboracion_id FROM publicacion_medios m JOIN musicmad_stem_tracks t ON t.publicacion_id=m.publicacion_id JOIN colaboracion_stems s ON s.id=t.stem_id WHERE m.publicacion_id=? AND s.usuario_id=?');
            $st->execute([(int)$row['publicacion_id'],$uid]);
            $old = $st->fetch();
            if (!$old || (int)$old['colaboracion_id'] !== $project || !hash_equals($old['sha256'], hash_file('sha256', $file['tmp_name']))) {
                throw new DomainException('Ese envío ya corresponde a otro aporte.');
            }
        } else {
            $offset = 0;
            $existing = null;
            if ((int)$row['recibidos'] > 0) {
                $path = rutaSubidaPendiente($row['id']);
                if (is_link($path) || !is_file($path)) {
                    throw new RuntimeException('El temporal no está disponible.');
                }
                $existing = fopen($path, 'rb');
                if (!$existing) {
                    throw new RuntimeException('El temporal no está disponible.');
                }
            }
            try {
                while ($offset < (int)$row['bytes']) {
                    $size = min(MUSICMAD_CHUNK_BYTES, (int)$row['bytes'] - $offset);
                    if ($offset < (int)$row['recibidos']) {
                        $size = min($size, (int)$row['recibidos'] - $offset);
                    }
                    $chunk = fread($stream, $size);
                    if ($chunk === false || strlen($chunk) !== $size) {
                        throw new LengthException('El archivo llegó incompleto.');
                    }
                    if ($offset < (int)$row['recibidos']) {
                        if (!hash_equals($chunk, (string)fread($existing, $size))) {
                            throw new DomainException('El archivo cambió. Vuelve a abrir el formulario para otro envío.');
                        }
                    } else {
                        recibirBloqueSubida($db, $uid, $row['id'], $offset, $chunk, hash('sha256', $chunk));
                    }
                    $offset += $size;
                }
            } finally {
                if (is_resource($existing)) {
                    fclose($existing);
                }
            }
            $row = finalizarSubida($db, $uid, $row['id'], $_POST);
        }
    } finally {
        fclose($stream);
    }
    $st = $db->prepare('SELECT s.id FROM colaboracion_stems s JOIN musicmad_stem_tracks t ON t.stem_id=s.id WHERE s.colaboracion_id=? AND s.usuario_id=? AND t.publicacion_id=?');
    $st->execute([$project,$uid,(int)$row['publicacion_id']]);
    $target .= '#stem-' . (int)$st->fetchColumn();
    $flash = ['success','Aporte guardado. Tu pista conserva su archivo original.'];
} catch (InvalidArgumentException|DomainException|OutOfBoundsException|LogicException $e) {
    $flash = ['error',$e->getMessage()];
} catch (Throwable $e) {
    error_log('Studio upload: ' . get_class($e));
    $flash = ['error','No se pudo completar el aporte. Conserva el audio y reintenta.'];
}
header('Cache-Control: no-store');
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'MusicMad-PJAX') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $flash[0] !== 'error','flash' => $flash,'redirect' => $target]);
    exit;
}
$_SESSION['flash'] = $flash;
header('Location: ' . $target, true, 303);

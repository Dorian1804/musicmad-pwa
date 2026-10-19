<?php

function sincronizacionDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        getDB()->query('SELECT solicitud FROM musicmad_action_receipts LIMIT 0');
        return $ready = true;
    } catch (PDOException $error) {
        if ($error->getCode() === '42S02') {
            return $ready = false;
        }
        throw $error;
    }
}
function normalizarAccionPendiente(array $input): array
{
    $key = $input['solicitud'] ?? '';
    $action = $input['accion'] ?? '';
    if (!is_string($key) || !preg_match('/^[a-f0-9]{32}$/D', $key) || !is_string($action) || !in_array($action, ['aplaudir','guardar','comentar','responder'], true)) {
        throw new InvalidArgumentException('Acción no válida.');
    }
    $id = idCampo($input, 'publicacion');
    if (!$id) {
        throw new InvalidArgumentException('Publicación no válida.');
    }
    $result = ['solicitud' => $key,'accion' => $action,'publicacion' => $id];
    if (in_array($action, ['aplaudir','guardar'], true)) {
        if (!is_bool($input['estado'] ?? null)) {
            throw new InvalidArgumentException('Estado no válido.');
        }
        $result['estado'] = $input['estado'];
    } else {
        $text = trim(textoCampo($input, 'texto'));
        if ($text === '' || mb_strlen($text) > 400) {
            throw new InvalidArgumentException('Escribe entre 1 y 400 caracteres.');
        }
        $result['texto'] = $text;
        if ($action === 'responder') {
            $result['comentario'] = idCampo($input, 'comentario');
            if (!$result['comentario']) {
                throw new InvalidArgumentException('Comentario no válido.');
            }
        }
    }
    return $result;
}
/** Atomic side effects + receipt, serialized per account; safe to retry after timeout. */
function ejecutarAccionPendiente(int $uid, array $input, bool $checkDevice = true): array
{
    $action = normalizarAccionPendiente($input);
    $db = getDB();
    $hash = hash('sha256', json_encode($action, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT activo FROM usuarios WHERE id=? FOR UPDATE');
        $st->execute([$uid]);
        if (!(int)$st->fetchColumn() || ($checkDevice && !dispositivoSesionValido($uid))) {
            throw new DomainException('Vuelve a iniciar sesión.');
        }
        $st = $db->prepare('SELECT contenido_hash,resultado FROM musicmad_action_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$action['solicitud']]);
        $receipt = $st->fetch();
        if ($receipt) {
            if (!hash_equals($hash, $receipt['contenido_hash'])) {
                throw new LogicException('La solicitud ya tiene otro contenido.');
            }
            if ($owns) {
                $db->commit();
            }
            return json_decode($receipt['resultado'], true, 512, JSON_THROW_ON_ERROR) + ['repetida' => true];
        }
        $st = $db->prepare('SELECT COUNT(*) FROM musicmad_action_receipts WHERE usuario_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 MINUTE)');
        $st->execute([$uid]);
        if ((int)$st->fetchColumn() >= 30) {
            throw new OverflowException('Espera un minuto antes de continuar.');
        }
        $pub = $action['publicacion'];
        if (!puedeVerPublicacionSocial3($uid, $pub)) {
            throw new OutOfBoundsException('Esta publicación ya no está disponible.');
        }
        $result = ['ok' => true,'solicitud' => $action['solicitud'],'publicacion' => $pub,'accion' => $action['accion']];
        if (in_array($action['accion'], ['aplaudir','guardar'], true)) {
            $table = $action['accion'] === 'aplaudir' ? 'aplausos' : 'publicaciones_guardadas';
            if ($action['estado']) {
                $st = $db->prepare('INSERT IGNORE INTO ' . $table . '(usuario_id,publicacion_id) VALUES(?,?)');
                $st->execute([$uid,$pub]);
                if ($action['accion'] === 'aplaudir' && $st->rowCount()) {
                    notificar(duenoPublicacion($pub), $uid, 'aplauso', $pub);
                }
            } else {
                $db->prepare('DELETE FROM ' . $table . ' WHERE usuario_id=? AND publicacion_id=?')->execute([$uid,$pub]);
            }
            $result['estado'] = $action['estado'];
            if ($action['accion'] === 'aplaudir') {
                $count = $db->prepare('SELECT COUNT(*) FROM aplausos WHERE publicacion_id=?');
                $count->execute([$pub]);
                $result['aplausos'] = (int)$count->fetchColumn();
            }
        } elseif ($action['accion'] === 'comentar') {
            $db->prepare('INSERT INTO comentarios(publicacion_id,usuario_id,texto) VALUES(?,?,?)')->execute([$pub,$uid,$action['texto']]);
            $result['comentario'] = (int)$db->lastInsertId();
            notificar(duenoPublicacion($pub), $uid, 'comentario', $pub);
        } else {
            $st = $db->prepare('SELECT publicacion_id FROM comentarios WHERE id=?');
            $st->execute([$action['comentario']]);
            if ((int)$st->fetchColumn() !== $pub || !responderComentario($uid, $action['comentario'], $action['texto'])) {
                throw new OutOfBoundsException('El comentario ya no está disponible.');
            }
            $result['respuesta'] = (int)$db->lastInsertId();
        }
        $db->prepare('INSERT INTO musicmad_action_receipts(usuario_id,solicitud,contenido_hash,resultado) VALUES(?,?,?,?)')->execute([$uid,$action['solicitud'],$hash,json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        if ($owns) {
            $db->commit();
        }
        return $result + ['repetida' => false];
    } catch (Throwable $error) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}

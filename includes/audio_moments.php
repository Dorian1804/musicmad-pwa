<?php

function precisionComentariosDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        getDB()->query('SELECT comentario_id FROM musicmad_timed_comment_ms LIMIT 0');
        getDB()->query('SELECT solicitud FROM musicmad_timed_comment_receipts LIMIT 0');
        return $ready = true;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02') {
            return $ready = false;
        }
        throw $e;
    }
}
function etiquetaMomentoAudio(float $seconds): string
{
    $ms = max(0, (int)round($seconds * 1000));
    return intdiv($ms, 60000) . ':' . str_pad((string)(intdiv($ms, 1000) % 60), 2, '0', STR_PAD_LEFT) . ($ms % 1000 ? '.' . str_pad((string)($ms % 1000), 3, '0', STR_PAD_LEFT) : '');
}
function crearComentarioTiempo(int $usuarioId, int $pubId, float $segundo, string $texto, string $request = ''): bool
{
    $texto = trim($texto);
    if (!social3Disponible() || $pubId < 1 || !is_finite($segundo) || $segundo < 0 || $segundo > 86400 || $texto === '' || mb_strlen($texto) > 300 || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
        return false;
    }
    if ($request !== '' && !preg_match('/^[a-f0-9]{32}$/D', $request)) {
        return false;
    }
    $precise = precisionComentariosDisponible();
    if (!$precise && floor($segundo) !== $segundo) {
        return false;
    }
    $ms = (int)round($segundo * 1000);
    $db = getDB();
    $owns = !$db->inTransaction();
    if ($owns) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$usuarioId]);
        if (!$st->fetchColumn()) {
            if ($owns) {
                $db->rollBack();
            }
            return false;
        }
        $st = $db->prepare('SELECT p.id,m.duracion FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id LEFT JOIN publicacion_medios m ON m.publicacion_id=p.id WHERE p.id=? FOR UPDATE');
        $st->execute([$pubId]);
        $row = $st->fetch();
        if (!$row || ($row['duracion'] !== null && $ms / 1000 > (float)$row['duracion']) || !puedeVerPublicacionSocial3($usuarioId, $pubId)) {
            if ($owns) {
                $db->rollBack();
            }
            return false;
        }
        $hash = hash('sha256', $ms . "\0" . $texto);
        if ($precise && $request !== '') {
            $st = $db->prepare('SELECT payload_hash FROM musicmad_timed_comment_receipts WHERE usuario_id=? AND publicacion_id=? AND solicitud=?');
            $st->execute([$usuarioId,$pubId,$request]);
            $old = $st->fetchColumn();
            if ($old !== false) {
                if (!hash_equals($old, $hash)) {
                    throw new UnexpectedValueException('Ese envío ya se usó para otro comentario.');
                }
                if ($owns) {
                    $db->commit();
                }
                return true;
            }
        }
        limiteChat($usuarioId, 'momento_audio', 20);
        $db->prepare('INSERT INTO comentarios_tiempo(publicacion_id,usuario_id,segundo,texto) VALUES(?,?,?,?)')->execute([$pubId,$usuarioId,(int)floor($ms / 1000),$texto]);
        $id = (int)$db->lastInsertId();
        if ($precise) {
            $db->prepare('INSERT INTO musicmad_timed_comment_ms(comentario_id,momento_ms) VALUES(?,?)')->execute([$id,$ms]);
        }
        if ($precise && $request !== '') {
            $db->prepare('INSERT INTO musicmad_timed_comment_receipts(usuario_id,publicacion_id,solicitud,payload_hash,comentario_id) VALUES(?,?,?,?,?)')->execute([$usuarioId,$pubId,$request,$hash,$id]);
        }
        notificar(duenoPublicacion($pubId), $usuarioId, 'comentario', $pubId);
        if ($owns) {
            $db->commit();
        }
        return true;
    } catch (Throwable $e) {
        if ($owns && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}

function comentariosTiempoDe(array $pubIds, ?int $viewer = null): array
{
    if (!social3Disponible() || !$pubIds) {
        return [];
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $pubIds)))), 0, 100);
    if (!$ids) {
        return [];
    }
    $viewer = $viewer ?? (int)sesion('usuario_id');
    $ids = array_values(array_filter($ids, fn ($id) => puedeVerPublicacionSocial3($viewer, $id)));
    if (!$ids) {
        return [];
    }
    $params = [];
    $marks = [];
    foreach ($ids as $index => $id) {
        $key = ':pub' . $index;
        $marks[] = $key;
        $params[$key] = $id;
    }
    [$policy,$access] = filtroAccesoContenido($viewer, 'ct.usuario_id', 'moment_author');
    $params += $access;
    $precise = precisionComentariosDisponible();
    $position = $precise ? 'COALESCE(ms.momento_ms/1000,ct.segundo)' : 'ct.segundo';
    $join = $precise ? ' LEFT JOIN musicmad_timed_comment_ms ms ON ms.comentario_id=ct.id ' : ' ';
    $st = getDB()->prepare('SELECT ct.id,ct.publicacion_id,ct.usuario_id,' . $position . ' AS segundo,ct.texto,ct.created_at,u.nombre FROM comentarios_tiempo ct JOIN usuarios u ON u.id=ct.usuario_id AND u.activo=1 ' . $join . ' WHERE ct.publicacion_id IN (' . implode(',', $marks) . ') AND (' . $policy . ') ORDER BY ct.publicacion_id,ct.segundo,ct.id LIMIT 500');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[(int) $row['publicacion_id']][] = $row;
    }
    return $out;
}

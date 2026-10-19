<?php

function repostComentariosDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');
    $st->execute(['musicmad_repost_notes','musicmad_repost_receipts']);
    return $ready = (int)$st->fetchColumn() === 2;
}
function notaRepostPara(int $actor, int $pub, int $viewer): string
{
    if (!repostComentariosDisponible() || !puedeVerPerfilSocial3($viewer, $actor) || !puedeVerPublicacionSocial3($viewer, $pub)) {
        return '';
    }
    $st = getDB()->prepare('SELECT n.texto FROM musicmad_repost_notes n JOIN reposts r ON r.id=n.repost_id JOIN usuarios u ON u.id=r.usuario_id AND u.activo=1 WHERE r.usuario_id=? AND r.publicacion_id=?');
    $st->execute([$actor,$pub]);
    return (string)($st->fetchColumn() ?: '');
}
function guardarRepostComentario(int $uid, int $pub, string $action, string $text, string $request): void
{
    if (!repostComentariosDisponible()) {
        throw new RuntimeException('Actualización de compartidos no disponible.');
    }
    $text = trim($text);
    if (!in_array($action, ['guardar','retirar'], true) || mb_strlen($text) > 500 || !preg_match('/^[a-f0-9]{32}$/D', $request)) {
        throw new InvalidArgumentException('Revisa el comentario y vuelve a abrir Compartir.');
    }
    if ($action === 'retirar') {
        $text = '';
    }
    $hash = hash('sha256', json_encode([$pub,$action,$text], JSON_THROW_ON_ERROR));
    limiteChat($uid, 'repost_comment', 15);
    $db = getDB();
    $own = !$db->inTransaction();
    if ($own) {
        $db->beginTransaction();
    }
    try {
        $st = $db->prepare('SELECT id FROM usuarios WHERE id=? AND activo=1 FOR UPDATE');
        $st->execute([$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Cuenta no disponible.');
        }
        $st = $db->prepare('SELECT payload_hash FROM musicmad_repost_receipts WHERE usuario_id=? AND solicitud=?');
        $st->execute([$uid,$request]);
        $old = $st->fetchColumn();
        if ($old !== false) {
            if (!hash_equals($old, $hash)) {
                throw new UnexpectedValueException('Ese envío ya tiene otro contenido.');
            }
            if ($own) {
                $db->commit();
            }
            return;
        }
        $st = $db->prepare('SELECT usuario_id FROM publicaciones WHERE id=? FOR UPDATE');
        $st->execute([$pub]);
        $owner = $st->fetchColumn();
        if ($owner === false) {
            throw new OutOfBoundsException('Publicación no disponible.');
        }
        if ($action === 'retirar') {
            $db->prepare('DELETE FROM reposts WHERE usuario_id=? AND publicacion_id=?')->execute([$uid,$pub]);
        } else {
            if ((int)$owner === $uid || !puedeVerPublicacionSocial3($uid, $pub)) {
                throw new OutOfBoundsException('Esta publicación no se puede compartir.');
            }
            $st = $db->prepare('SELECT id FROM reposts WHERE usuario_id=? AND publicacion_id=?');
            $st->execute([$uid,$pub]);
            $id = (int)$st->fetchColumn();
            if (!$id) {
                $db->prepare('INSERT INTO reposts(usuario_id,publicacion_id,created_at) VALUES(?,?,UTC_TIMESTAMP())')->execute([$uid,$pub]);
                $id = (int)$db->lastInsertId();
            }
            $db->prepare('INSERT INTO musicmad_repost_notes(repost_id,texto,updated_at) VALUES(?,?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE texto=VALUES(texto),updated_at=UTC_TIMESTAMP()')->execute([$id,$text]);
        }
        $db->prepare('INSERT INTO musicmad_repost_receipts(usuario_id,publicacion_id,solicitud,payload_hash,created_at) VALUES(?,?,?,?,UTC_TIMESTAMP())')->execute([$uid,$pub,$request,$hash]);
        if ($own) {
            $db->commit();
        }
    } catch (Throwable $e) {
        if ($own && $db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
function limpiarRecibosRepost(): void
{
    if (repostComentariosDisponible()) {
        getDB()->exec('DELETE FROM musicmad_repost_receipts WHERE created_at<UTC_TIMESTAMP()-INTERVAL 90 DAY LIMIT 1000');
    }
}

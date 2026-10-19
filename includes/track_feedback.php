<?php

function feedbackDisponible(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $st = getDB()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    $st->execute(['musicmad_track_feedback']);
    return $ready = (int)$st->fetchColumn() === 1;
}
function pistaPideFeedback(int $id): bool
{
    if (!feedbackDisponible()) {
        return false;
    }
    $st = getDB()->prepare('SELECT 1 FROM musicmad_track_feedback WHERE publicacion_id=?');
    $st->execute([$id]);
    return (bool)$st->fetchColumn();
}
function cambiarFeedbackPista(int $uid, int $id, bool $enabled): void
{
    if (!feedbackDisponible()) {
        throw new RuntimeException('La actualización de feedback no está disponible.');
    }
    limiteChat($uid, 'track_feedback', 20);
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
        $st = $db->prepare("SELECT p.id FROM publicaciones p JOIN publicacion_audio a ON a.publicacion_id=p.id WHERE p.id=? AND p.usuario_id=? AND p.tipo IN ('cancion','cover','demo') FOR UPDATE");
        $st->execute([$id,$uid]);
        if (!$st->fetchColumn()) {
            throw new OutOfBoundsException('Pista no disponible.');
        }
        if ($enabled) {
            $db->prepare('INSERT INTO musicmad_track_feedback(publicacion_id,actualizado_en) VALUES(?,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE actualizado_en=UTC_TIMESTAMP()')->execute([$id]);
        } else {
            $db->prepare('DELETE FROM musicmad_track_feedback WHERE publicacion_id=?')->execute([$id]);
        }
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

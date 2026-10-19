<?php

function videoPropioParaRestaurar(int $uid, int $pub, bool $lock = false): array
{
    $st = getDB()->prepare('SELECT p.id,p.titulo,p.tipo,v.archivo,v.formato FROM publicaciones p JOIN usuarios u ON u.id=p.usuario_id AND u.activo=1 LEFT JOIN publicacion_video v ON v.publicacion_id=p.id WHERE p.id=? AND p.usuario_id=?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$pub,$uid]);
    $row = $st->fetch();
    if (!$row || $row['tipo'] !== 'video') {
        throw new OutOfBoundsException('Publicación no disponible.');
    }
    if (is_string($row['archivo']) && nombreMedioRespaldoValido('videos', $row['archivo']) && is_file(musicmadMediaDirectoryForFile('videos', $row['archivo']) . '/' . $row['archivo'])) {
        throw new DomainException('Este video todavía tiene un archivo. No se reemplazó.');
    }
    return $row;
}
/** Called only inside the owned resumable upload transaction. Keeps the post ID. */
function restaurarArchivoVideoPropio(int $uid, int $pub, array $file): array
{
    $db = getDB();
    if (!$db->inTransaction()) {
        throw new LogicException('La restauración necesita una transacción.');
    }
    $previous = videoPropioParaRestaurar($uid, $pub, true);
    if ($previous['formato'] !== null && strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION)) !== $previous['formato']) {
        throw new InvalidArgumentException('Selecciona el archivo original en el mismo formato del video anterior.');
    }
    $db->prepare('DELETE FROM publicacion_video WHERE publicacion_id=?')->execute([$pub]);
    $db->prepare('DELETE FROM publicacion_medios WHERE publicacion_id=?')->execute([$pub]);
    $stored = guardarVideo($pub, $file);
    if (!$stored['ok']) {
        throw new InvalidArgumentException($stored['mensaje']);
    }
    try {
        if (estudioCreativoDisponible() && is_string($previous['archivo'])) {
            $db->prepare("UPDATE clips_musicales c JOIN musicmad_clip_sources s ON s.clip_id=c.id SET c.archivo=? WHERE s.publicacion_id=? AND c.tipo='video' AND c.archivo=?")->execute([$stored['archivo'],$pub,$previous['archivo']]);
        }
    } catch (Throwable $error) {
        if (is_file(VIDEO_DIR . '/' . $stored['archivo'])) {
            unlink(VIDEO_DIR . '/' . $stored['archivo']);
        }
        throw $error;
    }
    return $stored;
}
